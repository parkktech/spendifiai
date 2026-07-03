<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserDocumentTypeExclusion — per-user "Not applicable" preference for a document type.
 *
 * When a user marks a type as "not applicable" it:
 *   1. Counts as "populated" for the accordion tri-state (computeAccordionDefault).
 *   2. Prevents Stage-0 / doc-cascade from requesting that type.
 *   3. For hsa_statement: records finance.has_hsa=no via UserTaxFact::recordFact()
 *      (source_type=user_edit, confirmed at write time per Item 2 fix).
 *   4. For medical_receipt: preference-only, no semantic fact emitted.
 *
 * Storing the exclusion (not a soft-delete flag on the type) allows one-click reversal
 * without migrating existing doc-type status data.
 */
class UserDocumentTypeExclusion extends Model
{
    protected $fillable = [
        'user_id',
        'document_type',
        'excluded_at',
    ];

    protected function casts(): array
    {
        return [
            'excluded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return the set of excluded document_type values for a user.
     *
     * @return string[]
     */
    public static function exclusionsForUser(int $userId): array
    {
        return static::where('user_id', $userId)
            ->pluck('document_type')
            ->toArray();
    }
}
