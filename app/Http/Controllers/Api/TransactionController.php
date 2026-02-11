<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTransactionCategoryRequest;
use App\Http\Resources\TransactionResource;
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
            ->with('bankAccount:id,name,mask,purpose,nickname');

        // Account purpose filter (the key one)
        if ($request->filled('purpose'))    $query->where('account_purpose', $request->purpose);
        if ($request->filled('account_id')) $query->where('bank_account_id', $request->account_id);

        if ($request->filled('category')) {
            $cat = $request->category;
            $query->where(function ($q) use ($cat) {
                $q->where('user_category', $cat)
                  ->orWhere(fn($q2) => $q2->whereNull('user_category')->where('ai_category', $cat));
            });
        }

        if ($request->filled('status'))       $query->where('review_status', $request->status);
        if ($request->filled('type'))         $query->where('expense_type', $request->type);
        if ($request->boolean('deductible'))  $query->where('tax_deductible', true);
        if ($request->boolean('subscriptions')) $query->where('is_subscription', true);
        if ($request->filled('search'))       $query->where('merchant_name', 'ILIKE', "%{$request->search}%");
        if ($request->filled('from'))         $query->where('transaction_date', '>=', $request->from);
        if ($request->filled('to'))           $query->where('transaction_date', '<=', $request->to);

        $paginated = $query->orderByDesc('transaction_date')
            ->paginate($request->input('per_page', 50));

        return response()->json(
            TransactionResource::collection($paginated)->response()->getData(true)
        );
    }

    /**
     * Update a transaction's category, expense type, and tax deductibility.
     */
    public function updateCategory(UpdateTransactionCategoryRequest $request, Transaction $transaction): JsonResponse
    {
        $transaction->update([
            'user_category'  => $request->validated('category'),
            'expense_type'   => $request->validated('expense_type') ?? $transaction->expense_type,
            'tax_deductible' => $request->validated('tax_deductible') ?? $transaction->tax_deductible,
            'review_status'  => 'user_confirmed',
        ]);

        // Invalidate dashboard cache for all view modes
        $userId = auth()->id();
        Cache::forget("dashboard:{$userId}:all");
        Cache::forget("dashboard:{$userId}:personal");
        Cache::forget("dashboard:{$userId}:business");

        return response()->json([
            'message'     => 'Updated',
            'transaction' => new TransactionResource($transaction->fresh()),
        ]);
    }
}
