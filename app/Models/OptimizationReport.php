<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The assembled, ranked, sectioned optimization report for a user + tax year.
 *
 * Reports are keyed on (user_id, tax_year) for idempotent upsert.
 * The `sections` JSON column contains section objects — each has:
 *   - section_key, title, section_type (topical | wrapper | glossary | year_end)
 *   - findings: array of {finding_id, finding_type, severity, description}
 *   - narrator_prose: Claude-written 2–4 sentence section overview (no $ figures)
 *   - disclaimer: per-section educational disclaimer string (RPT-03)
 *
 * Dollar amounts are NEVER stored in this model directly.
 * All numeric figures in the report originate from OptimizationFinding records
 * (which are written only by TaxRulesEngineService per SAFE-03).
 *
 * SECURITY (T-12-03-02): Always query through scopeForUser() to prevent
 * cross-user information disclosure. Report API owner-check added in 12-04.
 */
class OptimizationReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'sections',
        'executive_summary',
        'is_stale',
        'stale_since',
        'rebuilt_at',
    ];

    /**
     * No sensitive PII columns — sections carry finding IDs and prose only,
     * never raw dollar amounts or user-identifying details beyond user_id.
     */
    protected $hidden = [];

    /**
     * Laravel 12 method-syntax casts.
     */
    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'is_stale' => 'boolean',
            'stale_since' => 'datetime',
            'rebuilt_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────

    /**
     * SECURITY (T-12-03-02): Scope all queries to a specific user.
     * Never query OptimizationReport without this scope in application code.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Fetch or initialize a report record for the given user + tax year.
     *
     * Callers always receive a model instance (Pitfall 8 guard). If the
     * report has never been built, is_stale=true and sections=[] will signal
     * the API layer (12-04) to dispatch GenerateOptimizationReport.
     */
    public static function fetchOrInit(int $userId, int $taxYear): static
    {
        return static::firstOrCreate(
            ['user_id' => $userId, 'tax_year' => $taxYear],
            ['is_stale' => true, 'sections' => []]
        );
    }
}
