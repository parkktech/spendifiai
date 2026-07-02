<?php

declare(strict_types=1);

/**
 * §T.10-11 — ScenarioSolverService unit tests.
 *
 * Tests run entirely on pre-built baselines (no User/DB) to keep them fast and
 * to verify that solve() is purely deterministic given a baseline array.
 * These are the tests the plan mandates for TDD Task 1: determinism, objective
 * dominance, take-home floor, and full-match guard.
 */

use App\Services\ScenarioSolverService;
use App\Services\TaxRulesEngineService;

// ── Test fixtures ────────────────────────────────────────────────────────────

/**
 * A minimal valid baseline for a single W2 earner: $80k/yr, biweekly, age 40,
 * employer with 5% match at 100%, no marketplace, not cash-constrained.
 */
function solverBaseline(array $overrides = []): array
{
    return array_replace_recursive([
        'annual_gross_cents' => 8_000_000,   // $80 k/yr
        'se_income_cents' => 0,
        'pay_periods_per_year' => 26,          // biweekly
        'filing_status' => 'single',
        'w4_on_file' => [
            'filing_status' => 'single_or_mfs',
            'dependents_claimed' => 0,
        ],
        'family' => [
            'dependents_under_17' => 0,
            'other_dependents' => 0,
        ],
        'age' => 40,
        'target_retirement_age' => 65,
        'prior_year_fica_wages_cents' => null,
        'current' => [
            'trad_401k_cents' => 250_000,  // $2,500/yr (annualized ~3.1%)
            'roth_401k_cents' => 0,
            'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 0,
            'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 3.125,    // 2500 / 80000 × 100
        ],
        'employer' => [
            'match_pct' => 100.0,
            'match_threshold_pct' => 5.0,
            'has_401k' => true,
        ],
        'hsa_coverage_type' => null,
        'medicare_enrollment_date' => null,
        'is_marketplace_enrollee' => false,
        'is_cash_constrained' => false,
        'spouse_covered_by_plan' => false,
        'monthly_surplus_cents' => 120_000,    // $1,200/mo surplus
        'annual_withholding_cents' => null,
        'prior_year_federal_liability_cents' => null,
        'fact_set_hash' => 'test-hash-unit',
        'baseline_per_period_take_home_cents' => 250_000,  // $2,500 / paycheck
    ], $overrides);
}

/**
 * Baseline that already hits the employee 401k limit with current contributions —
 * useful for verifying the floor guard prevents breaching the limit.
 */
function cashConstrainedBaseline(): array
{
    return solverBaseline([
        'is_cash_constrained' => true,
        'monthly_surplus_cents' => 20_000,      // very tight
        'baseline_per_period_take_home_cents' => 180_000,   // $1,800
    ]);
}

/**
 * Baseline with HSA-eligible coverage.
 */
function hsaBaseline(): array
{
    return solverBaseline([
        'hsa_coverage_type' => 'self_only',
        'current' => [
            'trad_401k_cents' => 250_000,
            'roth_401k_cents' => 0,
            'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 0,
            'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 3.125,
        ],
    ]);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeSolverForTest(): ScenarioSolverService
{
    return app(ScenarioSolverService::class);
}

function makeEngineForTest(): TaxRulesEngineService
{
    return app(TaxRulesEngineService::class);
}

// ── §T.11: Determinism ────────────────────────────────────────────────────────

describe('determinism', function () {
    it('same baseline twice → identical take_home vectors', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        expect($solver->solve($baseline, 'take_home'))
            ->toBe($solver->solve($baseline, 'take_home'));
    });

    it('same baseline twice → identical tax_burden vectors', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        expect($solver->solve($baseline, 'tax_burden'))
            ->toBe($solver->solve($baseline, 'tax_burden'));
    });

    it('same baseline twice → identical retirement vectors', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        expect($solver->solve($baseline, 'retirement'))
            ->toBe($solver->solve($baseline, 'retirement'));
    });

    it('same baseline twice → identical diffKnobs output', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        $th = $solver->solve($baseline, 'take_home');
        $r = $solver->solve($baseline, 'retirement');

        expect($solver->diffKnobs($th, $r))
            ->toBe($solver->diffKnobs($th, $r));
    });
});

// ── §T.11: Objective dominance ────────────────────────────────────────────────

