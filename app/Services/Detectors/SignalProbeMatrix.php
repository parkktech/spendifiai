<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * SignalProbeMatrix — FLAG-17
 *
 * Implements the signal→question→strategy matrix from the tax-strategy playbook
 * (PB-v1 §10 table) as prerequisite-gated income-signal probes.
 *
 * Each probe fires ONLY when its verified prerequisite signal is present.
 * Probes emit educational findings with question/docs framing — never directives.
 *
 * LIABILITY BOUNDARIES (D10, D11, CONTEXT.md — all binding):
 *
 * D10 Entity reframe: the entity-analysis probe (net>$50K) surfaces the 60-month
 *   election-lock warning BEFORE any classification content and tops out at
 *   "commonly considered at this level" — NO recommendation form. (Blocked #16)
 *
 * D10 Income-drop: the Roth-conversion trigger keys off payroll/income signals
 *   ONLY, never asset values. (Blocked CR-2 distinguisher)
 *
 * D10 529/FEIE: federal parts ONLY; state deduction → STATE-01.
 *
 * D11 QBI: above-threshold → professional-review sentinel ONLY; NO W-2 wage /
 *   UBIA test implementation (v2.1 decision, owner review pending).
 *
 * Default ruling 2 (AMT/ISO): ships ONLY the safe one-liner
 *   "ISO exercises may have AMT implications — consider a professional review
 *    before exercising" — NO crossover modeling, NO AMT computation.
 *
 * Default ruling 10 (MFS): ships ONLY the ceiling line
 *   "MFS may be worth modeling with your preparer" — NO optimizer.
 *
 * Blocked items: no asset-location, TLH, sell-and-rebuy, direct-indexing,
 *   AMT crossover modeling, entity recommendation form.
 *
 * SAFE-03: No estimated_value_cents is assigned by this class.
 */
class SignalProbeMatrix
{
    /**
     * @param  array<string, string>  $electionFacts  Preloaded method-election facts
     * @return string[] Finding keys emitted
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        $profile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        // ── Probe 1: Deferral gap (payroll detected + no/low 401k contribution) ──
        // Signal: w2_wages present in snapshot
        // Question: "Does your employer offer a 401(k)? Current contribution %?"
        //
        // EMISSION-TIME FACT-GATE: if employer.has_401k is already confirmed in the
        // durable fact store (e.g. from a benefits guide or paystub extraction), the
        // core question "Does your employer offer a 401(k)?" is answered and this
        // probe must NOT emit. The template questions (employer.has_401k,
        // employer.contribution_pct) cover any remaining data gaps through their own
        // paths. This prevents the exact reported defect: probe emitting while
        // employer.has_401k=yes is confirmed.
        if ($this->hasPayrollIncome($profile)
            && UserTaxFact::currentFact($userId, 'employer.has_401k') === null
            && ! $this->isMaxing401k($userId, $taxYear)
        ) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'probe_deferral_gap',
                findingType: 'retirement',
                band: 'auto',
                // D18 anatomy: evidence lead only — no embedded question strings in treatment.
                // The question is surfaced by the interview system, not baked into copy.
                treatment: 'Your payroll deposits suggest you may have access to a 401(k) plan. '
                    .'If you are not yet maximizing your employer plan, increasing contributions '
                    .'may reduce your taxable income and capture any available employer match.',
                legalBasis: 'IRC §401(k); IRC §402(g) (deferral limits)',
                ruleId: 'employer_match_gap',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 2: SE income detected — Schedule C education suite ─────────────
        // Signal: self_employment_income present in snapshot
        // Unlocks: Schedule C suite, solo-401(k), entity tree (all separately gated)
        if ($this->hasSEIncome($profile)) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'probe_se_income',
                findingType: 'self_employment',
                band: 'conditional',
                treatment: 'Income patterns suggest self-employment or business activity. '
                    .'Self-employed individuals may have access to several tax strategies '
                    .'that employees do not — including the solo 401(k), self-employed health '
                    .'insurance deduction, home office deduction, and the §199A QBI deduction. '
                    .'Is this income from a business you own, freelancing, or consulting?',
                legalBasis: 'IRC §1401 (SE tax); IRC §401(k); IRC §162(l); IRC §280A; IRC §199A',
                ruleId: 'deductible_saas_sweep',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 3: Solo 401(k) — SE net > $10K threshold ───────────────────────
        // Signal: schedule_c_net_cents fact > $10K
        // BINDING: employee gate is mandatory (employees besides a spouse eliminate eligibility)
        $netCents = $this->getScheduleCNet($userId, $taxYear);
        if ($netCents !== null && $netCents > 1_000_000) { // > $10,000
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'probe_solo_401k',
                findingType: 'retirement',
                band: 'conditional',
                treatment: 'With self-employment net income above $10,000, a solo 401(k) may allow you '
                    .'to contribute both an employee deferral (up to $24,500 in 2026) and an employer '
                    .'contribution (up to 20% of net SE earnings). '
                    .'Note: solo 401(k) plans are generally only available if you have no '
                    .'full-time employees other than yourself and a spouse. '
                    .'Do you have any employees besides a spouse? '
                    .'Consider discussing this option with a tax professional.',
                legalBasis: 'IRC §401(k); IRC §415(c); IRS Publication 560',
                ruleId: 'deductible_saas_sweep',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 4: Entity analysis — SE net > $50K ─────────────────────────────
        // BINDING (D10): 60-month lock warning BEFORE any classification content
        // BINDING: tops out at "commonly considered at this level" — NO recommendation
        if ($netCents !== null && $netCents > 5_000_000) { // > $50,000
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'probe_entity_analysis',
                findingType: 'entity',
                band: 'conditional',
                // 60-month lock is the FIRST sentence — binding order requirement (D10)
                treatment: 'Important: entity classification changes are generally locked for a 60-month '
                    .'period (five years) once elected — this is a long-term commitment. '
                    .'At this profit level, an S-corp election or LLC structure is commonly considered, '
                    .'as distributions from an S-corp may reduce self-employment tax. '
                    .'The trade-offs include payroll filing requirements, reasonable compensation rules, '
                    .'and ongoing compliance costs. '
                    .'A tax professional can model whether the savings justify the overhead for your situation.',
                legalBasis: 'IRC §1361 (S-corp election); IRC §1402 (SE tax); IRC §3121 (FICA)',
                ruleId: 'deductible_saas_sweep',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 5: QBI above-threshold sentinel (D11) ──────────────────────────
        // BINDING: above QBI phaseout ($201,750 single / $403,500 MFJ 2026) →
        //   professional-review sentinel ONLY; NO W-2 wage/UBIA test (D11)
        if ($netCents !== null && $netCents > 20_175_000) { // > $201,750
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'probe_qbi_high_income',
                findingType: 'qbi',
                band: 'specialist',
                // BINDING D11: sentinel — no implementation of W-2/UBIA test
                treatment: 'At your income level, the §199A QBI deduction phaseout rules may apply. '
                    .'Above the phaseout threshold, W-2 wages paid and qualified property can affect '
                    .'the deduction — but computing this accurately requires your full tax picture. '
                    .'A tax professional can determine whether the deduction applies to you and '
                    .'whether any planning could optimize it.',
                legalBasis: 'IRC §199A; Rev. Proc. 2019-38 (QBI safe harbor)',
                ruleId: null, // sentinel — no rule registry validation
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 6: Income-drop Roth conversion ─────────────────────────────────
        // BINDING (D10 / CR-2 block): trigger uses income signals ONLY
        //   — never asset values, never portfolio drawdown
        $priorYearFact = UserTaxFact::currentFact($userId, 'income.prior_year_income_cents');
        if ($priorYearFact !== null && $profile !== null) {
            $currentIncomeCents = $this->estimateCurrentIncome($profile);
            $priorYearCents = (int) $priorYearFact->value;

            // Drop > 30% in income signals a potential Roth conversion window
            if ($priorYearCents > 0 && $currentIncomeCents < ($priorYearCents * 0.70)) {
                $key = $service->registerFinding(
                    userId: $userId,
                    taxYear: $taxYear,
                    findingKey: 'probe_income_drop_roth',
                    findingType: 'retirement',
                    band: 'conditional',
                    // BINDING: income-keyed trigger only — no asset/portfolio language (CR-2 block)
                    treatment: 'Your income this year appears lower than the prior year. '
                        .'A year with lower income may create an opportunity to convert '
                        .'pre-tax retirement balances to Roth at a lower income tax bracket. '
                        .'This observation is based on your income signals only — not on '
                        .'any investment performance or market conditions. '
                        .'Do you hold any pre-tax traditional IRA or 401(k) balances? '
                        .'Consider discussing a partial Roth conversion with a tax professional.',
                    legalBasis: 'IRC §408A (Roth IRA); IRC §402(c) (rollover rules)',
                    ruleId: null,
                    electionFacts: $electionFacts,
                );
                if ($key !== null) {
                    $emitted[] = $key;
                }
            }
        }

        // ── Probe 7: ISO/AMT — safe one-liner only (Default ruling 2) ────────────
        // BINDING: only the safe professional-review one-liner
        //   NO crossover modeling, NO AMT computation (DO NOT BUILD per default ruling 2)
        $isoFact = UserTaxFact::currentFact($userId, 'equity.has_iso');
        if ($isoFact !== null && $isoFact->value === 'true') {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'probe_iso_amt',
                findingType: 'equity',
                band: 'specialist',
                // BINDING: safe one-liner ONLY — no crossover modeling
                treatment: 'ISO exercises may have AMT implications — '
                    .'consider a professional review before exercising.',
                legalBasis: 'IRC §422 (ISO); IRC §55–57 (AMT)',
                ruleId: null,
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 8: 529 education — federal-parts only ───────────────────────────
        // BINDING: 529 content is federal-parts-only; state deductions → STATE-01
        $hasChildrenFact = UserTaxFact::currentFact($userId, 'family.has_children');
        $has529Fact = UserTaxFact::currentFact($userId, 'family.has_529_account');
        if ($hasChildrenFact !== null && $hasChildrenFact->value === 'true'
            && $has529Fact !== null && $has529Fact->value === 'false') {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'probe_529_education',
                findingType: 'education',
                band: 'conditional',
                // BINDING: federal parts only; no state deduction mention
                treatment: 'A 529 education savings plan can grow federal tax-free when used for '
                    .'qualified education expenses — including K-12 tuition (up to $20,000/year), '
                    .'college costs, and (for 15-year-old accounts) a lifetime $35,000 rollover to '
                    .'a Roth IRA for unused funds. Federal rules allow "superfunding" with up to '
                    .'five years of gift exclusions at once. '
                    .'State deduction rules vary and are outside this analysis — consult a tax '
                    .'professional for your state\'s treatment.',
                legalBasis: 'IRC §529; SECURE 2.0 (Roth rollover); IRC §2503(b) (gift exclusion)',
                ruleId: null,
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function hasPayrollIncome(?IncomeOptimizationProfile $profile): bool
    {
        if ($profile === null) {
            return false;
        }

        return $profile->w2_wages !== null && (int) $profile->w2_wages > 0;
    }

    private function hasSEIncome(?IncomeOptimizationProfile $profile): bool
    {
        if ($profile === null) {
            return false;
        }

        return $profile->has_self_employment === true
            && $profile->self_employment_income !== null
            && (int) $profile->self_employment_income > 0;
    }

    private function isMaxing401k(int $userId, int $taxYear): bool
    {
        // Deferral max 2026: $24,500 (standard) + catch-ups; use standard limit for gate
        $maxDeferralCents = 2_450_000; // $24,500

        // Check the combined YTD key (interview-sourced)
        $combinedFact = UserTaxFact::currentFact($userId, 'retirement.k401_contribution_ytd_cents', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'retirement.k401_contribution_ytd_cents');

        if ($combinedFact !== null && (int) $combinedFact->value >= $maxDeferralCents) {
            return true;
        }

        // Also check paystub-extracted split-key facts (traditional + roth).
        // PaystubFactExtractorService writes retirement.traditional_401k_ytd_cents
        // and retirement.roth_401k_ytd_cents — not k401_contribution_ytd_cents.
        // Combining them gives the total employee deferral for the year-to-date check.
        $tradFact = UserTaxFact::currentFact($userId, 'retirement.traditional_401k_ytd_cents', null, $taxYear);
        $rothFact = UserTaxFact::currentFact($userId, 'retirement.roth_401k_ytd_cents', null, $taxYear);

        if ($tradFact !== null || $rothFact !== null) {
            $totalYtd = (int) ($tradFact?->value ?? 0) + (int) ($rothFact?->value ?? 0);

            return $totalYtd >= $maxDeferralCents;
        }

        return false; // no deferral data → treat as not maxing
    }

    private function getScheduleCNet(int $userId, int $taxYear): ?int
    {
        $fact = UserTaxFact::currentFact($userId, 'business.schedule_c_net_cents', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'business.schedule_c_net_cents');

        return $fact !== null ? (int) $fact->value : null;
    }

    private function estimateCurrentIncome(IncomeOptimizationProfile $profile): int
    {
        return (int) ($profile->w2_wages ?? 0)
            + (int) ($profile->self_employment_income ?? 0)
            + (int) ($profile->retirement_distributions ?? 0);
    }
}
