<?php

use App\Services\TaxRulesEngineService;

/*
|--------------------------------------------------------------------------
| DELTA-CONSISTENCY + MONOTONICITY property tests
|--------------------------------------------------------------------------
| Defect: before/after pair mixed observed-withholding (baseline) with
| model-estimated withholding (scenario), causing corrupt banner deltas.
|
| Fix: the `annual_withholding_cents` branch in computeScenarioOutcome now
| uses estimatePeriodWithholdingCents for BOTH sides (DELTA-CONSISTENCY LAW).
|
| This test file verifies:
|   1. DELTA-CONSISTENCY: baseline_absolute.per_period_take_home_cents is the
|      modelled value — NOT the observed paystub value.
|   2. MONOTONICITY: a pure roth_share decrease at equal deferral must produce
|      modelled take-home ≥ before AND federal tax ≤ before (over ~50 baselines).
|
| Zero Claude / Zero HTTP — verified by guard test below.
*/

beforeEach(function () {
    $this->engine = new TaxRulesEngineService;
});

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function dcBaseline(array $overrides = []): array
{
    return array_replace_recursive([
        'annual_gross_cents' => 20_000_000,    // $200k
        'se_income_cents' => 0,
        'pay_periods_per_year' => 26,
        'filing_status' => 'single',
        'w4_on_file' => ['filing_status' => null, 'dependents_claimed' => null, 'step3_credits_cents' => null],
        'family' => ['dependents_under_17' => 0, 'other_dependents' => 0],
        'age' => 35,
        'target_retirement_age' => 65,
        'prior_year_fica_wages_cents' => null,
        'current' => [
            'trad_401k_cents' => 0,
            'roth_401k_cents' => 0,
            'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 0,
            'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 0.0,
            'roth_share_pct' => 20.0,
        ],
        'employer' => ['match_pct' => 0.0, 'match_threshold_pct' => 0.0, 'has_401k' => true],
        'hsa_coverage_type' => 'self_only',
        'medicare_enrollment_date' => null,
        'is_marketplace_enrollee' => false,
        'is_cash_constrained' => false,
        'spouse_covered_by_plan' => false,
        'monthly_surplus_cents' => 100_000,
        // Paystub-observed withholding — triggers the annual_withholding_cents branch.
        'annual_withholding_cents' => 3_000_000,  // $30k/yr = $1,153.85/period
        'prior_year_federal_liability_cents' => null,
        'fact_set_hash' => 'test',
    ], $overrides);
}

function dcKnobs(float $deferralPct, float $rothSharePct, string $w4Status = 'single_or_mfs'): array
{
    return [
        'w4' => ['filing_status' => $w4Status, 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => $deferralPct, 'roth_share_pct' => $rothSharePct],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];
}

// ─────────────────────────────────────────────────────────────────────────
// DC-01: DELTA-CONSISTENCY — per_period_take_home_cents uses the model
// ─────────────────────────────────────────────────────────────────────────

it('DC-01: baseline_absolute.per_period_take_home_cents is modelled, not the observed paystub value', function () {
    $baseline = dcBaseline(['annual_withholding_cents' => 1_716_000]);  // $16.9k/yr ≈ $660/period
    $knobs = dcKnobs(10.0, 20.0);  // 20% Roth (baseline state)

    $outcome = $this->engine->computeScenarioOutcome($baseline, $knobs);

    $modelledPP = $outcome['baseline_absolute']['per_period_take_home_cents'];
    $observedPP = $outcome['baseline_absolute']['observed_per_period_take_home_cents'];

    // The modelled per-period take-home is derived via estimatePeriodWithholdingCents.
    // It MUST differ from what the observed take-home would be (observed WH = $660/period,
    // modelled WH > $660 due to model overestimation bias).
    expect($modelledPP)->toBeInt();
    expect($observedPP)->toBeInt();
    // Observed uses the actual $660/period WH; modelled uses the estimator.
    // They must not be equal (unless by coincidence, which is astronomically unlikely).
    expect($modelledPP)->not->toBe($observedPP);
});

it('DC-01b: observed_per_period_take_home_cents is null when no annual_withholding_cents', function () {
    $baseline = dcBaseline(['annual_withholding_cents' => null]);
    $knobs = dcKnobs(10.0, 20.0);

    $outcome = $this->engine->computeScenarioOutcome($baseline, $knobs);

    expect($outcome['baseline_absolute']['observed_per_period_take_home_cents'])->toBeNull();
});

