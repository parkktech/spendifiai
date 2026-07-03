<?php

namespace App\Services\AI;

use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use App\Models\UserTaxFact;

/**
 * PaystubFactExtractorService — DOC-07 / D4 Proposal-Creation Bridge.
 *
 * Reads fields from a TaxDocument's extracted_data (already stored by
 * TaxDocumentExtractorService) and creates per-field UserTaxFact PROPOSALS
 * via UserTaxFact::recordFact(sourceType: 'document_extraction').
 *
 * D4 CONTRACT (binding owner decision — do NOT change):
 *   - source_type='document_extraction' ensures is_current=false on every row
 *   - Proposals NEVER supersede user_edit / interview_answer / profile_field facts
 *   - Only DurableFactsController::confirm() can promote a proposal to current
 *   - No Claude / HTTP calls — purely reads extracted_data and writes fact rows
 *
 * PITFALL 2 (runtime shape): extracted_data stores the nested-with-confidence
 * shape { "fields": { "field_name": { "value": "...", "confidence": 0.9 } } }.
 * Always read fields as:
 *   $document->extracted_data['fields'][$fieldName]['value'] ?? null
 *
 * PITFALL 3 (source_type): MUST be 'document_extraction' so recordFact() writes
 * is_current=false. Never use 'interview_answer' or 'user_edit' here.
 *
 * PITFALL 7 (boolean fields): BenefitsGuide booleans come from Claude as the
 * string "true" or "false". Store as 'yes' / 'no' to match interview convention.
 * The original bool string is preserved in metadata['original_bool'] for UI display.
 */
