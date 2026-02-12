<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_name' => $this->merchant_name,
            'merchant_normalized' => $this->merchant_normalized,
            'amount' => (float) $this->amount,
            'frequency' => $this->frequency,
            'category' => $this->category,
            'status' => $this->status,
            'is_essential' => $this->is_essential,
            'last_charge_date' => $this->last_charge_date?->format('Y-m-d'),
            'next_expected_date' => $this->next_expected_date?->format('Y-m-d'),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'annual_cost' => (float) $this->annual_cost,
            'charge_history' => $this->charge_history,
            'response_type' => $this->response_type,
            'previous_amount' => $this->previous_amount ? (float) $this->previous_amount : null,
            'response_reason' => $this->response_reason,
            'has_alternatives' => ! empty($this->ai_alternatives),
            'responded_at' => $this->responded_at?->toIso8601String(),
        ];
    }
}
