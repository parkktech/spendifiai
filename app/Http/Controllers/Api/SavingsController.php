<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RespondToPlanActionRequest;
use App\Http\Requests\SetSavingsTargetRequest;
use App\Http\Resources\SavingsPlanActionResource;
use App\Http\Resources\SavingsRecommendationResource;
use App\Http\Resources\SavingsTargetResource;
use App\Models\SavingsPlanAction;
use App\Models\SavingsProgress;
use App\Models\SavingsRecommendation;
use App\Models\SavingsTarget;
use App\Models\Transaction;
use App\Services\AI\SavingsAnalyzerService;
use App\Services\AI\SavingsTargetPlannerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SavingsController extends Controller
{
    public function __construct(
        private readonly SavingsAnalyzerService $analyzer,
        private readonly SavingsTargetPlannerService $planner,
    ) {}

    /**
     * List active savings recommendations with summary.
     */
    public function recommendations(): JsonResponse
    {
        $recs = SavingsRecommendation::where('user_id', auth()->id())
            ->where('status', 'active')
            ->orderByDesc('annual_savings')
            ->get();

        return response()->json([
            'recommendations'   => SavingsRecommendationResource::collection($recs),
            'total_monthly'     => $recs->sum('monthly_savings'),
            'total_annual'      => $recs->sum('annual_savings'),
            'easy_wins_monthly' => $recs->where('difficulty', 'easy')->sum('monthly_savings'),
        ]);
    }

    /**
     * Generate a fresh savings analysis using AI.
     */
    public function analyze(): JsonResponse
    {
        $result = $this->analyzer->analyze(auth()->user());

        return response()->json($result);
    }

    /**
     * Dismiss a savings recommendation.
     */
    public function dismiss(SavingsRecommendation $rec): JsonResponse
    {
        $this->authorize('update', $rec);
        $rec->update(['status' => 'dismissed', 'dismissed_at' => now()]);

        return response()->json(['message' => 'Dismissed']);
    }

    /**
     * Mark a savings recommendation as applied.
     */
    public function apply(SavingsRecommendation $rec): JsonResponse
    {
        $this->authorize('update', $rec);
        $rec->update(['status' => 'applied', 'applied_at' => now()]);

        return response()->json(['message' => 'Marked as applied']);
    }

    /**
     * Set or update the user's monthly savings target.
     */
    public function setTarget(SetSavingsTargetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Deactivate any existing target
        SavingsTarget::where('user_id', auth()->id())
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $target = SavingsTarget::create([
            'user_id'           => auth()->id(),
            'monthly_target'    => $validated['monthly_target'],
            'motivation'        => $validated['motivation'] ?? null,
            'goal_total'        => $validated['goal_total'] ?? null,
            'target_start_date' => now()->startOfMonth(),
            'is_active'         => true,
        ]);

        // Immediately generate an action plan
        $plan = $this->planner->generatePlan(auth()->user(), $target);

        return response()->json([
            'target' => new SavingsTargetResource($target),
            'plan'   => $plan,
        ]);
    }

    /**
     * Get the current active savings target, plan, and progress.
     */
    public function getTarget(): JsonResponse
    {
        $target = SavingsTarget::where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (!$target) {
            return response()->json([
                'has_target' => false,
                'message'    => 'No savings target set. Set one to get a personalized plan.',
            ]);
        }

        // Get plan actions grouped by status
        $actions = SavingsPlanAction::where('savings_target_id', $target->id)
            ->orderBy('priority')
            ->get();

        $accepted  = $actions->where('status', 'accepted');
        $suggested = $actions->where('status', 'suggested');

        // Get progress history
        $progress = SavingsProgress::where('savings_target_id', $target->id)
            ->orderBy('month')
            ->get();

        // Current month progress
        $currentMonth = $this->planner->calculateProgress(auth()->user(), $target);

        // Time to goal calculation
        $timeToGoal = null;
        if ($target->goal_total && $currentMonth->cumulative_saved > 0) {
            $avgMonthlySavings = $currentMonth->cumulative_saved
                / max($progress->count(), 1);
            $remaining = $target->goal_total - $currentMonth->cumulative_saved;
            if ($avgMonthlySavings > 0) {
                $timeToGoal = [
                    'months_remaining' => (int) ceil($remaining / $avgMonthlySavings),
                    'projected_date'   => now()->addMonths((int) ceil($remaining / $avgMonthlySavings))->format('M Y'),
                    'on_pace'          => $avgMonthlySavings >= $target->monthly_target,
                    'pct_complete'     => round(($currentMonth->cumulative_saved / $target->goal_total) * 100, 1),
                ];
            }
        }

        return response()->json([
            'has_target'       => true,
            'target'           => new SavingsTargetResource($target),
            'current_month'    => $currentMonth,
            'progress_history' => $progress,
            'time_to_goal'     => $timeToGoal,
            'plan'             => [
                'accepted_actions'        => SavingsPlanActionResource::collection($accepted->values()),
                'suggested_actions'       => SavingsPlanActionResource::collection($suggested->values()),
                'accepted_total_savings'  => round($accepted->sum('monthly_savings'), 2),
                'suggested_total_savings' => round($suggested->sum('monthly_savings'), 2),
                'full_plan_savings'       => round($actions->whereIn('status', ['accepted', 'suggested'])->sum('monthly_savings'), 2),
            ],
        ]);
    }

    /**
     * Regenerate the action plan (e.g., after spending changes or target update).
     */
    public function regeneratePlan(): JsonResponse
    {
        $target = SavingsTarget::where('user_id', auth()->id())
            ->where('is_active', true)
            ->firstOrFail();

        $plan = $this->planner->generatePlan(auth()->user(), $target);

        return response()->json($plan);
    }

    /**
     * Accept or reject a plan action.
     */
    public function respondToAction(RespondToPlanActionRequest $request, SavingsPlanAction $action): JsonResponse
    {
        $this->authorize('update', $action);

        if ($request->validated('response') === 'accept') {
            $action->update([
                'status'      => 'accepted',
                'accepted_at' => now(),
            ]);
        } else {
            $action->update([
                'status'           => 'rejected',
                'rejected_at'      => now(),
                'rejection_reason' => $request->validated('rejection_reason'),
            ]);
        }

        // Recalculate whether accepted actions cover the gap
        $target = $action->savingsTarget;
        $acceptedTotal = SavingsPlanAction::where('savings_target_id', $target->id)
            ->where('status', 'accepted')
            ->sum('monthly_savings');

        return response()->json([
            'action'         => new SavingsPlanActionResource($action->fresh()),
            'accepted_total' => round($acceptedTotal, 2),
            'target'         => $target->monthly_target,
            'gap_remaining'  => round(max($target->monthly_target - $acceptedTotal, 0), 2),
            'covers_target'  => $acceptedTotal >= $target->monthly_target,
        ]);
    }

    /**
     * Get a mid-month pulse check: are they on track this month?
     */
    public function pulseCheck(): JsonResponse
    {
        $target = SavingsTarget::where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (!$target) {
            return response()->json(['has_target' => false]);
        }

        $now          = Carbon::now();
        $daysInMonth  = $now->daysInMonth;
        $dayOfMonth   = $now->day;
        $pctMonthElapsed = round($dayOfMonth / $daysInMonth, 2);

        // Get current spending this month
        $monthStart = $now->copy()->startOfMonth();

        $spending = Transaction::where('user_id', auth()->id())
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $monthStart)
            ->sum('amount');

        $income = Transaction::where('user_id', auth()->id())
            ->where('amount', '<', 0)
            ->where('transaction_date', '>=', $monthStart)
            ->whereNotIn('plaid_category', ['TRANSFER_IN', 'TRANSFER_OUT'])
            ->sum(DB::raw('ABS(amount)'));

        // Spending by category so far
        $categorySpending = Transaction::where('user_id', auth()->id())
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $monthStart)
            ->select(
                DB::raw("COALESCE(user_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();

        // Compare to accepted plan actions
        $acceptedActions = SavingsPlanAction::where('savings_target_id', $target->id)
            ->where('status', 'accepted')
            ->get();

        $warnings = [];
        foreach ($acceptedActions as $action) {
            $spent  = $categorySpending[$action->category] ?? 0;
            $budget = $action->recommended_spending;

            // Prorate the budget to how far through the month we are
            $proratedBudget = $budget * $pctMonthElapsed;

            if ($spent > $proratedBudget * 1.1) { // 10% over prorated budget
                $overPct = round((($spent / max($proratedBudget, 1)) - 1) * 100);
                $warnings[] = [
                    'action_id'   => $action->id,
                    'title'       => $action->title,
                    'category'    => $action->category,
                    'spent'       => round($spent, 2),
                    'budget'      => round($budget, 2),
                    'prorated'    => round($proratedBudget, 2),
                    'over_by_pct' => $overPct,
                    'message'     => "{$action->category}: \${$spent} spent ({$overPct}% over pace). "
                                   . "Budget for the full month is \${$budget}.",
                ];
            }
        }

        $currentSavings   = $income - $spending;
        $projectedSavings = ($currentSavings / max($pctMonthElapsed, 0.01));
        $onTrack          = $projectedSavings >= $target->monthly_target;

        return response()->json([
            'day_of_month'          => $dayOfMonth,
            'pct_month_elapsed'     => $pctMonthElapsed,
            'spending_so_far'       => round($spending, 2),
            'income_so_far'         => round($income, 2),
            'savings_so_far'        => round(max($currentSavings, 0), 2),
            'projected_savings'     => round($projectedSavings, 2),
            'target'                => $target->monthly_target,
            'on_track'              => $onTrack,
            'status'                => $onTrack ? 'on_track' : ($warnings ? 'at_risk' : 'behind'),
            'warnings'              => $warnings,
            'daily_budget_remaining' => round(
                max(($income - $spending - $target->monthly_target) / max($daysInMonth - $dayOfMonth, 1), 0),
                2
            ),
        ]);
    }
}
