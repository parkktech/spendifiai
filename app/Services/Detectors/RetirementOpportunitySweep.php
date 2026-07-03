<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;
use App\Services\TaxRulesEngineService;

/**
 * RetirementOpportunitySweep — Fix 2 (2026-07-02)
 *
 * Deterministic retirement-opportunity detector that emits EDUCATIONAL findings into the
 * "Retirement & Benefits" section from CONFIRMED durable facts (UserTaxFact.is_current=true).
 * Zero Claude calls. Zero hardcoded dollar amounts (all from config/TaxRulesEngineService).
 *
 * Four two-tier findings (known-facts → conditional-band):
 *
 *   RET-A  after_tax_401k_opportunity
 *          Trigger: employer.allows_after_tax_401k = 'true' AND total 401k YTD < employee deferral max.
 *          Band: conditional. Copy: "After-tax 401(k) contributions are available in your plan —
 *          a strategy sometimes called the mega backdoor Roth may be worth exploring."
 *
 *   RET-B  contribution_mix_review
 *          Trigger: both retirement.roth_401k_ytd_cents > 0 AND retirement.traditional_401k_ytd_cents > 0.
 *          Band: conditional. Copy: "You split contributions between Roth and Traditional —
 *          reviewing the mix against your marginal rate may be worthwhile."
 *
 *   RET-C  match_pace_gap
 *          Trigger: match formula facts + YTD-based annual pace below full-match threshold.
 *          Band: auto. Uses TaxRulesEngineService::matchCaptureCents() for the gap amount
 *          (SAFE-03 compliant: no inline arithmetic, engine-computed only).
 *
 *   RET-D  w4_step3_alignment
 *          Trigger: w4.step3_annual_credits_cents fact present AND family.qualifying_children_under_17
 *          fact present AND the claimed credits do not match expected per-child CTC amount.
 *          Band: conditional. Copy: "Your W-4 Step 3 credits may not fully reflect your
 *          qualifying dependents — reviewing your W-4 elections may be worthwhile."
 *
 * LIABILITY BOUNDARIES:
 *   - All copy uses "may / could / consider / worth exploring" (D18 educational framing).
 *   - No guaranteed savings amounts claimed.
 *   - No tax-filing advice; no specific plan recommendations.
 *   - "If your plan allows" framing on mega-backdoor references (INTEGRATION-MAP).
 *   - Complements (not duplicates) W2BenefitArbitrageDetector which fires when already at max;
 *     this detector fires when after-tax is available but not yet at max.
 *
 * SAFE-03 compliance:
 *   - estimated_value_cents is computed by TaxRulesEngineService::matchCaptureCents() only.
 *   - No dollar arithmetic performed inline in this class.
 *   - No other finding sets estimated_value_cents (only RET-C).
 */
class RetirementOpportunitySweep
{
    public function __construct(
        private readonly TaxRulesEngineService $engine,
    ) {}

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

        // Require a W-2 income profile — retirement benefit facts come from paystubs/W-2
        $profile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        if ($profile === null || (int) ($profile->w2_wages ?? 0) <= 0) {
            return $emitted;
        }

        // ─── RET-A: After-tax 401(k) opportunity (not-yet-maxed path) ──────────────
        // W2BenefitArbitrageDetector covers the already-maxed path; this covers the
        // "available but not at max" path — a broader educational signal.
        $allowsAfterTax = UserTaxFact::currentFact($userId, 'employer.allows_after_tax_401k', null, $taxYear);

