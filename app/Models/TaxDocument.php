<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'original_filename',
        'stored_path',
        'disk',
        'mime_type',
        'file_size',
        'file_hash',
        'tax_year',
        'category',
        'status',
        'classification_confidence',
        'extracted_data',
        'metadata',
    ];

    protected $hidden = [
        'stored_path',
        'disk',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'category' => TaxDocumentCategory::class,
            'extracted_data' => 'encrypted:array',
            'metadata' => 'array',
            'file_size' => 'integer',
            'classification_confidence' => 'decimal:2',
        ];
    }

    // ─── Relationships ───

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(TaxVaultAuditLog::class);
    }

    // ─── Scopes ───

    /**
     * SECURITY: Always scope queries through user relationship or forUser scope.
     * Never use TaxDocument::find() without tenant check (AUDIT-06).
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByYear(Builder $query, int $year): Builder
    {
        return $query->where('tax_year', $year);
    }

    public function scopeByStatus(Builder $query, DocumentStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
