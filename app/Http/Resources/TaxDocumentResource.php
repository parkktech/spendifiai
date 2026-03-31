<?php

namespace App\Http\Resources;

use App\Services\TaxVaultStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $storageService = app(TaxVaultStorageService::class);

        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_hash' => $this->file_hash,
            'tax_year' => $this->tax_year,
            'category' => $this->category?->label(),
            'status' => $this->status?->value,
            'classification_confidence' => $this->classification_confidence ? (float) $this->classification_confidence : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'signed_url' => $storageService->getSignedUrl($this->resource),
        ];
    }
}
