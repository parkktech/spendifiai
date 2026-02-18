<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderItemExpenseTypeRequest;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class OrderItemController extends Controller
{
    public function updateExpenseType(UpdateOrderItemExpenseTypeRequest $request, OrderItem $item): JsonResponse
    {
        $this->authorize('update', $item);

        $expenseType = $request->validated('expense_type');

        $item->update([
            'expense_type' => $expenseType,
            'tax_deductible' => $expenseType === 'business',
        ]);

        // Clear dashboard cache for all view modes
        $userId = $item->user_id;
        Cache::forget("dashboard:{$userId}:all");
        Cache::forget("dashboard:{$userId}:personal");
        Cache::forget("dashboard:{$userId}:business");

        return response()->json([
            'message' => 'Expense type updated',
            'item' => $item->only(['id', 'expense_type', 'tax_deductible']),
        ]);
    }
}
