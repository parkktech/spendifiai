<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\SavingsRecommendation;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'categories'     => $categories,
            'questions'      => $questions,
            'recent'         => TransactionResource::collection($recent),
            'spending_trend' => $trend,
            'sync_status'    => BankConnection::where('user_id', $user->id)
                ->first()?->only(['status', 'last_synced_at', 'institution_name']),
            'accounts_summary' => [
                'personal' => BankAccount::where('user_id', $user->id)->where('purpose', 'personal')->count(),
                'business' => BankAccount::where('user_id', $user->id)->where('purpose', 'business')->count(),
                'mixed'    => BankAccount::where('user_id', $user->id)->where('purpose', 'mixed')->count(),
            ],
        ]);
    }
}
