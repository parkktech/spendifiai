<?php

namespace App\Models;

use App\Traits\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Append-only durable-facts store for the ask-once interview (STORE-01).
 *
 * APPEND-ONLY INVARIANT: A fact's value is NEVER updated in place; rows are NEVER
 * deleted (except GDPR cascade). A re-answer inserts a new row and flips is_current +
 * superseded_by_id on the old row, preserving full provenance and supersession chain.
 *
 * CONFIRM-BEFORE-WRITE GATE (D3/Decision 4): facts with source_type=document_extraction
 * are written as PROPOSALS (is_current=false, confirmed_at=null). They never feed
 * skip-logic, headroom math, or findings until the user confirms (confirmProposal()).
 * They NEVER silently supersede user_edit/interview_answer/profile_field facts.
 *
 * ENCRYPTED value column (TEXT): all sensitive fact content is encrypted.
 * Money values stored as integer-cents-as-string (e.g. '7250000' = $72,500.00).
 * NEVER index or query the value column directly.
 *
 * PARTIAL UNIQUE INDEX (idx_tax_facts_current): enforces at most one is_current=true
 * row per (user_id, fact_key, COALESCE(entity_id,0), COALESCE(tax_year,0)).
 * Concurrent writes are protected via DB transaction + SELECT FOR UPDATE in recordFact().
 */