it('DC-01c: observed_per_period_take_home_cents equals paystub-derived take-home (not model)', function () {
    $gross = 19_782_750;  // $197,827.50/yr — User 1 proxy
    $periods = 26;
    $periodGross = (int) round($gross / $periods);     // $761,029
    $annualWH = 1_715_612;                             // observed $17,156.12/yr ≈ $660/period
    $observedWHPerPeriod = (int) round($annualWH / $periods);

    $baseline = dcBaseline([
        'annual_gross_cents' => $gross,
        'pay_periods_per_year' => $periods,
        'annual_withholding_cents' => $annualWH,
        'current' => [
            'trad_401k_cents' => 608_700,    // $608.70/period × 26 = $15,826.20
            'roth_401k_cents' => 152_180,    // $152.18/period × 26 = $3,956.68
            'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 0,
            'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 10.0,
            'roth_share_pct' => 20.0,
        ],
    ]);
    $knobs = dcKnobs(10.0, 20.0);

    $outcome = $this->engine->computeScenarioOutcome($baseline, $knobs);
    $observedPP = $outcome['baseline_absolute']['observed_per_period_take_home_cents'];

    // Observed take-home = periodGross - deductions - observedWH - FICA
    // The test just verifies the value exists and is plausible (not trying to hit exact FICA).
    expect($observedPP)->toBeInt();
    // Observed WH = $660/period → observed take-home should be substantially higher
    // than the modelled take-home (which uses a higher model WH).
    $modelledPP = $outcome['baseline_absolute']['per_period_take_home_cents'];
    expect($observedPP)->toBeGreaterThan($modelledPP);
});

// ─────────────────────────────────────────────────────────────────────────
// DC-02: MONOTONICITY — roth_share decrease at equal deferral
// ─────────────────────────────────────────────────────────────────────────

/**
 * MONOTONICITY PROPERTY: for any baseline, a pure roth_share decrease at equal deferral:
 *   - modelled take-home after >= before  (lower traditional deduction → less WH → more home)
 *   - Wait — actually roth→traditional REDUCES withholding (traditional is pre-tax).
 *     Roth_share 20% → 0% at equal deferral means MORE traditional → MORE pre-tax → LESS taxable
 *     → LOWER withholding → MORE take-home.
 *   - federal_tax after <= before  (more traditional pre-tax deduction)
 *
 * Both properties must hold over a range of baselines.
 */

// Single canonical test: User 1 proxy — roth_share 20% → 0%, deferral unchanged at 10%
it('DC-02a: roth_share 20%→0% at equal deferral produces non-negative take-home delta (User-1 proxy)', function () {
    $baseline = dcBaseline([
        'annual_gross_cents' => 19_782_750,
        'pay_periods_per_year' => 26,
        'annual_withholding_cents' => 1_715_612,
        'current' => [
            'trad_401k_cents' => 608_700,
            'roth_401k_cents' => 152_180,
            'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 0,
            'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 10.0,
            'roth_share_pct' => 20.0,
        ],
    ]);

    $beforeKnobs = dcKnobs(10.0, 20.0);  // 20% Roth (current state)
    $afterKnobs = dcKnobs(10.0, 0.0);  // 0% Roth  (take-home-max state)

    $before = $this->engine->computeScenarioOutcome($baseline, $beforeKnobs);
    $after = $this->engine->computeScenarioOutcome($baseline, $afterKnobs);

    $beforePP = $before['baseline_absolute']['per_period_take_home_cents'];
    $afterPP = $after['baseline_absolute']['per_period_take_home_cents'];

    // MONOTONICITY: take-home must be non-negative
    $delta = $afterPP - $beforePP;
    expect($delta)->toBeGreaterThanOrEqual(0);
    // Federal tax must also be ≤ before (more traditional pre-tax)
    expect($after['baseline_absolute']['federal_tax_annual_cents'])
        ->toBeLessThanOrEqual($before['baseline_absolute']['federal_tax_annual_cents']);
});

