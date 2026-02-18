<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IncomeDetectorService
{
    /**
     * Plaid detailed category → income type mapping.
     */
    protected array $plaidTypeMap = [
        'INCOME_WAGES' => 'employment',
        'INCOME_SALARY' => 'employment',
        'INCOME_DIVIDENDS' => 'interest',
        'INCOME_INTEREST_EARNED' => 'interest',
        'INCOME_RETIREMENT_PENSION' => 'employment',
        'INCOME_TAX_REFUND' => 'other',
        'INCOME_UNEMPLOYMENT' => 'employment',
        'INCOME_OTHER_INCOME' => 'other',
        'TRANSFER_IN_ACCOUNT_TRANSFER' => 'transfer',
        'TRANSFER_IN_CASH_ADVANCES_AND_LOANS' => 'other',
        'TRANSFER_IN_DEPOSIT' => 'other',
        'TRANSFER_IN_INVESTMENT_AND_RETIREMENT_FUNDS' => 'other',
        'TRANSFER_IN_SAVINGS' => 'transfer',
        'TRANSFER_IN_TRANSFER_IN_FROM_APPS' => 'transfer',
    ];

    /**
     * Plaid primary category → income type fallback.
     */
    protected array $plaidPrimaryMap = [
        'INCOME' => 'employment',
        'TRANSFER_IN' => 'transfer',
    ];

    /**
     * AI category → income type mapping.
     */
    protected array $aiTypeMap = [
        'Salary & Wages' => 'employment',
        'Payroll' => 'employment',
        'Direct Deposit' => 'employment',
        'Contractor Income' => 'contractor',
        'Freelance Income' => 'contractor',
        'Interest Income' => 'interest',
        'Dividends' => 'interest',
        'Investment Income' => 'interest',
        'Rental Income' => 'other',
        'Refund' => 'other',
        'Tax Refund' => 'other',
    ];

    /**
     * Analyze income sources for a user.
     *
     * @param  array<string, array<string, string>>  $overrides  User classification overrides
     * @return array{sources: array, reliable_monthly: float, total_monthly_avg: float, primary_monthly: float, extra_monthly: float, months_analyzed: int}
     */
    public function analyze(int $userId, string $viewMode = 'all', int $monthsBack = 3, array $overrides = []): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subMonths($monthsBack)->startOfMonth();
        $monthsElapsed = max((int) $since->diffInMonths($now->copy()->startOfMonth()), 1);

        // Fetch all income transactions (negative amounts = money in)
        $query = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0)
            ->where('transaction_date', '>=', $since)
            ->when($viewMode === 'personal', fn ($q) => $q->where('account_purpose', 'personal'))
            ->when($viewMode === 'business', fn ($q) => $q->where('account_purpose', 'business'));

        $transactions = $query->select(
            'id', 'merchant_name', 'amount', 'transaction_date',
            'plaid_category', 'plaid_detailed_category',
            DB::raw('COALESCE(user_category, ai_category) as resolved_category')
        )->orderBy('transaction_date')->get();

        if ($transactions->isEmpty()) {
            return [
                'sources' => [],
                'reliable_monthly' => 0,
                'total_monthly_avg' => 0,
                'primary_monthly' => 0,
                'extra_monthly' => 0,
                'months_analyzed' => $monthsElapsed,
            ];
        }

        // Classify each transaction
        $classified = $transactions->map(function ($tx) {
            $type = $this->classifyType($tx);
            $normalized = $this->normalizeMerchant($tx->merchant_name, $type);

            return [
                'id' => $tx->id,
                'merchant_name' => $tx->merchant_name,
                'normalized' => $normalized,
                'amount' => abs((float) $tx->amount),
                'date' => $tx->transaction_date,
                'type' => $type,
            ];
        });

        // Group by (type, normalized merchant)
        $groups = $classified->groupBy(fn ($item) => $item['type'].'|'.$item['normalized']);

        $incomeOverrides = $overrides['income_source'] ?? [];

        $sources = [];
        foreach ($groups as $key => $group) {
            [$type, $label] = explode('|', $key, 2);

            $amounts = $group->pluck('amount')->toArray();
            $total = array_sum($amounts);
            $avgAmount = $total / count($amounts);
            $occurrences = count($amounts);

            // Run interval analysis for groups with 2+ charges
            $frequency = null;
            $isRegular = false;
            if ($occurrences >= 2) {
                $intervalResult = $this->analyzeInterval($group->pluck('date')->toArray());
                $frequency = $intervalResult['frequency'];
                $isRegular = $intervalResult['is_regular'];
            }

            // Calculate monthly equivalent
            $monthlyEquivalent = $this->calculateMonthlyEquivalent(
                $total, $occurrences, $frequency, $monthsElapsed
            );

            // Auto-classify: primary if regular employment or regular recurring contractor
            $overrideKey = $type.'|'.$label;
            if (isset($incomeOverrides[$overrideKey])) {
                $classification = $incomeOverrides[$overrideKey];
            } else {
                $classification = $this->autoClassify($type, $isRegular, $frequency);
            }

            $sources[] = [
                'type' => $type,
                'label' => $label,
                'merchant_name' => $group->first()['merchant_name'],
                'avg_amount' => round($avgAmount, 2),
                'monthly_equivalent' => round($monthlyEquivalent, 2),
                'frequency' => $frequency,
                'is_regular' => $isRegular,
                'occurrences' => $occurrences,
                'classification' => $classification,
            ];
        }

        // Sort by monthly equivalent descending
        usort($sources, fn ($a, $b) => $b['monthly_equivalent'] <=> $a['monthly_equivalent']);

        // Reliable monthly = only regular employment income
        $reliableMonthly = collect($sources)
            ->filter(fn ($s) => $s['type'] === 'employment' && $s['is_regular'])
            ->sum('monthly_equivalent');

        // Total monthly avg excludes transfers (Zelle, account transfers)
        $totalMonthlyAvg = collect($sources)
            ->filter(fn ($s) => $s['type'] !== 'transfer')
            ->sum('monthly_equivalent');

        // Primary vs extra (excludes transfers from both)
        $nonTransferSources = collect($sources)->filter(fn ($s) => $s['type'] !== 'transfer');
        $primaryMonthly = $nonTransferSources
            ->filter(fn ($s) => $s['classification'] === 'primary')
            ->sum('monthly_equivalent');
        $extraMonthly = $nonTransferSources
            ->filter(fn ($s) => $s['classification'] === 'extra')
            ->sum('monthly_equivalent');

        return [
            'sources' => $sources,
            'reliable_monthly' => round($reliableMonthly, 2),
            'total_monthly_avg' => round($totalMonthlyAvg, 2),
            'primary_monthly' => round($primaryMonthly, 2),
            'extra_monthly' => round($extraMonthly, 2),
            'months_analyzed' => $monthsElapsed,
        ];
    }

    /**
     * Auto-classify an income source as primary or extra.
     */
    protected function autoClassify(string $type, bool $isRegular, ?string $frequency): string
    {
        // Regular employment is always primary
        if ($type === 'employment' && $isRegular) {
            return 'primary';
        }

        // Regular contractor income with a recurring frequency is primary
        if ($type === 'contractor' && $isRegular && in_array($frequency, ['weekly', 'bi-weekly', 'monthly'])) {
            return 'primary';
        }

        // Everything else (irregular, one-off, interest, transfers) is extra
        return 'extra';
    }

    /**
     * Classify a transaction's income type.
     */
    protected function classifyType(object $tx): string
    {
        // 1. Plaid detailed category (most specific)
        if ($tx->plaid_detailed_category && isset($this->plaidTypeMap[$tx->plaid_detailed_category])) {
            return $this->plaidTypeMap[$tx->plaid_detailed_category];
        }

        // 2. Plaid primary category
        if ($tx->plaid_category && isset($this->plaidPrimaryMap[$tx->plaid_category])) {
            return $this->plaidPrimaryMap[$tx->plaid_category];
        }

        // 3. AI/user category
        $resolved = $tx->resolved_category ?? null;
        if ($resolved && isset($this->aiTypeMap[$resolved])) {
            return $this->aiTypeMap[$resolved];
        }

        // 4. Merchant name heuristics
        $merchant = strtoupper($tx->merchant_name ?? '');
        if (str_contains($merchant, 'PAYROLL') || str_contains($merchant, 'DIRECT DEP')
            || str_contains($merchant, 'SALARY') || str_contains($merchant, 'PAYCHECK')) {
            return 'employment';
        }
        if (str_contains($merchant, 'ZELLE') || str_contains($merchant, 'VENMO')
            || str_contains($merchant, 'CASHAPP') || str_contains($merchant, 'CASH APP')) {
            return 'transfer';
        }
        if (str_contains($merchant, 'INTEREST')) {
            return 'interest';
        }

        return 'other';
    }

    /**
     * Normalize merchant name for grouping income sources.
     */
    protected function normalizeMerchant(?string $name, string $type): string
    {
        if (! $name) {
            return match ($type) {
                'employment' => 'Employment Income',
                'contractor' => 'Contractor Income',
                'interest' => 'Interest Income',
                'transfer' => 'Peer Transfers',
                default => 'Other Income',
            };
        }

        $upper = strtoupper(trim($name));

        // Group all Zelle transfers together
        if (str_contains($upper, 'ZELLE')) {
            return 'Peer Transfers (Zelle)';
        }
        if (str_contains($upper, 'VENMO')) {
            return 'Peer Transfers (Venmo)';
        }
        if (str_contains($upper, 'CASHAPP') || str_contains($upper, 'CASH APP')) {
            return 'Peer Transfers (Cash App)';
        }

        // Clean up merchant name
        $clean = preg_replace('/[#*]+\d*\s*$/', '', $upper);
        $clean = preg_replace('/\s+\d{3,}$/', '', $clean);
        $clean = preg_replace('/\s+(DIRECT|DIR)\s*(DEP|DEPOSIT).*$/i', '', $clean);
        $clean = preg_replace('/\s+PAYROLL.*$/i', '', $clean);

        return trim($clean) ?: $name;
    }

    /**
     * Analyze intervals between income dates to detect frequency.
     *
     * @return array{frequency: string|null, is_regular: bool}
     */
    protected function analyzeInterval(array $dates): array
    {
        $sorted = collect($dates)->map(fn ($d) => Carbon::parse($d))->sort()->values();

        $intervals = [];
        for ($i = 1; $i < $sorted->count(); $i++) {
            $intervals[] = (int) $sorted[$i - 1]->diffInDays($sorted[$i]);
        }

        if (empty($intervals)) {
            return ['frequency' => null, 'is_regular' => false];
        }

        // Median interval (more robust than average)
        $sortedIntervals = $intervals;
        sort($sortedIntervals);
        $medianInterval = $sortedIntervals[(int) floor(count($sortedIntervals) / 2)];

        // Check regularity via coefficient of variation
        $avgInterval = array_sum($intervals) / count($intervals);
        $isRegular = false;
        if (count($intervals) >= 2 && $avgInterval > 0) {
            $variance = array_sum(array_map(
                fn ($i) => ($i - $avgInterval) ** 2, $intervals
            )) / count($intervals);
            $cv = sqrt($variance) / $avgInterval;
            $isRegular = $cv < 0.25;
        } elseif (count($intervals) === 1) {
            // Single interval — regular if it matches a known cycle
            $isRegular = $medianInterval >= 5 && $medianInterval <= 35;
        }

        // Determine frequency from median interval (includes bi-weekly)
        $frequency = match (true) {
            $medianInterval <= 10 => 'weekly',
            $medianInterval >= 11 && $medianInterval <= 17 => 'bi-weekly',
            $medianInterval >= 18 && $medianInterval <= 35 => 'monthly',
            $medianInterval >= 80 && $medianInterval <= 100 => 'quarterly',
            $medianInterval >= 340 && $medianInterval <= 380 => 'annual',
            default => 'irregular',
        };

        return [
            'frequency' => $frequency,
            'is_regular' => $isRegular,
        ];
    }

    /**
     * Calculate monthly equivalent for an income source.
     */
    protected function calculateMonthlyEquivalent(
        float $total,
        int $occurrences,
        ?string $frequency,
        int $monthsElapsed
    ): float {
        return match ($frequency) {
            'weekly' => ($total / $occurrences) * 4.33,
            'bi-weekly' => ($total / $occurrences) * 2.17,
            'monthly' => $total / $occurrences,
            'quarterly' => ($total / $occurrences) / 3,
            'annual' => ($total / $occurrences) / 12,
            default => $total / $monthsElapsed, // irregular: spread over analyzed period
        };
    }
}
