<?php

namespace App\Services\AI;

use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\SavingsTarget;
use App\Models\SavingsPlanAction;
use App\Models\SavingsProgress;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SavingsTargetPlannerService
{
    protected ?string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        $this->model  = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Generate (or regenerate) a concrete action plan to hit the user's savings target.
     * This is the core method — it figures out WHERE to cut and by HOW MUCH.
     */
    public function generatePlan(User $user, SavingsTarget $target): array
    {
        $since = Carbon::now()->subMonths(3);

        // Pull comprehensive spending data
        $categorySpending  = $this->getCategoryAverages($user->id, $since);
        $subscriptions     = $this->getActiveSubscriptions($user->id);
        $merchantFrequency = $this->getTopMerchants($user->id, $since);
        $incomeEstimate    = $this->estimateMonthlyIncome($user->id, $since);
        $currentSavings    = $incomeEstimate - collect($categorySpending)->sum('monthly_avg');

        $gap = $target->monthly_target - max($currentSavings, 0);

        // If they're already saving more than target, congrats
        if ($gap <= 0) {
            return [
                'status'          => 'on_track',
                'current_savings' => round($currentSavings, 2),
                'target'          => $target->monthly_target,
                'surplus'         => round(abs($gap), 2),
                'message'         => "You're already saving \${$currentSavings}/mo — \$" . abs(round($gap)) . " above your target!",
                'actions'         => [],
            ];
        }

        // Build the AI plan
        $plan = $this->callAIPlanner(
            $target,
            $categorySpending,
            $subscriptions,
            $merchantFrequency,
            $incomeEstimate,
            $currentSavings,
            $gap,
            $user
        );

        if (isset($plan['error'])) {
            return ['error' => $plan['error']];
        }

        // Store the plan actions
        $this->storePlanActions($user->id, $target->id, $plan);

        // Calculate plan summary
        $totalPlanSavings = collect($plan)->sum('monthly_savings');
        $easyWins = collect($plan)->where('difficulty', 'easy')->sum('monthly_savings');

        return [
            'status'             => $gap > $totalPlanSavings ? 'challenging' : 'achievable',
            'current_savings'    => round(max($currentSavings, 0), 2),
            'target'             => $target->monthly_target,
            'gap_to_close'       => round($gap, 2),
            'plan_total_savings' => round($totalPlanSavings, 2),
            'easy_wins'          => round($easyWins, 2),
            'coverage_pct'       => round(min(($totalPlanSavings / $gap) * 100, 100), 1),
            'estimated_income'   => round($incomeEstimate, 2),
            'actions_count'      => count($plan),
            'message'            => $this->buildSummaryMessage($gap, $totalPlanSavings, $easyWins),
        ];
    }

    /**
     * Get average monthly spending by category over the period.
     */
    protected function getCategoryAverages(int $userId, Carbon $since): array
    {
        $months = max(Carbon::now()->diffInMonths($since), 1);

        return Transaction::where('user_id', $userId)
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $since)
            ->select(
                DB::raw("COALESCE(user_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(amount) as total'),
                DB::raw("ROUND(SUM(amount) / {$months}, 2) as monthly_avg"),
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw("ROUND(COUNT(*)::numeric / {$months}, 1) as monthly_frequency"),
                DB::raw('ROUND(AVG(amount), 2) as avg_per_transaction'),
                DB::raw('MAX(amount) as max_single'),
            )
            ->groupBy('category')
            ->orderByDesc('monthly_avg')
            ->get()
            ->toArray();
    }

    /**
     * Get all active subscriptions with cost data.
     */
    protected function getActiveSubscriptions(int $userId): array
    {
        return Subscription::where('user_id', $userId)
            ->whereIn('status', ['active', 'unused'])
            ->orderByDesc('amount')
            ->get()
            ->map(fn($s) => [
                'name'        => $s->merchant_normalized,
                'amount'      => $s->amount,
                'frequency'   => $s->frequency,
                'annual_cost' => $s->annual_cost,
                'category'    => $s->category,
                'is_unused'   => $s->status === 'unused',
                'is_essential' => $s->is_essential,
            ])
            ->toArray();
    }

    /**
     * Get top merchants by total spend.
     */
    protected function getTopMerchants(int $userId, Carbon $since): array
    {
        $months = max(Carbon::now()->diffInMonths($since), 1);

        return Transaction::where('user_id', $userId)
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $since)
            ->select(
                DB::raw("COALESCE(merchant_normalized, merchant_name) as merchant"),
                DB::raw('SUM(amount) as total'),
                DB::raw("ROUND(SUM(amount) / {$months}, 2) as monthly_avg"),
                DB::raw('COUNT(*) as visits'),
                DB::raw('ROUND(AVG(amount), 2) as avg_per_visit'),
            )
            ->groupBy('merchant')
            ->orderByDesc('monthly_avg')
            ->limit(30)
            ->get()
            ->toArray();
    }

    /**
     * Estimate monthly income from deposits/credits.
     */
    protected function estimateMonthlyIncome(int $userId, Carbon $since): float
    {
        $months = max(Carbon::now()->diffInMonths($since), 1);

        $totalIncome = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0) // Plaid: negative = income/credit
            ->where('transaction_date', '>=', $since)
            ->whereNotIn('plaid_category', ['TRANSFER_IN', 'TRANSFER_OUT']) // Exclude transfers
            ->sum(DB::raw('ABS(amount)'));

        // Also check the user's stated income
        $profile = $userId
            ? \App\Models\UserFinancialProfile::where('user_id', $userId)->first()
            : null;

        $estimated = $totalIncome / $months;

        // Use whichever is higher — stated income or detected income
        return max($estimated, $profile?->monthly_income ?? 0);
    }

    /**
     * Call Claude to generate the actual action plan.
     */
    protected function callAIPlanner(
        SavingsTarget $target,
        array $categorySpending,
        array $subscriptions,
        array $merchantFrequency,
        float $income,
        float $currentSavings,
        float $gap,
        User $user,
    ): array {
        $profile = $user->financialProfile;

        $system = <<<'PROMPT'
You are a personal finance advisor building a CONCRETE action plan for a user to hit their
monthly savings target. You must find EXACTLY enough cuts to close the gap between their
current savings and their target.

RULES:
1. The plan MUST close the gap. If the user needs to save $300 more, your actions must total ≥ $300.
2. Prioritize EASY wins first (unused subscriptions, service downgrades, plan switches).
3. Then target DISCRETIONARY spending (dining out, entertainment, impulse shopping).
4. Only cut ESSENTIAL spending as a last resort, and WARN the user clearly.
5. Use REAL numbers from their data. "You spend $142/mo at Starbucks" not "reduce coffee."
6. Every action must specify CURRENT spending and RECOMMENDED spending.
7. Include a "how_to" field with specific steps to execute each action.
8. Set priority: 1 = do this first, ascending. Easy wins first.
9. Never recommend cutting below $0 for a category.
10. For subscriptions, recommend cancel/pause/downgrade specific services by name.
11. For frequency-based cuts (dining out), recommend specific new frequency.
12. If you can't close the gap with discretionary cuts alone, be honest about it.

Return a JSON array of actions:
{
  "title": "Cancel Paramount+ and Crunchyroll",
  "description": "These 2 streaming services haven't been used in 30+ days and cost $19.98/mo combined.",
  "how_to": "1. Go to paramountplus.com/account → Cancel subscription\n2. Go to crunchyroll.com/account → Cancel subscription\n3. Set a calendar reminder in 30 days to see if you miss them",
  "monthly_savings": 19.98,
  "current_spending": 19.98,
  "recommended_spending": 0.00,
  "category": "Subscriptions & Streaming",
  "difficulty": "easy",
  "impact": "medium",
  "priority": 1,
  "is_essential_cut": false,
  "related_merchants": ["Paramount+", "Crunchyroll"]
}

DIFFICULTY:
- easy: No lifestyle change (cancel unused sub, switch plans, use autopay discount)
- medium: Moderate behavior change (eat out less, batch errands for gas, generic brands)
- hard: Significant sacrifice (drop a service you use, major downgrades, cook every meal)

If the gap is very large relative to discretionary spending, include a note action with
is_essential_cut: true explaining the situation honestly.

Respond with ONLY a JSON array. No markdown.
PROMPT;

        $userData = json_encode([
            'savings_target'      => $target->monthly_target,
            'target_motivation'   => $target->motivation,
            'goal_total'          => $target->goal_total,
            'gap_to_close'        => round($gap, 2),
            'estimated_income'    => round($income, 2),
            'current_savings'     => round(max($currentSavings, 0), 2),
            'spending_by_category' => $categorySpending,
            'subscriptions'        => $subscriptions,
            'top_merchants'        => $merchantFrequency,
            'employment_type'      => $profile?->employment_type,
        ], JSON_PRETTY_PRINT);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 4000,
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $userData]],
            ]);

            $text = $response->json('content.0.text');
            $text = preg_replace('/^```json\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', $text);

            $decoded = json_decode(trim($text), true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : ['error' => 'Invalid JSON'];

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Store AI plan actions in the database, replacing any existing suggested ones.
     */
    protected function storePlanActions(int $userId, int $targetId, array $actions): void
    {
        // Clear old suggested (non-accepted) actions for this target
        SavingsPlanAction::where('savings_target_id', $targetId)
            ->where('status', 'suggested')
            ->delete();

        foreach ($actions as $action) {
            SavingsPlanAction::create([
                'user_id'                  => $userId,
                'savings_target_id'        => $targetId,
                'title'                    => $action['title'],
                'description'              => $action['description'],
                'how_to'                   => $action['how_to'] ?? '',
                'monthly_savings'          => $action['monthly_savings'],
                'current_spending'         => $action['current_spending'],
                'recommended_spending'     => $action['recommended_spending'],
                'category'                 => $action['category'],
                'difficulty'               => $action['difficulty'] ?? 'medium',
                'impact'                   => $action['impact'] ?? 'medium',
                'priority'                 => $action['priority'] ?? 99,
                'is_essential_cut'         => $action['is_essential_cut'] ?? false,
                'related_merchants'        => $action['related_merchants'] ?? null,
                'related_subscription_ids' => $action['related_subscription_ids'] ?? null,
                'status'                   => 'suggested',
            ]);
        }
    }

    /**
     * Calculate monthly progress against the savings target.
     * Called at month-end or on demand.
     */
    public function calculateProgress(User $user, SavingsTarget $target, ?string $month = null): SavingsProgress
    {
        $month     = $month ?? Carbon::now()->format('Y-m');
        $monthStart = Carbon::parse($month . '-01')->startOfMonth();
        $monthEnd   = Carbon::parse($month . '-01')->endOfMonth();

        // Income this month
        $income = Transaction::where('user_id', $user->id)
            ->where('amount', '<', 0)
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->whereNotIn('plaid_category', ['TRANSFER_IN', 'TRANSFER_OUT'])
            ->sum(DB::raw('ABS(amount)'));

        // Total spending this month
        $spending = Transaction::where('user_id', $user->id)
            ->where('amount', '>', 0)
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->sum('amount');

        // Spending by category
        $categoryBreakdown = Transaction::where('user_id', $user->id)
            ->where('amount', '>', 0)
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->select(
                DB::raw("COALESCE(user_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();

        $actualSavings = $income - $spending;
        $gap = $target->monthly_target - $actualSavings;

        // Check accepted plan actions vs. actual spending in those categories
        $planAdherence = $this->checkPlanAdherence($user->id, $target->id, $categoryBreakdown);

        // Cumulative totals
        $priorProgress = SavingsProgress::where('user_id', $user->id)
            ->where('savings_target_id', $target->id)
            ->where('month', '<', $month)
            ->orderByDesc('month')
            ->first();

        $cumulativeSaved  = ($priorProgress?->cumulative_saved ?? 0) + max($actualSavings, 0);
        $cumulativeTarget = ($priorProgress?->cumulative_target ?? 0) + $target->monthly_target;

        return SavingsProgress::updateOrCreate(
            [
                'user_id'           => $user->id,
                'savings_target_id' => $target->id,
                'month'             => $month,
            ],
            [
                'income'             => round($income, 2),
                'total_spending'     => round($spending, 2),
                'actual_savings'     => round($actualSavings, 2),
                'target_savings'     => $target->monthly_target,
                'gap'                => round(max($gap, 0), 2),
                'cumulative_saved'   => round($cumulativeSaved, 2),
                'cumulative_target'  => round($cumulativeTarget, 2),
                'target_met'         => $actualSavings >= $target->monthly_target,
                'category_breakdown' => $categoryBreakdown,
                'plan_adherence'     => $planAdherence,
            ]
        );
    }

    /**
     * Compare actual category spending to the accepted plan actions.
     * Returns which actions the user is following and which they're not.
     */
    protected function checkPlanAdherence(int $userId, int $targetId, array $categoryBreakdown): array
    {
        $acceptedActions = SavingsPlanAction::where('savings_target_id', $targetId)
            ->where('status', 'accepted')
            ->get();

        $adherence = [];

        foreach ($acceptedActions as $action) {
            $actualSpend = $categoryBreakdown[$action->category] ?? 0;
            $onTrack     = $actualSpend <= $action->recommended_spending;
            $overBy      = max($actualSpend - $action->recommended_spending, 0);

            $adherence[] = [
                'action_id'            => $action->id,
                'title'                => $action->title,
                'category'             => $action->category,
                'recommended_spending' => $action->recommended_spending,
                'actual_spending'      => round($actualSpend, 2),
                'on_track'             => $onTrack,
                'over_by'              => round($overBy, 2),
                'savings_captured'     => $onTrack
                    ? $action->monthly_savings
                    : max($action->current_spending - $actualSpend, 0),
            ];
        }

        return $adherence;
    }

    /**
     * Build a human-readable summary of the plan.
     */
    protected function buildSummaryMessage(float $gap, float $planTotal, float $easyWins): string
    {
        if ($planTotal >= $gap) {
            $buffer = round($planTotal - $gap);
            $msg = "The plan covers your full \${$gap}/mo gap";
            if ($easyWins >= $gap) {
                $msg .= " — and the easy wins alone (\${$easyWins}/mo) get you there.";
            } else {
                $msg .= " with \${$buffer} buffer. Start with the easy wins (\${$easyWins}/mo) first.";
            }
            return $msg;
        }

        $shortfall = round($gap - $planTotal);
        return "The plan saves \${$planTotal}/mo but you're still \${$shortfall}/mo short. "
             . "Consider increasing income or adjusting your target.";
    }
}
