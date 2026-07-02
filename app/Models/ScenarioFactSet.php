<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned, citable snapshot of a resolved fact set (SCENARIOS-SPEC §A.8.2).
 *
 * Scenario results must cite EXACTLY the facts they used (D10.6 grounded-in-
 * confirmed-facts, D9.2 fact-gating). ScenarioFactResolverService::snapshotFactSet()
 * freezes the current resolution here at CHOOSE time (M9): the row id is what
 * `scenario.chosen_knobs` metadata and materialized checklist rows reference
 * (consumed in 14-08).
 *
 * fact_set_hash is HMAC-SHA256 keyed on config('app.key') (NOT bare SHA-256):
 * money values have a small brute-forceable search space, so a plain hash of the
 * unencrypted column would be attackable.
 *
 * SECURITY:
 *   - resolved_facts is an encrypted TEXT column holding a JSON map of
 *     fact_key => ResolvedFact (money values inside). Stored via manual
 *     json_encode into the 'encrypted' cast — the InterviewSession.assertions
 *     precedent, avoiding encrypted:array double-encode. NEVER index/query it.
 *   - resolved_facts is $hidden — excluded from toArray()/JSON. Endpoints cite
 *     labels/source-types only, never values (§A.6.4).
 *   - Always query through scopeForUser().
 *
 * GDPR: cascadeOnDelete on user_id FK wipes rows when the account is deleted.
 */
class ScenarioFactSet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'fact_set_hash',
        'resolved_facts',
    ];

    /**
     * SECURITY: resolved_facts holds encrypted money values — never expose it.
     */
    protected $hidden = [
        'resolved_facts',
    ];

    /**
     * NOTE on resolved_facts encryption: the column stores a JSON string BEFORE
     * encryption. 'encrypted' wraps the raw JSON; read back it decrypts to the
     * JSON string, decoded manually via resolvedFactsArray(). ('encrypted:array'
     * would double-encode — matches the InterviewSession.assertions precedent.)
     */
    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'resolved_facts' => 'encrypted',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * SECURITY: Always scope queries through this method.
     * Consistent with UserTaxFact / IncomeOptimizationProfile / InterviewSession.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Decode the encrypted resolved_facts JSON into an array.
     *
     * @return array<string, array<string, mixed>> fact_key => ResolvedFact
     */
    public function resolvedFactsArray(): array
    {
        if ($this->resolved_facts === null) {
            return [];
        }

        $decoded = json_decode($this->resolved_facts, true);

        return is_array($decoded) ? $decoded : [];
    }
}
