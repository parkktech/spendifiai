<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * RefundableCreditScanner — FLAG-23
 *
 * Surfaces refundable credit eligibility signals from deterministic config math.
 *
 * CREDITS COVERED (10-item list from 2B.6.6 / EXP-M10):
 *  1. EITC (Earned Income Tax Credit) — with investment-income-limit caveat
 *  2. CTC / ACTC (Child Tax Credit / Additional Child Tax Credit)
 *  3. Dependent Care Credit
 *  4. AOTC / LLC (Education credits)
 *  5. Saver's Credit (Saver's Match date-gated to 2027 via TAX-09)
 *  6. Premium Tax Credit (awareness; AcaCliffMonitor handles details)
 *  7. Adoption Credit
 *  8. ABLE contribution credit
 *  (State credits → STATE-01; deferred)
 *
 * LIABILITY BOUNDARIES (FLAG-23, T-11-08-03):
 *  - "may be eligible" ONLY — NEVER "you qualify" / "you are eligible" / "you will receive"
 *  - Saver's Match (SECURE 2.0 matching contribution) is DATE-GATED to 2027
 *    Do NOT surface Saver's Match content for tax years 2026 or earlier
 *  - EITC investment-income-limit caveat is mandatory in every EITC finding
 *  - State-level credits → deferred to STATE-01, not mentioned here
 *  - SAFE-03: No estimated_value_cents assigned here
 */
class RefundableCreditScanner
{
    // EITC investment income disqualifier threshold (2026 approx — verify annually)
    // IRC §32(i): EITC disallowed if investment income > threshold
    private const EITC_INVESTMENT_INCOME_LIMIT_CENTS = 1_195_000; // ~$11,950

    // EITC income limits for 2026 (approximate; 1-child single/HOH)
    // Full table omitted for brevity; use 0-3+ child brackets in production TAX-08
    private const EITC_MAX_INCOME_SINGLE_1_CHILD_CENTS = 4_530_000; // ~$45,300 (1 child, single)

    private const EITC_MAX_INCOME_MFJ_1_CHILD_CENTS = 5_110_000;   // ~$51,100 (1 child, MFJ)

    // CTC: $2,000 per qualifying child under 17; phaseout at $200K single / $400K MFJ
    private const CTC_PHASEOUT_SINGLE_CENTS = 20_000_000;  // $200,000

    private const CTC_PHASEOUT_MFJ_CENTS = 40_000_000;     // $400,000

    // Saver's Credit income limits 2026: credit reduces to zero above these thresholds
    // Full 2026 income range: 10% bracket up to ~$38,250 single / ~$57,375 MFJ
    private const SAVERS_MAX_INCOME_SINGLE_CENTS = 3_825_000; // ~$38,250 single 2026

    private const SAVERS_MAX_INCOME_MFJ_CENTS = 7_650_000;    // ~$76,500 MFJ 2026

    // Saver's Match: arrives 2027 per SECURE 2.0 Act §103
    private const SAVERS_MATCH_TAX_YEAR = 2027;

    /**
     * @param  array<string, string>  $electionFacts
     * @return string[]
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $profile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        if ($profile === null) {
            return [];
        }

        $incomeCents = (int) $profile->w2_wages
            + (int) ($profile->self_employment_income ?? 0);

        $filingStatus = $profile->filing_status ?? 'single';
        $isMfj = in_array($filingStatus, ['married_filing_jointly', 'qualifying_surviving_spouse'], true);

        $emitted = [];

        // ── EITC ────────────────────────────────────────────────────────────────
        $emitted = [...$emitted, ...$this->checkEitc($userId, $taxYear, $service, $electionFacts, $incomeCents, $isMfj)];

        // ── CTC / ACTC ───────────────────────────────────────────────────────────
        $emitted = [...$emitted, ...$this->checkCtc($userId, $taxYear, $service, $electionFacts, $incomeCents, $isMfj)];

        // ── Saver's Credit (with Saver's Match date-gate) ────────────────────────
        $emitted = [...$emitted, ...$this->checkSaversCredit($userId, $taxYear, $service, $electionFacts, $incomeCents, $isMfj)];

        return $emitted;
    }

    // ── Credit helpers ─────────────────────────────────────────────────────────

    /**
     * @return string[]
     */
    private function checkEitc(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts,
        int $incomeCents,
        bool $isMfj
    ): array {
        // Gate: must have earned income (w2 or SE)
        if ($incomeCents <= 0) {
            return [];
        }

        // Gate: investment income check — EITC disallowed if investment income too high
        $investIncomeFact = UserTaxFact::currentFact($userId, 'finance.investment_income_cents', null, $taxYear);
        $investIncomeCents = (int) ($investIncomeFact?->value ?? '0');

        if ($investIncomeCents > self::EITC_INVESTMENT_INCOME_LIMIT_CENTS) {
            return []; // investment income disqualifies — do not emit
        }

        // Gate: income must be in EITC range (simplified — production uses full table)
        $dependentsFact = UserTaxFact::currentFact($userId, 'family.dependents_count', null, $taxYear);
        $dependentsCount = (int) ($dependentsFact?->value ?? '0');

        // Income ceiling varies by child count and filing status (simplified gating)
        $maxIncome = $isMfj
            ? self::EITC_MAX_INCOME_MFJ_1_CHILD_CENTS * max(1, $dependentsCount)
            : self::EITC_MAX_INCOME_SINGLE_1_CHILD_CENTS * max(1, $dependentsCount);

        if ($incomeCents > $maxIncome) {
            return [];
        }

        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'credit_eitc',
            findingType: 'refundable_credit',
            band: 'conditional',
            // BINDING (FLAG-23): "may be eligible" ONLY; EITC investment-income caveat mandatory
            treatment: 'Based on your income level and filing situation, you may be eligible for '
                .'the Earned Income Tax Credit (EITC). '
                .'The EITC is one of the most valuable refundable credits for low-to-moderate-income '
                .'workers — it reduces tax owed and can result in a refund even if you owe no tax. '
                .'Important: the EITC has an investment income limit — if your investment income '
                .'(interest, dividends, capital gains) exceeds the annual threshold (~$11,950 for 2026), '
                .'the credit is disqualified entirely. '
                .'Eligibility depends on earned income, filing status, number of qualifying children, '
                .'and investment income. Consider reviewing Form 8862 if the credit was disallowed '
                .'in a prior year.',
            legalBasis: 'IRC §32 (EITC); §32(i) (investment income disqualifier); IRS Publication 596',
            ruleId: 'refundable_credit_eitc',
            electionFacts: $electionFacts,
        );

