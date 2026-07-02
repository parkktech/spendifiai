<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * DetectionMerchant — FLAG-10 detection-merchant knowledge table.
 *
 * Reference data table mapping known merchants to category library modules.
 * Follows CancellationProvider precedent (aliases as JSON array).
 *
 * NOT scope-guarded by user_id — this is global reference data, not user data.
 *
 * SEEDING: additive only via DetectionMerchantSeeder.
 *   php artisan db:seed --class=DetectionMerchantSeeder
 * NEVER run db:seed or migrate:fresh without explicit --class flag (CLAUDE.md §1-7).
 *
 * DEFENSIBILITY RATINGS:
 *   auto        → high-confidence (address-matched rental repair etc.)
 *   conditional → standard interview question required
 *   specialist  → pro-review routing; gray-area module
 *   suppress    → NEVER emit a finding (gambling_losses_fully_deductible etc.)
 *
 * GRAY-AREA CONSTRAINT (TD-v1 §0 safety boundary):
 *   For gray_area=true merchants, CategoryLibraryDetector MUST surface ONLY:
 *   (1) a question, (2) a doc checklist, (3) static defensibility rating,
 *   (4) pro-review routing — NEVER a deduction assertion.
 */
class DetectionMerchant extends Model
{
    protected $fillable = [
        'company_name',
        'aliases',
        'category',
        'subdetector_key',
        'defensibility_rating',
        'gray_area',
        'notes',
        'rule_id',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'gray_area' => 'boolean',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Filter by category (e.g. 'vehicle', 'gambling', 'solar_energy').
     */
    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Filter by subdetector_key (e.g. 'vehicle_parts', 'gambling_signal').
     */
    public function scopeForSubdetector(Builder $query, string $subdetectorKey): Builder
    {
        return $query->where('subdetector_key', $subdetectorKey);
    }

    /**
     * Exclude suppress-band merchants (gambling etc.) from positive finding emission.
     */
    public function scopeNotSuppressed(Builder $query): Builder
    {
        return $query->where('defensibility_rating', '!=', 'suppress');
    }

    // ── Matching helpers ──────────────────────────────────────────────────────

    /**
     * Check whether a normalized merchant name matches any alias for this merchant.
     *
     * Comparison is lowercase, trimmed — mirrors the existing merchant-normalization
     * pipeline in SubscriptionDetectorService (lowercase, trim, suffix-strip).
     *
     * @param  string  $normalizedName  Already-normalized (lowercase/trimmed) name to check
     */
    public function matchesNormalizedName(string $normalizedName): bool
    {
        $needle = strtolower(trim($normalizedName));

        foreach ((array) $this->aliases as $alias) {
            if (strtolower(trim($alias)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find merchants whose aliases match the given normalized name.
     *
     * Performs a case-insensitive match against the aliases JSONB array.
     * Uses PostgreSQL `@>` operator via a JSON contains approach.
     *
     * Note: Since aliases are stored as JSONB, we cast to TEXT for ILIKE matching
     * which is sufficient for the known-alias list (not wildcard search).
     *
     * @return Builder<DetectionMerchant>
     */
    public static function findByNormalizedName(string $normalizedName): Builder
    {
        $upper = strtoupper(trim($normalizedName));
        $lower = strtolower(trim($normalizedName));

        return static::query()->where(function (Builder $q) use ($upper, $lower) {
            // PostgreSQL JSONB: check if aliases array contains the name
            $q->whereRaw('aliases::text ILIKE ?', ["%\"{$upper}\"%"])
                ->orWhereRaw('aliases::text ILIKE ?', ["%\"{$lower}\"%"]);
        });
    }
}
