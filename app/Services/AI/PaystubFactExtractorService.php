<?php

namespace App\Services\AI;

use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use Carbon\Carbon;

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
        // Bug-2 fix (2026-07-03): these are PER-PAYCHECK deduction amounts, not YTD.
        // Stored under retirement.*_per_period_cents so assembleBaseline can annualize
        // them correctly (×pay_periods_per_year) instead of dividing by year fraction.
        // The _ytd_cents keys remain for true YTD values entered via interview/user_edit.
        'traditional_401k_deduction' => [
            'fact_key' => 'retirement.traditional_401k_per_period_cents',
            'label' => 'Traditional 401(k) per-paycheck deduction (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'roth_401k_deduction' => [
            'fact_key' => 'retirement.roth_401k_per_period_cents',
            'label' => 'Roth 401(k) per-paycheck deduction (paystub)',
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
        // now also runs here (Item 3) after the period-date facts are written.
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
        // Banner-anchor fix (2026-07-06): knob-invariant per-paycheck deductions the
        // stub already shows. Without these the banner's take-home is federal-model-only
        // and overstates the real check (owner report: "$5.6k shown, check says less").
        // They are subtracted from BOTH sides of the banner — never from the delta.
        'state_tax_withheld' => [
            'fact_key' => 'pay.state_withholding_per_period_cents',
            'label' => 'State income tax withheld per paycheck (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'health_insurance_premium' => [
            'fact_key' => 'pay.health_premium_per_period_cents',
            'label' => 'Health insurance premium per paycheck (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'dental_vision_premium' => [
            'fact_key' => 'pay.dental_vision_premium_per_period_cents',
            'label' => 'Dental/vision premium per paycheck (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        // Bonus-grounding fix (2026-07-06): YTD evidence facts. ytd_gross feeds the
        // income.bonus_annual_cents derivation (proposeBonusAnnual); ytd_federal_tax
        // is context for withholding-vs-liability comparisons.
        'ytd_gross' => [
            'fact_key' => 'pay.ytd_gross_cents',
            'label' => 'Year-to-date gross pay (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        'ytd_federal_tax' => [
            'fact_key' => 'pay.ytd_federal_withheld_cents',
            'label' => 'Year-to-date federal tax withheld (paystub)',
            'volatility' => 'annual',
            'money' => true,
        ],
        // Item 3: map period dates into facts so frequency derivation can be persisted
        // as a confirmable proposal (not just an ephemeral resolver derivation).
        'pay_period_start' => [
            'fact_key' => 'pay.period_start',
            'label' => 'Pay period start date (paystub)',
            'volatility' => 'annual',
            'money' => false,
        ],
        'pay_period_end' => [
            'fact_key' => 'pay.period_end',
            'label' => 'Pay period end date (paystub)',
            'volatility' => 'annual',
            'money' => false,
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
        // Bonus-accounting fix (2026-07-06): employer YTD feeds the match derivation.
        'ytd_employer_contributions' => [
            'fact_key' => 'retirement.statement_ytd_employer_contributions_cents',
            'label' => 'Employer YTD contributions (retirement statement)',
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
            // Fix-2: removed "(mega backdoor gate)" — internal detector terminology.
            // The mega-backdoor explanation belongs in finding/context copy, not the label.
            'label' => 'After-tax 401(k) contributions allowed',
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
        // Total-rewards compensation dollars (owner gap 2026-07-06). Evidence facts
        // for reconciliation plus direct income proposals (equity, other bonus,
        // bonus target %). All confirmable — nothing silently overwrites.
        'total_annual_compensation' => [
            'fact_key' => 'comp.total_annual_cents',
            'label' => 'Total annual compensation (HR statement)',
            'volatility' => 'annual',
            'money' => true,
            'bool_field' => false,
        ],
        'total_cash_compensation' => [
            'fact_key' => 'comp.cash_annual_cents',
            'label' => 'Total cash compensation (HR statement)',
            'volatility' => 'annual',
            'money' => true,
            'bool_field' => false,
        ],
        'total_benefits_value' => [
            'fact_key' => 'comp.benefits_value_cents',
            'label' => 'Employer-paid benefits value (HR statement)',
            'volatility' => 'annual',
            'money' => true,
            'bool_field' => false,
        ],
        'stated_base_salary' => [
            'fact_key' => 'comp.stated_base_salary_cents',
            'label' => 'Base salary stated on HR statement',
            'volatility' => 'annual',
            'money' => true,
            'bool_field' => false,
        ],
        'bonus_target_pct' => [
            'fact_key' => 'income.bonus_structure_pct',
            'label' => 'Annual bonus (% of base salary)',
            'volatility' => 'annual',
            'money' => false,
            'bool_field' => false,
        ],
        'stock_annual_value' => [
            'fact_key' => 'income.equity_annual_cents',
            'label' => 'Stock / RSUs per year (value)',
            'volatility' => 'annual',
            'money' => true,
            'bool_field' => false,
        ],
        'other_bonus_amount' => [
            'fact_key' => 'income.other_bonus_annual_cents',
            'label' => 'Other yearly bonuses (beyond the main bonus)',
            'volatility' => 'annual',
            'money' => true,
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
            } elseif ($factConfig['fact_key'] === 'w4.filing_status') {
                // Fix 1 (choices-repair): W-4 filing status verbatim display strings → engine enums.
                // Claude extraction produces the W-4 form's verbatim label; the engine expects an enum.
                // Original display string preserved in metadata for UI humanization.
                $metadata['original_display'] = $rawValue;
                $storedValue = $this->normalizeW4FilingStatus((string) $rawValue);
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

        // ── Item 3 (a/b): Derive pay.frequency from period dates ─────────────────
        // Only for PayStub documents. Reads the just-written pay.period_start /
        // pay.period_end proposals (or falls back to raw extracted fields) and
        // derives the pay frequency using the canonical span table.
        // Emits pay.frequency as a document_extraction proposal (known tier; confirmable).
        // ── Bonus-accounting fix: derive the employer match from statement YTDs ──
        // employer_ytd / employee_ytd = the match rate actually being paid. Beats
        // asking the user ("I don't remember" was previously recorded as 0% match,
        // making the engine model ZERO employer match).
        if ($document->category === TaxDocumentCategory::RetirementStatement) {
            $this->proposeEmployerMatch($document, $fields, $count);
        }

        if ($document->category === TaxDocumentCategory::PayStub) {
            $frequencyProposalId = $this->proposeFrequency($document, $fields, $count);

            // ── Item 3 (c): Income reconciliation ────────────────────────────────
            // When gross_per_period and a derived frequency are both known, compute
            // the monthly base income and compare with the user's profile value.
            // If they differ >5%, write an income reconciliation proposal.
            if ($frequencyProposalId !== null) {
                $this->proposeIncomeReconciliation($document, $fields);

                // ── Bonus-grounding fix: derive annual variable comp ──────────────
                // Annualized YTD gross ≥20% above the base pace → propose
                // income.bonus_annual_cents so the engine prices taxes at real income.
                $this->proposeBonusAnnual($document, $fields);
            }
        }

        return $count;
    }

    /**
     * Derive pay frequency from pay period dates and write it as a proposal.
     *
     * Span table (Item 3 spec):
     *   6-8  days   → weekly
     *   13-15 days  → biweekly
     *   15-16 days anchored (1st-15th / 16th-EOM) → semimonthly
     *   27-31 days  → monthly
     *   else        → null (ambiguous; falls through to resolver ask)
     *
     * Note: semimonthly is tested BEFORE biweekly (anchor condition gates the
     * overlap at 15). This is order-sensitive in the match.
     *
     * Returns the ID of the written proposal, or null if derivation is ambiguous.
     */
    protected function proposeFrequency(TaxDocument $document, array $fields, int &$count): ?int
    {
        $startRaw = $this->fieldValue($fields, 'pay_period_start');
        $endRaw = $this->fieldValue($fields, 'pay_period_end');

        if ($startRaw === null || $endRaw === null) {
            return null;
        }

        try {
            $s = Carbon::parse((string) $startRaw);
            $e = Carbon::parse((string) $endRaw);
        } catch (\Throwable) {
            return null;
        }

        $span = $s->diffInDays($e) + 1; // inclusive span

        // Semimonthly anchor: starts on the 1st, ends on the 15th;
        // OR starts on the 16th, ends on the last day of the month.
        $anchored = ($s->day === 1 && $e->day === 15)
            || ($s->day === 16 && $e->isLastOfMonth());

        $frequency = match (true) {
            $span >= 6 && $span <= 8 => 'weekly',
            $span >= 27 && $span <= 31 => 'monthly',
            // Semimonthly BEFORE biweekly: both can be 15 days, but semimonthly requires anchor.
            $anchored && $span >= 15 && $span <= 16 => 'semimonthly',
            $span >= 13 && $span <= 15 => 'biweekly',
            default => null,
        };

        if ($frequency === null) {
            return null;
        }

        $proposal = UserTaxFact::recordFact(
            userId: $document->user_id,
            factKey: 'pay.frequency',
            value: $frequency,
            sourceType: 'document_extraction',
            label: 'Pay frequency (derived from period dates)',
            volatility: 'annual',
            taxYear: $document->tax_year,
            sourceId: (string) $document->id,
            metadata: [
                'confidence' => 0.92,
                'document_id' => $document->id,
                'derived_from' => 'pay_period_span',
                'span_days' => $span,
                'period_start' => $startRaw,
                'period_end' => $endRaw,
            ],
        );

        $count++;

        return $proposal->id;
    }

    /**
     * Income reconciliation: when gross_per_period + frequency are known,
     * write an income.paystub_monthly_base_cents proposal if the derived
     * monthly income differs >5% from the user's profile monthly_income.
     *
     * Also checks if YTD gross annualizes ≥20% above the base (variable comp
     * note) and records that in the proposal metadata.
     *
     * YTD annualization uses the pay_period_end date as the reference point.
     *
     * D18/D19 copy: proposal label uses human-readable copy without fact_key paths.
     */
    protected function proposeIncomeReconciliation(TaxDocument $document, array $fields): void
    {
        // Need gross_per_period to compute the monthly base
        $grossRaw = $this->fieldValue($fields, 'gross_pay');
        if ($grossRaw === null || (float) $grossRaw <= 0) {
            return;
        }

        $grossPerPeriod = (float) $grossRaw;

        // Load the frequency from the just-written pay.frequency proposal
        $frequencyFact = UserTaxFact::forUser($document->user_id)
            ->where('fact_key', 'pay.frequency')
            ->where('tax_year', $document->tax_year)
            ->where('is_current', false)
            ->where('source_type', 'document_extraction')
            ->whereNull('superseded_by_id')
            ->latest('id')
            ->first();

        if ($frequencyFact === null) {
            return;
        }

        $frequency = $frequencyFact->value;
        $periodsPerYear = match ($frequency) {
            'weekly' => 52,
            'biweekly' => 26,
            'semimonthly' => 24,
            'monthly' => 12,
            default => null,
        };

        if ($periodsPerYear === null) {
            return;
        }

        $derivedMonthlyBase = $grossPerPeriod * $periodsPerYear / 12;

        // Compare with profile monthly_income
        $profile = UserFinancialProfile::where('user_id', $document->user_id)->first();
        $profileMonthly = $profile ? (float) $profile->monthly_income : null;

        if ($profileMonthly === null || $profileMonthly <= 0) {
            // No profile to reconcile against — still write the derived fact so
            // the user can confirm it and update their profile.
            $profileMonthly = 0.0;
        }

        // >5% divergence check (or no profile baseline — also write the proposal)
        $diverges = $profileMonthly <= 0
            || abs($derivedMonthlyBase - $profileMonthly) / $profileMonthly > 0.05;

        if (! $diverges) {
            return;
        }

        // YTD variable-comp check: annualize YTD gross; if ≥20% above base, note variable pay.
        $ytdVariableCompNote = false;
        $ytdRaw = $this->fieldValue($fields, 'ytd_gross');
        $periodEndRaw = $this->fieldValue($fields, 'pay_period_end') ?? $this->fieldValue($fields, 'pay_date');
        if ($ytdRaw !== null && (float) $ytdRaw > 0 && $periodEndRaw !== null) {
            try {
                $periodEnd = Carbon::parse((string) $periodEndRaw);
                $taxYear = $document->tax_year ?? $periodEnd->year;
                $yearStart = Carbon::create($taxYear, 1, 1);
                $yearEnd = Carbon::create($taxYear, 12, 31);
                // Fraction of year elapsed through the period end date
                $totalDays = $yearStart->diffInDays($yearEnd) + 1;
                $elapsedDays = $yearStart->diffInDays($periodEnd) + 1;
                $elapsedFraction = $totalDays > 0 ? $elapsedDays / $totalDays : 0.0;

                if ($elapsedFraction > 0.05) {
                    $annualizedYtd = (float) $ytdRaw / $elapsedFraction;
                    $annualBase = $derivedMonthlyBase * 12;
                    // ≥20% above base → variable comp exists
                    if ($annualBase > 0 && ($annualizedYtd - $annualBase) / $annualBase >= 0.20) {
                        $ytdVariableCompNote = true;
                    }
                }
            } catch (\Throwable) {
                // Non-fatal — variable comp note is informational
            }
        }

        $derivedMonthlyCents = (int) round($derivedMonthlyBase * 100);

        UserTaxFact::recordFact(
            userId: $document->user_id,
            factKey: 'income.paystub_monthly_base_cents',
            value: (string) $derivedMonthlyCents,
            sourceType: 'document_extraction',
            label: 'Monthly base income (from pay stub)',
            volatility: 'annual',
            taxYear: $document->tax_year,
            sourceId: (string) $document->id,
            metadata: [
                'confidence' => 0.88,
                'document_id' => $document->id,
                'is_income_reconciliation' => true,
                'derived_from_gross_per_period' => $grossPerPeriod,
                'derived_from_frequency' => $frequency,
                'derived_from_periods_per_year' => $periodsPerYear,
                'derived_monthly_dollars' => round($derivedMonthlyBase, 2),
                'current_profile_monthly_dollars' => $profileMonthly,
                'ytd_variable_comp_note' => $ytdVariableCompNote,
            ],
        );
    }

    /**
     * Bonus-accounting fix (2026-07-06): derive the employer 401(k) match from a
     * retirement statement's YTD figures and write confirmable proposals.
     *
     * match rate = employer_ytd / employee_ytd (the rate actually being paid).
     * The threshold is not directly observable — but the ratio holding at the
     * user's CURRENT deferral percentage proves threshold ≥ that percentage, so
     * we propose the observed deferral pct as an "at least" threshold. Both are
     * document_extraction proposals (user can correct to the plan's real terms).
     *
     * Interview answers of "I don't remember" were previously recorded as 0% —
     * a statement-derived rate replaces modeled-zero match with observed reality.
     */
    protected function proposeEmployerMatch(TaxDocument $document, array $fields, int &$count): void
    {
        $employeeYtd = $this->fieldValue($fields, 'ytd_contributions');
        $employerYtd = $this->fieldValue($fields, 'ytd_employer_contributions');

        if ($employeeYtd === null || (float) $employeeYtd <= 0
            || $employerYtd === null || (float) $employerYtd <= 0) {
            return;
        }

        $ratioPct = (float) $employerYtd / (float) $employeeYtd * 100;

        // Plausible match range (10%–200%); outside it the figures likely include
        // profit-sharing or rollovers — don't propose garbage.
        if ($ratioPct < 10 || $ratioPct > 200) {
            return;
        }

        UserTaxFact::recordFact(
            userId: $document->user_id,
            factKey: 'employer.match_pct',
            value: (string) round($ratioPct),
            sourceType: 'document_extraction',
            label: 'Employer 401(k) match percentage',
            volatility: 'stable',
            taxYear: $document->tax_year,
            sourceId: (string) $document->id,
            metadata: [
                'confidence' => 0.85,
                'document_id' => $document->id,
                'derived_from' => 'statement_employer_vs_employee_ytd',
                'employee_ytd_dollars' => round((float) $employeeYtd, 2),
                'employer_ytd_dollars' => round((float) $employerYtd, 2),
            ],
        );
        $count++;

        // Threshold ≥ current deferral pct (observed: the full match rate held at
        // this deferral level). Derive the pct from confirmed paystub facts.
        $grossPP = UserTaxFact::currentFact($document->user_id, 'pay.gross_per_period_cents', null, $document->tax_year);
        $tradPP = UserTaxFact::currentFact($document->user_id, 'retirement.traditional_401k_per_period_cents', null, $document->tax_year);
        $rothPP = UserTaxFact::currentFact($document->user_id, 'retirement.roth_401k_per_period_cents', null, $document->tax_year);

        $grossCents = $grossPP !== null ? (int) $grossPP->value : 0;
        $deferralCents = ($tradPP !== null ? (int) $tradPP->value : 0)
            + ($rothPP !== null ? (int) $rothPP->value : 0);

        if ($grossCents > 0 && $deferralCents > 0) {
            $deferralPct = $deferralCents / $grossCents * 100;
            UserTaxFact::recordFact(
                userId: $document->user_id,
                factKey: 'employer.match_threshold_pct',
                value: (string) round($deferralPct),
                sourceType: 'document_extraction',
                label: 'Employer match threshold (% of pay matched)',
                volatility: 'stable',
                taxYear: $document->tax_year,
                sourceId: (string) $document->id,
                metadata: [
                    'confidence' => 0.70,
                    'document_id' => $document->id,
                    'derived_from' => 'match_held_at_observed_deferral',
                    'note' => 'At least this much — the full match rate held at your current deferral. Correct to your plan\'s stated threshold if you know it.',
                ],
            );
            $count++;
        }
    }

    /**
     * Bonus-grounding fix (2026-07-06): derive annual variable comp (bonus/commission/
     * RSU) from paystub YTD evidence and write it as a confirmable proposal.
     *
     * Derivation: annualized YTD gross (ytd ÷ elapsed-year-fraction at period end)
     * compared to the base pace (gross_per_period × periods_per_year). When the
     * annualized figure is ≥20% above base (same gate as the reconciliation
     * variable-comp note), the excess is proposed as income.bonus_annual_cents.
     *
     * The fact is OPTIONAL everywhere: it is not in any objective requirement chain,
     * so it never blocks readiness — it only sharpens the tax math once confirmed.
     * Annualizing mid-year YTD assumes the variable-comp pace continues; the proposal
     * shape lets the user correct the number if their bonus was a one-time payment.
     */
    protected function proposeBonusAnnual(TaxDocument $document, array $fields): void
    {
        $grossRaw = $this->fieldValue($fields, 'gross_pay');
        $ytdRaw = $this->fieldValue($fields, 'ytd_gross');
        $periodEndRaw = $this->fieldValue($fields, 'pay_period_end') ?? $this->fieldValue($fields, 'pay_date');

        if ($grossRaw === null || (float) $grossRaw <= 0
            || $ytdRaw === null || (float) $ytdRaw <= 0
            || $periodEndRaw === null) {
            return;
        }

        // Load the frequency from the just-written pay.frequency proposal (same
        // read pattern as proposeIncomeReconciliation).
        $frequencyFact = UserTaxFact::forUser($document->user_id)
            ->where('fact_key', 'pay.frequency')
            ->where('tax_year', $document->tax_year)
            ->where('is_current', false)
            ->where('source_type', 'document_extraction')
            ->whereNull('superseded_by_id')
            ->latest('id')
            ->first();

        $periodsPerYear = match ($frequencyFact?->value) {
            'weekly' => 52,
            'biweekly' => 26,
            'semimonthly' => 24,
            'monthly' => 12,
            default => null,
        };

        if ($periodsPerYear === null) {
            return;
        }

        try {
            $periodEnd = Carbon::parse((string) $periodEndRaw);
        } catch (\Throwable) {
            return;
        }

        $taxYear = $document->tax_year ?? $periodEnd->year;
        $yearStart = Carbon::create($taxYear, 1, 1);
        $yearEnd = Carbon::create($taxYear, 12, 31);
        $totalDays = $yearStart->diffInDays($yearEnd) + 1;
        $elapsedDays = $yearStart->diffInDays($periodEnd) + 1;
        $elapsedFraction = $totalDays > 0 ? $elapsedDays / $totalDays : 0.0;

        // Too early in the year to annualize meaningfully (same guard as reconciliation).
        if ($elapsedFraction <= 0.05) {
            return;
        }

        $annualBase = (float) $grossRaw * $periodsPerYear;
        $annualizedYtd = (float) $ytdRaw / $elapsedFraction;

        // ≥20% above base pace → variable comp is real, propose the excess.
        if ($annualBase <= 0 || ($annualizedYtd - $annualBase) / $annualBase < 0.20) {
            return;
        }

        $bonusCents = (int) round(($annualizedYtd - $annualBase) * 100);

        // Owner refinement (2026-07-06): also propose the bonus STRUCTURE as a percent
        // of base salary when the excess implies a plausible bonus (5–100%). The
        // structure is what most users actually know ("I get a 25% annual bonus") and
        // it recomputes with income, so it takes precedence over the dollar lump in
        // assembleBaseline once confirmed or corrected.
        $impliedPct = $annualBase > 0 ? ($annualizedYtd - $annualBase) / $annualBase * 100 : 0.0;
        if ($impliedPct >= 5 && $impliedPct <= 100) {
            UserTaxFact::recordFact(
                userId: $document->user_id,
                factKey: 'income.bonus_structure_pct',
                value: (string) round($impliedPct, 1),
                sourceType: 'document_extraction',
                label: 'Annual bonus (% of base salary)',
                volatility: 'annual',
                taxYear: $document->tax_year,
                sourceId: (string) $document->id,
                metadata: [
                    'confidence' => 0.70,
                    'document_id' => $document->id,
                    'derived_from' => 'ytd_excess_vs_base_pct',
                    'note' => 'Estimated from your pay history — correct this to your actual bonus percentage if it differs.',
                ],
            );
        }

        UserTaxFact::recordFact(
            userId: $document->user_id,
            factKey: 'income.bonus_annual_cents',
            value: (string) $bonusCents,
            sourceType: 'document_extraction',
            label: 'Bonus / variable pay this year (beyond base)',
            volatility: 'annual',
            taxYear: $document->tax_year,
            sourceId: (string) $document->id,
            metadata: [
                'confidence' => 0.80,
                'document_id' => $document->id,
                'derived_from' => 'ytd_gross_annualized_vs_base',
                'annualized_ytd_dollars' => round($annualizedYtd, 2),
                'annual_base_dollars' => round($annualBase, 2),
                'elapsed_year_fraction' => round($elapsedFraction, 4),
                'note' => 'Assumes the variable-pay pace continues for the full year — correct this if the bonus was a one-time payment.',
            ],
        );
    }

    /**
     * Nested-with-fallback field read (mirrors ScenarioFactResolverService::fieldValue).
     * Prefers $fields[name]['value'], falls back to flat $fields[name].
     */
    protected function fieldValue(array $fields, string $name): mixed
    {
        if (! array_key_exists($name, $fields)) {
            return null;
        }

        $field = $fields[$name];

        return is_array($field) ? ($field['value'] ?? null) : $field;
    }

    /**
     * Normalize a W-4 filing-status display string to the engine enum expected
     * by TaxRulesEngineService (Fix 1 — choices-repair).
     *
     * W-4 form verbatim labels (as printed on the form and returned by Claude):
     *   "Single or Married filing separately"        → single_or_mfs
     *   "Married filing jointly (or Qualifying widow(er))" → married_joint
     *   "Head of household"                          → head_of_household
     *
     * Already-valid enums pass through unchanged so the normalizer is idempotent.
     * Truly unknown values are stored as-is; the engine boundary (ScenarioFactResolverService
     * defensive layer) handles them with a clear error only for genuinely unknown inputs.
     *
     * @param  string  $raw  verbatim string from extraction
     * @return string engine enum value
     */
    public static function normalizeW4FilingStatus(string $raw): string
    {
        // Pass-through valid enums unchanged (idempotent).
        $valid = ['single_or_mfs', 'married_joint', 'head_of_household'];
        if (in_array($raw, $valid, true)) {
            return $raw;
        }

        $lower = strtolower(trim($raw));

        // W-4 Step 1(c) verbatim labels (2020+ form) — longest match first.
        if (str_contains($lower, 'married filing jointly')
            || str_contains($lower, 'qualifying widow')) {
            return 'married_joint';
        }
        if (str_contains($lower, 'head of household')) {
            return 'head_of_household';
        }
        if (str_contains($lower, 'single')
            || str_contains($lower, 'married filing separately')) {
            return 'single_or_mfs';
        }
        // Legacy / abbreviated forms
        if ($lower === 'married') {
            return 'married_joint';
        }
        if ($lower === 'single') {
            return 'single_or_mfs';
        }

        // Unknown: return as-is; the engine boundary logs a clear error.
        return $raw;
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
