<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CancellationProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'slug' => $this->slug,
            'aliases' => $this->aliases,
            'cancellation_url' => $this->cancellation_url,
            'cancellation_phone' => $this->cancellation_phone,
            'cancellation_instructions' => $this->cancellation_instructions,
            'difficulty' => $this->difficulty,
            'category' => $this->category,
            'is_essential' => $this->is_essential,
            'is_verified' => $this->is_verified,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
