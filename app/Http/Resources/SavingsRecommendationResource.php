<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsRecommendationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'monthly_savings' => (float) $this->monthly_savings,
            'annual_savings' => (float) $this->annual_savings,
            'difficulty' => $this->difficulty,
            'category' => $this->category,
            'impact' => $this->impact,
            'status' => $this->status,
            'action_steps' => $this->action_steps,
            'related_merchants' => $this->related_merchants,
            'response_type' => $this->response_type,
            'response_data' => $this->response_data,
            'actual_monthly_savings' => $this->actual_monthly_savings ? (float) $this->actual_monthly_savings : null,
            'has_alternatives' => ! empty($this->ai_alternatives),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