class UserTaxFact extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'fact_key',
        'value',
        'label',
        'volatility',
        'reconfirm_after',
        'tax_year',
        'entity_id',
        'source_type',
        'source_id',
        'asserted_at',
        'confirmed_at',
        'is_current',
        'superseded_by_id',
        'metadata',
    ];

    /**
     * Hide encrypted value from API responses / toArray().
     * fact_key, label, volatility, source_type remain visible for UI.
     */
    protected $hidden = [
        'value',
    ];

    /**
     * Laravel 12 method-syntax casts.
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',           // TEXT column, AES-256-GCM via Laravel
            'metadata' => 'array',             // JSONB (non-PII extraction confidence)
            'reconfirm_after' => 'date',
            'asserted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function entity(): BelongsTo
    {
        return $this->belongsTo(TaxProfileEntity::class, 'entity_id');
    }

    /**
     * The newer row that superseded this one (null if still current or not superseded yet).
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    // ─── Static Lookup Helpers ────────────────────────────────────────────────

    /**
     * Retrieve the single confirmed current fact for a (user, key, entity?, year?) tuple.
     *
     * CONFIRM GATE: document_extraction facts are EXCLUDED until confirmed_at is set.
     * Only user_edit / interview_answer / profile_field / detector source types
     * are returned without a confirmed_at; document_extraction requires confirmed_at.
     *
     * Returns null if no confirmed current fact exists.
     */
    public static function currentFact(
        int $userId,
        string $factKey,
        ?int $entityId = null,
        ?int $taxYear = null
    ): ?self {
        return static::forUser($userId)
            ->where('fact_key', $factKey)
            ->where('entity_id', $entityId)
            ->where('tax_year', $taxYear)
            ->where('is_current', true)
            ->where(function (Builder $q) {
                // Exclude unconfirmed document_extraction proposals
                $q->where('source_type', '!=', 'document_extraction')
                    ->orWhereNotNull('confirmed_at');
            })
            ->first();
    }

    /**
     * Return all confirmed current fact keys for this user.
     * Used by IncomeOptimizationProfile::answerableFields() for CTX-04 skip-logic.
     * Proposals (source_type=document_extraction, confirmed_at=null) are EXCLUDED.
     *
     * @return string[]
     */
    public static function currentFactKeys(int $userId): array
    {
        return static::forUser($userId)
            ->where('is_current', true)
            ->where(function (Builder $q) {
                $q->where('source_type', '!=', 'document_extraction')
                    ->orWhereNotNull('confirmed_at');
            })
            ->pluck('fact_key')
            ->toArray();
    }

    // ─── Mutation Helpers ─────────────────────────────────────────────────────

    /**
     * Append-only fact write with concurrency safety and confirm gate.
     *
     * For source_type=document_extraction: the new row is written with is_current=false
     * (a PROPOSAL) and NEVER supersedes existing user_edit/interview_answer/profile_field
     * facts. The caller must call confirmProposal() after user confirmation.
     *
     * For all other source types: acquires a row lock (SELECT FOR UPDATE) on the
     * existing current row inside a DB transaction, flips it to is_current=false,
     * then inserts the new current row. This prevents concurrent duplicates that
     * would violate the partial unique index.
     *
     * @param  array<string,mixed>  $metadata  Non-PII context (e.g. extraction confidence)
     */
    public static function recordFact(
        int $userId,
        string $factKey,
        ?string $value,
        string $sourceType,
        ?string $label = null,
        string $volatility = 'stable',
        ?\DateTimeInterface $reconfirmAfter = null,
        ?int $taxYear = null,
        ?int $entityId = null,
        ?string $sourceId = null,
        array $metadata = [],
    ): self {
        $isProposal = $sourceType === 'document_extraction';

        // user_edit IS the strongest confirmation tier — set confirmed_at immediately so
        // confirmed-tier queries (QuestionRetirementService, resolve confirmed flag) pick
        // it up without a separate confirm step. interview_answer and profile_field are
        // equivalently self-confirmed (the act of writing them IS the user's assent).
        $selfConfirmedTypes = ['user_edit', 'interview_answer', 'profile_field', 'detector'];
        $isSelfConfirmed = in_array($sourceType, $selfConfirmedTypes, true);

        return DB::transaction(function () use (
            $userId, $factKey, $value, $sourceType, $label, $volatility,
            $reconfirmAfter, $taxYear, $entityId, $sourceId, $metadata, $isProposal,
            $isSelfConfirmed
        ) {
            // Acquire row lock on the current non-proposal fact for this key tuple.
            // This prevents concurrent inserts from racing past the partial unique index.
            // For proposals we still lock to prevent concurrent proposal races,
            // but we do NOT flip the existing current row to superseded.
            $existing = static::forUser($userId)
                ->where('fact_key', $factKey)
                ->where('entity_id', $entityId)
                ->where('tax_year', $taxYear)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            // For proposals: find any existing OPEN proposal for the same key tuple
            // so we can supersede it after inserting the new row (newest wins).
            // "Open" = unconfirmed + not yet superseded.
            $existingProposal = null;
            if ($isProposal) {
                $existingProposal = static::forUser($userId)
                    ->where('fact_key', $factKey)
                    ->where('entity_id', $entityId)
                    ->where('tax_year', $taxYear)
                    ->where('is_current', false)
                    ->where('source_type', 'document_extraction')
                    ->whereNull('confirmed_at')
                    ->whereNull('superseded_by_id')
                    ->lockForUpdate()
                    ->first();
            }

            // Step 1: For non-proposal writes, flip the existing current row to
            // is_current=false BEFORE inserting the new row. This avoids a partial
            // unique index violation (only one is_current=true allowed per key tuple).
            if (! $isProposal && $existing !== null) {
                $existing->update(['is_current' => false]);
            }

            // Step 2: Insert the new row (now safe — no competing is_current=true row).
            // For proposals: if the prior proposal had a HIGHER confidence score and we
            // were not passed an explicit confidence, carry the higher value forward so
            // the winning proposal always reflects the best extraction certainty.
            $storedMetadata = $metadata;
            if ($isProposal && $existingProposal !== null) {
                $incomingConf = (float) ($metadata['confidence'] ?? 0);
                $priorConf = (float) ($existingProposal->metadata['confidence'] ?? 0);
                if ($priorConf > $incomingConf && ! array_key_exists('confidence', $metadata)) {
                    $storedMetadata['confidence'] = $priorConf;
                    $storedMetadata['confidence_from_prior_proposal'] = true;
                }
            }

            $newFact = static::create([
                'user_id' => $userId,
                'fact_key' => $factKey,
                'value' => $value,
                'label' => $label,
                'volatility' => $volatility,
                'reconfirm_after' => $reconfirmAfter,
                'tax_year' => $taxYear,
                'entity_id' => $entityId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'asserted_at' => now(),
                // Self-confirmed source types (user_edit, interview_answer, profile_field,
                // detector) are confirmed at write time — the write IS the assent.
                // document_extraction proposals stay null until confirmProposal() is called.
                'confirmed_at' => $isSelfConfirmed ? now() : null,
                'metadata' => empty($storedMetadata) ? null : $storedMetadata,
                // Proposals are not current; all other source types are current
                'is_current' => ! $isProposal,
            ]);

            // Step 3a: For non-proposals, set superseded_by_id on the old current row.
            if (! $isProposal && $existing !== null) {
                $existing->update(['superseded_by_id' => $newFact->id]);
            }

            // Step 3b: For proposals, supersede the prior open proposal (newest wins).
            // This ensures at most one open proposal per fact_key tuple at all times.
            if ($isProposal && $existingProposal !== null) {
                $existingProposal->update(['superseded_by_id' => $newFact->id]);
            }

            return $newFact;
        });
    }

    /**
     * Confirm a document_extraction proposal, making it the current fact.
     *
     * Sets is_current=true, confirmed_at=now(), and supersedes any prior current fact
     * for the same key tuple (which may be a user-entered value).
     *
     * This is the ONLY path by which a document_extraction fact can become current.
     * It requires the existing current fact to be locked first (DB transaction).
     *
     * @throws \InvalidArgumentException if the fact is not a document_extraction proposal
     */
    public static function confirmProposal(int $factId): self
    {
        return DB::transaction(function () use ($factId) {
            /** @var self $proposal */
            $proposal = static::lockForUpdate()->findOrFail($factId);

            if ($proposal->source_type !== 'document_extraction') {
                throw new \InvalidArgumentException(
                    "confirmProposal() can only confirm document_extraction facts; got: {$proposal->source_type}"
                );
            }

            if ($proposal->is_current && $proposal->confirmed_at !== null) {
                // Already confirmed — idempotent return
                return $proposal;
            }

            // Lock and supersede the existing current fact for this key tuple, if any
            $existing = static::forUser($proposal->user_id)
                ->where('fact_key', $proposal->fact_key)
                ->where('entity_id', $proposal->entity_id)
                ->where('tax_year', $proposal->tax_year)
                ->where('is_current', true)
                ->where('id', '!=', $factId)
                ->lockForUpdate()
                ->first();

            // IMPORTANT: flip the existing current row to is_current=false FIRST,
            // THEN flip the proposal to is_current=true. This avoids a unique index
            // violation on idx_tax_facts_current (mirrors the recordFact() ordering).
            if ($existing !== null) {
                $existing->update(['is_current' => false]);
            }

            // Now safe: no competing is_current=true row for this key tuple.
            $proposal->update([
                'is_current' => true,
                'confirmed_at' => now(),
            ]);

            // Set superseded_by_id now that we have the proposal's confirmed id.
            if ($existing !== null) {
                $existing->update(['superseded_by_id' => $proposal->id]);
            }

            return $proposal->fresh();
        });
    }

    /**
     * Check whether a stable fact is due for re-confirmation.
     * Uses config('tax-rules.facts.reconfirm_months', 12) to compute the threshold.
     */
    public function isDueForReconfirmation(): bool
    {
        if ($this->volatility !== 'stable') {
            return false;
        }

        if ($this->reconfirm_after !== null) {
            return now()->isAfter($this->reconfirm_after);
        }

        // Fallback: use config threshold from the asserted_at date
        $months = (int) config('tax-rules.facts.reconfirm_months', 12);

        return $this->asserted_at !== null
            && $this->asserted_at->copy()->addMonths($months)->isPast();
    }
}
