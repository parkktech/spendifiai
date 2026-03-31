<?php

namespace App\Models;

use App\Enums\DocumentRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequest extends Model
{
    protected $fillable = [
        'accounting_firm_id',
        'client_id',
        'accountant_id',
        'description',
        'tax_year',
        'category',
        'status',
        'fulfilled_document_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentRequestStatus::class,
        ];
    }

    // ─── Relationships ───

    public function firm(): BelongsTo
    {
        return $this->belongsTo(AccountingFirm::class, 'accounting_firm_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function accountant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accountant_id');
    }

    public function fulfilledDocument(): BelongsTo
    {
        return $this->belongsTo(TaxDocument::class, 'fulfilled_document_id');
    }

    // ─── Scopes ───

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentRequestStatus::Pending);
    }
}
