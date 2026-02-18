<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionDetectorService
{
    /**
     * Known subscription merchant patterns.
     * Maps bank statement names to clean names + categories.
     */
    protected array $knownSubscriptions = [
        'NETFLIX' => ['name' => 'Netflix', 'category' => 'Streaming', 'essential' => false],
        'SPOTIFY' => ['name' => 'Spotify', 'category' => 'Music', 'essential' => false],
        'HULU' => ['name' => 'Hulu', 'category' => 'Streaming', 'essential' => false],
        'DISNEY PLUS' => ['name' => 'Disney+', 'category' => 'Streaming', 'essential' => false],
        'APPLE.COM/BILL' => ['name' => 'Apple Services', 'category' => 'Software', 'essential' => false],
        'AMAZON PRIME' => ['name' => 'Amazon Prime', 'category' => 'Shopping', 'essential' => false],
        'YOUTUBE PREMIUM' => ['name' => 'YouTube Premium', 'category' => 'Streaming', 'essential' => false],
        'ADOBE' => ['name' => 'Adobe Creative Cloud', 'category' => 'Software', 'essential' => false],
        'OPENAI' => ['name' => 'ChatGPT Plus', 'category' => 'Software', 'essential' => false],
        'MICROSOFT' => ['name' => 'Microsoft 365', 'category' => 'Software', 'essential' => false],
        'PARAMOUNT' => ['name' => 'Paramount+', 'category' => 'Streaming', 'essential' => false],
        'HBO MAX' => ['name' => 'Max', 'category' => 'Streaming', 'essential' => false],
        'PEACOCK' => ['name' => 'Peacock', 'category' => 'Streaming', 'essential' => false],
        'CRUNCHYROLL' => ['name' => 'Crunchyroll', 'category' => 'Streaming', 'essential' => false],
        'HEADSPACE' => ['name' => 'Headspace', 'category' => 'Health', 'essential' => false],
        'PLANET FITNESS' => ['name' => 'Planet Fitness', 'category' => 'Fitness', 'essential' => false],
        'DROPBOX' => ['name' => 'Dropbox', 'category' => 'Storage', 'essential' => false],
        'ICLOUD' => ['name' => 'iCloud+', 'category' => 'Storage', 'essential' => false],
        'GEICO' => ['name' => 'GEICO', 'category' => 'Insurance', 'essential' => true],
        'STATE FARM' => ['name' => 'State Farm', 'category' => 'Insurance', 'essential' => true],
        'PROGRESSIVE' => ['name' => 'Progressive', 'category' => 'Insurance', 'essential' => true],
        'AT&T' => ['name' => 'AT&T', 'category' => 'Phone', 'essential' => true],
        'T-MOBILE' => ['name' => 'T-Mobile', 'category' => 'Phone', 'essential' => true],
        'VERIZON' => ['name' => 'Verizon', 'category' => 'Phone', 'essential' => true],
        'XFINITY' => ['name' => 'Xfinity/Comcast', 'category' => 'Internet', 'essential' => true],
        'SPECTRUM' => ['name' => 'Spectrum', 'category' => 'Internet', 'essential' => true],
        'APS ' => ['name' => 'APS Electric', 'category' => 'Utilities', 'essential' => true],
        'SRP ' => ['name' => 'SRP Power', 'category' => 'Utilities', 'essential' => true],
        'REPUBLIC SVCS' => ['name' => 'Republic Services', 'category' => 'Trash', 'essential' => true],
        'WASTE MGMT' => ['name' => 'Waste Management', 'category' => 'Trash', 'essential' => true],
    ];

    /**
     * Plaid categories that are never subscriptions.
     */
    protected array $excludedCategories = [
        'TRANSFER_IN', 'TRANSFER_OUT', 'BANK_FEES', 'LOAN_PAYMENTS',
        'ATM', 'INCOME', 'RENT', 'MORTGAGE',
        'FOOD_AND_DRINK', 'GENERAL_MERCHANDISE', 'GENERAL_SERVICES',
        'TRANSPORTATION', 'MEDICAL', 'PERSONAL_CARE', 'GOVERNMENT_AND_NON_PROFIT',
        'HOME_IMPROVEMENT', 'TRAVEL',
    ];

    /**
     * Payment channels that indicate in-person purchases (not subscriptions).
     */
    protected array $excludedChannels = ['in store', 'in_store'];

    /**
     * Scan all transactions and detect recurring subscriptions.
     */
    public function detectSubscriptions(int $userId): array
    {
        $since = Carbon::now()->subMonths(6);

        $transactions = Transaction::where('user_id', $userId)
            ->where('transaction_date', '>=', $since)
            ->where('amount', '>', 0)
            ->select(['id', 'user_id', 'merchant_name', 'merchant_normalized', 'amount', 'transaction_date', 'ai_category', 'plaid_category', 'payment_channel'])
            ->orderBy('transaction_date')
            ->get();

        // Group by normalized merchant
        $merchantGroups = $transactions->groupBy(function ($tx) {
            return $this->normalizeMerchant($tx->merchant_name);
        });

        $detected = [];
        $aiCandidates = [];

        foreach ($merchantGroups as $merchant => $txGroup) {
            // Check if this is a known subscription (bypasses category/channel filters)
            $known = $this->lookupKnownSubscription($merchant);

            if (! $known) {
                // Skip in-store purchases — subscriptions are billed online
                $primaryChannel = $txGroup->first()->payment_channel;
                if ($primaryChannel && in_array(strtolower($primaryChannel), $this->excludedChannels)) {
                    continue;
                }

                // Skip excluded transaction categories (food, retail, medical, etc.)
                $primaryCategory = $txGroup->first()->plaid_category;
                if ($primaryCategory && in_array($primaryCategory, $this->excludedCategories)) {
                    continue;
                }
            }

            // Known essential bills (insurance, utilities, phone) have varying amounts.
            // Analyze them as a whole group with relaxed amount tolerance.
            if ($known && ($known['essential'] ?? false) && $txGroup->count() >= 3) {
                $recurring = $this->analyzeRecurrence($txGroup, tolerant: true);
                if ($recurring) {
                    $detected[] = $this->createOrUpdateSubscription(
                        $userId, $merchant, $txGroup, $recurring
                    );
                }

                continue;
            }

            // Sub-group by exact amount to split mixed charges (e.g. two Microsoft plans)
            $amountGroups = $txGroup->groupBy(fn ($tx) => number_format((float) $tx->amount, 2, '.', ''));

            foreach ($amountGroups as $subGroup) {
                // Require minimum 3 charges at this exact amount
                if ($subGroup->count() < 3) {
                    continue;
                }

                $recurring = $this->analyzeRecurrence($subGroup);
                if (! $recurring) {
                    continue;
                }

                if ($known) {
                    $detected[] = $this->createOrUpdateSubscription(
                        $userId, $merchant, $subGroup, $recurring
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
                        $candidate['recurring']
                    );
                }
            }
        }

        // Mark subscriptions as unused if charges have stopped
        $this->detectUnusedSubscriptions($userId);

        return [
            'detected' => count($detected),
            'total_monthly' => collect($detected)->sum('amount'),
        ];
    }

    /**
     * Analyze a group of transactions to determine if they're recurring.
     * Requires: exact amount match + regular intervals.
     */
    protected function analyzeRecurrence(Collection $transactions, bool $tolerant = false): ?array
    {
        $sorted = $transactions->sortBy('transaction_date')->values();
        $amounts = $sorted->pluck('amount')->map(fn ($a) => (float) $a)->toArray();
        $dates = $sorted->pluck('transaction_date')->toArray();

        // --- Amount consistency ---
        if ($tolerant) {
            // Essential bills (utilities, insurance, phone) have varying amounts.
            // Use 15% tolerance for these.
            $avgAmount = array_sum($amounts) / count($amounts);
            $amountConsistent = collect($amounts)->every(
                fn ($a) => abs($a - $avgAmount) / max($avgAmount, 0.01) < 0.15
            );
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
        // Allow up to 25% coefficient of variation (std dev / mean)
        if (count($intervals) >= 2) {
            $variance = array_sum(array_map(
                fn ($i) => ($i - $avgInterval) ** 2, $intervals
            )) / count($intervals);
            $stdDev = sqrt($variance);
            $cv = $avgInterval > 0 ? $stdDev / $avgInterval : 1;

            if ($cv > 0.25) {
                return null;
            }
        }

        // Determine frequency from median interval (more robust than average)
        $sortedIntervals = $intervals;
        sort($sortedIntervals);
        $medianInterval = $sortedIntervals[(int) floor(count($sortedIntervals) / 2)];

        $frequency = match (true) {
            $medianInterval <= 10 => 'weekly',
            $medianInterval <= 35 => 'monthly',
            $medianInterval <= 100 => 'quarterly',
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
            // No API key — skip AI validation, reject all unknowns
            return array_fill(0, count($candidates), false);
        }

        $prompt = "You are a financial analyst. For each merchant below, determine if the charges represent a TRUE recurring subscription (like a streaming service, software license, gym membership, insurance, etc.) or NOT a subscription (like repeated shopping at the same store, on-demand API usage charges, repeated one-time purchases, etc.).\n\n";
        $prompt .= "A TRUE subscription means:\n";
        $prompt .= "- The company charges a fixed amount on a regular billing cycle\n";
        $prompt .= "- The user signed up for an ongoing service that bills automatically\n";
        $prompt .= "- Examples: Netflix, Spotify, gym, insurance, phone bill\n\n";
        $prompt .= "NOT a subscription:\n";
        $prompt .= "- Variable usage-based charges (API fees, cloud computing, pay-per-use)\n";
        $prompt .= "- Repeated purchases at the same retailer (grocery store, Amazon orders, gas station)\n";
        $prompt .= "- Multiple one-time purchases that happen to be the same amount\n\n";

        $merchantData = [];
        foreach ($candidates as $idx => $c) {
            $charges = $c['txGroup']->sortBy('transaction_date')->map(fn ($tx) => [
                'date' => $tx->transaction_date->format('Y-m-d'),
                'amount' => (float) $tx->amount,
                'description' => $tx->merchant_name,
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
     */
    protected function createOrUpdateSubscription(
        int $userId,
        string $merchant,
        Collection $transactions,
        array $recurring
    ): Subscription {
        $known = $this->lookupKnownSubscription($merchant);
        $lastTx = $transactions->sortByDesc('transaction_date')->first();

        $annualMultiplier = match ($recurring['frequency']) {
            'weekly' => 52,
            'monthly' => 12,
            'quarterly' => 4,
            'annual' => 1,
        };

        return Subscription::updateOrCreate(
            ['user_id' => $userId, 'merchant_normalized' => $known['name'] ?? $merchant],
            [
                'merchant_name' => $lastTx->merchant_name,
                'amount' => $recurring['avg_amount'],
                'frequency' => $recurring['frequency'],
                'category' => $known['category'] ?? $lastTx->ai_category ?? 'Subscriptions',
                'last_charge_date' => $lastTx->transaction_date,
                'next_expected_date' => $recurring['next_expected'],
                'status' => 'active',
                'months_active' => $recurring['charge_count'],
                'is_essential' => $known['essential'] ?? false,
                'annual_cost' => round($recurring['avg_amount'] * $annualMultiplier, 2),
                'charge_history' => $transactions->map(fn ($t) => [
                    'date' => $t->transaction_date->toDateString(),
                    'amount' => $t->amount,
                ])->values()->toArray(),
            ]
        );
    }

    /**
     * Mark subscriptions as "unused" if they haven't been charged in significantly
     * longer than their expected billing cycle. We compare the last charge date to
     * 2x the subscription frequency (e.g., >60 days for monthly, >180 days for quarterly).
     */
    protected function detectUnusedSubscriptions(int $userId): void
    {
        $now = Carbon::now();

        $subscriptions = Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->where('is_essential', false)
            ->whereNotNull('last_charge_date')
            ->get();

        foreach ($subscriptions as $sub) {
            $maxGapDays = match ($sub->frequency) {
                'weekly' => 21,       // 3x weekly interval
                'monthly' => 60,      // 2x monthly interval
                'quarterly' => 180,   // 2x quarterly interval
                'annual' => 400,      // ~13 months
                default => 60,
            };

            $daysSinceCharge = (int) abs($now->diffInDays(Carbon::parse($sub->last_charge_date)));

            if ($daysSinceCharge > $maxGapDays) {
                $sub->update(['status' => 'unused']);
            }
        }
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
     */
    protected function normalizeMerchant(?string $name): string
    {
        if (! $name) {
            return 'unknown';
        }

        $upper = strtoupper(trim($name));
        // Strip trailing numbers, #, *, locations
        $clean = preg_replace('/[#*]+\d*\s*$/', '', $upper);
        $clean = preg_replace('/\s+\d{3,}$/', '', $clean);

        return trim($clean);
    }

    /**
     * Look up a merchant in known subscriptions.
     */
    protected function lookupKnownSubscription(string $merchant): ?array
    {
        $upper = strtoupper($merchant);
        foreach ($this->knownSubscriptions as $pattern => $info) {
            if (str_contains($upper, $pattern)) {
                return $info;
            }
        }

        return null;
    }
}
