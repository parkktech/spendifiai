<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CategorizePendingTransactions;
use App\Jobs\ProcessOrderEmails;
use App\Models\AIQuestion;
use App\Models\BankConnection;
use App\Models\EmailConnection;
use App\Models\SavingsRecommendation;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\OrderItem;
use App\Models\UserFinancialProfile;
use App\Services\AI\SavingsAnalyzerService;
use App\Services\AI\TransactionCategorizerService;
use App\Services\AI\SavingsTargetPlannerService;
use App\Services\PlaidService;
use App\Services\SubscriptionDetectorService;
use App\Services\TaxExportService;
use App\Models\SavingsTarget;
use App\Models\SavingsPlanAction;
use App\Models\SavingsProgress;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpendWiseController extends Controller
{
    // ─────────────────────────────────────────────
    // BANK ACCOUNTS — PURPOSE & MANAGEMENT
    // ─────────────────────────────────────────────

    /**
     * List all linked accounts with purpose labels.
     */
    public function accounts(): JsonResponse
    {
        $accounts = BankAccount::where('user_id', auth()->id())
            ->where('is_active', true)
            ->with('bankConnection:id,institution_name,status,last_synced_at')
            ->get()
            ->map(fn($a) => [
                'id'                    => $a->id,
                'institution'           => $a->bankConnection->institution_name,
                'name'                  => $a->nickname ?? $a->name,
                'official_name'         => $a->official_name,
                'type'                  => $a->type,
                'subtype'               => $a->subtype,
                'mask'                  => $a->mask,
                'purpose'               => $a->purpose,
                'business_name'         => $a->business_name,
                'tax_entity_type'       => $a->tax_entity_type,
                'include_in_spending'   => $a->include_in_spending,
                'include_in_tax_tracking' => $a->include_in_tax_tracking,
                'current_balance'       => $a->current_balance,
                'available_balance'     => $a->available_balance,
                'last_synced'           => $a->bankConnection->last_synced_at,
            ]);

        // Summary by purpose
        $personal = $accounts->where('purpose', 'personal');
        $business = $accounts->where('purpose', 'business');
        $mixed    = $accounts->where('purpose', 'mixed');

        return response()->json([
            'accounts' => $accounts,
            'summary'  => [
                'personal_accounts' => $personal->count(),
                'business_accounts' => $business->count(),
                'mixed_accounts'    => $mixed->count(),
                'personal_balance'  => $personal->sum('current_balance'),
                'business_balance'  => $business->sum('current_balance'),
            ],
        ]);
    }

    /**
     * Set or update an account's purpose (personal/business/mixed).
     * Optionally re-categorize all its transactions.
     */
    public function updateAccountPurpose(Request $request, BankAccount $account): JsonResponse
    {
        $this->authorize('update', $account);

        $validated = $request->validate([
            'purpose'               => 'required|in:personal,business,mixed,investment',
            'nickname'              => 'nullable|string|max:100',
            'business_name'         => 'nullable|string|max:200',
            'tax_entity_type'       => 'nullable|in:sole_prop,llc,s_corp,c_corp,partnership,personal',
            'ein'                   => 'nullable|string|max:20',
            'include_in_spending'   => 'nullable|boolean',
            'include_in_tax_tracking' => 'nullable|boolean',
        ]);

        $oldPurpose = $account->purpose;
        $account->update($validated);

        // If purpose changed, update all transactions from this account
        if ($oldPurpose !== $validated['purpose']) {
            $newPurpose = $validated['purpose'];

            // Bulk update the denormalized field on transactions
            $updated = Transaction::where('bank_account_id', $account->id)
                ->update(['account_purpose' => $newPurpose]);

            // Bulk update expense_type defaults for transactions that
            // haven't been manually categorized by the user yet
            Transaction::where('bank_account_id', $account->id)
                ->whereNull('user_category')
                ->update([
                    'expense_type'   => match ($newPurpose) {
                        'business' => 'business',
                        'mixed'    => 'mixed',
                        default    => 'personal',
                    },
                    'tax_deductible' => $newPurpose === 'business',
                    'review_status'  => 'pending_ai', // Queue for re-categorization
                ]);

            // Re-run AI categorization with the new account context
            CategorizePendingTransactions::dispatch(auth()->id());

            return response()->json([
                'message'              => "Account updated to '{$newPurpose}'. {$updated} transactions being re-categorized.",
                'account'              => $account->fresh(),
                'transactions_updated' => $updated,
            ]);
        }

        return response()->json([
            'message' => 'Account updated.',
            'account' => $account->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────
    // DASHBOARD (updated with purpose-aware views)
    // ─────────────────────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $user = auth()->user();
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

        // View filter: personal, business, or all (default)
        $viewMode = $request->input('view', 'all');

        // Base query builder that respects the view filter
        $txQuery = fn() => Transaction::where('user_id', $user->id)
            ->when($viewMode === 'personal', fn($q) => $q->where('account_purpose', 'personal'))
            ->when($viewMode === 'business', fn($q) => $q->where('account_purpose', 'business'));

        // This month's spending
        $thisMonth = $txQuery()
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $monthStart)
            ->sum('amount');

        // Last month's spending
        $lastMonth = $txQuery()
            ->where('amount', '>', 0)
            ->whereBetween('transaction_date', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $monthOverMonth = $lastMonth > 0
            ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
            : 0;

        // Category breakdown (this month)
        $categories = $txQuery()
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $monthStart)
            ->select(
                DB::raw("COALESCE(user_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        // Pending AI questions
        $questions = AIQuestion::where('user_id', $user->id)
            ->where('status', 'pending')
            ->with('transaction:id,merchant_name,amount,transaction_date,account_purpose')
            ->when($viewMode !== 'all', function ($q) use ($viewMode) {
                $q->whereHas('transaction', fn($tq) => $tq->where('account_purpose', $viewMode));
            })
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Savings potential
        $savingsTotal = SavingsRecommendation::where('user_id', $user->id)
            ->where('status', 'active')
            ->sum('monthly_savings');

        // Tax deductible YTD
        $taxDeductible = $txQuery()
            ->where('tax_deductible', true)
            ->where('transaction_date', '>=', $now->copy()->startOfYear())
            ->sum('amount');

        // Items needing review
        $needsReview = $txQuery()
            ->whereIn('review_status', ['needs_review', 'pending_ai', 'ai_uncertain'])
            ->count();

        // Unused subscriptions
        $unusedSubs = Subscription::where('user_id', $user->id)
            ->where('status', 'unused')
            ->count();

        // Recent transactions
        $recent = $txQuery()
            ->with('bankAccount:id,name,mask,purpose,nickname')
            ->orderByDesc('transaction_date')
            ->limit(20)
            ->get()
            ->map(fn($tx) => [
                'id'              => $tx->id,
                'merchant'        => $tx->merchant_normalized ?? $tx->merchant_name,
                'amount'          => $tx->amount,
                'date'            => $tx->transaction_date->format('M j'),
                'category'        => $tx->user_category ?? $tx->ai_category ?? 'Uncategorized',
                'review_status'   => $tx->review_status,
                'is_subscription' => $tx->is_subscription,
                'tax_deductible'  => $tx->tax_deductible,
                'expense_type'    => $tx->expense_type,
                'account_purpose' => $tx->account_purpose,
                'account'         => $tx->bankAccount?->nickname ?? $tx->bankAccount?->name,
                'account_mask'    => $tx->bankAccount?->mask,
            ]);

        // Monthly spending trend (6 months)
        $trend = $txQuery()
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $now->copy()->subMonths(6)->startOfMonth())
            ->select(
                DB::raw("TO_CHAR(transaction_date, 'Mon') as month"),
                DB::raw("DATE_TRUNC('month', transaction_date) as month_start"),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('month', 'month_start')
            ->orderBy('month_start')
            ->get();

        return response()->json([
            'view_mode' => $viewMode,
            'summary' => [
                'this_month_spending'  => round($thisMonth, 2),
                'month_over_month'     => $monthOverMonth,
                'potential_savings'    => round($savingsTotal, 2),
                'tax_deductible_ytd'  => round($taxDeductible, 2),
                'needs_review'        => $needsReview,
                'unused_subscriptions' => $unusedSubs,
                'pending_questions'   => $questions->count(),
            ],
            'categories'    => $categories,
            'questions'     => $questions,
            'recent'        => $recent,
            'spending_trend' => $trend,
            'sync_status'   => BankConnection::where('user_id', $user->id)
                ->first()?->only(['status', 'last_synced_at', 'institution_name']),
            'accounts_summary' => [
                'personal' => BankAccount::where('user_id', $user->id)->where('purpose', 'personal')->count(),
                'business' => BankAccount::where('user_id', $user->id)->where('purpose', 'business')->count(),
                'mixed'    => BankAccount::where('user_id', $user->id)->where('purpose', 'mixed')->count(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────
    // PLAID / BANK CONNECTION
    // ─────────────────────────────────────────────

    public function createPlaidLinkToken(PlaidService $plaid): JsonResponse
    {
        $token = $plaid->createLinkToken(auth()->user());
        return response()->json(['link_token' => $token['link_token']]);
    }

    public function exchangePlaidToken(Request $request, PlaidService $plaid): JsonResponse
    {
        $request->validate(['public_token' => 'required|string']);

        $connection = $plaid->exchangePublicToken(
            auth()->user(),
            $request->public_token
        );

        // Sync initial transactions
        $plaid->syncTransactions($connection);

        // Queue AI categorization
        CategorizePendingTransactions::dispatch(auth()->id());

        return response()->json([
            'message'     => 'Bank connected successfully',
            'institution' => $connection->institution_name,
            'accounts'    => $connection->accounts()->count(),
        ]);
    }

    public function syncBank(PlaidService $plaid): JsonResponse
    {
        $connection = BankConnection::where('user_id', auth()->id())
            ->where('status', 'active')
            ->firstOrFail();

        $result = $plaid->syncTransactions($connection);

        // Categorize any new transactions
        if ($result['added'] > 0) {
            CategorizePendingTransactions::dispatch(auth()->id());
        }

        return response()->json([
            'message' => 'Sync complete',
            ...$result,
        ]);
    }

    // ─────────────────────────────────────────────
    // AI QUESTIONS / USER INTERACTION
    // ─────────────────────────────────────────────

    public function getQuestions(): JsonResponse
    {
        $questions = AIQuestion::where('user_id', auth()->id())
            ->where('status', 'pending')
            ->with('transaction:id,merchant_name,amount,transaction_date,description')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($questions);
    }

    public function answerQuestion(
        Request $request,
        AIQuestion $question,
        TransactionCategorizerService $categorizer
    ): JsonResponse {
        $this->authorize('update', $question);

        $request->validate(['answer' => 'required|string|max:200']);

        $categorizer->handleUserAnswer($question, $request->answer);

        return response()->json([
            'message' => 'Answer recorded',
            'transaction' => $question->transaction->fresh(),
        ]);
    }

    public function bulkAnswerQuestions(
        Request $request,
        TransactionCategorizerService $categorizer
    ): JsonResponse {
        $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer|exists:ai_questions,id',
            'answers.*.answer' => 'required|string|max:200',
        ]);

        $processed = 0;
        foreach ($request->answers as $item) {
            $question = AIQuestion::where('id', $item['question_id'])
                ->where('user_id', auth()->id())
                ->first();

            if ($question && $question->status === 'pending') {
                $categorizer->handleUserAnswer($question, $item['answer']);
                $processed++;
            }
        }

        return response()->json(['processed' => $processed]);
    }

    // ─────────────────────────────────────────────
    // TRANSACTIONS
    // ─────────────────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $query = Transaction::where('user_id', auth()->id())
            ->with('bankAccount:id,name,mask,purpose,nickname');

        // Account purpose filter (the key one)
        if ($request->filled('purpose'))   $query->where('account_purpose', $request->purpose);
        if ($request->filled('account_id')) $query->where('bank_account_id', $request->account_id);

        if ($request->filled('category')) {
            $cat = $request->category;
            $query->where(function ($q) use ($cat) {
                $q->where('user_category', $cat)
                  ->orWhere(fn($q2) => $q2->whereNull('user_category')->where('ai_category', $cat));
            });
        }

        if ($request->filled('status'))    $query->where('review_status', $request->status);
        if ($request->filled('type'))      $query->where('expense_type', $request->type);
        if ($request->boolean('deductible')) $query->where('tax_deductible', true);
        if ($request->boolean('subscriptions')) $query->where('is_subscription', true);
        if ($request->filled('search'))    $query->where('merchant_name', 'ILIKE', "%{$request->search}%");
        if ($request->filled('from'))      $query->where('transaction_date', '>=', $request->from);
        if ($request->filled('to'))        $query->where('transaction_date', '<=', $request->to);

        return response()->json(
            $query->orderByDesc('transaction_date')->paginate($request->input('per_page', 50))
        );
    }

    public function updateTransactionCategory(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $request->validate([
            'category'       => 'required|string|max:100',
            'expense_type'   => 'nullable|in:personal,business,mixed',
            'tax_deductible' => 'nullable|boolean',
        ]);

        $transaction->update([
            'user_category'  => $request->category,
            'expense_type'   => $request->expense_type ?? $transaction->expense_type,
            'tax_deductible' => $request->tax_deductible ?? $transaction->tax_deductible,
            'review_status'  => 'user_confirmed',
        ]);

        return response()->json(['message' => 'Updated', 'transaction' => $transaction->fresh()]);
    }

    // ─────────────────────────────────────────────
    // SUBSCRIPTIONS
    // ─────────────────────────────────────────────

    public function subscriptions(): JsonResponse
    {
        $subs = Subscription::where('user_id', auth()->id())
            ->orderByDesc('amount')
            ->get();

        return response()->json([
            'subscriptions'   => $subs,
            'total_monthly'   => $subs->where('status', 'active')->sum('amount'),
            'total_annual'    => $subs->where('status', 'active')->sum('annual_cost'),
            'unused_monthly'  => $subs->where('status', 'unused')->sum('amount'),
            'unused_count'    => $subs->where('status', 'unused')->count(),
        ]);
    }

    public function detectSubscriptions(SubscriptionDetectorService $detector): JsonResponse
    {
        $result = $detector->detectSubscriptions(auth()->id());
        return response()->json($result);
    }

    // ─────────────────────────────────────────────
    // SAVINGS RECOMMENDATIONS
    // ─────────────────────────────────────────────

    public function savingsRecommendations(): JsonResponse
    {
        $recs = SavingsRecommendation::where('user_id', auth()->id())
            ->where('status', 'active')
            ->orderByDesc('annual_savings')
            ->get();

        return response()->json([
            'recommendations'    => $recs,
            'total_monthly'      => $recs->sum('monthly_savings'),
            'total_annual'       => $recs->sum('annual_savings'),
            'easy_wins_monthly'  => $recs->where('difficulty', 'easy')->sum('monthly_savings'),
        ]);
    }

    public function generateSavingsAnalysis(SavingsAnalyzerService $analyzer): JsonResponse
    {
        $result = $analyzer->analyze(auth()->user());
        return response()->json($result);
    }

    public function dismissRecommendation(SavingsRecommendation $rec): JsonResponse
    {
        $this->authorize('update', $rec);
        $rec->update(['status' => 'dismissed', 'dismissed_at' => now()]);
        return response()->json(['message' => 'Dismissed']);
    }

    public function applyRecommendation(SavingsRecommendation $rec): JsonResponse
    {
        $this->authorize('update', $rec);
        $rec->update(['status' => 'applied', 'applied_at' => now()]);
        return response()->json(['message' => 'Marked as applied']);
    }

    // ─────────────────────────────────────────────
    // SAVINGS TARGETS & PLANS
    // ─────────────────────────────────────────────

    /**
     * Set or update the user's monthly savings target.
     */
    public function setSavingsTarget(Request $request, SavingsTargetPlannerService $planner): JsonResponse
    {
        $validated = $request->validate([
            'monthly_target' => 'required|numeric|min:1|max:100000',
            'motivation'     => 'nullable|string|max:200',
            'goal_total'     => 'nullable|numeric|min:0',
        ]);

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
        $plan = $planner->generatePlan(auth()->user(), $target);

        return response()->json([
            'target'  => $target,
            'plan'    => $plan,
        ]);
    }

    /**
     * Get the current active savings target, plan, and progress.
     */
    public function getSavingsTarget(SavingsTargetPlannerService $planner): JsonResponse
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

        $accepted = $actions->where('status', 'accepted');
        $suggested = $actions->where('status', 'suggested');

        // Get progress history
        $progress = SavingsProgress::where('savings_target_id', $target->id)
            ->orderBy('month')
            ->get();

        // Current month progress
        $currentMonth = $planner->calculateProgress(auth()->user(), $target);

        // Time to goal calculation
        $timeToGoal = null;
        if ($target->goal_total && $currentMonth->cumulative_saved > 0) {
            $avgMonthlySavings = $currentMonth->cumulative_saved
                / max($progress->count(), 1);
            $remaining = $target->goal_total - $currentMonth->cumulative_saved;
            if ($avgMonthlySavings > 0) {
                $timeToGoal = [
                    'months_remaining'  => (int) ceil($remaining / $avgMonthlySavings),
                    'projected_date'    => now()->addMonths((int) ceil($remaining / $avgMonthlySavings))->format('M Y'),
                    'on_pace'           => $avgMonthlySavings >= $target->monthly_target,
                    'pct_complete'      => round(($currentMonth->cumulative_saved / $target->goal_total) * 100, 1),
                ];
            }
        }

        return response()->json([
            'has_target'         => true,
            'target'             => $target,
            'current_month'      => $currentMonth,
            'progress_history'   => $progress,
            'time_to_goal'       => $timeToGoal,
            'plan'               => [
                'accepted_actions'       => $accepted->values(),
                'suggested_actions'      => $suggested->values(),
                'accepted_total_savings' => round($accepted->sum('monthly_savings'), 2),
                'suggested_total_savings' => round($suggested->sum('monthly_savings'), 2),
                'full_plan_savings'      => round($actions->whereIn('status', ['accepted', 'suggested'])->sum('monthly_savings'), 2),
            ],
        ]);
    }

    /**
     * Regenerate the action plan (e.g., after spending changes or target update).
     */
    public function regeneratePlan(SavingsTargetPlannerService $planner): JsonResponse
    {
        $target = SavingsTarget::where('user_id', auth()->id())
            ->where('is_active', true)
            ->firstOrFail();

        $plan = $planner->generatePlan(auth()->user(), $target);

        return response()->json($plan);
    }

    /**
     * Accept or reject a plan action.
     */
    public function respondToPlanAction(Request $request, SavingsPlanAction $action): JsonResponse
    {
        $this->authorize('update', $action);

        $request->validate([
            'response'         => 'required|in:accept,reject',
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        if ($request->response === 'accept') {
            $action->update([
                'status'      => 'accepted',
                'accepted_at' => now(),
            ]);
        } else {
            $action->update([
                'status'           => 'rejected',
                'rejected_at'      => now(),
                'rejection_reason' => $request->rejection_reason,
            ]);
        }

        // Recalculate whether accepted actions cover the gap
        $target = $action->savingsTarget;
        $acceptedTotal = SavingsPlanAction::where('savings_target_id', $target->id)
            ->where('status', 'accepted')
            ->sum('monthly_savings');

        return response()->json([
            'action'          => $action->fresh(),
            'accepted_total'  => round($acceptedTotal, 2),
            'target'          => $target->monthly_target,
            'gap_remaining'   => round(max($target->monthly_target - $acceptedTotal, 0), 2),
            'covers_target'   => $acceptedTotal >= $target->monthly_target,
        ]);
    }

    /**
     * Get a mid-month pulse check: are they on track this month?
     */
    public function savingsPulseCheck(SavingsTargetPlannerService $planner): JsonResponse
    {
        $target = SavingsTarget::where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (!$target) {
            return response()->json(['has_target' => false]);
        }

        $now       = Carbon::now();
        $daysInMonth = $now->daysInMonth;
        $dayOfMonth  = $now->day;
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
                    'action_id'  => $action->id,
                    'title'      => $action->title,
                    'category'   => $action->category,
                    'spent'      => round($spent, 2),
                    'budget'     => round($budget, 2),
                    'prorated'   => round($proratedBudget, 2),
                    'over_by_pct' => $overPct,
                    'message'    => "{$action->category}: \${$spent} spent ({$overPct}% over pace). "
                                 . "Budget for the full month is \${$budget}.",
                ];
            }
        }

        $currentSavings = $income - $spending;
        $projectedSavings = ($currentSavings / max($pctMonthElapsed, 0.01));
        $onTrack = $projectedSavings >= $target->monthly_target;

        return response()->json([
            'day_of_month'       => $dayOfMonth,
            'pct_month_elapsed'  => $pctMonthElapsed,
            'spending_so_far'    => round($spending, 2),
            'income_so_far'      => round($income, 2),
            'savings_so_far'     => round(max($currentSavings, 0), 2),
            'projected_savings'  => round($projectedSavings, 2),
            'target'             => $target->monthly_target,
            'on_track'           => $onTrack,
            'status'             => $onTrack ? 'on_track' : ($warnings ? 'at_risk' : 'behind'),
            'warnings'           => $warnings,
            'daily_budget_remaining' => round(
                max(($income - $spending - $target->monthly_target) / max($daysInMonth - $dayOfMonth, 1), 0),
                2
            ),
        ]);
    }

    // ─────────────────────────────────────────────
    // TAX WRITEOFFS
    // ─────────────────────────────────────────────

    public function taxSummary(Request $request): JsonResponse
    {
        $year = $request->input('year', now()->year);
        $userId = auth()->id();

        // Deductible transactions by category
        $categories = Transaction::where('user_id', $userId)
            ->where('tax_deductible', true)
            ->whereYear('transaction_date', $year)
            ->select(
                DB::raw("COALESCE(tax_category, user_category, ai_category) as category"),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as item_count')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        // Deductible order items (from email parsing)
        $orderItems = OrderItem::where('user_id', $userId)
            ->where('tax_deductible', true)
            ->whereHas('order', fn($q) => $q->whereYear('order_date', $year))
            ->select(
                DB::raw("COALESCE(tax_category, ai_category) as category"),
                DB::raw('SUM(total_price) as total'),
                DB::raw('COUNT(*) as item_count')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $totalDeductible = $categories->sum('total') + $orderItems->sum('total');
        $profile = UserFinancialProfile::where('user_id', $userId)->first();
        $estRate = ($profile?->estimated_tax_bracket ?? 22) / 100;

        return response()->json([
            'year'                 => $year,
            'total_deductible'     => round($totalDeductible, 2),
            'estimated_tax_savings' => round($totalDeductible * $estRate, 2),
            'effective_rate_used'   => $estRate,
            'transaction_categories' => $categories,
            'order_item_categories'  => $orderItems,
        ]);
    }

    /**
     * Generate the full tax export package (Excel + PDF + CSV).
     * Returns download links for all three files.
     */
    public function exportTaxPackage(Request $request, TaxExportService $exporter): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:' . (now()->year),
        ]);

        $result = $exporter->generate(auth()->user(), $request->year);

        // Generate temporary signed download URLs (valid 24 hours)
        $downloadLinks = [];
        foreach ($result['files'] as $type => $path) {
            $filename = basename($path);
            $downloadLinks[$type] = [
                'filename' => $filename,
                'url'      => route('tax.download', [
                    'year' => $request->year,
                    'type' => $type,
                ]),
                'size' => file_exists($path) ? $this->formatFileSize(filesize($path)) : null,
            ];
        }

        return response()->json([
            'message'   => 'Tax package generated successfully',
            'year'      => $request->year,
            'summary'   => $result['summary'],
            'downloads' => $downloadLinks,
        ]);
    }

    /**
     * Send the tax package directly to an accountant's email.
     * Also CC's the user so they have a copy.
     */
    public function sendToAccountant(Request $request, TaxExportService $exporter): JsonResponse
    {
        $request->validate([
            'year'             => 'required|integer|min:2020|max:' . (now()->year),
            'accountant_email' => 'required|email|max:255',
            'accountant_name'  => 'nullable|string|max:255',
            'message'          => 'nullable|string|max:1000',
        ]);

        $result = $exporter->generate(
            auth()->user(),
            $request->year,
            $request->accountant_email
        );

        return response()->json([
            'message'    => "Tax package for {$request->year} sent to {$request->accountant_email}",
            'emailed_to' => $request->accountant_email,
            'cc'         => auth()->user()->email,
            'summary'    => $result['summary'],
            'files_sent' => [
                'SpendWise_Tax_' . $request->year . '.xlsx',
                'SpendWise_Tax_Summary_' . $request->year . '.pdf',
                'SpendWise_Transactions_' . $request->year . '.csv',
            ],
        ]);
    }

    /**
     * Download a specific tax export file.
     */
    public function downloadTaxFile(Request $request, int $year, string $type): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $allowedTypes = ['xlsx', 'pdf', 'csv'];
        if (!in_array($type, $allowedTypes)) {
            abort(404, 'Invalid file type');
        }

        // Find the most recent export for this year
        $dir = storage_path("app/tax-exports/" . auth()->id());
        $pattern = "{$dir}/SpendWise_Tax_{$year}_*.{$type}";
        $files = glob($pattern);

        if (empty($files)) {
            abort(404, 'Tax export not found. Generate it first.');
        }

        // Get the most recent one
        $latestFile = end($files);

        $mimeTypes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf'  => 'application/pdf',
            'csv'  => 'text/csv',
        ];

        return response()->download(
            $latestFile,
            "SpendWise_Tax_{$year}.{$type}",
            ['Content-Type' => $mimeTypes[$type]]
        );
    }

    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    // ─────────────────────────────────────────────
    // USER PROFILE
    // ─────────────────────────────────────────────

    public function updateFinancialProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employment_type'      => 'nullable|in:employed,self_employed,freelancer,business_owner',
            'business_type'        => 'nullable|string|max:200',
            'has_home_office'      => 'nullable|boolean',
            'tax_filing_status'    => 'nullable|in:single,married,head_of_household',
            'estimated_tax_bracket' => 'nullable|integer|in:10,12,22,24,32,35,37',
            'monthly_income'       => 'nullable|numeric|min:0',
            'monthly_savings_goal' => 'nullable|numeric|min:0',
        ]);

        $profile = UserFinancialProfile::updateOrCreate(
            ['user_id' => auth()->id()],
            $validated
        );

        // Re-categorize all transactions with the updated profile context
        CategorizePendingTransactions::dispatch(auth()->id(), true);

        return response()->json(['message' => 'Profile updated', 'profile' => $profile]);
    }
}