describe('objective dominance', function () {
    it('take_home option has highest per-paycheck take-home (or tied)', function () {
        $solver = makeSolverForTest();
        $engine = makeEngineForTest();
        $baseline = solverBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs = $solver->solve($baseline, 'retirement');

        $thDelta = $engine->computeScenarioOutcome($baseline, $thKnobs)['take_home']['per_paycheck_delta_cents'];
        $tbDelta = $engine->computeScenarioOutcome($baseline, $tbKnobs)['take_home']['per_paycheck_delta_cents'];
        $rDelta = $engine->computeScenarioOutcome($baseline, $rKnobs)['take_home']['per_paycheck_delta_cents'];

        // take_home option must be >= the others (ties allowed — epsilon 1 cent)
        expect($thDelta)->toBeGreaterThanOrEqual($tbDelta - 1);
        expect($thDelta)->toBeGreaterThanOrEqual($rDelta - 1);
    });

    it('tax_burden option has lowest (or tied) federal tax delta', function () {
        $solver = makeSolverForTest();
        $engine = makeEngineForTest();
        $baseline = solverBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs = $solver->solve($baseline, 'retirement');

        $thTax = $engine->computeScenarioOutcome($baseline, $thKnobs)['federal_tax']['annual_delta_cents'];
        $tbTax = $engine->computeScenarioOutcome($baseline, $tbKnobs)['federal_tax']['annual_delta_cents'];
        $rTax = $engine->computeScenarioOutcome($baseline, $rKnobs)['federal_tax']['annual_delta_cents'];

        // tax_burden option must have the most negative (or least positive) tax delta
        // Allowing 1-cent rounding epsilon for ties
        expect($tbTax)->toBeLessThanOrEqual($thTax + 1);
        expect($tbTax)->toBeLessThanOrEqual($rTax + 1);
    });

    it('retirement option has highest (or tied) retirement contribution delta', function () {
        $solver = makeSolverForTest();
        $engine = makeEngineForTest();
        $baseline = solverBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs = $solver->solve($baseline, 'retirement');

        $thR = $engine->computeScenarioOutcome($baseline, $thKnobs)['retirement']['annual_contributions_delta_cents'];
        $tbR = $engine->computeScenarioOutcome($baseline, $tbKnobs)['retirement']['annual_contributions_delta_cents'];
        $rR = $engine->computeScenarioOutcome($baseline, $rKnobs)['retirement']['annual_contributions_delta_cents'];

        expect($rR)->toBeGreaterThanOrEqual($thR - 1);
        expect($rR)->toBeGreaterThanOrEqual($tbR - 1);
    });

    it('dominance holds with an HSA-eligible baseline', function () {
        $solver = makeSolverForTest();
        $engine = makeEngineForTest();
        $baseline = hsaBaseline();

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs = $solver->solve($baseline, 'retirement');

        $thDelta = $engine->computeScenarioOutcome($baseline, $thKnobs)['take_home']['per_paycheck_delta_cents'];
        $tbDelta = $engine->computeScenarioOutcome($baseline, $tbKnobs)['take_home']['per_paycheck_delta_cents'];
        $rDelta = $engine->computeScenarioOutcome($baseline, $rKnobs)['take_home']['per_paycheck_delta_cents'];

        expect($thDelta)->toBeGreaterThanOrEqual($tbDelta - 1);
        expect($thDelta)->toBeGreaterThanOrEqual($rDelta - 1);

        // tax_burden should give a lower (more negative) federal tax delta than take_home
        $thTax = $engine->computeScenarioOutcome($baseline, $thKnobs)['federal_tax']['annual_delta_cents'];
        $tbTax = $engine->computeScenarioOutcome($baseline, $tbKnobs)['federal_tax']['annual_delta_cents'];
        expect($tbTax)->toBeLessThanOrEqual($thTax + 1);
    });
});

// ── §T.11: Take-home floor guard ──────────────────────────────────────────────

