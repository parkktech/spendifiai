<?php

namespace App\Services\Detectors;

use App\Models\IncomeOptimizationProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;
use App\Services\TaxRulesEngineService;

/**
 * WithholdingGapDetector — FLAG-03
 *
 * Emits a finding when the difference between estimated annual tax (computed by
 * TaxRulesEngineService::computeTax) and the detected federal withholding
 * (sourced from the employer.federal_withholding durable fact) exceeds
 * config('tax-detection.withholding.gap_floor_cents') (default $500).
 *
 * SAFE-03 compliance:
 *   - TaxRulesEngineService::computeTax() is called for the estimated-tax computation.
 *   - This class does NOT assign estimated_value_cents to any OptimizationFinding.
 *   - The gap arithmetic is internal decision logic ONLY (not a finding dollar field).
 *
 * The finding omits dollar amounts from its treatment; NarrationService will narrate
 * the magnitude via the engine-computed estimated_value_cents (Phase 12 TaxRulesEngineService
 * extension will write that field).
 */
class WithholdingGapDetector
{
    public function __construct(
        protected TaxRulesEngineService $engine,
    ) {}

    /**
     * @param  array<string, string>  $electionFacts  Preloaded method-election facts
     * @return string[]  Finding keys emitted
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        // Need income snapshot to estimate tax liability
        $snapshot = IncomeOptimizationProfile::forUser($userId)
            ->where('tax_year', $taxYear)
            ->first();

        if (! $snapshot || $snapshot->getW2WagesCentsAttribute() === 0) {
            return $emitted;
        }

        // Load detected federal withholding from durable facts (paystub / interview)
        // Fact written by interview (employer.federal_withholding) or document extraction
        $withholdingFact = UserTaxFact::currentFact(
            $userId,
            'employer.federal_withholding',
            null,
            $taxYear,
        );

        if (! $withholdingFact) {
            // No withholding data available — cannot compute gap
            return $emitted;
        }

        $withholdingCents = (int) $withholdingFact->value;
        if ($withholdingCents <= 0) {
            return $emitted;
        }

        // Estimate annual income tax via TaxRulesEngineService (engine-only computation)
        // Uses W-2 wages as a proxy for taxable income; filing_status from snapshot.
        $filingStatus = $snapshot->filing_status ?? 'single';
        $incomeCents = $snapshot->getW2WagesCentsAttribute()
            + $snapshot->getSelfEmploymentIncomeCentsAttribute();

        // computeTax uses the engine's bracket tables — no dollar literals here
        $estimatedTaxCents = $this->engine->computeTax($incomeCents, $filingStatus, $taxYear);

        // Gap = estimated liability - detected withholding (decision logic only; never stored)
        $gapCents = $estimatedTaxCents - $withholdingCents;

        // Read gap floor from config (NEVER a literal — D2 / FLAG-08 mandate)
        $gapFloorCents = (int) config('tax-detection.withholding.gap_floor_cents', 50_000);

        if ($gapCents < $gapFloorCents) {
            return $emitted;
        }

        // Gap exceeds configured floor — emit educational finding (no dollar in text)
        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'withholding_gap',
            findingType: 'withholding',
            band: 'conditional',
            treatment: 'Your estimated federal income tax may be higher than the withholding shown '
                . 'in your records. You may want to review your W-4 with your employer or consult '
                . 'a tax professional to adjust your withholding and avoid an unexpected balance due.',
            legalBasis: 'IRC §3402; IRS Publication 505',
            ruleId: 'withholding_gap',
            docsMissing: ['w2_box_12'],
            electionFacts: $electionFacts,
        );

        if ($key !== null) {
            $emitted[] = $key;
        }

        return $emitted;
    }
}
