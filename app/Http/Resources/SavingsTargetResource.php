<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'monthly_target'    => (float) $this->monthly_target,
            'motivation'        => $this->motivation,
            'target_start_date' => $this->target_start_date?->format('Y-m-d'),
            'target_end_date'   => $this->target_end_date?->format('Y-m-d'),
            'goal_total'        => $this->goal_total ? (float) $this->goal_total : null,
            'is_active'         => $this->is_active,
            'created_at'        => $this->created_at?->toIso8601String(),

            'actions'  => SavingsPlanActionResource::collection($this->whenLoaded('actions')),
            'progress' => $this->whenLoaded('progress'),
        ];
    }
}
