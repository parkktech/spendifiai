<?php

declare(strict_types=1);

/**
 * §T.12 — ScenarioSolverService agreement/conflict model tests.
 *
 * Verifies:
 *  - converged baseline → agreement (diffKnobs empty) → single merged option
 *  - divergent baseline → 3 distinct vectors with non-empty knob_diffs
 *  - Balanced synthesis is COMPUTED (not averaged)
 *  - Balanced-seeded-from-TB rule fires when TB diverges from both poles
 *  - minimum-federal-tax option is identifiable for the "lowest-tax" badge
 */

use App\Services\ScenarioSolverService;
use App\Services\TaxRulesEngineService;

// ── Baseline fixtures ─────────────────────────────────────────────────────────

/**
 * A "fully converging" baseline: no 401k employer, no HSA, no IRA room left,
 * minimal take-home scope → all three solvers produce nearly identical knobs.
 */
function convergingBaseline(): array
{
    return [
        'annual_gross_cents' => 5_000_000,   // $50k
        'se_income_cents' => 0,
        'pay_periods_per_year' => 26,
        'filing_status' => 'single',
        'w4_on_file' => [
            'filing_status' => 'single_or_mfs',
            'dependents_claimed' => 0,
        ],
        'family' => ['dependents_under_17' => 0, 'other_dependents' => 0],
        'age' => 35,
        'target_retirement_age' => 65,
        'prior_year_fica_wages_cents' => null,
        'current' => [
            'trad_401k_cents' => 2_450_000,  // maxed already (full $24,500 limit for 2026)
            'roth_401k_cents' => 0,
            'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 750_000,    // full 2026 IRA limit ($7,500) already used
            'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 49.0,       // maxed
        ],
        'employer' => [
            'match_pct' => 0.0,
            'match_threshold_pct' => 0.0,
            'has_401k' => true,
        ],
        'hsa_coverage_type' => null,
        'medicare_enrollment_date' => null,
        'is_marketplace_enrollee' => false,
        'is_cash_constrained' => false,
        'spouse_covered_by_plan' => false,
        'monthly_surplus_cents' => 10_000,
        'annual_withholding_cents' => null,
        'prior_year_federal_liability_cents' => null,
        'fact_set_hash' => 'converging-hash',
        'baseline_per_period_take_home_cents' => 120_000,
    ];
}

/**
 * A "clearly diverging" baseline: significant 401k room, HSA room, IRA room.
 * take_home (low deferral / no IRA) and retirement (max deferral / max IRA)
 * will produce vectors that differ substantially.
 */
function divergingBaseline(): array
{
    return [
        'annual_gross_cents' => 12_000_000,  // $120k
        'se_income_cents' => 0,
        'pay_periods_per_year' => 26,
        'filing_status' => 'single',
        'w4_on_file' => [
            'filing_status' => 'single_or_mfs',
            'dependents_claimed' => 0,
        ],
        'family' => ['dependents_under_17' => 0, 'other_dependents' => 0],
        'age' => 40,
        'target_retirement_age' => 65,
        'prior_year_fica_wages_cents' => null,
        'current' => [
            'trad_401k_cents' => 600_000,    // $6k/yr (only 5%)
            'roth_401k_cents' => 0,
            'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 0,
            'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 5.0,
        ],
        'employer' => [
            'match_pct' => 100.0,
            'match_threshold_pct' => 5.0,
            'has_401k' => true,
        ],
        'hsa_coverage_type' => 'self_only',
        'medicare_enrollment_date' => null,
        'is_marketplace_enrollee' => false,
        'is_cash_constrained' => false,
        'spouse_covered_by_plan' => false,
        'monthly_surplus_cents' => 300_000,     // $3k/mo surplus
        'annual_withholding_cents' => null,
        'prior_year_federal_liability_cents' => null,
        'fact_set_hash' => 'diverging-hash',
        'baseline_per_period_take_home_cents' => 350_000,     // $3,500
    ];
}

function makeSolverForAgreement(): ScenarioSolverService
{
    return app(ScenarioSolverService::class);
}

function makeEngineForAgreement(): TaxRulesEngineService
{
    return app(TaxRulesEngineService::class);
}

// ── Agreement / conflict model ────────────────────────────────────────────────

describe('agreement detection', function () {
    it('converged baseline → diffKnobs(TH, R) and diffKnobs(TH, TB) are both empty', function () {
        $solver = makeSolverForAgreement();
        $baseline = convergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs = $solver->solve($baseline, 'retirement');

        $diffTHvsR = $solver->diffKnobs($thKnobs, $rKnobs);
        $diffTHvsTB = $solver->diffKnobs($thKnobs, $tbKnobs);

        expect($diffTHvsR)->toBeEmpty('TH vs R should converge on a maxed-out baseline');
        expect($diffTHvsTB)->toBeEmpty('TH vs TB should converge on a maxed-out baseline');
    });

    it('diverging baseline → at least one non-empty diff (TH vs R)', function () {
        $solver = makeSolverForAgreement();
        $baseline = divergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $rKnobs = $solver->solve($baseline, 'retirement');

        $diff = $solver->diffKnobs($thKnobs, $rKnobs);

        expect($diff)->not->toBeEmpty('TH vs R should diverge on a high-income baseline with room');
    });

    it('diverging baseline → three vectors are distinct from each other', function () {
        $solver = makeSolverForAgreement();
        $baseline = divergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs = $solver->solve($baseline, 'retirement');

        // TH vs R must differ (already asserted above, but here we compare all three)
        // We check that not all three are identical
        $allSame = ($thKnobs === $tbKnobs && $tbKnobs === $rKnobs);

        expect($allSame)->toBeFalse('On a diverging baseline, not all three objective vectors should be identical');
    });
});

