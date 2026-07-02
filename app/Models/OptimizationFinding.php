<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deterministic discrepancy finding produced by CrossSourceReviewService.
 *
 * Findings are keyed on (user_id, tax_year, finding_key) for idempotent upsert.
 * The `details` JSON payload contains the raw comparison figures in cents:
 *   - gap_cents: absolute dollar gap in cents (integer)
 *   - gap_pct:   fraction 0..1 (float)
 *   - w2_cents / bank_cents (or se_income_cents / bank_cents): source figures
 *
 * The `description` column is intentionally left null in Phase 10.
 * Phase 11 listeners call Claude to generate a human-readable description
 * and populate it via OptimizationFinding::updateOrCreate().
 *
 * SECURITY (T-10-09): Always query through scopeForUser() to prevent
 * cross-user information disclosure.
 */
class OptimizationFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'finding_key',
        'finding_type',
        'severity',
        'details',
        'description',
        'status',
    ];

    /**
     * Laravel 12 method-syntax casts.
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────

    /**
     * SECURITY (T-10-09): Scope all queries to a specific user.
     * Never query OptimizationFinding without this scope in application code.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
