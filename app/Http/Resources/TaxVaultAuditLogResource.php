<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxVaultAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'action' => $this->action,
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
            ],
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        // Only include ip_address and user_agent when Super Admin has made them visible
        if ($this->resource->ip_address !== null) {
            $data['ip_address'] = $this->ip_address;
            $data['user_agent'] = $this->user_agent;
        }

        return $data;
    }
}