class PaystubFactExtractorService
{
    /**
     * PayStub extracted field → UserTaxFact fact_key / label / volatility mapping.
     *
     * Money fields store integer-cents-as-string in the fact value
     * (e.g. '150000' = $1,500.00). The raw dollar string from extracted_data
     * is converted via dollarsToCents() before storage.
     *
     * @var array<string, array{fact_key: string, label: string, volatility: string, money: bool}>
     */
    protected const PAYSTUB_FACT_MAP = [
        // ── Stage-C identity-plane fields (14-11 additive — D4 gate applies) ──────────
        // employee_name already present in PAY_STUB_FIELDS; proposes identity.employee_name fact
        // for the name-conformance plane in ProfileConformanceDetector.
        'employee_name' => [
            'fact_key' => 'identity.employee_name',
            'label' => 'Employee legal name (paystub)',
            'volatility' => 'stable',
            'money' => false,
        ],
        'employee_address' => [
            'fact_key' => 'identity.employee_address',
            'label' => 'Employee address (paystub)',
            'volatility' => 'annual',
            'money' => false,
        ],
        // W-4 evidence extracted directly from the paystub — feeds Plane 1 (filing status)
        // and the new Plane 5 (dependents) conformance planes without any added AI call.
        'w4_filing_status' => [
            'fact_key' => 'w4.filing_status',
            'label' => 'W-4 filing status (paystub evidence)',
            'volatility' => 'annual',
            'money' => false,
        ],
        // Fix-B: Modern W-4 Step 3 is an annual dollar credit amount, NOT a dependent count.
        // The old w4_dependents_claimed field mis-mapped a dollar figure into w4.dependents_claimed
        // (a count), producing "3200 dependents". Renamed field + new money fact key.
        // w4.dependents_claimed is a COUNT fact — its only sources are profile/interview/actual W-4 doc.
        'w4_step3_credits' => [
            'fact_key' => 'w4.step3_annual_credits_cents',
            'label' => 'W-4 Step 3 dependent credits',
            'volatility' => 'annual',
            'money' => true,
        ],
        'traditional_401k_deduction' => [
            'fact_key' => 'retirement.traditional_401k_ytd_cents',
            'label' => 'Traditional 401(k) contribution (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'roth_401k_deduction' => [
            'fact_key' => 'retirement.roth_401k_ytd_cents',
            'label' => 'Roth 401(k) contribution (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'hsa_deduction' => [
            'fact_key' => 'retirement.hsa_ytd_cents',
            'label' => 'HSA payroll deduction (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'fsa_deduction' => [
            'fact_key' => 'benefits.fsa_ytd_cents',
            'label' => 'FSA payroll deduction (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        // §A.5.4 additive per-period paystub maps (money, annual, tax_year-scoped).
        // These feed take_home withholding/gross derivations; pay-frequency derivation
        // stays in the RESOLVER (M7), NOT here.
        'federal_tax_withheld' => [
            'fact_key' => 'pay.federal_withholding_per_period_cents',
            'label' => 'Federal income tax withheld per paycheck (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'gross_pay' => [
            'fact_key' => 'pay.gross_per_period_cents',
            'label' => 'Gross pay per paycheck (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
    ];

    /**
     * RETIREMENT_STATEMENT extracted field → UserTaxFact mapping (§A.5.4, NEW).
     *
     * `ytd_contributions` is a CROSS-CHECK fact only — statement contributions are
     * ambiguous across account types and must NEVER be treated as the canonical
     * 401(k) YTD. All writes remain proposals (source_type='document_extraction').
     *
     * @var array<string, array{fact_key: string, label: string, volatility: string, money: bool}>
     */
    protected const RETIREMENT_STATEMENT_FACT_MAP = [
        'account_balance' => [
            'fact_key' => 'retirement.statement_balance_cents',
            'label' => 'Retirement account balance (statement)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'ytd_contributions' => [
            'fact_key' => 'retirement.statement_ytd_contributions_cents',
            'label' => 'Retirement YTD contributions (statement — cross-check only)',
            'volatility' => 'annual',
            'money' => true,
        ],
    ];

    /**
     * BenefitsGuide extracted field → UserTaxFact fact_key / label / volatility mapping.
     *
     * Boolean fields are stored as 'yes' / 'no' (interview convention).
     * String fields (like employer_match_formula) are stored as-is.
     *
     * @var array<string, array{fact_key: string, label: string, volatility: string, money: bool, bool_field: bool}>
     */
    protected const BENEFITS_FACT_MAP = [
        'has_401k' => [
            'fact_key' => 'employer.has_401k',
            'label' => 'Employer has 401(k) plan',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'employer_match_formula' => [
            'fact_key' => 'employer.match_formula',
            'label' => 'Employer match formula',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => false,
        ],
        'after_tax_401k_available' => [
            'fact_key' => 'employer.after_tax_401k_available',
            'label' => 'After-tax 401(k) available (mega backdoor gate)',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'in_plan_roth_conversion_available' => [
            'fact_key' => 'employer.in_plan_roth_conversion_available',
            'label' => 'In-plan Roth conversion available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'hdhp_hsa_available' => [
            'fact_key' => 'employer.hdhp_hsa_available',
            'label' => 'HDHP/HSA plan available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'fsa_available' => [
            'fact_key' => 'employer.fsa_available',
            'label' => 'FSA available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'dependent_care_fsa_available' => [
            'fact_key' => 'employer.dependent_care_fsa_available',
            'label' => 'Dependent care FSA available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'espp_available' => [
            'fact_key' => 'employer.espp_available',
            'label' => 'ESPP available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'espp_terms' => [
            'fact_key' => 'employer.espp_terms',
            'label' => 'ESPP terms',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => false,
        ],
        'nqdc_available' => [
            'fact_key' => 'employer.nqdc_available',
            'label' => 'Non-qualified deferred compensation available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'section_127_available' => [
            'fact_key' => 'employer.section_127_available',
            'label' => 'Section 127 education assistance available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'commuter_benefits_available' => [
            'fact_key' => 'employer.commuter_benefits_available',
            'label' => 'Commuter benefits available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'group_legal_available' => [
            'fact_key' => 'employer.group_legal_available',
            'label' => 'Group legal benefit available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'trump_account_available' => [
            'fact_key' => 'employer.trump_account_available',
            'label' => 'Trump account available',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => true,
        ],
        'employer_trump_account_contribution' => [
            'fact_key' => 'employer.trump_account_employer_contribution',
            'label' => 'Employer Trump account contribution',
            'volatility' => 'stable',
            'money' => false,
            'bool_field' => false,
        ],
    ];

    /**
     * Create UserTaxFact proposals from extracted PayStub or BenefitsGuide data.
     *
     * CRITICAL (D4): sourceType MUST be 'document_extraction' so recordFact()
     * writes is_current=false and never supersedes user-entered values.
     *
     * Returns the count of proposals created (0 if no mappable fields present).
     */
    public function proposeFacts(TaxDocument $document): int
    {
        // Select the correct field map for this document category
        $map = match ($document->category) {
            TaxDocumentCategory::PayStub => self::PAYSTUB_FACT_MAP,
            TaxDocumentCategory::BenefitsGuide => self::BENEFITS_FACT_MAP,
            TaxDocumentCategory::RetirementStatement => self::RETIREMENT_STATEMENT_FACT_MAP,
            default => [],
        };

        if (empty($map)) {
            return 0;
        }

        // PITFALL 2 (runtime shape): read via the nested-with-confidence path.
        // extracted_data is stored as { "fields": { ... }, "overall_confidence": 0.9 }
        $extractedData = $document->extracted_data ?? [];
        $fields = $extractedData['fields'] ?? [];

        $count = 0;

        foreach ($map as $fieldName => $factConfig) {
            // Defensive nested-with-fallback read (plan-check fix):
            // $fields[$fieldName] may be: { "value": "...", "confidence": 0.9 }  (nested)
            //                        or:  "..."  (flat string — legacy/inconsistent arm)
            // Try nested first, fall back to flat.
            $fieldData = $fields[$fieldName] ?? null;
            if ($fieldData === null) {
                continue;
            }

            if (is_array($fieldData)) {
                $rawValue = $fieldData['value'] ?? null;
                $confidence = (float) ($fieldData['confidence'] ?? 0.5);
            } else {
                $rawValue = $fieldData;
                $confidence = 0.5;   // No confidence available in flat shape
            }

            if ($rawValue === null || $rawValue === '') {
                continue;
            }

            // Build the stored value and metadata
            $isBool = $factConfig['bool_field'] ?? false;
            $isMoney = $factConfig['money'] ?? false;
            $metadata = [
                'confidence' => $confidence,
                'document_id' => $document->id,
            ];

            if ($isBool) {
                // PITFALL 7: store boolean fields as 'yes' / 'no' (interview convention).
                // Original string ('true'/'false') preserved in metadata for UI display.
                $metadata['original_bool'] = $rawValue;
                $storedValue = in_array(strtolower((string) $rawValue), ['true', '1', 'yes'], true)
                    ? 'yes'
                    : 'no';
            } elseif ($isMoney) {
                // Convert dollar float string to integer cents string
                $storedValue = (string) $this->dollarsToCents((string) $rawValue);
            } else {
                $storedValue = (string) $rawValue;
            }

            // PITFALL 3 (D4 gate): sourceType MUST be 'document_extraction'.
            // recordFact() will set is_current=false and will NOT supersede
            // any existing user_edit / interview_answer / profile_field fact.
            UserTaxFact::recordFact(
                userId: $document->user_id,
                factKey: $factConfig['fact_key'],
                value: $storedValue,
                sourceType: 'document_extraction',
                label: $factConfig['label'],
                volatility: $factConfig['volatility'],
                taxYear: $document->tax_year,
                sourceId: (string) $document->id,
                metadata: $metadata,
            );

            $count++;
        }

        return $count;
    }

    /**
     * Convert a dollar float string to integer cents.
     * Mirrors IncomeOptimizerDataAssemblerService::dollarsToCents() exactly.
     */
    protected function dollarsToCents(string $dollarString): int
    {
        return (int) round((float) $dollarString * 100);
    }
}
