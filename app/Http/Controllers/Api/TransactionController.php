<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTransactionCategoryRequest;
use App\Http\Resources\TransactionResource;
use App\Jobs\CategorizePendingTransactions;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TransactionController extends Controller
{
    /**
     * List transactions with extensive filter support.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::where('user_id', auth()->id())
            ->with(['bankAccount:id,name,mask,purpose,nickname', 'matchedOrder.items']);

        // Account purpose filter (the key one)
        if ($request->filled('purpose')) {
            $query->where('account_purpose', $request->purpose);
        }
        if ($request->filled('account_id')) {
            $query->where('bank_account_id', $request->account_id);
        }

        if ($request->filled('category')) {
            $cat = $request->category;
            $query->where(function ($q) use ($cat) {
                $q->where('user_category', $cat)
                    ->orWhere(fn ($q2) => $q2->whereNull('user_category')->where('ai_category', $cat));
            });
        }

        if ($request->filled('status')) {
            $query->where('review_status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('expense_type', $request->type);
        }
        if ($request->boolean('deductible')) {
            $query->where('tax_deductible', true);
        }
        if ($request->boolean('subscriptions')) {
            $query->where('is_subscription', true);
        }
        if ($request->filled('search')) {
            $query->where('merchant_name', 'ILIKE', "%{$request->search}%");
        }
        if ($request->filled('from')) {
            $query->where('transaction_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('transaction_date', '<=', $request->to);
        }

        $paginated = $query->orderByDesc('transaction_date')
            ->paginate($request->input('per_page', 50));

        return response()->json(
            TransactionResource::collection($paginated)->response()->getData(true)
        );
    }

    /**
     * Update a transaction's category, expense type, and tax deductibility.
     * Also applies the same category to all matching merchant transactions
     * that haven't already been user-confirmed with a different category.
     */
    public function updateCategory(UpdateTransactionCategoryRequest $request, Transaction $transaction): JsonResponse
    {
        $category = $request->validated('category');
        $expenseType = $request->validated('expense_type') ?? $transaction->expense_type;
        $taxDeductible = $request->validated('tax_deductible') ?? $transaction->tax_deductible;

        $transaction->update([
            'user_category' => $category,
            'expense_type' => $expenseType,
            'tax_deductible' => $taxDeductible,
            'review_status' => 'user_confirmed',
        ]);

        // Apply to all other transactions from the same merchant that are not yet user-confirmed
        $merchantName = $transaction->merchant_normalized ?? $transaction->merchant_name;
        $matchCount = 0;

        if ($merchantName) {
            $matchCount = Transaction::where('user_id', auth()->id())
                ->where('id', '!=', $transaction->id)
                ->where(function ($q) use ($merchantName) {
                    $q->where('merchant_normalized', $merchantName)
                        ->orWhere('merchant_name', $merchantName);
                })
                ->where('review_status', '!=', 'user_confirmed')
                ->update([
                    'user_category' => $category,
                    'expense_type' => $expenseType,
                    'tax_deductible' => $taxDeductible,
                    'review_status' => 'user_confirmed',
                ]);
        }

        // Invalidate dashboard cache for all view modes
        $userId = auth()->id();
        Cache::forget("dashboard:{$userId}:all");
        Cache::forget("dashboard:{$userId}:personal");
        Cache::forget("dashboard:{$userId}:business");

        return response()->json([
            'message' => $matchCount > 0
                ? "Updated this and {$matchCount} other {$merchantName} transaction".($matchCount !== 1 ? 's' : '')
                : 'Updated',
            'matched' => $matchCount,
            'transaction' => new TransactionResource($transaction->fresh()),
        ]);
    }

    /**
     * Trigger AI categorization for pending transactions.
     * Runs synchronously so the user gets immediate results.
     */
    public function categorize(): JsonResponse
    {
        $pending = Transaction::where('user_id', auth()->id())
            ->whereIn('review_status', ['pending_ai', 'needs_review'])
            ->count();

        if ($pending === 0) {
            return response()->json([
                'message' => 'No transactions pending categorization.',
                'processed' => 0,
            ]);
        }

        CategorizePendingTransactions::dispatchSync(auth()->id());

        // Clear dashboard cache so fresh data shows immediately
        $userId = auth()->id();
        Cache::forget("dashboard:{$userId}:all");
        Cache::forget("dashboard:{$userId}:personal");
        Cache::forget("dashboard:{$userId}:business");

        $stats = Transaction::where('user_id', auth()->id())
            ->selectRaw("COUNT(CASE WHEN review_status = 'auto_categorized' THEN 1 END) as auto_categorized")
            ->selectRaw("COUNT(CASE WHEN review_status = 'needs_review' THEN 1 END) as needs_review")
            ->selectRaw("COUNT(CASE WHEN review_status = 'pending_ai' THEN 1 END) as still_pending")
            ->first();

        return response()->json([
            'message' => "Categorized {$pending} transactions.",
            'auto_categorized' => $stats->auto_categorized,
            'needs_review' => $stats->needs_review,
            'still_pending' => $stats->still_pending,
        ]);
    }
}