        if ($allowsAfterTax?->value === 'true') {
            $rothYtd = (int) ($profile->roth_401k_ytd ?? 0);
            $tradYtd = (int) ($profile->traditional_401k_ytd ?? 0);
            $totalYtd = $rothYtd + $tradYtd;

            // Employee deferral limit from config (TAX-08) — stored in dollars, convert to cents
            $employeeDeferralLimitDollars = (int) config("tax-rules.{$taxYear}.401k.employee_deferral", 23500);
            $employeeDeferralLimitCents = $employeeDeferralLimitDollars * 100;
            // If YTD < max (or YTD not yet known), surface the after-tax opportunity
            // note: YTD=0 means we haven't extracted the data yet — still educational to surface
            $notYetMaxed = $totalYtd < $employeeDeferralLimitCents;

            if ($notYetMaxed) {
                $key = $service->registerFinding(
                    userId: $userId,
                    taxYear: $taxYear,
                    findingKey: 'retirement_after_tax_401k_opportunity',
                    findingType: 'retirement',
                    band: 'conditional',
                    // BINDING (INTEGRATION-MAP): "if your plan allows" mandatory; "mega backdoor" with hedges
                    treatment: 'Your employer\'s plan appears to allow after-tax (non-Roth) 401(k) contributions. '
                        .'If your plan allows, this may open up a strategy sometimes called the "mega backdoor Roth" — '
                        .'contributing after-tax dollars beyond the standard employee deferral limit '
                        .'and then converting them to Roth within the plan (if in-plan Roth conversions are also permitted). '
                        .'This is a plan-specific feature and not available in every 401(k). '
                        .'Check your plan documents or ask your HR or plan administrator whether after-tax '
                        .'contributions and in-plan Roth conversions are available to you before acting.',
                    legalBasis: 'IRC §415(c) (total DC plan limit); IRC §402(g) (employee deferral limit); IRS Notice 2014-54 (after-tax conversion)',
                    electionFacts: $electionFacts,
                );

                if ($key !== null) {
                    $emitted[] = $key;
                }
            }
        }

        // ─── RET-B: Contribution mix review (Roth + Traditional split) ──────────────
        $rothYtdCents = (int) ($profile->roth_401k_ytd ?? 0);
        $tradYtdCents = (int) ($profile->traditional_401k_ytd ?? 0);

