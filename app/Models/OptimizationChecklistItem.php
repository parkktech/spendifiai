<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OptimizationChecklistItem — the D9 durable action checklist store (§D.5).
 *
 * Populated by ScenarioChecklistService::materialize() when the user chooses
 * a scenario option (§D.6). One row per action step (+ one header row per year).
 *
 * Columns:
 *  - source_type = 'scenario_choice' (enables scoped delete on re-choose — §D.6)
 *  - kind = 'directive'|'confirm_ask' (§D.5 / D9.2 fact-gated rendering)
 *  - benefit_line_params = engine-computed token values (integer cents — user-scoped
 *    authed-only row, same as OptimizationFinding.estimated_value_cents precedent)
 *  - fact_set_id → citable ScenarioFactSet (M9 citation linkage)
 *  - done_at = completion timestamp; done-toggle writes reality facts (§D.5)
 *
 * Security:
 *  - scopeForUser() enforces cross-user isolation (T-14-08-02)
 *  - Serialization: benefit_line_params is only returned via authed API responses
 *    (no $hidden needed — the column is not sensitive PII by itself; it carries
 *     engine deltas on an ownership-gated row, matching the OptimizationFinding pattern)
 */
class OptimizationChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'source_type',
        'source_id',
        'fact_set_id',
        'knob',
        'step_key',
        'kind',
        'benefit_line_params',
        'position',
        'done_at',
    ];

    protected function casts(): array
    {
        return [
            'benefit_line_params' => 'array',
            'done_at' => 'datetime',
            'tax_year' => 'integer',
            'position' => 'integer',
            'source_id' => 'integer',
            'fact_set_id' => 'integer',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function factSet(): BelongsTo
    {
        return $this->belongsTo(ScenarioFactSet::class, 'fact_set_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Cross-user isolation scope (T-14-08-02).
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
