<?php

namespace App\Services;

use App\Models\SavingsLedger;
use App\Models\SavingsRecommendation;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class SavingsTrackingService
{
    /**
     * Record a savings entry in the ledger, keyed on user + source + month.
     */
    public function recordSavings(
        int $userId,
        string $sourceType,
        int $sourceId,
        string $actionTaken,
        float $monthlySavings,
        ?float $previousAmount = null,
        ?float $newAmount = null,
        ?string $notes = null,
    ): SavingsLedger {
        return SavingsLedger::updateOrCreate(
            [
                'user_id' => $userId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'month' => now()->format('Y-m'),
            ],
            [
                'action_taken' => $actionTaken,
                'monthly_savings' => $monthlySavings,
                'previous_amount' => $previousAmount,
                'new_amount' => $newAmount,
                'notes' => $notes,
            ],
        );
    }

    /**
     * Get projected monthly and annual savings from all responded actions.
     *
     * @return array{projected_monthly_savings: float, projected_annual_savings: float, breakdown: array, verification: array}
     */
    public function getProjectedSavings(int $userId): array
    {
        $fromRecommendations = (float) SavingsRecommendation::where('user_id', $userId)
            ->where('status', 'applied')
            ->whereNotNull('actual_monthly_savings')
            ->sum('actual_monthly_savings');

        $fromCancelledSubs = (float) Subscription::where('user_id', $userId)
            ->where('response_type', 'cancelled')
            ->whereNotNull('previous_amount')
            ->sum('previous_amount');

        $fromReducedSubs = (float) (Subscription::where('user_id', $userId)
            ->where('response_type', 'reduced')
            ->whereNotNull('previous_amount')
            ->selectRaw('SUM(previous_amount - amount) as total')
            ->value('total') ?? 0);

        $monthly = round($fromRecommendations + $fromCancelledSubs + $fromReducedSubs, 2);

        // Verification stats
        $totalActions = SavingsRecommendation::where('user_id', $userId)
            ->whereNotNull('response_type')
            ->count()
            + Subscription::where('user_id', $userId)
                ->whereNotNull('response_type')
                ->count();

        $verifiedActions = SavingsRecommendation::where('user_id', $userId)
            ->whereIn('response_type', ['cancelled', 'reduced'])
            ->count()
            + Subscription::where('user_id', $userId)
                ->whereIn('response_type', ['cancelled', 'reduced'])
                ->count();

        $verifiedSavings = round($fromRecommendations + $fromCancelledSubs + $fromReducedSubs, 2);

        return [
            'projected_monthly_savings' => $monthly,
            'projected_annual_savings' => round($monthly * 12, 2),
            'breakdown' => [
                'recommendations' => round($fromRecommendations, 2),
                'cancelled_subscriptions' => round($fromCancelledSubs, 2),
                'reduced_subscriptions' => round($fromReducedSubs, 2),
            ],
            'verification' => [
                'total_actions' => $totalActions,
                'verified' => $verifiedActions,
                'pending_verification' => max($totalActions - $verifiedActions, 0),
                'verified_savings' => $verifiedSavings,
            ],
        ];
    }

    /**
     * Get savings history grouped by month, with per-source-type breakdowns.
     *
     * @return array<int, array{month: string, total_savings: float, actions_count: int, verified_savings: float, subscription_savings: float, recommendation_savings: float}>
     */
    public function getSavingsHistory(int $userId, int $months = 6): array
    {
        return SavingsLedger::where('user_id', $userId)
            ->where('month', '>=', now()->subMonths($months)->format('Y-m'))
            ->select(
                'month',
                DB::raw('SUM(monthly_savings) as total_savings'),
                DB::raw('COUNT(*) as actions_count'),
                DB::raw('SUM(monthly_savings) as verified_savings'),
                DB::raw("SUM(CASE WHEN source_type = 'subscription' THEN monthly_savings ELSE 0 END) as subscription_savings"),
                DB::raw("SUM(CASE WHEN source_type = 'recommendation' THEN monthly_savings ELSE 0 END) as recommendation_savings"),
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'total_savings' => round((float) $row->total_savings, 2),
                'actions_count' => (int) $row->actions_count,
                'verified_savings' => round((float) $row->verified_savings, 2),
                'subscription_savings' => round((float) $row->subscription_savings, 2),
                'recommendation_savings' => round((float) $row->recommendation_savings, 2),
            ])
            ->toArray();
    }
}