        if ($rothYtdCents > 0 && $tradYtdCents > 0) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'retirement_contribution_mix_review',
                findingType: 'retirement',
                band: 'conditional',
                treatment: 'You appear to be splitting your 401(k) contributions between Roth (post-tax) '
                    .'and Traditional (pre-tax) buckets. '
                    .'The optimal mix often depends on your current marginal tax rate compared to your '
                    .'expected rate in retirement. '
                    .'If your current marginal rate is high, Traditional pre-tax contributions may reduce '
                    .'current-year taxes more; if you expect a higher rate later, Roth contributions grow '
                    .'tax-free. Reviewing the mix each year — particularly after income changes — may be '
                    .'worthwhile. Consider discussing your contribution allocation with a tax professional '
                    .'or financial advisor.',
                legalBasis: 'IRC §401(k) (elective deferrals); IRC §402(g) (Roth elective deferrals)',
                electionFacts: $electionFacts,
            );

            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ─── RET-C: Match pace gap (YTD-based annual pace vs. full-match threshold) ──
        // Uses TaxRulesEngineService::matchCaptureCents() for dollar gap (SAFE-03).
        // Complements EmployerMatchGapDetector (which uses employer.contribution_pct).
        $matchPctFact = UserTaxFact::currentFact($userId, 'employer.match_pct', null, $taxYear);
        $thresholdFact = UserTaxFact::currentFact($userId, 'employer.match_threshold_pct', null, $taxYear);
        $annualGrossFact = UserTaxFact::currentFact($userId, 'income.annual_gross_cents', null, $taxYear);

        if ($matchPctFact && $annualGrossFact) {
            $matchPct = (float) $matchPctFact->value;
            $annualGrossCents = (int) $annualGrossFact->value;

            // Match threshold: default to match_pct if not separately specified
            $matchThresholdPct = $thresholdFact ? (float) $thresholdFact->value : $matchPct;

            // YTD-based annualized contribution pace
            $ytdTotalCents = $tradYtdCents + $rothYtdCents;

            // Only emit when we have enough data to assess the gap
            if ($matchPct > 0 && $annualGrossCents > 0 && $matchThresholdPct > 0) {
                // Annualised YTD pace based on current month (directional estimate only)
                $currentMonth = (int) now()->format('n');
                $annualisedContribCents = $currentMonth > 0
                    ? (int) round($ytdTotalCents * (12.0 / $currentMonth))
                    : $ytdTotalCents;

                $annualisedContribPct = $annualGrossCents > 0
                    ? ($annualisedContribCents / $annualGrossCents) * 100
                    : 0.0;

                if ($annualisedContribPct < $matchThresholdPct) {
                    // SAFE-03: dollar gap computed by TaxRulesEngineService only
                    $fullMatchCents = $this->engine->matchCaptureCents(
                        annualGrossCents: $annualGrossCents,
                        contributionPct: $matchThresholdPct,
                        matchPct: $matchPct,
                        matchThresholdPct: $matchThresholdPct,
                    );
                    $currentMatchCents = $this->engine->matchCaptureCents(
                        annualGrossCents: $annualGrossCents,
                        contributionPct: (float) min($annualisedContribPct, $matchThresholdPct),
                        matchPct: $matchPct,
                        matchThresholdPct: $matchThresholdPct,
                    );
                    $gapCents = max(0, $fullMatchCents - $currentMatchCents);

                    $key = $service->registerFinding(
                        userId: $userId,
                        taxYear: $taxYear,
                        findingKey: 'retirement_match_pace_gap',
                        findingType: 'retirement',
                        band: 'auto',
                        // SAFE-03: no dollar figures in treatment text; amount in estimated_value_cents only
                        treatment: 'Based on your current year-to-date 401(k) contributions and your '
                            .'employer\'s reported match formula, your contribution pace appears to fall '
                            .'below the threshold needed to capture the full employer match. '
                            .'Employer matching contributions are generally the highest-return step '
                            .'available in a typical W-2 benefit package. '
                            .'Increasing your contribution rate to at least the match threshold may allow '
                            .'you to receive the full employer match — but confirm the exact rules with '
                            .'your HR department or plan documents, as match formulas vary.',
                        legalBasis: 'IRC §401(k) (matching contributions); IRC §414(s) (compensation definition for match)',
                        electionFacts: $electionFacts,
                        estimatedValueCents: $gapCents,  // SAFE-03: engine-computed gap
                    );

                    if ($key !== null) {
                        $emitted[] = $key;
                    }
                }
            }
        }

        // ─── RET-D: W-4 Step 3 alignment (credits vs. qualifying children) ──────────
        $step3Fact = UserTaxFact::currentFact($userId, 'w4.step3_annual_credits_cents', null, $taxYear);
        $qualChildrenFact = UserTaxFact::currentFact($userId, 'family.qualifying_children_under_17', null, $taxYear);

        if ($step3Fact && $qualChildrenFact) {
            $step3CreditsCents = (int) $step3Fact->value;
            $qualifyingChildren = (int) $qualChildrenFact->value;

            // Expected CTC from config (TAX-08 detection block) — stored in dollars, convert to cents.
            // 2026: $2,200 per qualifying child (OBBBA §70301; IRC §24).
            // Key is under 'detection' block per config/tax-rules.php structure.
            $ctcPerChildDollars = (int) config("tax-rules.{$taxYear}.detection.ctc_amount", 2000);
            $ctcPerChildCents = $ctcPerChildDollars * 100;

            // Mismatch: claimed credits ≠ expected for the number of qualifying children
            $expectedCreditsCents = $qualifyingChildren * $ctcPerChildCents;
            $hasMismatch = $expectedCreditsCents > 0
                && $step3CreditsCents !== $expectedCreditsCents;

            if ($hasMismatch) {
                $key = $service->registerFinding(
                    userId: $userId,
                    taxYear: $taxYear,
                    findingKey: 'retirement_w4_step3_alignment',
                    findingType: 'withholding',
                    band: 'conditional',
                    treatment: 'Your W-4 Step 3 (dependent credits) may not fully align with the '
                        .'number of qualifying children under 17 on your tax return. '
                        .'The Child Tax Credit for qualifying dependents has annual limits set by the IRS '
                        .'(see IRC §24 and your applicable tax year guidelines). '
                        .'An under-claimed Step 3 amount can result in over-withholding and a larger-than-necessary '
                        .'refund; an over-claimed amount can result in under-withholding and potential penalties. '
                        .'Reviewing your W-4 with your employer\'s HR or a tax professional may be '
                        .'worthwhile, particularly after changes in your family situation.',
                    legalBasis: 'IRC §24 (Child Tax Credit); IRC §3402(f) (W-4 withholding allowances)',
                    electionFacts: $electionFacts,
                );

                if ($key !== null) {
                    $emitted[] = $key;
                }
            }
        }

        return $emitted;
    }
}