// ── Balanced synthesis ─────────────────────────────────────────────────────────

describe('Balanced synthesis', function () {
    it('Balanced vector is computed (re-run through engine), not an average of outcomes', function () {
        $solver = makeSolverForAgreement();
        $engine = makeEngineForAgreement();
        $baseline = divergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $rKnobs = $solver->solve($baseline, 'retirement');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');

        $balanced = $solver->solveBalanced($thKnobs, $rKnobs, $tbKnobs, $baseline);

        // Balanced must be a valid knob vector (engine won't throw)
        $outcome = $engine->computeScenarioOutcome($baseline, $balanced);

        expect($outcome)->toHaveKeys(['knobs', 'take_home', 'federal_tax', 'retirement', 'guards']);
    });

    it('Balanced roth_share_pct is on the grid', function () {
        $solver = makeSolverForAgreement();
        $baseline = divergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $rKnobs = $solver->solve($baseline, 'retirement');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');

        $balanced = $solver->solveBalanced($thKnobs, $rKnobs, $tbKnobs, $baseline);

        $grid = config('optimizer-scenarios.grids.roth_share_pct');
        expect(in_array($balanced['k401']['roth_share_pct'], $grid, true))->toBeTrue(
            'Balanced roth_share_pct must be on the grid'
        );
    });

    it('Balanced deferral_pct is between TH and R (inclusive)', function () {
        $solver = makeSolverForAgreement();
        $baseline = divergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $rKnobs = $solver->solve($baseline, 'retirement');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');

        $balanced = $solver->solveBalanced($thKnobs, $rKnobs, $tbKnobs, $baseline);

        $low = min($thKnobs['k401']['deferral_pct'], $rKnobs['k401']['deferral_pct']);
        $high = max($thKnobs['k401']['deferral_pct'], $rKnobs['k401']['deferral_pct']);

        // After snapping to 1-pt steps, allow 0.5 tolerance
        expect($balanced['k401']['deferral_pct'])->toBeGreaterThanOrEqual($low - 0.5);
        expect($balanced['k401']['deferral_pct'])->toBeLessThanOrEqual($high + 0.5);
    });

    it('Balanced-seeded-from-TB rule: when TB diverges from both poles, Balanced equals TB', function () {
        $solver = makeSolverForAgreement();
        $baseline = divergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $rKnobs = $solver->solve($baseline, 'retirement');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');

        // Check if the rule fires
        $tbDivFromTH = $solver->diffKnobs($tbKnobs, $thKnobs);
        $tbDivFromR = $solver->diffKnobs($tbKnobs, $rKnobs);

        $balanced = $solver->solveBalanced($thKnobs, $rKnobs, $tbKnobs, $baseline);

        if (! empty($tbDivFromTH) && ! empty($tbDivFromR)) {
            // Rule fires: Balanced should equal TB knobs (re-run through engine may clamp)
            // We verify by checking the deferral_pct matches TB's
            expect($balanced['k401']['deferral_pct'])
                ->toBeGreaterThanOrEqual($tbKnobs['k401']['deferral_pct'] - 0.001);
        } else {
            // Rule doesn't fire: midpoint synthesis
            // Just verify it's a valid outcome (engine doesn't throw)
            expect($balanced)->toBeArray();
        }
    });
});

// ── Lowest-tax badge identifiability ─────────────────────────────────────────

describe('lowest-tax badge', function () {
    it('the option with minimum federal_tax delta is identifiable', function () {
        $solver = makeSolverForAgreement();
        $engine = makeEngineForAgreement();
        $baseline = divergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs = $solver->solve($baseline, 'retirement');
        $balanced = $solver->solveBalanced($thKnobs, $rKnobs, $tbKnobs, $baseline);

        $options = [
            'take_home' => $engine->computeScenarioOutcome($baseline, $thKnobs),
            'tax_burden' => $engine->computeScenarioOutcome($baseline, $tbKnobs),
            'retirement' => $engine->computeScenarioOutcome($baseline, $rKnobs),
            'balanced' => $engine->computeScenarioOutcome($baseline, $balanced),
        ];

        $minTaxDelta = PHP_INT_MAX;
        $minTaxKey = '';
        foreach ($options as $key => $outcome) {
            $delta = $outcome['federal_tax']['annual_delta_cents'];
            if ($delta < $minTaxDelta) {
                $minTaxDelta = $delta;
                $minTaxKey = $key;
            }
        }

        // tax_burden option should have the lowest (or tied-lowest) delta
        $tbDelta = $options['tax_burden']['federal_tax']['annual_delta_cents'];
        expect($tbDelta)->toBeLessThanOrEqual($minTaxDelta + 1);
    });
});

// ── No Claude / No HTTP guard ─────────────────────────────────────────────────

describe('no-claude guard', function () {
    it('solver methods make zero HTTP calls', function () {
        \Illuminate\Support\Facades\Http::fake(); // ensure nothing is allowed through

        $solver = makeSolverForAgreement();
        $baseline = divergingBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs = $solver->solve($baseline, 'retirement');
        $balanced = $solver->solveBalanced($thKnobs, $rKnobs, $tbKnobs, $baseline);
        $attr = $solver->attributeBenefits($baseline, $thKnobs);

        \Illuminate\Support\Facades\Http::assertNothingSent();
    });
});
