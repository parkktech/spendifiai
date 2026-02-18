<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant' => $this->merchant_normalized ?? $this->merchant_name,
            'merchant_name' => $this->merchant_name,
            'amount' => (float) $this->amount,
            'date' => $this->transaction_date?->format('Y-m-d'),
            'authorized_date' => $this->authorized_date?->format('Y-m-d'),
            'category' => $this->user_category ?? $this->ai_category ?? 'Uncategorized',
            'ai_category' => $this->ai_category,
            'user_category' => $this->user_category,
            'ai_confidence' => $this->ai_confidence ? (float) $this->ai_confidence : null,
            'review_status' => $this->review_status,
            'expense_type' => $this->expense_type,
            'account_purpose' => $this->account_purpose,
            'tax_deductible' => $this->tax_deductible,
            'tax_category' => $this->tax_category,
            'is_subscription' => $this->is_subscription,
            'is_reconciled' => $this->is_reconciled,
            'matched_order_id' => $this->matched_order_id,
            'description' => $this->description,
            'payment_channel' => $this->payment_channel,

            'account' => new BankAccountResource($this->whenLoaded('bankAccount')),
            'ai_question' => new AIQuestionResource($this->whenLoaded('aiQuestion')),
            'order_items' => $this->when(
                $this->relationLoaded('matchedOrder') && $this->matchedOrder?->relationLoaded('items'),
                fn () => $this->matchedOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'product_description' => $item->product_description,
                    'quantity' => $item->quantity,
                    'total_price' => (float) $item->total_price,
                    'ai_category' => $item->ai_category,
                    'user_category' => $item->user_category,
                    'expense_type' => $item->expense_type,
                    'tax_deductible' => (bool) $item->tax_deductible,
                    'tax_deductible_confidence' => $item->tax_deductible_confidence,
                ]),
                []
            ),
        ];
    }
}
