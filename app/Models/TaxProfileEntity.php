<?php

namespace App\Models;

use App\Traits\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Non-person tax-relevant entities: vehicles, properties, business entities (STORE-01/STORE-02).
 *
 * People (dependents, household members) are NOT stored here — use Dependent/Household.
 *
 * STORE-02 Basis Ledger (entity_type=property):
 * The encrypted `attributes` JSON accumulates capital-improvement basis entries, each
 * referencing its Vault receipt by tax_document_id. Basis ledger rules:
 *   - Improvements (new roof, addition, etc.) INCREASE basis
 *   - Rebates (solar incentive rebates, etc.) REDUCE basis (is_rebate=true)
 *   - Maintenance/repairs are EXCLUDED (never increase basis)
 *   - Recapture years are tracked on each qualifying entry
 *   - Each entry references its supporting document via tax_document_id
 *
 * Enforcement is in addBasisEntry() — callers cannot bypass these rules.
 *
 * ENCRYPTED attributes (TEXT): all entity-specific financial detail is encrypted.
 * Money values inside attributes = integer-cents-as-string.
 */
class TaxProfileEntity extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'entity_type',
        'label',
        'attributes',
        'is_current',
        'superseded_by_id',
    ];

    /**
     * Hide encrypted attributes from API responses / toArray().
     * entity_type and label remain visible for listing/display.
     */
    protected $hidden = [
        'attributes',
    ];

    /**
     * Laravel 12 method-syntax casts.
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'encrypted:array',  // TEXT column, encrypted JSON
            'is_current' => 'boolean',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * UserTaxFacts that reference this entity.
     */
    public function facts(): HasMany
    {
        return $this->hasMany(UserTaxFact::class, 'entity_id');
    }

    /**
     * The newer entity record that superseded this one.
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    // ─── STORE-02: Basis Ledger ───────────────────────────────────────────────

    /**
     * Accumulate a capital-improvement basis entry on this property entity (STORE-02).
     *
     * Rules enforced here (not by callers):
     *   - kind=maintenance entries are REJECTED (maintenance doesn't increase basis)
     *   - is_rebate=true entries REDUCE basis (not increase) — stored with negative sense
     *   - recapture_year is tracked per entry for §1250 unrecaptured depreciation
     *   - tax_document_id links each entry to the supporting Vault receipt
     *
     * @param  array{
     *   kind: string,
     *   amount_cents: int,
     *   incurred_on: string,
     *   tax_document_id: int|null,
     *   is_rebate: bool,
     *   recapture_year: int|null
     * }  $entry
     *
     * @throws \InvalidArgumentException if entity_type is not 'property'
     * @throws \InvalidArgumentException if kind is 'maintenance' (not basis-eligible)
     * @throws \InvalidArgumentException if amount_cents is negative
     */
    public function addBasisEntry(array $entry): void
    {
        if ($this->entity_type !== 'property') {
            throw new \InvalidArgumentException(
                'addBasisEntry() is only valid for entity_type=property; got: '.$this->entity_type
            );
        }

        $kind = $entry['kind'] ?? 'improvement';
        if ($kind === 'maintenance') {
            throw new \InvalidArgumentException(
                'Maintenance entries do not increase basis and cannot be added to the basis ledger. '.
                'Only capital improvements, rebates, and basis-affecting events are eligible.'
            );
        }

        $amountCents = (int) ($entry['amount_cents'] ?? 0);
        if ($amountCents < 0) {
            throw new \InvalidArgumentException(
                'amount_cents must be a non-negative integer. '.
                'For rebates, set is_rebate=true — the ledger handles the sign internally.'
            );
        }

        $ledgerEntry = [
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'incurred_on' => $entry['incurred_on'] ?? null,
            'tax_document_id' => $entry['tax_document_id'] ?? null,
            'is_rebate' => (bool) ($entry['is_rebate'] ?? false),
            'recapture_year' => isset($entry['recapture_year']) ? (int) $entry['recapture_year'] : null,
            'recorded_at' => now()->toIso8601String(),
        ];

        // Merge with existing basis_entries in the encrypted attributes bag.
        // Use getAttribute/setAttribute to avoid conflict with Eloquent's internal
        // $this->attributes array (which holds ALL model columns, not our 'attributes' column).
        $bag = $this->getAttribute('attributes') ?? [];
        $existingEntries = $bag['basis_entries'] ?? [];
        $existingEntries[] = $ledgerEntry;
        $bag['basis_entries'] = $existingEntries;

        // Save (the 'encrypted:array' cast handles serialization + encryption)
        $this->setAttribute('attributes', $bag);
        $this->save();
    }

    /**
     * Compute the net adjusted basis from all basis ledger entries (STORE-02).
     *
     * Net basis = sum(improvements) - sum(rebates).
     * Maintenance entries are never in the ledger (enforced by addBasisEntry).
     *
     * @return int Net adjusted basis in integer cents
     */
    public function computeNetBasisCents(): int
    {
        $entries = ($this->getAttribute('attributes') ?? [])['basis_entries'] ?? [];
        $net = 0;

        foreach ($entries as $entry) {
            $cents = (int) ($entry['amount_cents'] ?? 0);
            if ($entry['is_rebate'] ?? false) {
                $net -= $cents;
            } else {
                $net += $cents;
            }
        }

        return $net;
    }
}
