<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsPlanActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'title'                  => $this->title,
            'description'            => $this->description,
            'category'               => $this->category,
            'monthly_savings'        => (float) $this->monthly_savings,
            'current_spending'       => (float) $this->current_spending,
            'recommended_spending'   => (float) $this->recommended_spending,
            'difficulty'             => $this->difficulty,
            'status'                 => $this->status,
            'user_response'          => $this->user_response,
            'created_at'             => $this->created_at?->toIso8601String(),
        ];
    }
}