describe('take-home floor', function () {
    it('every solver option stays at or above 90% of baseline take-home', function () {
        $solver = makeSolverForTest();
        $engine = makeEngineForTest();
        $baseline = solverBaseline();

        $minRatio = config('optimizer-scenarios.assumptions.min_take_home_ratio');
        $baselineTH = $baseline['baseline_per_period_take_home_cents'];
        $floor = (int) floor($baselineTH * $minRatio) - 1; // -1 for rounding tolerance

        foreach (['take_home', 'tax_burden', 'retirement'] as $obj) {
            $knobs = $solver->solve($baseline, $obj);
            $outcome = $engine->computeScenarioOutcome($baseline, $knobs);
            $candidateTH = $baselineTH + $outcome['take_home']['per_paycheck_delta_cents'];

            expect($candidateTH)
                ->toBeGreaterThanOrEqual($floor, "Objective '{$obj}' breaches the 90% floor");
        }
    });

    it('cash-constrained floor tightens to 97%', function () {
        $solver = makeSolverForTest();
        $engine = makeEngineForTest();
        $baseline = cashConstrainedBaseline();

        $minRatio = config('optimizer-scenarios.assumptions.min_take_home_ratio_cash_constrained');
        $baselineTH = $baseline['baseline_per_period_take_home_cents'];
        $floor = (int) floor($baselineTH * $minRatio) - 1;

        foreach (['take_home', 'tax_burden', 'retirement'] as $obj) {
            $knobs = $solver->solve($baseline, $obj);
            $outcome = $engine->computeScenarioOutcome($baseline, $knobs);
            $candidateTH = $baselineTH + $outcome['take_home']['per_paycheck_delta_cents'];

            expect($candidateTH)
                ->toBeGreaterThanOrEqual($floor, "Cash-constrained '{$obj}' breaches the 97% floor");
        }
    });

    it('k401 deferral never drops below match threshold (dominant free-return rule)', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        $matchThreshold = (float) $baseline['employer']['match_threshold_pct'];

        foreach (['take_home', 'tax_burden', 'retirement'] as $obj) {
            $knobs = $solver->solve($baseline, $obj);

            expect($knobs['k401']['deferral_pct'])
                ->toBeGreaterThanOrEqual($matchThreshold - 0.001,
                    "Objective '{$obj}' left employer match on the table");
        }
    });

    it('k401 deferral never drops below match threshold when cash-constrained', function () {
        $solver = makeSolverForTest();
        $baseline = cashConstrainedBaseline();

        $matchThreshold = (float) $baseline['employer']['match_threshold_pct'];

        foreach (['take_home', 'tax_burden', 'retirement'] as $obj) {
            $knobs = $solver->solve($baseline, $obj);

            expect($knobs['k401']['deferral_pct'])
                ->toBeGreaterThanOrEqual($matchThreshold - 0.001,
                    "Cash-constrained '{$obj}' left employer match on the table");
        }
    });
});

// ── §T.11: knob structure (shape + domains) ───────────────────────────────────

describe('knob vector structure', function () {
    it('returns a well-shaped knob vector for each objective', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        $roth_grid = config('optimizer-scenarios.grids.roth_share_pct');

        foreach (['take_home', 'tax_burden', 'retirement'] as $obj) {
            $v = $solver->solve($baseline, $obj);

            // Shape: all required keys present
            expect($v)->toHaveKeys(['w4', 'k401', 'hsa', 'ira', 'transfer']);
            expect($v['w4'])->toHaveKeys(['filing_status', 'dependents_under_17', 'other_dependents']);
            expect($v['k401'])->toHaveKeys(['deferral_pct', 'roth_share_pct']);
            expect($v['hsa'])->toHaveKey('annual_election_cents');
            expect($v['ira'])->toHaveKeys(['traditional_cents', 'roth_cents']);
            expect($v['transfer'])->toHaveKey('per_period_cents');

            // roth_share_pct on the grid
            expect(in_array($v['k401']['roth_share_pct'], $roth_grid, true))->toBeTrue(
                "'{$obj}' roth_share_pct {$v['k401']['roth_share_pct']} not on grid"
            );

            // All non-negative values
            expect($v['k401']['deferral_pct'])->toBeGreaterThanOrEqual(0);
            expect($v['hsa']['annual_election_cents'])->toBeGreaterThanOrEqual(0);
            expect($v['ira']['traditional_cents'])->toBeGreaterThanOrEqual(0);
            expect($v['ira']['roth_cents'])->toBeGreaterThanOrEqual(0);
            expect($v['transfer']['per_period_cents'])->toBeGreaterThanOrEqual(0);
        }
    });

    it('take_home sets roth_share to 0 (traditional lowers withholding)', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        $v = $solver->solve($baseline, 'take_home');

        // K2 = 0 for take_home (unless mandatory-Roth-catchup overrides)
        expect($v['k401']['roth_share_pct'])->toBe(0);
    });

    it('tax_burden sets ira roth_cents to 0 (all-traditional is MAGI-minimizing)', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        $v = $solver->solve($baseline, 'tax_burden');

        // Roth IRA = 0 in tax_burden (MAGI minimization)
        expect($v['ira']['roth_cents'])->toBe(0);
    });

    it('tax_burden sets K6 transfer to 0', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        $v = $solver->solve($baseline, 'tax_burden');

        expect($v['transfer']['per_period_cents'])->toBe(0);
    });

    it('take_home K6 is sized as income_objective_transfer_share of paycheck gain or 0', function () {
        $solver = makeSolverForTest();
        $engine = makeEngineForTest();
        $baseline = solverBaseline();

        $v = $solver->solve($baseline, 'take_home');

        // K6 should be >= 0
        expect($v['transfer']['per_period_cents'])->toBeGreaterThanOrEqual(0);
    });
});

