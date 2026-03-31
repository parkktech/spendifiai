<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxVaultAuditLog extends Model
{
    /**
     * Audit log entries are immutable -- no updated_at column.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tax_document_id',
        'user_id',
        'action',
        'ip_address',
        'user_agent',
        'metadata',
        'previous_hash',
        'entry_hash',
    ];

    protected $hidden = [
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function document(): BelongsTo
    {
        return $this->belongsTo(TaxDocument::class, 'tax_document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Immutability Guards ───

    /**
     * @throws \RuntimeException
     */
    public function delete(): ?bool
    {
        throw new \RuntimeException('Audit log entries are immutable');
    }

    /**
     * @throws \RuntimeException
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('Audit log entries are immutable');
    }
}
