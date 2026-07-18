<?php

namespace App\Models;

use App\Traits\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user financial snapshot for the Optimize My Income feature.
 *
 * Encrypted money columns store integer CENTS as strings.
 * Example: '7250000' == $72,500.00
 * This convention is enforced by IncomeOptimizerDataAssemblerService,
 * which always converts extracted_data dollar floats via:
 *   (int) round((float) $value * 100)
 *
 * Consuming code (TaxRulesEngineService) reads cents as:
 *   (int) $profile->w2_wages
 *
 * NEVER store dollars in these columns — TaxRulesEngineService
 * expects integer cents and will produce wrong results if given dollars.
 */
class IncomeOptimizationProfile extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        // Income signals (encrypted cents)
        'w2_wages',
        'self_employment_income',
        'interest_income',
        'dividend_income',
        'retirement_distributions',
        'bank_deposit_total',
        // Deduction signals (encrypted cents)
        'mortgage_interest',
        'property_tax_paid',
        'student_loan_interest',
        'charitable_contributions',
        // Retirement contribution signals (encrypted cents)
        'traditional_401k_ytd',
        'roth_401k_ytd',
        'ira_ytd',
        'hsa_ytd',
        // Non-sensitive flags
        'filing_status',
        'has_home_office',
        'has_self_employment',
        'has_hsa_eligible_plan',
        'has_ira',
        'ira_type',
        'employment_type',
        'business_type',
        'housing_status',
        'estimated_age',
        // Metadata
        'data_sources',
        'doc_count',
        'profile_hash',
        'built_at',
    ];

    /**
     * Hide all encrypted money columns from API responses / toArray().
     * Non-sensitive flags remain visible to downstream code.
     */
    protected $hidden = [
        'w2_wages',
        'self_employment_income',
        'interest_income',
        'dividend_income',
        'retirement_distributions',
        'bank_deposit_total',
        'mortgage_interest',
        'property_tax_paid',
        'student_loan_interest',
        'charitable_contributions',
        'traditional_401k_ytd',
        'roth_401k_ytd',
        'ira_ytd',
        'hsa_ytd',
    ];

    /**
     * Laravel 12 method-syntax casts.
     * All 14 money columns use 'encrypted' cast on TEXT DB columns.
     */
    protected function casts(): array
    {
        return [
            // Income signals — encrypted cent strings
            'w2_wages' => 'encrypted',
            'self_employment_income' => 'encrypted',
            'interest_income' => 'encrypted',
            'dividend_income' => 'encrypted',
            'retirement_distributions' => 'encrypted',
            'bank_deposit_total' => 'encrypted',
            // Deduction signals — encrypted cent strings
            'mortgage_interest' => 'encrypted',
            'property_tax_paid' => 'encrypted',
            'student_loan_interest' => 'encrypted',
            'charitable_contributions' => 'encrypted',
            // Retirement contribution signals — encrypted cent strings
            'traditional_401k_ytd' => 'encrypted',
            'roth_401k_ytd' => 'encrypted',
            'ira_ytd' => 'encrypted',
            'hsa_ytd' => 'encrypted',
            // Non-sensitive flags
            'has_home_office' => 'boolean',
            'has_self_employment' => 'boolean',
            'has_hsa_eligible_plan' => 'boolean',
            'has_ira' => 'boolean',
            // Metadata
            'data_sources' => 'array',
            'built_at' => 'datetime',
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * CTX-04: Return the map of "answerable" fields so Phase 11 interview
     * and detectors can skip questions for facts already known from the snapshot.
     *
     * Returns an array where each key is the skip-logic description and the
     * value is whether that fact is already answerable from this profile.
     *
     * ADDITIVE EXTENSION (INT-03, Phase 11-02): accepts an optional UserTaxFact proxy
     * to merge confirmed durable facts into the answerable map. Unconfirmed
     * document_extraction proposals are EXCLUDED (confirm gate — D3/Decision 4).
     * If no proxy is passed, falls back to the original 9-key base map.
     *
     * @param  UserTaxFact|null  $factsProxy  A (possibly empty) UserTaxFact instance used
     *                                        to call currentFactKeys($this->user_id).
     *                                        Pass null (default) to use base map only.
     * @return array<string, bool>
     */
    public function answerableFields(?UserTaxFact $factsProxy = null): array
    {
        $base = [
            'filing_status' => $this->filing_status !== null,
            'has_hsa_eligible_plan' => $this->has_hsa_eligible_plan === true,
            'has_ira' => $this->has_ira === true,
            'ira_type' => $this->ira_type !== null,
            'has_home_office' => $this->has_home_office === true,
            'has_self_employment' => $this->has_self_employment === true,
            'has_401k_contributions' => $this->traditional_401k_ytd !== null && (int) $this->traditional_401k_ytd > 0,
            'has_hsa_contributions' => $this->hsa_ytd !== null && (int) $this->hsa_ytd > 0,
            'employment_type' => $this->employment_type !== null,
        ];

        // Merge confirmed durable facts from UserTaxFact store.
        // currentFactKeys() EXCLUDES unconfirmed document_extraction proposals (confirm gate).
        // This is an additive merge — no existing key is ever removed or overwritten to false.
        if ($factsProxy !== null && $this->user_id !== null) {
            foreach (UserTaxFact::currentFactKeys($this->user_id) as $factKey) {
                $base[$factKey] = true;
            }
        }

        return $base;
    }

    // ─── Float accessors for encrypted cent fields ───────────────────────────
    // Use these for arithmetic; (int) cast gives cents, / 100 gives dollars.

    public function getW2WagesCentsAttribute(): int
    {
        return $this->w2_wages !== null ? (int) $this->w2_wages : 0;
    }

    public function getSelfEmploymentIncomeCentsAttribute(): int
    {
        return $this->self_employment_income !== null ? (int) $this->self_employment_income : 0;
    }

    public function getBankDepositTotalCentsAttribute(): int
    {
        return $this->bank_deposit_total !== null ? (int) $this->bank_deposit_total : 0;
    }
}
