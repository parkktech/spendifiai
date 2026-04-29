<?php

namespace App\Services;

use App\Models\CancellationProvider;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionDetectorService
{
    /**
     * Cached provider alias lookups, loaded from cancellation_providers table.
     * Each entry: ['pattern' => 'NETFLIX', 'name' => 'Netflix', 'category' => 'Streaming', 'essential' => false, 'provider_id' => 1]
     */
    protected ?Collection $providerAliasCache = null;

    /**
     * Plaid categories that are NEVER subscriptions.
     * Dramatically reduced from the original list — most categories now pass through to AI validation.
     */
    protected array $excludedCategories = [
        'TRANSFER_IN', 'BANK_FEES', 'LOAN_PAYMENTS',
        'ATM', 'INCOME', 'RENT', 'MORTGAGE',
    ];

    /**
     * Companies known to sell both fixed subscriptions AND usage-based API access.
     * For these, we check amount consistency before treating as subscription.
     */
    protected array $knownApiCompanies = [
        'ANTHROPIC', 'OPENAI', 'AWS', 'GOOGLE CLOUD', 'AZURE',
        'DIGITALOCEAN', 'TWILIO', 'SENDGRID', 'STRIPE',
    ];

    /**
     * Payment processor patterns — extract actual merchant from processor descriptions.
     */
    protected array $paymentProcessors = [
        'PAYPAL' => '/PAYPAL\s+(?:INST\s+XFER\s+|PURCHASE\s+)?(.+?)(?:\s+WEB\s+ID|\s+\d{10,}|\s*$)/i',
        'VENMO' => '/VENMO\s+\*?(.+?)(?:\s+\d{4}|\s*$)/i',
        'CASH APP' => '/CASH\s+APP\s+\*?(.+?)(?:\s+\d{4}|\s*$)/i',
    ];

    /**
     * Scan all transactions and detect recurring subscriptions.
     */
    public function detectSubscriptions(int $userId): array
    {
        $since = Carbon::now()->subMonths(6);

        $transactions = Transaction::where('user_id', $userId)
            ->where('transaction_date', '>=', $since)
            ->where('amount', '>', 0)
            ->select(['id', 'user_id', 'merchant_name', 'merchant_normalized', 'description', 'amount', 'transaction_date', 'ai_category', 'plaid_category', 'payment_channel'])
            ->orderBy('transaction_date')
            ->get();

        // Group by normalized merchant
        $merchantGroups = $transactions->groupBy(function ($tx) {
            return $this->normalizeMerchant($tx->merchant_name);
        });

        $detected = [];
        $aiCandidates = [];
        $matchedSubIds = []; // Track already-matched subscription IDs to prevent duplicates

        foreach ($merchantGroups as $merchant => $txGroup) {
            if ($merchant === 'unknown' || $merchant === '') {
                continue;
            }

            // Check if this is a known subscription from our provider database
            $known = $this->lookupKnownSubscription($merchant);

            if (! $known) {
                // Skip hard-excluded categories (bank fees, transfers, loans, ATM, income, rent)
                $primaryCategory = $txGroup->first()->plaid_category;
                if ($primaryCategory && in_array($primaryCategory, $this->excludedCategories)) {
                    continue;
                }
            }

            // For known API companies, check if the overall merchant has mixed charges.
            // If so, treat ALL subgroups as unknown (require AI validation) to avoid
            // false positives like OpenAI API charges at $186 being flagged as subscriptions.
            $isApiMerchant = $this->isKnownApiCompany($merchant);
            $merchantHasMixedAmounts = false;
            if ($isApiMerchant) {
                $allAmounts = $txGroup->pluck('amount')->map(fn ($a) => (float) $a);
                if ($allAmounts->count() >= 2) {
                    $merchantHasMixedAmounts = ($allAmounts->max() - $allAmounts->min()) > 1.00;
                }
            }

            // Minimum charge thresholds: 2 for known providers, 3 for unknown
            $minCharges = $known ? 2 : 3;

            // Known essential bills (insurance, utilities, phone) have varying amounts.
            // Analyze them as a whole group with relaxed amount tolerance.
            if ($known && ($known['essential'] ?? false) && $txGroup->count() >= $minCharges) {
                $recurring = $this->analyzeRecurrence($txGroup, tolerant: true);
                if ($recurring) {
                    $detected[] = $this->createOrUpdateSubscription(
                        $userId, $merchant, $txGroup, $recurring, $matchedSubIds
                    );
                }

                continue;
            }

            // Sub-group by exact amount to split mixed charges (e.g. two Microsoft plans)
            $amountGroups = $txGroup->groupBy(fn ($tx) => number_format((float) $tx->amount, 2, '.', ''));

            foreach ($amountGroups as $subGroup) {
                if ($subGroup->count() < $minCharges) {
                    continue;
                }

                $recurring = $this->analyzeRecurrence($subGroup);
                if (! $recurring) {
                    continue;
                }

                // For API companies with mixed amounts, always use AI validation
                // to distinguish ChatGPT Plus ($63.78) from API charges ($186)
                if ($known && ! $merchantHasMixedAmounts) {
                    $detected[] = $this->createOrUpdateSubscription(
                        $userId, $merchant, $subGroup, $recurring, $matchedSubIds
                    );
                } else {
                    $aiCandidates[] = [
                        'merchant' => $merchant,
                        'txGroup' => $subGroup,
                        'recurring' => $recurring,
                    ];
                }
            }
        }

        // Validate unknown merchants with AI in a single batch call
        if (! empty($aiCandidates)) {
            $aiResults = $this->aiValidateSubscriptions($aiCandidates);
            foreach ($aiResults as $idx => $isSubscription) {
                if ($isSubscription) {
                    $candidate = $aiCandidates[$idx];
                    $detected[] = $this->createOrUpdateSubscription(
                        $userId,
                        $candidate['merchant'],
                        $candidate['txGroup'],
                        $candidate['recurring'],
                        $matchedSubIds
                    );
                }
            }
        }

        // Try to link any unlinked subscriptions to CancellationProviders
        $this->linkUnlinkedSubscriptions($userId);

        // Merge any remaining duplicates (same merchant name substring + same amount)
        // Must run AFTER linkUnlinked so renamed subs can be matched
        $this->mergeRemainingDuplicates($userId);

        // Second pass: also merge by provider_id (catches cross-name dupes linked to same provider)
        $this->mergeByProvider($userId);

        // Third pass: SQL-based dedup (guaranteed to eliminate all duplicates)
        $deleted = $this->sqlDedup($userId);
        if ($deleted > 0) {
            Log::info('SQL dedup removed duplicates', ['user_id' => $userId, 'deleted' => $deleted]);
        }

        // Detect subscriptions that missed their expected billing cycle
        $this->detectMissedCharges($userId);

        return [
            'detected' => count($detected),
            'total_monthly' => collect($detected)->sum('amount'),
        ];
    }

    /**
     * Check if a merchant is a known API/cloud company that sells both subscriptions and usage-based access.
     */
    protected function isKnownApiCompany(string $merchant): bool
    {
        $lower = strtolower($merchant);

        return collect($this->knownApiCompanies)->contains(
            fn ($c) => str_contains($lower, strtolower($c))
        );
    }

    /**
     * Analyze a group of transactions to determine if they're recurring.
     * Requires: amount consistency + regular intervals.
     */
    protected function analyzeRecurrence(Collection $transactions, bool $tolerant = false): ?array
    {
        $sorted = $transactions->sortBy('transaction_date')->values();
        $amounts = $sorted->pluck('amount')->map(fn ($a) => (float) $a)->toArray();
        $dates = $sorted->pluck('transaction_date')->toArray();

        // --- Amount consistency ---
        if ($tolerant) {
            // Essential bills (utilities, insurance, phone) have varying amounts.
            // Skip amount consistency — if a known provider charges on a regular cycle, it's a bill.
            $avgAmount = array_sum($amounts) / count($amounts);
            $amountConsistent = true;
        } else {
            // True subscriptions bill the exact same amount each cycle.
            // Allow only $0.01 tolerance for floating-point rounding.
            $mode = $this->modeAmount($amounts);
            $amountConsistent = collect($amounts)->every(
                fn ($a) => abs($a - $mode) <= 0.01
            );
            $avgAmount = $mode;
        }

        if (! $amountConsistent) {
            return null;
        }

        // --- Interval regularity ---
        $intervals = [];
        for ($i = 1; $i < count($dates); $i++) {
            $intervals[] = Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i]));
        }

        if (empty($intervals)) {
            return null;
        }

        $avgInterval = array_sum($intervals) / count($intervals);

        // Check interval consistency via standard deviation
        // Tolerant mode (known essential bills) allows higher variance — extra charges, plan changes, etc.
        $maxCv = $tolerant ? 0.60 : 0.25;
        if (count($intervals) >= 2) {
            $variance = array_sum(array_map(
                fn ($i) => ($i - $avgInterval) ** 2, $intervals
            )) / count($intervals);
            $stdDev = sqrt($variance);
            $cv = $avgInterval > 0 ? $stdDev / $avgInterval : 1;

            if ($cv > $maxCv) {
                return null;
            }
        } elseif (count($intervals) === 1) {
            // Single interval (2 charges): just check it falls in a valid frequency range
            if ($intervals[0] < 5 || $intervals[0] > 380) {
                return null;
            }
        }

        // Determine frequency from median interval (more robust than average)
        $sortedIntervals = $intervals;
        sort($sortedIntervals);
        $medianInterval = $sortedIntervals[(int) floor(count($sortedIntervals) / 2)];

        // With only 1 interval (2 charges), gaps can be noisy — a monthly sub may show
        // a 50-60 day gap if one charge was early and the next late. Use wider bands.
        // With 2+ intervals, the median is more reliable so we can use tighter bands.
        $hasMultipleIntervals = count($intervals) >= 2;

        $frequency = match (true) {
            $medianInterval <= 10 => 'weekly',
            $medianInterval <= ($hasMultipleIntervals ? 40 : 62) => 'monthly',
            $medianInterval <= ($hasMultipleIntervals ? 120 : 150) => 'quarterly',
            $medianInterval <= 380 => 'annual',
            default => null,
        };

        if (! $frequency) {
            return null;
        }

        // Predict next charge
        $lastDate = Carbon::parse(end($dates));
        $nextDate = match ($frequency) {
            'weekly' => $lastDate->copy()->addWeek(),
            'monthly' => $lastDate->copy()->addMonth(),
            'quarterly' => $lastDate->copy()->addMonths(3),
            'annual' => $lastDate->copy()->addYear(),
        };

        return [
            'frequency' => $frequency,
            'avg_amount' => round($avgAmount, 2),
            'next_expected' => $nextDate->toDateString(),
            'charge_count' => count($amounts),
        ];
    }

    /**
     * Ask Claude AI to validate whether candidate merchants are true subscriptions.
     * Sends a single batch request to minimize API calls.
     *
     * @return array<int, bool> Indexed results matching input order
     */
    protected function aiValidateSubscriptions(array $candidates): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            return array_fill(0, count($candidates), false);
        }

        $prompt = "You are a financial analyst. For each merchant below, determine if the charges represent a TRUE recurring subscription or NOT a subscription.\n\n";
        $prompt .= "A TRUE subscription means:\n";
        $prompt .= "- The company charges a fixed amount on a regular billing cycle\n";
        $prompt .= "- The user signed up for an ongoing service that bills automatically\n";
        $prompt .= "- Examples: Netflix, Spotify, gym, software licenses, VPN, hosting, credit monitoring\n\n";
        $prompt .= "NOT a subscription:\n";
        $prompt .= "- Variable usage-based charges (API fees, cloud computing, pay-per-use)\n";
        $prompt .= "- Repeated purchases at the same retailer (grocery store, Amazon orders, gas station)\n";
        $prompt .= "- Multiple one-time purchases that happen to be the same amount\n";
        $prompt .= "- Regular bill payments (credit card autopay, loan payments, rent)\n";
        $prompt .= "- Transfers between accounts\n\n";
        $prompt .= 'CRITICAL: Companies like OpenAI, Anthropic, Google Cloud, AWS sell BOTH fixed subscriptions AND usage-based API access. ';
        $prompt .= 'ChatGPT Plus ($20/mo fixed) = subscription. API charges ($186.10, $186.51 varying) = NOT subscription. ';
        $prompt .= "If amounts vary by more than $1 for a tech/SaaS company, it is likely API usage.\n\n";

        $merchantData = [];
        foreach ($candidates as $idx => $c) {
            $charges = $c['txGroup']->sortBy('transaction_date')->map(fn ($tx) => [
                'date' => $tx->transaction_date->format('Y-m-d'),
                'amount' => (float) $tx->amount,
                'description' => $tx->merchant_name,
                'channel' => $tx->payment_channel,
            ])->values()->toArray();

            $merchantData[] = [
                'index' => $idx,
                'merchant' => $c['merchant'],
                'frequency' => $c['recurring']['frequency'],
                'charges' => $charges,
            ];
        }

        $prompt .= "Merchants to evaluate:\n".json_encode($merchantData, JSON_PRETTY_PRINT);
        $prompt .= "\n\nRespond with JSON array of objects: [{\"index\": 0, \"is_subscription\": true/false, \"reason\": \"brief reason\"}]";

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-sonnet-4-20250514'),
                'max_tokens' => 2000,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (! $response->successful()) {
                Log::warning('AI subscription validation failed', ['status' => $response->status()]);

                return array_fill(0, count($candidates), false);
            }

            $text = $response->json('content.0.text');
            $text = preg_replace('/^```json\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', $text);
            $decoded = json_decode(trim($text), true);

            if (! is_array($decoded)) {
                return array_fill(0, count($candidates), false);
            }

            // Map results back by index
            $results = array_fill(0, count($candidates), false);
            foreach ($decoded as $item) {
                if (isset($item['index'], $item['is_subscription'])) {
                    $results[$item['index']] = (bool) $item['is_subscription'];

                    Log::info('AI subscription validation', [
                        'merchant' => $candidates[$item['index']]['merchant'] ?? 'unknown',
                        'is_subscription' => $item['is_subscription'],
                        'reason' => $item['reason'] ?? '',
                    ]);
                }
            }

            return $results;

        } catch (\Exception $e) {
            Log::warning('AI subscription validation error', ['error' => $e->getMessage()]);

            return array_fill(0, count($candidates), false);
        }
    }

    /**
     * Create or update a subscription record.
     * Handles deduplication by checking both normalized name and raw merchant name.
     */
    protected function createOrUpdateSubscription(
        int $userId,
        string $merchant,
        Collection $transactions,
        array $recurring,
        array &$matchedSubIds = []
    ): Subscription {
        $known = $this->lookupKnownSubscription($merchant);
        $lastTx = $transactions->sortByDesc('transaction_date')->first();
        // Use provider name if known, otherwise use the original merchant_name from the transaction
        // (preserves proper casing like "OpenAI" instead of lowercase "openai")
        $normalizedName = $known['name'] ?? $lastTx->merchant_name;

        $annualMultiplier = match ($recurring['frequency']) {
            'weekly' => 52,
            'monthly' => 12,
            'quarterly' => 4,
            'annual' => 1,
        };

        // Try to find existing subscription, checking multiple match strategies.
        // For essential bills (utilities, phone, insurance), match by provider_id only — amounts vary.
        // For non-essential subs, require exact amount match to keep different tiers separate.
        $rawMerchant = $lastTx->merchant_name;
        $isEssential = $known['essential'] ?? false;

        $existing = null;

        // Exclude subscriptions already matched in this detection run
        $excludeQuery = function ($q) use (&$matchedSubIds) {
            return ! empty($matchedSubIds) ? $q->whereNotIn('id', $matchedSubIds) : $q;
        };

        // Strategy 1: Essential bills with a known provider — match by provider_id, ignore amount
        if ($isEssential && ! empty($known['provider_id'])) {
            $existing = $excludeQuery(Subscription::where('user_id', $userId)
                ->where('cancellation_provider_id', $known['provider_id']))
                ->first();
        }

        // Strategy 2: Match by name + exact amount (non-essential, or essential without provider)
        if (! $existing) {
            $lowerMerchant = strtolower($merchant);
            $lowerNormalized = strtolower($normalizedName);
            $lowerRaw = strtolower($rawMerchant);

            $existing = $excludeQuery(Subscription::where('user_id', $userId)
                ->where('amount', $recurring['avg_amount'])
                ->where(function ($q) use ($lowerMerchant, $lowerNormalized, $lowerRaw, $known) {
                    // Exact name matches
                    $q->whereRaw('lower(merchant_normalized) = ?', [$lowerNormalized])
                        ->orWhereRaw('lower(merchant_normalized) = ?', [$lowerMerchant])
                        ->orWhereRaw('lower(merchant_name) = ?', [$lowerRaw]);

                    // Substring matches: "Amazon" matches "Amazon Prime" and vice versa
                    if (strlen($lowerMerchant) >= 4) {
                        $q->orWhereRaw('lower(merchant_normalized) LIKE ?', ['%'.$lowerMerchant.'%'])
                            ->orWhereRaw('? LIKE \'%\' || lower(merchant_normalized) || \'%\'', [$lowerMerchant]);
                    }

                    if (! empty($known['provider_id'])) {
                        $q->orWhere('cancellation_provider_id', $known['provider_id']);
                    }
                }))
                ->first();
        }

        // Extract a useful description from the bank transaction
        $description = $lastTx->description ?? $lastTx->merchant_name;

        $data = [
            'merchant_name' => $lastTx->merchant_name,
            'merchant_normalized' => $normalizedName,
            'description' => $description,
            'amount' => $recurring['avg_amount'],
            'frequency' => $recurring['frequency'],
            'category' => $known['category'] ?? $lastTx->ai_category ?? 'Subscriptions',
            'last_charge_date' => $lastTx->transaction_date,
            'next_expected_date' => $recurring['next_expected'],
            'missed_charge_at' => null,
            'status' => 'active',
            'months_active' => $recurring['charge_count'],
            'is_essential' => $known['essential'] ?? $this->isEssentialByCategory($known['category'] ?? $lastTx->ai_category),
            'annual_cost' => round($recurring['avg_amount'] * $annualMultiplier, 2),
            'cancellation_provider_id' => $known['provider_id'] ?? null,
            'charge_history' => $transactions->map(fn ($t) => [
                'date' => $t->transaction_date->toDateString(),
                'amount' => $t->amount,
            ])->values()->toArray(),
        ];

        if ($existing) {
            // Don't overwrite provider link or normalized name if already set from a prior link
            if ($existing->cancellation_provider_id && empty($data['cancellation_provider_id'])) {
                unset($data['cancellation_provider_id'], $data['merchant_normalized']);
            }

            // If the subscription was cancelled by the user, don't reset to active.
            // Only flag as recharged if a NEW charge appeared AFTER the cancellation date.
            if ($existing->status === \App\Enums\SubscriptionStatus::Cancelled) {
                $latestChargeDate = Carbon::parse($lastTx->transaction_date);

                if ($existing->responded_at && $latestChargeDate->gt($existing->responded_at)) {
                    $existing->update([
                        'recharged_at' => $latestChargeDate,
                        'recharged_amount' => $recurring['avg_amount'],
                        'last_charge_date' => $latestChargeDate,
                        'charge_history' => $data['charge_history'],
                    ]);

                    Log::info('Subscription recharged after cancellation', [
                        'subscription_id' => $existing->id,
                        'merchant' => $existing->merchant_normalized,
                        'recharged_at' => $latestChargeDate->toDateString(),
                        'amount' => $recurring['avg_amount'],
                    ]);
                }

                // Either way, don't overwrite cancelled status
                $matchedSubIds[] = $existing->id;

                return $existing;
            }

            // Normal update for active subscriptions
            $existing->update($data);
            $matchedSubIds[] = $existing->id;

            return $existing;
        }

        // Final guard: check if a subscription with matching name+amount already exists
        // Uses substring matching to catch "Amazon" vs "Amazon Prime" cross-name duplicates
        // NOTE: does NOT exclude matchedSubIds — this is the last resort to prevent duplicates
        $guardName = strtolower($data['merchant_normalized']);
        $guard = Subscription::where('user_id', $userId)
            ->whereRaw('amount = ?', [$data['amount']])
            ->where(function ($q) use ($guardName) {
                $q->whereRaw('lower(merchant_normalized) = ?', [$guardName]);
                if (strlen($guardName) >= 4) {
                    $q->orWhereRaw('lower(merchant_normalized) LIKE ?', ['%'.$guardName.'%'])
                        ->orWhereRaw('? LIKE \'%\' || lower(merchant_normalized) || \'%\'', [$guardName]);
                }
            })
            ->first();

        if ($guard) {
            $guard->update(collect($data)->except(['status', 'user_id'])->toArray());
            $matchedSubIds[] = $guard->id;

            return $guard;
        }

        $new = Subscription::create(array_merge($data, ['user_id' => $userId]));
        $matchedSubIds[] = $new->id;

        return $new;
    }

    /**
     * Try to link unlinked subscriptions to CancellationProviders.
     * Runs after detection to catch AI-validated subscriptions that weren't matched during creation.
     */
    protected function linkUnlinkedSubscriptions(int $userId): void
    {
        $unlinked = Subscription::where('user_id', $userId)
            ->whereNull('cancellation_provider_id')
            ->get();

        $aliases = $this->loadProviderAliases();

        foreach ($unlinked as $sub) {
            $merchantName = strtolower(trim($sub->merchant_normalized ?? $sub->merchant_name));

            // First try standard lookup (merchant contains alias)
            $known = $this->lookupKnownSubscription($merchantName);

            // If no match, try reverse: check if any alias contains the merchant name.
            // This catches cases like "openai" matching "openai chatgpt" alias for ChatGPT Plus.
            if (! $known && strlen($merchantName) >= 4) {
                $bestMatch = null;
                $bestLength = 0;
                foreach ($aliases as $entry) {
                    if (str_contains($entry['pattern'], $merchantName)) {
                        if (strlen($entry['pattern']) > $bestLength) {
                            $bestMatch = $entry;
                            $bestLength = strlen($entry['pattern']);
                        }
                    }
                }
                $known = $bestMatch;
            }

            if ($known) {
                // Check if a linked subscription with this provider + amount already exists.
                // If so, delete this unlinked duplicate instead of creating a second entry.
                $existingLinked = Subscription::where('user_id', $userId)
                    ->where('cancellation_provider_id', $known['provider_id'])
                    ->where('amount', $sub->amount)
                    ->where('id', '!=', $sub->id)
                    ->first();

                if ($existingLinked) {
                    // Copy fresher data from the unlinked duplicate before deleting it
                    $updates = [];
                    if (! $existingLinked->description && $sub->description) {
                        $updates['description'] = $sub->description;
                    }
                    if ($sub->last_charge_date && (! $existingLinked->last_charge_date || $sub->last_charge_date > $existingLinked->last_charge_date)) {
                        $updates['last_charge_date'] = $sub->last_charge_date;
                        $updates['charge_history'] = $sub->charge_history;
                    }
                    if (! empty($updates)) {
                        $existingLinked->update($updates);
                    }

                    $sub->delete();

                    continue;
                }

                $sub->update([
                    'cancellation_provider_id' => $known['provider_id'],
                    'merchant_normalized' => $known['name'],
                    'category' => $known['category'] ?? $sub->category,
                    'is_essential' => $known['essential'] ?? $sub->is_essential,
                ]);
            }
        }
    }

    /**
     * Merge duplicate subscriptions: same user + same amount + overlapping merchant names.
     * Keeps the one with the most charge history, merges histories, deletes the rest.
     */
    protected function mergeRemainingDuplicates(int $userId): void
    {
        $subs = Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('amount')
            ->get();

        $merged = [];

        foreach ($subs as $sub) {
            if (in_array($sub->id, $merged)) {
                continue;
            }

            $lowerName = strtolower($sub->merchant_normalized ?? $sub->merchant_name);
            if (strlen($lowerName) < 4) {
                continue;
            }

            // Find other subs at the same amount with overlapping names
            $dupes = $subs->filter(function ($other) use ($sub, $lowerName, $merged) {
                if ($other->id === $sub->id || in_array($other->id, $merged)) {
                    return false;
                }
                if ((float) $other->amount !== (float) $sub->amount) {
                    return false;
                }
                $otherName = strtolower($other->merchant_normalized ?? $other->merchant_name);

                return str_contains($lowerName, $otherName) || str_contains($otherName, $lowerName);
            });

            if ($dupes->isEmpty()) {
                continue;
            }

            // Keep user-cancelled records over auto-created; then latest charge date
            $allRecords = collect([$sub])->merge($dupes)->sort(function ($a, $b) {
                $aResponded = $a->responded_at ? 0 : 1;
                $bResponded = $b->responded_at ? 0 : 1;
                if ($aResponded !== $bResponded) {
                    return $aResponded - $bResponded;
                }

                return ($b->last_charge_date ?? '') <=> ($a->last_charge_date ?? '');
            });
            $keep = $allRecords->first();
            $deleteRecords = $allRecords->skip(1);

            // Merge charge histories
            $allHistory = collect($keep->charge_history ?? []);
            foreach ($deleteRecords as $old) {
                $allHistory = $allHistory->merge(collect($old->charge_history ?? []));
                $merged[] = $old->id;
            }

            $keep->update([
                'charge_history' => $allHistory->unique('date')->sortBy('date')->values()->toArray(),
                'months_active' => $allHistory->unique('date')->count(),
            ]);

            Subscription::whereIn('id', $deleteRecords->pluck('id'))->delete();
        }
    }

    /**
     * Merge subscriptions with the same provider_id + same amount.
     * This catches duplicates created from different merchant name normalizations
     * (e.g. "Amazon" vs "Amazon Prime" both linked to provider #75).
     */
    protected function mergeByProvider(int $userId): void
    {
        $dupes = Subscription::where('user_id', $userId)
            ->whereNotNull('cancellation_provider_id')
            ->selectRaw('cancellation_provider_id, amount, COUNT(*) as cnt')
            ->groupBy('cancellation_provider_id', 'amount')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $d) {
            $subs = Subscription::where('user_id', $userId)
                ->where('cancellation_provider_id', $d->cancellation_provider_id)
                ->whereRaw('CAST(amount AS NUMERIC) = ?', [(float) $d->amount])
                ->orderByRaw('CASE WHEN responded_at IS NOT NULL THEN 0 ELSE 1 END, last_charge_date DESC NULLS LAST')
                ->get();

            $keep = $subs->first();
            $deleteRecords = $subs->skip(1);
            if ($deleteRecords->isEmpty()) {
                continue;
            }

            $allHistory = collect($keep->charge_history ?? []);
            foreach ($deleteRecords as $old) {
                $allHistory = $allHistory->merge(collect($old->charge_history ?? []));
            }

            $keep->update([
                'charge_history' => $allHistory->unique('date')->sortBy('date')->values()->toArray(),
                'months_active' => $allHistory->unique('date')->count(),
            ]);

            Subscription::whereIn('id', $deleteRecords->pluck('id'))->delete();
        }
    }

    /**
     * SQL-based dedup: delete duplicate subscriptions keeping the one with the latest charge.
     * This is the final safety net — runs raw SQL so Eloquent caching can't interfere.
     */
    protected function sqlDedup(int $userId): int
    {
        // Prefer user-cancelled records (responded_at IS NOT NULL) over auto-created active ones.
        // Among same-status records, prefer the one with the latest charge date.
        $ids = DB::select('
            SELECT id FROM (
                SELECT id,
                       ROW_NUMBER() OVER (
                           PARTITION BY user_id, merchant_normalized, amount
                           ORDER BY
                               CASE WHEN responded_at IS NOT NULL THEN 0 ELSE 1 END,
                               last_charge_date DESC NULLS LAST,
                               id DESC
                       ) as rn
                FROM subscriptions
                WHERE user_id = ?
            ) ranked
            WHERE rn > 1
        ', [$userId]);

        if (empty($ids)) {
            return 0;
        }

        $deleteIds = array_map(fn ($r) => $r->id, $ids);

        return DB::table('subscriptions')->whereIn('id', $deleteIds)->delete();
    }

    /**
     * Detect active subscriptions that have missed their expected billing cycle.
     * Uses expected interval + grace period (weekends/processing delays).
     * Sets missed_charge_at when a charge is overdue; clears it when a new charge arrives.
     */
    protected function detectMissedCharges(int $userId): void
    {
        $now = Carbon::now();

        $subscriptions = Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->whereNotNull('last_charge_date')
            ->get();

        foreach ($subscriptions as $sub) {
            // Compute thresholds from the subscription's actual charge history
            [$graceDays, $autoCancelDays] = $this->computeBillingThresholds($sub);

            $daysSinceCharge = (int) abs($now->diffInDays(Carbon::parse($sub->last_charge_date)));

            if ($daysSinceCharge > $autoCancelDays) {
                // Before auto-cancelling, check if this merchant has recent transactions
                // at a different amount (e.g. phone plan changed, price increase).
                // If so, update the subscription instead of cancelling it.
                $recentTx = Transaction::where('user_id', $userId)
                    ->where('amount', '>', 0)
                    ->where('transaction_date', '>=', $now->copy()->subDays($autoCancelDays))
                    ->where(function ($q) use ($sub) {
                        $q->whereRaw('lower(merchant_name) LIKE ?', ['%'.strtolower($sub->merchant_normalized ?? $sub->merchant_name).'%'])
                            ->orWhereRaw('lower(merchant_normalized) LIKE ?', ['%'.strtolower($sub->merchant_normalized ?? $sub->merchant_name).'%']);
                    })
                    ->orderByDesc('transaction_date')
                    ->first();

                if ($recentTx) {
                    // Check if another subscription already covers this amount
                    $newAmount = (float) $recentTx->amount;
                    $alreadyCovered = Subscription::where('user_id', $userId)
                        ->where('id', '!=', $sub->id)
                        ->whereRaw('amount = ?', [$newAmount])
                        ->where(function ($q) use ($sub) {
                            $q->where('merchant_normalized', $sub->merchant_normalized)
                                ->orWhereRaw('lower(merchant_normalized) LIKE ?', ['%'.strtolower($sub->merchant_normalized).'%']);
                        })
                        ->exists();

                    if ($alreadyCovered) {
                        // Another sub already tracks this merchant at this amount — auto-cancel this one
                        $sub->update([
                            'status' => 'cancelled',
                            'response_type' => 'cancelled',
                            'previous_amount' => (float) $sub->amount,
                            'responded_at' => $sub->missed_charge_at ?? $now,
                            'missed_charge_at' => null,
                        ]);

                        continue;
                    }

                    // Merchant is still billing — amount probably changed
                    $sub->update([
                        'amount' => $newAmount,
                        'last_charge_date' => $recentTx->transaction_date,
                        'annual_cost' => round($newAmount * match ($sub->frequency) {
                            'weekly' => 52, 'monthly' => 12, 'quarterly' => 4, 'annual' => 1, default => 12,
                        }, 2),
                        'missed_charge_at' => null,
                    ]);

                    Log::info('Subscription amount updated (merchant still billing)', [
                        'subscription_id' => $sub->id,
                        'merchant' => $sub->merchant_normalized,
                        'old_amount' => $sub->amount,
                        'new_amount' => (float) $recentTx->amount,
                    ]);

                    continue;
                }

                // No recent charges from this merchant — auto-cancel
                $previousAmount = (float) $sub->amount;
                $sub->update([
                    'status' => 'cancelled',
                    'response_type' => 'cancelled',
                    'previous_amount' => $previousAmount,
                    'responded_at' => $sub->missed_charge_at ?? $now,
                    'missed_charge_at' => null,
                ]);

                Log::info('Subscription auto-cancelled after missed billing cycles', [
                    'subscription_id' => $sub->id,
                    'merchant' => $sub->merchant_normalized,
                    'days_since_charge' => $daysSinceCharge,
                    'frequency' => $sub->frequency,
                    'amount' => $previousAmount,
                ]);
            } elseif ($daysSinceCharge > $graceDays && ! $sub->missed_charge_at) {
                // 1 missed cycle — flag as overdue
                $sub->update(['missed_charge_at' => $now]);
            } elseif ($daysSinceCharge <= $graceDays && $sub->missed_charge_at) {
                // New charge came in — clear the missed flag
                $sub->update(['missed_charge_at' => null]);
            }
        }
    }

    /**
     * Compute grace period and auto-cancel threshold from actual charge history.
     * Uses the median interval (ignoring outliers) + a small buffer.
     *
     * @return array{0: int, 1: int} [graceDays, autoCancelDays]
     */
    protected function computeBillingThresholds(Subscription $sub): array
    {
        $history = collect($sub->charge_history ?? []);

        // Fallback to frequency-based defaults if not enough history
        $defaultInterval = match ($sub->frequency) {
            'weekly' => 7,
            'monthly' => 30,
            'quarterly' => 90,
            'annual' => 365,
            default => 30,
        };

        if ($history->count() < 3) {
            // Not enough data points — use frequency-based defaults with buffer
            $grace = $defaultInterval + 5;
            $autoCancel = (int) round($defaultInterval * 1.5) + 5;

            return [$grace, $autoCancel];
        }

        // Compute intervals between sequential charges
        $sorted = $history->sortBy('date')->values();
        $intervals = [];
        for ($i = 1; $i < $sorted->count(); $i++) {
            $days = Carbon::parse($sorted[$i - 1]['date'])->diffInDays(Carbon::parse($sorted[$i]['date']));
            $intervals[] = $days;
        }

        if (empty($intervals)) {
            return [$defaultInterval + 8, ($defaultInterval * 2) + 5];
        }

        // Remove outliers: intervals less than half or more than double the median
        sort($intervals);
        $rawMedian = $intervals[(int) floor(count($intervals) / 2)];

        $filtered = array_filter($intervals, function ($i) use ($rawMedian) {
            return $i >= ($rawMedian * 0.4) && $i <= ($rawMedian * 2.2);
        });

        if (empty($filtered)) {
            $filtered = $intervals;
        }

        // Use the median of filtered intervals as the true billing cycle
        $filtered = array_values($filtered);
        sort($filtered);
        $typicalInterval = $filtered[(int) floor(count($filtered) / 2)];

        // Grace: typical interval + 3 days (weekends + processing)
        $grace = $typicalInterval + 3;

        // Auto-cancel: 1.5 typical intervals + 3 days
        // A monthly sub at ~30d interval cancels at ~48 days (well past one missed cycle)
        $autoCancel = (int) round($typicalInterval * 1.5) + 3;

        return [$grace, $autoCancel];
    }

    /**
     * Categories that indicate essential/household bills.
     */
    protected function isEssentialByCategory(?string $category): bool
    {
        if (! $category) {
            return false;
        }

        $essentialCategories = [
            'housing & rent', 'mortgage', 'rent', 'housing',
            'utilities', 'electric', 'electricity', 'gas & electric',
            'water', 'sewer', 'trash', 'waste',
            'phone & internet', 'phone', 'internet', 'mobile phone',
            'car insurance', 'auto insurance', 'health insurance',
            'home insurance', 'renters insurance', 'life insurance',
            'childcare', 'daycare',
        ];

        $lower = strtolower(trim($category));

        return in_array($lower, $essentialCategories);
    }

    /**
     * Find the most common amount (mode) from a list of amounts.
     */
    protected function modeAmount(array $amounts): float
    {
        $rounded = array_map(fn ($a) => round($a, 2), $amounts);
        $counts = array_count_values(array_map('strval', $rounded));
        arsort($counts);

        return (float) array_key_first($counts);
    }

    /**
     * Normalize a merchant name for grouping.
     * Handles payment processor extraction (PayPal, Venmo, etc.)
     */
    protected function normalizeMerchant(?string $name): string
    {
        if (! $name) {
            return 'unknown';
        }

        // Try to extract actual merchant from payment processors first
        $extracted = $this->extractMerchantFromProcessor($name);
        if ($extracted) {
            $name = $extracted;
        }

        $lower = strtolower(trim($name));
        // Strip trailing numbers, #, *, locations
        $clean = preg_replace('/[#*]+\d*\s*$/', '', $lower);
        $clean = preg_replace('/\s+\d{3,}$/', '', $clean);

        return trim($clean);
    }

    /**
     * Extract actual merchant name from payment processor descriptions.
     * "PAYPAL INST XFER GOOGLE GOOGLE O" → "GOOGLE GOOGLE O"
     * "VENMO *JOHN DOE" → "JOHN DOE"
     */
    protected function extractMerchantFromProcessor(string $merchantName): ?string
    {
        $upper = strtoupper(trim($merchantName));
        foreach ($this->paymentProcessors as $processor => $pattern) {
            if (str_contains($upper, $processor)) {
                if (preg_match($pattern, $merchantName, $matches)) {
                    $extracted = trim($matches[1]);
                    // Clean up: remove trailing transaction IDs, asterisks
                    $extracted = preg_replace('/[\*#]+\d*$/', '', $extracted);
                    $extracted = trim($extracted);

                    return $extracted ?: null;
                }
            }
        }

        return null;
    }

    /**
     * Load provider aliases from the cancellation_providers table.
     */
    protected function loadProviderAliases(): Collection
    {
        if ($this->providerAliasCache === null) {
            $this->providerAliasCache = CancellationProvider::all()
                ->flatMap(function ($provider) {
                    return collect($provider->aliases)->map(fn ($alias) => [
                        'pattern' => strtolower($alias),
                        'name' => $provider->company_name,
                        'category' => $provider->category,
                        'essential' => $provider->is_essential,
                        'provider_id' => $provider->id,
                    ]);
                });
        }

        return $this->providerAliasCache;
    }

    /**
     * Look up a merchant in our provider database.
     * Uses case-insensitive pattern matching against all aliases.
     * Prefers longer (more specific) alias matches.
     */
    protected function lookupKnownSubscription(string $merchant): ?array
    {
        $lower = strtolower(trim($merchant));
        $aliases = $this->loadProviderAliases();

        $bestMatch = null;
        $bestLength = 0;

        foreach ($aliases as $entry) {
            $pattern = $entry['pattern']; // already lowercase from loadProviderAliases

            // Check if the normalized merchant name contains this alias pattern
            if (str_contains($lower, $pattern)) {
                // Prefer longer alias matches to avoid false positives
                // e.g., "DISCORD NITRO" should match "discord nitro" over "discord"
                $matchLen = strlen($pattern);
                if ($matchLen > $bestLength) {
                    $bestMatch = $entry;
                    $bestLength = $matchLen;
                }
            }
        }

        return $bestMatch;
    }
}
