<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Subscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SubscriptionDetectorService
{
    /**
     * Known subscription merchant patterns.
     * Maps bank statement names to clean names + categories.
     */
    protected array $knownSubscriptions = [
        'NETFLIX'         => ['name' => 'Netflix',           'category' => 'Streaming', 'essential' => false],
        'SPOTIFY'         => ['name' => 'Spotify',           'category' => 'Music', 'essential' => false],
        'HULU'            => ['name' => 'Hulu',              'category' => 'Streaming', 'essential' => false],
        'DISNEY PLUS'     => ['name' => 'Disney+',           'category' => 'Streaming', 'essential' => false],
        'APPLE.COM/BILL'  => ['name' => 'Apple Services',    'category' => 'Software', 'essential' => false],
        'AMAZON PRIME'    => ['name' => 'Amazon Prime',      'category' => 'Shopping', 'essential' => false],
        'YOUTUBE PREMIUM' => ['name' => 'YouTube Premium',   'category' => 'Streaming', 'essential' => false],
        'ADOBE'           => ['name' => 'Adobe Creative Cloud', 'category' => 'Software', 'essential' => false],
        'OPENAI'          => ['name' => 'ChatGPT Plus',      'category' => 'Software', 'essential' => false],
        'ANTHROPIC'       => ['name' => 'Claude Pro',        'category' => 'Software', 'essential' => false],
        'MICROSOFT'       => ['name' => 'Microsoft 365',     'category' => 'Software', 'essential' => false],
        'GOOGLE *'        => ['name' => 'Google Services',   'category' => 'Software', 'essential' => false],
        'PARAMOUNT'       => ['name' => 'Paramount+',        'category' => 'Streaming', 'essential' => false],
        'HBO MAX'         => ['name' => 'Max',               'category' => 'Streaming', 'essential' => false],
        'PEACOCK'         => ['name' => 'Peacock',           'category' => 'Streaming', 'essential' => false],
        'CRUNCHYROLL'     => ['name' => 'Crunchyroll',       'category' => 'Streaming', 'essential' => false],
        'HEADSPACE'       => ['name' => 'Headspace',         'category' => 'Health', 'essential' => false],
        'PLANET FITNESS'  => ['name' => 'Planet Fitness',    'category' => 'Fitness', 'essential' => false],
        'DROPBOX'         => ['name' => 'Dropbox',           'category' => 'Storage', 'essential' => false],
        'ICLOUD'          => ['name' => 'iCloud+',           'category' => 'Storage', 'essential' => false],
        'GEICO'           => ['name' => 'GEICO',             'category' => 'Insurance', 'essential' => true],
        'STATE FARM'      => ['name' => 'State Farm',        'category' => 'Insurance', 'essential' => true],
        'PROGRESSIVE'     => ['name' => 'Progressive',       'category' => 'Insurance', 'essential' => true],
        'AT&T'            => ['name' => 'AT&T',              'category' => 'Phone', 'essential' => true],
        'T-MOBILE'        => ['name' => 'T-Mobile',          'category' => 'Phone', 'essential' => true],
        'VERIZON'         => ['name' => 'Verizon',           'category' => 'Phone', 'essential' => true],
        'XFINITY'         => ['name' => 'Xfinity/Comcast',   'category' => 'Internet', 'essential' => true],
        'SPECTRUM'        => ['name' => 'Spectrum',          'category' => 'Internet', 'essential' => true],
        'APS '            => ['name' => 'APS Electric',      'category' => 'Utilities', 'essential' => true],
        'SRP '            => ['name' => 'SRP Power',         'category' => 'Utilities', 'essential' => true],
        'REPUBLIC SVCS'   => ['name' => 'Republic Services', 'category' => 'Trash', 'essential' => true],
        'WASTE MGMT'      => ['name' => 'Waste Management',  'category' => 'Trash', 'essential' => true],
    ];

    /**
     * Scan all transactions and detect recurring subscriptions.
     */
    public function detectSubscriptions(int $userId): array
    {
        $since = Carbon::now()->subMonths(6);

        // Get all transactions grouped by normalized merchant
        $transactions = Transaction::where('user_id', $userId)
            ->where('transaction_date', '>=', $since)
            ->where('amount', '>', 0)
            ->select(['id', 'user_id', 'merchant_name', 'merchant_normalized', 'amount', 'transaction_date', 'ai_category'])
            ->orderBy('transaction_date')
            ->get();

        // Group by merchant and look for recurring patterns
        $merchantGroups = $transactions->groupBy(function ($tx) {
            return $this->normalizeMerchant($tx->merchant_name);
        });

        $detected = [];

        foreach ($merchantGroups as $merchant => $txGroup) {
            if ($txGroup->count() < 2) continue;

            $recurring = $this->analyzeRecurrence($txGroup);
            if (!$recurring) continue;

            $detected[] = $this->createOrUpdateSubscription(
                $userId,
                $merchant,
                $txGroup,
                $recurring
            );
        }

        // Mark subscriptions as unused if no related activity in 30 days
        $this->detectUnusedSubscriptions($userId);

        return [
            'detected'   => count($detected),
            'total_monthly' => collect($detected)->sum('amount'),
        ];
    }

    /**
     * Analyze a group of transactions to determine if they're recurring.
     */
    protected function analyzeRecurrence(Collection $transactions): ?array
    {
        $sorted = $transactions->sortBy('transaction_date')->values();
        $amounts = $sorted->pluck('amount')->toArray();
        $dates = $sorted->pluck('transaction_date')->toArray();

        // Check if amounts are consistent (within 20% tolerance for price changes)
        $avgAmount = array_sum($amounts) / count($amounts);
        $consistent = collect($amounts)->every(fn($a) => abs($a - $avgAmount) / $avgAmount < 0.20);

        if (!$consistent && count($amounts) < 3) return null;

        // Calculate intervals between charges
        $intervals = [];
        for ($i = 1; $i < count($dates); $i++) {
            $intervals[] = Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i]));
        }

        if (empty($intervals)) return null;

        $avgInterval = array_sum($intervals) / count($intervals);

        // Determine frequency
        $frequency = match (true) {
            $avgInterval <= 10  => 'weekly',
            $avgInterval <= 35  => 'monthly',
            $avgInterval <= 100 => 'quarterly',
            $avgInterval <= 380 => 'annual',
            default             => null,
        };

        if (!$frequency) return null;

        // Predict next charge
        $lastDate = Carbon::parse(end($dates));
        $nextDate = match ($frequency) {
            'weekly'    => $lastDate->copy()->addWeek(),
            'monthly'   => $lastDate->copy()->addMonth(),
            'quarterly' => $lastDate->copy()->addMonths(3),
            'annual'    => $lastDate->copy()->addYear(),
        };

        return [
            'frequency'     => $frequency,
            'avg_amount'    => round($avgAmount, 2),
            'next_expected' => $nextDate->toDateString(),
            'charge_count'  => count($amounts),
        ];
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
            'weekly'    => 52,
            'monthly'   => 12,
            'quarterly' => 4,
            'annual'    => 1,
        };

        return Subscription::updateOrCreate(
            ['user_id' => $userId, 'merchant_normalized' => $known['name'] ?? $merchant],
            [
                'merchant_name'      => $lastTx->merchant_name,
                'amount'             => $recurring['avg_amount'],
                'frequency'          => $recurring['frequency'],
                'category'           => $known['category'] ?? $lastTx->ai_category ?? 'Subscriptions',
                'last_charge_date'   => $lastTx->transaction_date,
                'next_expected_date' => $recurring['next_expected'],
                'status'             => 'active',
                'months_active'      => $recurring['charge_count'],
                'is_essential'       => $known['essential'] ?? false,
                'annual_cost'        => round($recurring['avg_amount'] * $annualMultiplier, 2),
                'charge_history'     => $transactions->map(fn($t) => [
                    'date'   => $t->transaction_date->toDateString(),
                    'amount' => $t->amount,
                ])->values()->toArray(),
            ]
        );
    }

    /**
     * Mark subscriptions as "unused" if the associated service hasn't been
     * accessed or charged in 30+ days AND isn't essential.
     */
    protected function detectUnusedSubscriptions(int $userId): void
    {
        $threshold = Carbon::now()->subDays(30);

        Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->where('is_essential', false)
            ->where(function ($q) use ($threshold) {
                $q->where('last_charge_date', '<', $threshold)
                  ->orWhereNull('last_used_at')
                  ->orWhere('last_used_at', '<', $threshold);
            })
            ->update(['status' => 'unused']);
    }

    /**
     * Normalize a merchant name for grouping.
     */
    protected function normalizeMerchant(?string $name): string
    {
        if (!$name) return 'unknown';

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