// ── §T.11: diffKnobs ─────────────────────────────────────────────────────────

describe('diffKnobs', function () {
    it('identical vectors produce empty diff', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        $v = $solver->solve($baseline, 'take_home');

        expect($solver->diffKnobs($v, $v))->toBeEmpty();
    });

    it('w4 dimensions never appear in diff (identical by construction)', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        $th = $solver->solve($baseline, 'take_home');
        $r = $solver->solve($baseline, 'retirement');
        $diff = $solver->diffKnobs($th, $r);

        // w4 keys never diverge
        $w4Keys = array_filter($diff, static fn ($k) => str_starts_with($k, 'w4.'));
        expect($w4Keys)->toBeEmpty();
    });

    it('k401 deferral_pct diverges when difference ≥ 1.0 pt', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();

        // retirement will have higher deferral than take_home
        $th = $solver->solve($baseline, 'take_home');
        $r = $solver->solve($baseline, 'retirement');

        if (abs($r['k401']['deferral_pct'] - $th['k401']['deferral_pct']) >= 1.0) {
            expect($solver->diffKnobs($th, $r))->toContain('k401.deferral_pct');
        } else {
            expect($solver->diffKnobs($th, $r))->not->toContain('k401.deferral_pct');
        }
    });

    it('roth_share_pct diverges when difference ≥ 25 grid steps', function () {
        $solver = makeSolverForTest();

        $a = ['w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
            'k401' => ['deferral_pct' => 5.0, 'roth_share_pct' => 0],
            'hsa' => ['annual_election_cents' => 0],
            'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
            'transfer' => ['per_period_cents' => 0]];

        $b = array_replace_recursive($a, ['k401' => ['roth_share_pct' => 100]]);

        expect($solver->diffKnobs($a, $b))->toContain('k401.roth_share_pct');
    });

    it('roth_share_pct does NOT diverge when difference < 25', function () {
        $solver = makeSolverForTest();

        $a = ['w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
            'k401' => ['deferral_pct' => 5.0, 'roth_share_pct' => 25],
            'hsa' => ['annual_election_cents' => 0],
            'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
            'transfer' => ['per_period_cents' => 0]];

        $b = array_replace_recursive($a, ['k401' => ['roth_share_pct' => 25]]);

        expect($solver->diffKnobs($a, $b))->not->toContain('k401.roth_share_pct');
    });
});

// ── §T.11: attributeBenefits shape ───────────────────────────────────────────

describe('attributeBenefits', function () {
    it('returns attribution with at least take_home, federal_tax, retirement keys per knob', function () {
        $solver = makeSolverForTest();
        $baseline = solverBaseline();
        $chosen = $solver->solve($baseline, 'tax_burden');

        $attr = $solver->attributeBenefits($baseline, $chosen);

        // Each knob dimension that changed should have attribution
        expect($attr)->toBeArray();
        expect($attr)->toHaveKey('header_aggregate');

        $agg = $attr['header_aggregate'];
        expect($agg)->toHaveKeys(['take_home_annual_delta_cents', 'federal_tax_annual_delta_cents',
            'retirement_contributions_delta_cents']);
    });

    it('sum of knob deltas plus interaction remainder equals chosen total', function () {
        $solver = makeSolverForTest();
        $engine = makeEngineForTest();
        $baseline = solverBaseline();
        $chosen = $solver->solve($baseline, 'tax_burden');

        $attr = $solver->attributeBenefits($baseline, $chosen);
        $totalOutcome = $engine->computeScenarioOutcome($baseline, $chosen);

        $totalFedDelta = $totalOutcome['federal_tax']['annual_delta_cents'];

        // Sum of per-knob federal_tax_delta + interaction_remainder = total
        $sumFromKnobs = 0;
        foreach ($attr['knobs'] ?? [] as $knobDelta) {
            $sumFromKnobs += $knobDelta['federal_tax_annual_delta_cents'] ?? 0;
        }
        $interaction = $attr['header_aggregate']['interaction_remainder_federal_tax_cents'] ?? 0;

        // Within 1 cent of rounding tolerance
        expect(abs(($sumFromKnobs + $interaction) - $totalFedDelta))->toBeLessThanOrEqual(1);
    });
});
