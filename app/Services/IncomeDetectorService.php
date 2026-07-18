<?php

namespace App\Services;

use App\Models\Transaction;
use App\Services\Support\IncomeTypeClassifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IncomeDetectorService
{
    private IncomeTypeClassifier $classifier;

    public function __construct()
    {
        $this->classifier = new IncomeTypeClassifier(distinguishDividends: false);
    }

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
        // Employment with a known recurring frequency is primary (payroll)
        // Even if CV is slightly high due to bonuses/adjustments, payroll is primary
        if ($type === 'employment' && in_array($frequency, ['weekly', 'bi-weekly', 'monthly'])) {
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
        return $this->classifier->classify($tx);
    }

    /**
     * Normalize merchant name for grouping income sources.
     */
    protected function normalizeMerchant(?string $name, string $type): string
    {
        return $this->classifier->normalizeMerchant($name, $type);
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
        $rawMedian = $sortedIntervals[(int) floor(count($sortedIntervals) / 2)];

        // Filter out outlier intervals (same-day deposits, mid-cycle bonuses, etc.)
        // Keep only intervals within 40%-220% of the raw median
        $filtered = $rawMedian > 0
            ? array_values(array_filter($intervals, fn ($i) => $i >= ($rawMedian * 0.4) && $i <= ($rawMedian * 2.2)))
            : $intervals;
        if (empty($filtered)) {
            $filtered = $intervals;
        }

        sort($filtered);
        $medianInterval = $filtered[(int) floor(count($filtered) / 2)];

        // Check regularity via coefficient of variation on filtered intervals
        $avgInterval = array_sum($filtered) / count($filtered);
        $isRegular = false;
        if (count($filtered) >= 2 && $avgInterval > 0) {
            $variance = array_sum(array_map(
                fn ($i) => ($i - $avgInterval) ** 2, $filtered
            )) / count($filtered);
            $cv = sqrt($variance) / $avgInterval;
            $isRegular = $cv < 0.30;
        } elseif (count($filtered) === 1 || count($intervals) === 1) {
            // Single interval — regular if it matches a known cycle
            $isRegular = $medianInterval >= 5 && $medianInterval <= 35;
        }

        // Employment type with recurring frequency is almost certainly regular payroll
        // even if CV is slightly high (bonuses, adjustments, etc.)
        // This is handled by the caller via autoClassify

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
