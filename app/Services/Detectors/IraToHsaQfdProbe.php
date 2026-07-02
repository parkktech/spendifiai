<?php

namespace App\Services\Detectors;

use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * IraToHsaQfdProbe — FLAG-24
 *
 * Gates the IRA-to-HSA Qualified Funding Distribution (QFD) probe behind
 * all five prerequisite checks from EXP M7 (verbatim gate list).
 *
 * Prerequisites (all 5 REQUIRED):
 *  1. health.hsa_eligible = true
 *  2. retirement.has_ira_balance = true
 *  3. finance.is_cash_constrained = true
 *  4. health.has_medical_expenses = true (or health.wants_to_fund_hsa = true)
 *  5. health.qfd_previously_used = false (one-time lifetime limit)
 *
 * LIABILITY BOUNDARIES:
 *  - Testing-period caveat is MANDATORY in every emitted treatment
 *    Verbatim from EXP M7: "it does not create an extra deduction,
 *    and testing-period rules apply"
 *  - One-time lifetime limit: gate 5 (qfd_previously_used) is a hard stop
 *  - No dollar amounts computed (amounts from config/engine in future INT-07)
 *  - SAFE-01: no "you should transfer" / directive forms
 *  - SAFE-03: No estimated_value_cents assigned here
 */
class IraToHsaQfdProbe
{
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
        // ── Gate 1: HSA eligibility ──────────────────────────────────────────────
        $hsaEligible = UserTaxFact::currentFact($userId, 'health.hsa_eligible', null, $taxYear);
        if ($hsaEligible === null || $hsaEligible->value !== 'true') {
            return [];
        }

        // ── Gate 2: IRA balance exists ───────────────────────────────────────────
        $hasIra = UserTaxFact::currentFact($userId, 'retirement.has_ira_balance', null, $taxYear);
        if ($hasIra === null || $hasIra->value !== 'true') {
            return [];
        }

        // ── Gate 3: Cash constrained ─────────────────────────────────────────────
        $cashConstrained = UserTaxFact::currentFact($userId, 'finance.is_cash_constrained', null, $taxYear);
        if ($cashConstrained === null || $cashConstrained->value !== 'true') {
            return [];
        }

        // ── Gate 4: Medical expenses or funding intent ───────────────────────────
        $hasMedical = UserTaxFact::currentFact($userId, 'health.has_medical_expenses', null, $taxYear);
        $wantsToFund = UserTaxFact::currentFact($userId, 'health.wants_to_fund_hsa', null, $taxYear);
        if (($hasMedical?->value !== 'true') && ($wantsToFund?->value !== 'true')) {
            return [];
        }

        // ── Gate 5: QFD not previously used (one-time lifetime limit) ────────────
        $qfdUsed = UserTaxFact::currentFact($userId, 'health.qfd_previously_used', null, $taxYear);
        if ($qfdUsed?->value === 'true') {
            return [];
        }

        // All 5 gates passed — emit the finding
        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'ira_to_hsa_qfd',
            findingType: 'hsa_advanced',
            band: 'specialist',
            // BINDING (FLAG-24, EXP M7): testing-period caveat is mandatory
            // Verbatim required phrases: "does not create an extra deduction" + "testing-period"
            treatment: 'A one-time IRA-to-HSA qualified funding distribution may be possible. '
                .'This is a one-time, lifetime transfer of IRA funds directly into your HSA '
                .'(up to the annual HSA contribution limit) without incurring income tax or '
                .'the 10% early-distribution penalty. '
                .'Important: it does not create an extra deduction — the transfer simply moves '
                .'money from a pre-tax IRA into the triple-tax-advantaged HSA wrapper, '
                .'and testing-period rules apply (you must remain enrolled in a qualifying '
                .'high-deductible health plan for 12 months following the transfer). '
                .'If you leave an HDHP during the testing period, the transferred amount '
                .'becomes taxable and subject to a 10% penalty. '
                .'Consider discussing this strategy with a tax professional before proceeding.',
            legalBasis: 'IRC §408(d)(9) (qualified HSA funding distribution; one-time lifetime; testing period)',
            ruleId: null, // probe fires on prerequisite gates, bypasses rule registry
            electionFacts: $electionFacts,
        );

        return $key !== null ? [$key] : [];
    }
}
