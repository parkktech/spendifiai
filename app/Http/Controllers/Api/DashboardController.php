<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavingsRecommendationResource;
use App\Http\Resources\TransactionResource;
use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\SavingsRecommendation;
use App\Models\SavingsTarget;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\AI\SavingsTargetPlannerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Dashboard composite data: spending summary, categories, questions,
     * recent transactions, trend, sync status, and accounts summary.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $viewMode = $request->input('view', 'all');
        $cacheKey = "dashboard:{$user->id}:{$viewMode}";

        return response()->json(Cache::remember($cacheKey, 60, function () use ($user, $viewMode) {
            $now = Carbon::now();
            $monthStart = $now->copy()->startOfMonth();
            $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
            $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

            // Base query builder that respects the view filter
            $txQuery = fn () => Transaction::where('user_id', $user->id)
                ->when($viewMode === 'personal', fn ($q) => $q->where('account_purpose', 'personal'))
                ->when($viewMode === 'business', fn ($q) => $q->where('account_purpose', 'business'));

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
            // Use the query builder directly to avoid the model's `category` accessor
            // which overrides the COALESCE alias.
            $categories = $txQuery()
                ->where('amount', '>', 0)
                ->where('transaction_date', '>=', $monthStart)
                ->toBase()
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
                    $q->whereHas('transaction', fn ($tq) => $tq->where('account_purpose', $viewMode));
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

            // Unused subscription details for dashboard display
            $unusedSubDetails = Subscription::where('user_id', $user->id)
                ->where('status', 'unused')
                ->select('id', 'merchant_name', 'merchant_normalized', 'amount', 'last_charge_date', 'last_used_at', 'annual_cost')
                ->orderByDesc('amount')
                ->limit(5)
                ->get();

            // Top savings recommendations
            $savingsRecs = SavingsRecommendation::where('user_id', $user->id)
                ->where('status', 'active')
                ->orderByDesc('annual_savings')
                ->limit(5)
                ->get();

            // Savings target with progress
            $savingsTarget = SavingsTarget::where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            $savingsTargetData = null;
            if ($savingsTarget) {
                $planner = app(SavingsTargetPlannerService::class);
                $currentMonth = $planner->calculateProgress($user, $savingsTarget);
                $savingsTargetData = [
                    'monthly_target' => $savingsTarget->monthly_target,
                    'motivation' => $savingsTarget->motivation,
                    'goal_total' => $savingsTarget->goal_total,
                    'current_month' => $currentMonth,
                ];
            }

            // AI stats
            $autoCategorized = $txQuery()
                ->where('review_status', 'auto_categorized')
                ->count();
            $pendingReview = $txQuery()
                ->whereIn('review_status', ['pending_ai', 'needs_review'])
                ->count();

            // Recent transactions
            $recent = $txQuery()
                ->with('bankAccount:id,name,mask,purpose,nickname')
                ->orderByDesc('transaction_date')
                ->limit(20)
                ->get();

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

            return [
                'view_mode' => $viewMode,
                'summary' => [
                    'this_month_spending' => round($thisMonth, 2),
                    'month_over_month' => $monthOverMonth,
                    'potential_savings' => round($savingsTotal, 2),
                    'tax_deductible_ytd' => round($taxDeductible, 2),
                    'needs_review' => $needsReview,
                    'unused_subscriptions' => $unusedSubs,
                    'pending_questions' => $questions->count(),
                ],
                'categories' => $categories,
                'questions' => $questions,
                'recent' => TransactionResource::collection($recent),
                'spending_trend' => $trend,
                'sync_status' => BankConnection::where('user_id', $user->id)
                    ->first()?->only(['status', 'last_synced_at', 'institution_name']),
                'accounts_summary' => BankAccount::where('user_id', $user->id)
                    ->select('purpose', DB::raw('COUNT(*) as count'))
                    ->groupBy('purpose')
                    ->pluck('count', 'purpose'),
                'savings_recommendations' => SavingsRecommendationResource::collection($savingsRecs),
                'savings_target' => $savingsTargetData,
                'unused_subscription_details' => $unusedSubDetails,
                'ai_stats' => [
                    'auto_categorized' => $autoCategorized,
                    'pending_review' => $pendingReview,
                    'questions_generated' => $questions->count(),
                ],
            ];
        }));
    }
}
