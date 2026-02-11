<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'name'                   => $this->nickname ?? $this->name,
            'official_name'          => $this->official_name,
            'type'                   => $this->type,
            'subtype'                => $this->subtype,
            'mask'                   => $this->mask,
            'purpose'                => $this->purpose,
            'business_name'          => $this->business_name,
            'tax_entity_type'        => $this->tax_entity_type,
            'include_in_spending'    => $this->include_in_spending,
            'include_in_tax_tracking' => $this->include_in_tax_tracking,
            'current_balance'        => $this->current_balance ? (float) $this->current_balance : null,
            'available_balance'      => $this->available_balance ? (float) $this->available_balance : null,
            'is_active'              => $this->is_active,

            'connection' => new BankConnectionResource($this->whenLoaded('bankConnection')),
        ];
    }
}
