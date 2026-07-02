<?php

use App\Services\TaxRulesEngineService;

/*
|--------------------------------------------------------------------------
| SCENARIOS-SPEC §B.5 — ACA sequencing invariant (property test, §T.10)
|--------------------------------------------------------------------------
| For ANY baseline with is_marketplace_enrollee = true, every outcome emitted by
| computeScenarioOutcome() satisfies:
|
|   projectedMagiCents(baseline, outcome.knobs) ≤ cliff − buffer
|   OR
|   guards.aca_cliff_applied = true AND aca_need_remaining_cents > 0 AND both Roth knobs at 0.
|
| Proven over 200 randomized marketplace baselines seeded near the 400%-FPL cliff, run across a
| battery of Roth-heavy solver knob candidates. Grouped 'property' for the CI property profile
| (14-CONTEXT §68). This test is pure arithmetic — subsidy/clawback dollars are NEVER computed.
*/

/** Deterministic seed so any failure reproduces exactly. */
function acaSeed(): void
{
    mt_srand(1402);
}

/** Knob candidates that stress the guard (Roth-heavy allocations that push MAGI up). */
function acaKnobCandidates(): array
{
    $base = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 0.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    return [
        array_replace_recursive($base, ['k401' => ['deferral_pct' => 15.0, 'roth_share_pct' => 100], 'ira' => ['roth_cents' => 750_000]]),
        array_replace_recursive($base, ['k401' => ['deferral_pct' => 10.0, 'roth_share_pct' => 50], 'ira' => ['roth_cents' => 400_000, 'traditional_cents' => 100_000]]),
        array_replace_recursive($base, ['k401' => ['deferral_pct' => 8.0, 'roth_share_pct' => 100], 'ira' => ['roth_cents' => 750_000]]),
        array_replace_recursive($base, ['k401' => ['deferral_pct' => 20.0, 'roth_share_pct' => 75], 'ira' => ['roth_cents' => 550_000]]),
        array_replace_recursive($base, ['k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0]]),
    ];
}

test('ACA invariant holds across 200 randomized marketplace baselines', function () {
    $engine = new TaxRulesEngineService;
    acaSeed();

    $buffer = config('optimizer-scenarios.assumptions.aca_cliff_buffer_cents');
    $singleCliff = config('tax-rules.2026.detection.aca_fpl_threshold_single') * 100;
    $familyCliff = config('tax-rules.2026.detection.aca_fpl_threshold_family4') * 100;

    $candidates = acaKnobCandidates();
    $guardTriggered = 0;
    $checks = 0;

    for ($i = 0; $i < 200; $i++) {
        $isMfj = mt_rand(0, 1) === 1;
        $status = $isMfj ? 'married_joint' : 'single';
        $cliff = $isMfj ? $familyCliff : $singleCliff;

        // Gross seeded in a band straddling the cliff (roughly −12% … +15%).
        $gross = (int) round($cliff * (0.88 + mt_rand(0, 27) / 100));
        $age = mt_rand(30, 64);

        $baseline = [
            'annual_gross_cents' => $gross,
            'se_income_cents' => mt_rand(0, 1) ? mt_rand(0, 800_000) : 0,
            'pay_periods_per_year' => [12, 24, 26, 52][mt_rand(0, 3)],
            'filing_status' => $status,
            'w4_on_file' => ['filing_status' => null, 'dependents_claimed' => null],
            'family' => ['dependents_under_17' => mt_rand(0, 3), 'other_dependents' => 0],
            'age' => $age,
            'target_retirement_age' => 65,
            'prior_year_fica_wages_cents' => mt_rand(0, 1) ? $gross : null,
            'current' => [
                'trad_401k_cents' => mt_rand(0, 500_000),
                'roth_401k_cents' => mt_rand(0, 500_000),
                'hsa_cents' => mt_rand(0, 200_000),
                'ira_trad_ytd_cents' => mt_rand(0, 300_000),
                'ira_roth_ytd_cents' => mt_rand(0, 300_000),
                'deferral_pct' => (float) mt_rand(0, 10),
            ],
            'employer' => ['match_pct' => 100.0, 'match_threshold_pct' => (float) mt_rand(3, 6), 'has_401k' => true],
            'hsa_coverage_type' => mt_rand(0, 1) ? 'family' : 'self_only',
            'medicare_enrollment_date' => null,
            'is_marketplace_enrollee' => true,
            'is_cash_constrained' => false,
            'spouse_covered_by_plan' => false,
            'monthly_surplus_cents' => mt_rand(100_000, 800_000),
            'annual_withholding_cents' => null,
            'prior_year_federal_liability_cents' => null,
            'fact_set_hash' => 'prop'.$i,
        ];

        foreach ($candidates as $knobs) {
            $out = $engine->computeScenarioOutcome($baseline, $knobs);
            $magi = $engine->projectedMagiCents($baseline, $out['knobs']);
            $checks++;

            $clearsCliff = $magi <= ($cliff - $buffer);

            $flaggedWithRothZeroed = $out['guards']['aca_cliff_applied'] === true
                && $out['guards']['aca_need_remaining_cents'] > 0
                && (float) $out['knobs']['k401']['roth_share_pct'] === 0.0
                && (int) $out['knobs']['ira']['roth_cents'] === 0;

            if ($out['guards']['aca_cliff_applied']) {
                $guardTriggered++;
            }

            expect($clearsCliff || $flaggedWithRothZeroed)->toBeTrue(
                sprintf(
                    'ACA invariant violated: status=%s gross=%d magi=%d cliff-buffer=%d applied=%s need=%d roth_share=%s roth_ira=%d',
                    $status, $gross, $magi, $cliff - $buffer,
                    var_export($out['guards']['aca_cliff_applied'], true),
                    $out['guards']['aca_need_remaining_cents'],
                    var_export($out['knobs']['k401']['roth_share_pct'], true),
                    $out['knobs']['ira']['roth_cents'],
                )
            );
        }
    }

    // Non-vacuous: the guard actually fired on a meaningful share of the sampled space,
    // and every candidate was checked.
    expect($checks)->toBe(200 * count($candidates))
        ->and($guardTriggered)->toBeGreaterThan(0);
})->group('property');

test('ACA guard proves the Roth→Traditional reallocation order (401k before IRA)', function () {
    $engine = new TaxRulesEngineService;

    // Single, $63k — just over the cliff. Both Roth 401k and Roth IRA requested.
    // The guard must exhaust Roth-401k→Trad-401k BEFORE touching the Roth IRA.
    $baseline = [
        'annual_gross_cents' => 6_300_000,
        'se_income_cents' => 0,
        'pay_periods_per_year' => 26,
        'filing_status' => 'single',
        'w4_on_file' => ['filing_status' => null, 'dependents_claimed' => null],
        'family' => ['dependents_under_17' => 0, 'other_dependents' => 0],
        'age' => 40,
        'target_retirement_age' => 65,
        'prior_year_fica_wages_cents' => null,
        'current' => [
            'trad_401k_cents' => 0, 'roth_401k_cents' => 0, 'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 0, 'ira_roth_ytd_cents' => 0, 'deferral_pct' => 0.0,
        ],
        'employer' => ['match_pct' => 0.0, 'match_threshold_pct' => 0.0, 'has_401k' => true],
        'hsa_coverage_type' => 'self_only',
        'medicare_enrollment_date' => null,
        'is_marketplace_enrollee' => true,
        'is_cash_constrained' => false,
        'spouse_covered_by_plan' => false,
        'monthly_surplus_cents' => 300_000,
        'annual_withholding_cents' => null,
        'prior_year_federal_liability_cents' => null,
        'fact_set_hash' => 'order',
    ];
    $knobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 5.0, 'roth_share_pct' => 100],   // $3,150 all Roth
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 300_000],
        'transfer' => ['per_period_cents' => 0],
    ];

    $out = $engine->computeScenarioOutcome($baseline, $knobs);

    // need = 200,000 − (6,260,000 − 6,300,000) = 240,000. Roth-401k pool = 315,000 ≥ 240,000,
    // so the shift is fully absorbed by the 401k side; the Roth IRA is left untouched.
    expect($out['guards']['aca_cliff_applied'])->toBeTrue()
        ->and($out['guards']['aca_need_remaining_cents'])->toBe(0)
        ->and($out['knobs']['ira']['roth_cents'])->toBe(300_000)                // IRA untouched
        ->and((float) $out['knobs']['k401']['roth_share_pct'])->toBeLessThan(100.0) // 401k Roth reduced
        ->and($engine->projectedMagiCents($baseline, $out['knobs']))->toBeLessThanOrEqual(6_060_000);
})->group('property');