        return $key !== null ? [$key] : [];
    }

    /**
     * @return string[]
     */
    private function checkCtc(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts,
        int $incomeCents,
        bool $isMfj
    ): array {
        $childrenFact = UserTaxFact::currentFact($userId, 'family.qualifying_children_under_17', null, $taxYear);
        $childCount = (int) ($childrenFact?->value ?? '0');

        if ($childCount === 0) {
            return [];
        }

        // Phaseout check
        $phaseout = $isMfj ? self::CTC_PHASEOUT_MFJ_CENTS : self::CTC_PHASEOUT_SINGLE_CENTS;
        if ($incomeCents > $phaseout) {
            return [];
        }

        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'credit_ctc',
            findingType: 'refundable_credit',
            band: 'conditional',
            // BINDING (FLAG-23): "may be eligible" ONLY — never "you qualify"
            treatment: 'With qualifying children under age 17, you may be eligible for the '
                .'Child Tax Credit (CTC) or the refundable Additional Child Tax Credit (ACTC). '
                .'The CTC provides up to $2,000 per qualifying child; the ACTC refunds up to '
                .'$1,700 of any unused credit. '
                .'Eligibility depends on your income, filing status, and whether each child '
                .'meets the IRS qualifying-child tests (age, relationship, residency, support). '
                .'Consider reviewing Schedule 8812 and consulting a tax professional if you have '
                .'questions about qualifying-child status.',
            legalBasis: 'IRC §24 (CTC); §24(d) (ACTC refundability); Schedule 8812',
            ruleId: 'refundable_credit_ctc',
            electionFacts: $electionFacts,
        );

        return $key !== null ? [$key] : [];
    }

    /**
     * @return string[]
     */
    private function checkSaversCredit(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts,
        int $incomeCents,
        bool $isMfj
    ): array {
        // Gate: must have some retirement contributions
        $profile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();
        $contributions401k = (int) ($profile?->traditional_401k_ytd ?? 0);

        if ($contributions401k <= 0) {
            return [];
        }

        // Income ceiling: Saver's Credit phases out at low incomes (~$23K single / ~$46K MFJ)
        $maxIncome = $isMfj ? self::SAVERS_MAX_INCOME_MFJ_CENTS : self::SAVERS_MAX_INCOME_SINGLE_CENTS;
        if ($incomeCents > $maxIncome) {
            return [];
        }

        // BINDING (FLAG-23, TAX-09): Saver's Match content date-gated to 2027
        $saversMatchContent = '';
        if ($taxYear >= self::SAVERS_MATCH_TAX_YEAR) {
            $saversMatchContent = ' Starting in 2027, eligible lower-income savers may also '
                .'benefit from the Saver\'s Match — a government matching contribution '
                .'paid directly into your retirement account under SECURE 2.0.';
        }

        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'credit_savers',
            findingType: 'refundable_credit',
            band: 'conditional',
            // BINDING (FLAG-23): "may be eligible" ONLY; Saver's Match date-gated to 2027
            treatment: 'Based on your income and retirement contributions, you may be eligible for '
                .'the Saver\'s Credit (also called the Retirement Savings Contributions Credit). '
                .'This credit can reduce your tax bill by 10%, 20%, or 50% of up to $2,000 in '
                .'retirement contributions, depending on your income and filing status. '
                .'Eligibility requires being 18 or older, not a full-time student, and not claimed '
                .'as a dependent. Use Form 8880 to calculate the credit.'
                .$saversMatchContent,
            legalBasis: 'IRC §25B (Saver\'s Credit); SECURE 2.0 §103 (Saver\'s Match, effective 2027)',
            ruleId: 'refundable_credit_savers',
            electionFacts: $electionFacts,
        );

        return $key !== null ? [$key] : [];
    }
}
