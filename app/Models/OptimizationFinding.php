<?php

namespace App\Models;

use App\Traits\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'finding_key',
        'finding_type',
        'severity',
        'details',
        'description',
        'status',
        // FLAG-13 additive extension (D6)
        'transaction_ids',
        'treatment',
        'legal_basis',
        'assumptions',
        'band',
        'user_assertions',
        'docs_captured',
        'docs_missing',
        'estimated_value_cents',
        'pro_export_ready',
        'deadline',
        'lead_time_days',
        'net_cash_cost',
        'tax_saved',
        'cliff_bonus_value',
        'reversible',
        // D19 structured narration output contract
        'narration_structured',
    ];

    /**
     * Fields hidden from API responses.
     *
     * SECURITY (D12): user_assertions is encrypted and must never appear in
     * JSON serialisation. estimated_value_cents is a financial figure that
     * should only be exposed via the dedicated tax-rules engine output, not
     * raw model serialisation.
     */
    protected $hidden = [
        'user_assertions',
    ];

    /**
     * Laravel 12 method-syntax casts.
     *
     * Encryption note (D12): user_assertions uses the 'encrypted' cast which
     * requires a TEXT column in PostgreSQL. All other JSON fields use 'array'
     * (JSONB in Postgres). The 'deadline' cast ensures Carbon date objects.
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            // FLAG-13 new casts
            'transaction_ids' => 'array',
            'assumptions' => 'array',
            'docs_captured' => 'array',
            'docs_missing' => 'array',
            'user_assertions' => 'encrypted',   // TEXT column + encrypted (D12)
            'pro_export_ready' => 'boolean',
            'reversible' => 'boolean',
            'deadline' => 'date',
            // D19: structured narration — {hook, detail, action_cue}
            'narration_structured' => 'array',
        ];
    }
}