// Randomised property test: ~50 baselines
it('DC-02b: roth_share decrease at equal deferral → take-home ≥ before (50 randomised baselines)', function () {
    $engine = $this->engine;
    $failures = [];
    $tested = 0;

    $grossValues = [8_000_000, 10_000_000, 12_000_000, 15_000_000, 19_782_750, 25_000_000, 35_000_000];
    $deferralPcts = [4.0, 6.0, 8.0, 10.0, 12.0, 15.0];
    $rothBefores = [10.0, 20.0, 25.0, 50.0, 75.0, 100.0];
    $statusMap = ['single', 'married_joint', 'head_of_household'];
    $wh_ratios = [0.06, 0.09, 0.12, 0.15, 0.18, 0.22];  // annual WH as fraction of gross

    foreach ($grossValues as $gross) {
        foreach ($deferralPcts as $deferralPct) {
            foreach ($rothBefores as $rothBefore) {
                // Only test meaningful Roth decreases (reduce to 0 if before > 0)
                if ($rothBefore <= 0.0) {
                    continue;
                }
                $rothAfter = 0.0;  // pure decrease to 0

                $statusIdx = ($tested % count($statusMap));
                $status = $statusMap[$statusIdx];
                $w4Status = $status === 'single' ? 'single_or_mfs' : ($status === 'married_joint' ? 'married_joint' : 'head_of_household');

                $whRatio = $wh_ratios[$tested % count($wh_ratios)];
                $annualWH = (int) round($gross * $whRatio);

                $deferralCents = (int) round($gross * $deferralPct / 100);
                $rothCents = (int) round($deferralCents * $rothBefore / 100);
                $tradCents = $deferralCents - $rothCents;

                $baseline = [
                    'annual_gross_cents' => $gross,
                    'se_income_cents' => 0,
                    'pay_periods_per_year' => 26,
                    'filing_status' => $status,
                    'w4_on_file' => ['filing_status' => null, 'dependents_claimed' => null, 'step3_credits_cents' => null],
                    'family' => ['dependents_under_17' => 0, 'other_dependents' => 0],
                    'age' => 40,
                    'target_retirement_age' => 65,
                    'prior_year_fica_wages_cents' => null,
                    'current' => [
                        'trad_401k_cents' => $tradCents,
                        'roth_401k_cents' => $rothCents,
                        'hsa_cents' => 0,
                        'ira_trad_ytd_cents' => 0,
                        'ira_roth_ytd_cents' => 0,
                        'deferral_pct' => $deferralPct,
                        'roth_share_pct' => $rothBefore,
                    ],
                    'employer' => ['match_pct' => 0.0, 'match_threshold_pct' => 0.0, 'has_401k' => true],
                    'hsa_coverage_type' => 'self_only',
                    'medicare_enrollment_date' => null,
                    'is_marketplace_enrollee' => false,
                    'is_cash_constrained' => false,
                    'spouse_covered_by_plan' => false,
                    'monthly_surplus_cents' => 100_000,
                    'annual_withholding_cents' => $annualWH,  // observed — triggers the fix path
                    'prior_year_federal_liability_cents' => null,
                    'fact_set_hash' => 'test',
                ];

                $beforeKnobs = [
                    'w4' => ['filing_status' => $w4Status, 'dependents_under_17' => 0, 'other_dependents' => 0],
                    'k401' => ['deferral_pct' => $deferralPct, 'roth_share_pct' => $rothBefore],
                    'hsa' => ['annual_election_cents' => 0],
                    'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
                    'transfer' => ['per_period_cents' => 0],
                ];
                $afterKnobs = array_replace_recursive($beforeKnobs, [
                    'k401' => ['roth_share_pct' => $rothAfter],
                ]);

                try {
                    $before = $engine->computeScenarioOutcome($baseline, $beforeKnobs);
                    $after = $engine->computeScenarioOutcome($baseline, $afterKnobs);

                    $beforePP = $before['baseline_absolute']['per_period_take_home_cents'];
                    $afterPP = $after['baseline_absolute']['per_period_take_home_cents'];
                    $beforeTax = $before['baseline_absolute']['federal_tax_annual_cents'];
                    $afterTax = $after['baseline_absolute']['federal_tax_annual_cents'];

                    if ($afterPP < $beforePP) {
                        $failures[] = sprintf(
                            'TAKE-HOME REGRESSION gross=%d deferral=%.0f%% roth %.0f%%→%.0f%%: before=%d after=%d delta=%d',
                            $gross, $deferralPct, $rothBefore, $rothAfter, $beforePP, $afterPP, $afterPP - $beforePP
                        );
                    }
                    if ($afterTax > $beforeTax) {
                        $failures[] = sprintf(
                            'TAX INCREASE gross=%d deferral=%.0f%% roth %.0f%%→%.0f%%: before=%d after=%d',
                            $gross, $deferralPct, $rothBefore, $rothAfter, $beforeTax, $afterTax
                        );
                    }
                } catch (\Throwable $e) {
                    $failures[] = sprintf(
                        'EXCEPTION gross=%d deferral=%.0f%% roth %.0f%%→%.0f%%: %s',
                        $gross, $deferralPct, $rothBefore, $rothAfter, $e->getMessage()
                    );
                }

                $tested++;
                if ($tested >= 50) {
                    break 3;
                }
            }
        }
    }

    expect($failures)->toBeEmpty(
        "Monotonicity violations found ({$tested} baselines tested):\n"
        .implode("\n", $failures)
    );
});

// ─────────────────────────────────────────────────────────────────────────
// DC-03: No outbound HTTP
// ─────────────────────────────────────────────────────────────────────────

it('DC: zero outbound HTTP calls', function () {
    Http::fake([]);

    $baseline = dcBaseline();
    $knobs = dcKnobs(10.0, 0.0);
    $this->engine->computeScenarioOutcome($baseline, $knobs);

    Http::assertSentCount(0);
});
