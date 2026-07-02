<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * W2BenefitArbitrageDetector — FLAG-20
 *
 * For W-2 employees, the biggest opportunity is usually NOT deductions — route users
 * toward every available pre-tax/tax-favored employer benefit (13-item list, EXP M2).
 *
 * Surfaces "failed to elect" findings from interview answers and (when available)
 * extracted paystub/benefits facts.
 *
 * LIABILITY BOUNDARIES:
 *  - ESPP: participation-education only — ban "free money" / "guaranteed return" language
 *    and never model qualifying vs disqualifying disposition (RIA boundary)
 *  - NQDC: education with mandatory employer-credit-risk warning
 *  - Mega-backdoor: "if your plan allows" framing mandatory (plan-feature dependent)
 *  - No dollar amounts computed; figures come from config/engine
 *  - SAFE-03: No estimated_value_cents assigned here
 *
 * ONLY fires for users with W-2 income (w2_wages > 0). Pure SE users are excluded.
 */
class W2BenefitArbitrageDetector
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

        // Only fires for W-2 income holders
        if ($profile === null || (int) $profile->w2_wages <= 0) {
            return [];
        }

        $emitted = [];

        // ── HSA gap ─────────────────────────────────────────────────────────────
        $hasHsaPlan = UserTaxFact::currentFact($userId, 'employer.has_hsa_plan', null, $taxYear);
        $hsaEnrolled = UserTaxFact::currentFact($userId, 'employer.hsa_enrolled', null, $taxYear);

        if ($hasHsaPlan?->value === 'true' && $hsaEnrolled?->value === 'false') {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'w2_benefit_hsa_gap',
                findingType: 'benefit_gap',
                band: 'auto',
                treatment: 'Your employer appears to offer an HSA-eligible health plan, but your '
                    .'records do not show an active HSA contribution. '
                    .'Are you enrolled in a high-deductible health plan (HDHP)? '
                    .'If so, an HSA allows pre-tax contributions that grow tax-free and can be '
                    .'used for qualified medical expenses tax-free — a triple tax advantage. '
                    .'Consider enrolling during your next open enrollment window.',
                legalBasis: 'IRC §223 (HSA); IRC §106 (employer HSA contributions excluded from income)',
                ruleId: 'hsa_contribution_gap',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── ESPP non-participation (participation education only — FLAG-20 ban) ──
        $hasEspp = UserTaxFact::currentFact($userId, 'employer.has_espp', null, $taxYear);
        $esppEnrolled = UserTaxFact::currentFact($userId, 'employer.espp_enrolled', null, $taxYear);

        if ($hasEspp?->value === 'true' && $esppEnrolled?->value === 'false') {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'w2_benefit_espp_participation',
                findingType: 'benefit_gap',
                band: 'conditional',
                // BINDING (FLAG-20): participation education ONLY
                // Must NOT contain: "free money", "guaranteed return", disposition modeling
                treatment: 'Your employer may offer an Employee Stock Purchase Plan (ESPP). '
                    .'ESPPs typically allow employees to purchase company stock at a discount '
                    .'(commonly 15%) during offering periods. '
                    .'Participation is voluntary and involves investment risk — company stock '
                    .'concentrates exposure to your employer. '
                    .'The tax treatment depends on how and when shares are sold; consider '
                    .'reviewing the plan details and discussing tax implications with a professional '
                    .'before participating.',
                legalBasis: 'IRC §423 (qualified ESPP); IRC §83 (compensation income on disqualifying dispositions)',
                ruleId: 'deductible_saas_sweep',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── NQDC education with mandatory employer-credit-risk warning ───────────
        $hasNqdc = UserTaxFact::currentFact($userId, 'employer.has_nqdc', null, $taxYear);

        if ($hasNqdc?->value === 'true') {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'w2_benefit_nqdc_education',
                findingType: 'benefit_education',
                band: 'specialist',
                // BINDING (FLAG-20): employer-credit-risk warning is mandatory
                treatment: 'Your employer offers a Non-Qualified Deferred Compensation (NQDC) plan. '
                    .'NQDC plans allow deferral of current income to a future tax year, '
                    .'which can be useful if you expect to be in a lower tax bracket at distribution. '
                    .'Important: NQDC plan assets are not separately held — they remain on the '
                    .'employer\'s balance sheet and are subject to the employer\'s credit risk. '
                    .'If your employer becomes insolvent, plan balances could be lost. '
                    .'Review the plan documents and consider this credit risk carefully before deferring.',
                legalBasis: 'IRC §409A (NQDC requirements); IRC §409A(a) (distribution restrictions)',
                ruleId: 'deductible_saas_sweep',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Mega-backdoor Roth ("if your plan allows" gate — FLAG-20) ────────────
        // Signal: maxing employee deferral + employer allows after-tax 401k contributions
        $allowsAfterTax = UserTaxFact::currentFact($userId, 'employer.allows_after_tax_401k', null, $taxYear);
        $is401kMax = (int) ($profile->traditional_401k_ytd ?? 0) >= 2_300_000; // ~$23K employee max

        if ($allowsAfterTax?->value === 'true' && $is401kMax) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'w2_benefit_mega_backdoor',
                findingType: 'retirement',
                band: 'conditional',
                // BINDING (FLAG-20, INTEGRATION-MAP): "if your plan allows" framing mandatory
                treatment: 'You may be eligible for a "mega backdoor Roth" — '
                    .'if your plan allows after-tax (non-Roth) 401(k) contributions, '
                    .'you could contribute beyond the standard employee deferral limit '
                    .'up to the total IRS 415(c) limit. '
                    .'Some plans also allow an in-plan Roth conversion of those after-tax contributions — '
                    .'but this feature is plan-specific and not available in every plan. '
                    .'Check your plan documents or contact your plan administrator to confirm '
                    .'whether after-tax contributions and in-plan Roth conversions are permitted.',
                legalBasis: 'IRC §415(c) (total DC limit); IRC §402(g) (employee deferral limit); IRS Notice 2014-54',
                ruleId: 'employer_match_gap',
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }
}
