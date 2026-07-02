<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * ReimbursementRoutingRule — FLAG-25
 *
 * Implements the "Reimbursement Beats Deduction" routing rule from EXP M5.
 *
 * ROUTING LOGIC:
 *  - W-2 employee + detected work expenses → reframe as accountable-plan reimbursement ask
 *    ("consider asking your employer for an accountable-plan reimbursement")
 *    NOT "deduct this" framing
 *  - Surviving above-the-line categories (Congress-preserved statutory deductions) still
 *    route as deduction education:
 *    1. Impairment-related work expenses (IRC §67(b)(6))
 *    2. Armed forces reservists (IRC §62(a)(2)(E)) — travel > 100 miles
 *    3. Performing artists (IRC §62(a)(2)(B)) — qualifying income limits
 *    4. Fee-basis state/local officials (IRC §62(a)(2)(C))
 *
 * FUTURE: Employer Reimbursement Request Packet generator is deferred (PKT-01).
 *
 * LIABILITY BOUNDARIES:
 *  - Never say "deduct this" for W-2 employee expenses (main pool)
 *  - Accountable-plan reframe is a question/suggestion, not a directive
 *  - SAFE-03: No estimated_value_cents assigned here
 */
class ReimbursementRoutingRule
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
        $profile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        // Only W-2 employees
        if ($profile === null || (int) $profile->w2_wages <= 0) {
            return [];
        }

        $emitted = [];

        // ── Surviving category: Reservist above-the-line deduction ───────────────
        $isReservist = UserTaxFact::currentFact($userId, 'employment.is_reservist', null, $taxYear);

        if ($isReservist?->value === 'true') {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'reimbursement_survivor_reservist',
                findingType: 'deduction_education',
                band: 'conditional',
                // BINDING (FLAG-25): survivor category → deduction education (not reimbursement ask)
                treatment: 'As a member of the Armed Forces Reserve who travels more than 100 miles '
                    .'from home to perform reserve duties, your unreimbursed travel expenses '
                    .'(transportation, meals, lodging) may be deductible as an above-the-line '
                    .'adjustment on Schedule 1 — available even if you do not itemize. '
                    .'This reservist deduction is one of a small number of employee business '
                    .'expense deductions preserved after the 2017 tax reform. '
                    .'Keep receipts and a log of qualifying travel dates and distances.',
                legalBasis: 'IRC §62(a)(2)(E) (reservist above-the-line deduction); IRS Publication 463',
                ruleId: 'deductible_saas_sweep',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Main pool: W-2 employee work expenses → reimbursement routing ────────
        $hasWorkExpenses = UserTaxFact::currentFact($userId, 'employer.has_w2_work_expenses', null, $taxYear);

        if ($hasWorkExpenses?->value === 'true') {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'reimbursement_routing_w2',
                findingType: 'routing_rule',
                band: 'conditional',
                // BINDING (FLAG-25, M5): "consider asking your employer" — NOT "deduct this"
                treatment: 'Work-related expenses you pay as a W-2 employee (tools, mileage, '
                    .'home internet, phone, continuing education, uniforms, travel, client meals, '
                    .'professional dues) are generally NOT deductible on your personal return '
                    .'under current federal law. '
                    .'However, if your employer has an accountable plan, you may be able to submit '
                    .'these expenses for tax-free reimbursement. '
                    .'Consider asking your employer or HR department whether an accountable-plan '
                    .'reimbursement arrangement is available — reimbursement is typically more '
                    .'valuable than a personal deduction (if one were available).',
                legalBasis: 'IRC §62(a)(2)(A) (unreimbursed employee expenses suspended 2018-2025); '
                    .'§62(c) (accountable plan requirements); Reg. §1.62-2',
                ruleId: 'deductible_saas_sweep',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }
}
