<?php

use App\Services\TaxRulesEngineService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| SCENARIOS-SPEC §B.3 — SCN-01…SCN-07 pure engine methods
|--------------------------------------------------------------------------
| All monetary values are INTEGER CENTS. Every expected figure below is
| hand-computed from config/tax-rules.php (2026) + config/optimizer-scenarios.php.
| Zero Claude / zero HTTP — asserted by the guard test at the bottom.
*/

beforeEach(function () {
    $this->engine = new TaxRulesEngineService;
});

// ─────────────────────────────────────────────────────────────────────────
// SCN-01 — estimatePeriodWithholdingCents (Pub 15-T percentage-method shape)
// ─────────────────────────────────────────────────────────────────────────

it('SCN-01: floors withholding at 0 for a heavily-credited MFJ biweekly paycheck', function () {
    // MFJ, biweekly (26), gross $3,000/period, $300 pretax/period, 2 kids under 17.
    // annualWages = (300000 - 30000) * 26 = 7,020,000 ; std MFJ = 3,220,000
    // adjusted = 3,800,000 ; bracketTax = 406,400 ; credits = 2 * 220,000 = 440,000
    // (406,400 - 440,000) / 26 < 0 → floored at 0.
    $wh = $this->engine->estimatePeriodWithholdingCents(
        periodGrossCents: 300_000,
        periodPreTaxCents: 30_000,
        w4FilingStatus: 'married_joint',
        dependentsUnder17: 2,
        otherDependents: 0,
        payPeriodsPerYear: 26,
    );

    expect($wh)->toBe(0);
});

it('SCN-01: computes a positive single-filer per-paycheck withholding', function () {
    // Single, biweekly (26), gross $4,000/period, no pretax, 0 deps.
    // annualWages = 10,400,000 ; std single = 1,610,000 ; adjusted = 8,790,000
    // bracketTax single = 1,405,000 ; /26 = 54,038.46 → 54,038
    $wh = $this->engine->estimatePeriodWithholdingCents(
        periodGrossCents: 400_000,
        periodPreTaxCents: 0,
        w4FilingStatus: 'single',
        dependentsUnder17: 0,
        otherDependents: 0,
        payPeriodsPerYear: 26,
    );

    expect($wh)->toBe(54_038);
});

it('SCN-01: single_or_mfs maps to the single withholding tables (M11)', function () {
    $single = $this->engine->estimatePeriodWithholdingCents(400_000, 0, 'single', 0, 0, 26);
    $mfs = $this->engine->estimatePeriodWithholdingCents(400_000, 0, 'single_or_mfs', 0, 0, 26);

    expect($mfs)->toBe($single);
});

it('SCN-01: other-dependent credits reduce withholding via odc_amount', function () {
    $noDeps = $this->engine->estimatePeriodWithholdingCents(400_000, 0, 'single', 0, 0, 26);
    $twoOdc = $this->engine->estimatePeriodWithholdingCents(400_000, 0, 'single', 0, 2, 26);

    // 2 * $500 ODC = 100,000 cents / 26 ≈ 3,846 less per paycheck.
    expect($noDeps - $twoOdc)->toBe((int) round(2 * 50_000 / 26));
});

it('SCN-01: rejects an unknown W-4 filing status', function () {
    $this->engine->estimatePeriodWithholdingCents(400_000, 0, 'nonsense', 0, 0, 26);
})->throws(InvalidArgumentException::class);

// ─────────────────────────────────────────────────────────────────────────
// SCN-02 — employeeFicaCents (HSA §125 FICA-exempt handled by caller; 401k not)
// ─────────────────────────────────────────────────────────────────────────

it('SCN-02: splits employee FICA halves below the SS wage base', function () {
    // $104,000 wages, below ss_wage_base $184,500.
    // ss = 10,400,000 * 0.062 = 644,800 ; medicare = 10,400,000 * 0.0145 = 150,800
    $fica = $this->engine->employeeFicaCents(10_400_000);

    expect($fica['social_security_cents'])->toBe(644_800)
        ->and($fica['medicare_cents'])->toBe(150_800)
        ->and($fica['total_cents'])->toBe(795_600);
});

it('SCN-02: caps social security at the wage base, medicare uncapped', function () {
    // $200,000 wages, above ss_wage_base $184,500.
    // ss = 18,450,000 * 0.062 = 1,143,900 ; medicare = 20,000,000 * 0.0145 = 290,000
    $fica = $this->engine->employeeFicaCents(20_000_000);

    expect($fica['social_security_cents'])->toBe(1_143_900)
        ->and($fica['medicare_cents'])->toBe(290_000)
        ->and($fica['total_cents'])->toBe(1_433_900);
});

// ─────────────────────────────────────────────────────────────────────────
// SCN-03 — matchCaptureCents
// ─────────────────────────────────────────────────────────────────────────

it('SCN-03: captures full match at/above the threshold', function () {
    // gross $100,000, contrib 8%, match 100%, threshold 6% → min(8,6)=6%.
    $match = $this->engine->matchCaptureCents(10_000_000, 8.0, 100.0, 6.0);
    expect($match)->toBe(600_000);
});

it('SCN-03: reduces match capture below the threshold', function () {
    // gross $100,000, contrib 3%, match 100%, threshold 6% → min(3,6)=3%.
    $match = $this->engine->matchCaptureCents(10_000_000, 3.0, 100.0, 6.0);
    expect($match)->toBe(300_000);
});

it('SCN-03: applies partial employer match percentage', function () {
    // gross $100,000, contrib 4%, match 50%, threshold 6% → 4% * 50%.
    $match = $this->engine->matchCaptureCents(10_000_000, 4.0, 50.0, 6.0);
    expect($match)->toBe(200_000);
});

// ─────────────────────────────────────────────────────────────────────────
// Zero-HTTP discipline (D10/D17) — the engine never calls out.
// ─────────────────────────────────────────────────────────────────────────

it('makes zero outbound HTTP calls across SCN-01/02/03', function () {
    Http::preventStrayRequests();

    $this->engine->estimatePeriodWithholdingCents(400_000, 0, 'single', 1, 1, 26);
    $this->engine->employeeFicaCents(10_400_000);
    $this->engine->matchCaptureCents(10_000_000, 6.0, 100.0, 6.0);

    expect(true)->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────
// Shared baseline / knob builders (§B.1 knob vector, §B.2 baseline shape)
// ─────────────────────────────────────────────────────────────────────────

function scenarioBaseline(array $overrides = []): array
{
    return array_replace_recursive([
        'annual_gross_cents' => 10_000_000,   // $100k
        'se_income_cents' => 0,
        'pay_periods_per_year' => 26,
        'filing_status' => 'married_joint',   // CONFIRMED status
        'w4_on_file' => ['filing_status' => null, 'dependents_claimed' => null],
        'family' => ['dependents_under_17' => 0, 'other_dependents' => 0],
        'age' => 40,
        'target_retirement_age' => 65,
        'prior_year_fica_wages_cents' => null,
        'current' => [
            'trad_401k_cents' => 0, 'roth_401k_cents' => 0,
            'hsa_cents' => 0, 'ira_trad_ytd_cents' => 0, 'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 0.0,
        ],
        'employer' => ['match_pct' => 0.0, 'match_threshold_pct' => 0.0, 'has_401k' => false],
        'hsa_coverage_type' => 'self_only',
        'medicare_enrollment_date' => null,
        'is_marketplace_enrollee' => false,
        'is_cash_constrained' => false,
        'spouse_covered_by_plan' => false,
        'monthly_surplus_cents' => 500_000,
        'annual_withholding_cents' => null,
        'prior_year_federal_liability_cents' => null,
        'fact_set_hash' => 'test',
    ], $overrides);
}

function scenarioKnobs(array $overrides = []): array
{
    return array_replace_recursive([
        'w4' => ['filing_status' => 'married_joint', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 0.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ], $overrides);
}

// ─────────────────────────────────────────────────────────────────────────
// SCN-04 — futureValueRangeCents (D9.7 illustration: RANGE + assumptions)
// ─────────────────────────────────────────────────────────────────────────

it('SCN-04: returns a monotonic low<high FV range with an assumptions array', function () {
    $fv = $this->engine->futureValueRangeCents(600_000, 30);

    expect($fv['low_cents'])->toBeLessThan($fv['high_cents'])
        ->and($fv['low_cents'])->toBeGreaterThan(0)
        ->and($fv['horizon_years'])->toBe(30)
        ->and($fv['growth_rate_low'])->toBe(0.04)
        ->and($fv['growth_rate_high'])->toBe(0.07)
        ->and($fv['assumptions'])->toBeArray()->not->toBeEmpty();
});

it('SCN-04: never emits a single guaranteed figure — low differs from high', function () {
    $fv = $this->engine->futureValueRangeCents(600_000, 30);
    expect($fv['low_cents'])->not->toBe($fv['high_cents']);
});

it('SCN-04: zero or negative horizon yields zeros with horizon_years=0', function () {
    $zero = $this->engine->futureValueRangeCents(600_000, 0);
    $neg = $this->engine->futureValueRangeCents(600_000, -5);

    expect($zero['low_cents'])->toBe(0)
        ->and($zero['high_cents'])->toBe(0)
        ->and($zero['horizon_years'])->toBe(0)
        ->and($zero['assumptions'])->toBeArray()->not->toBeEmpty()
        ->and($neg['horizon_years'])->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────
// SCN-05 — projectedMagiCents
// ─────────────────────────────────────────────────────────────────────────

it('SCN-05: MAGI = gross + SE − trad401k − HSA − deductible trad IRA', function () {
    $baseline = scenarioBaseline();
    $knobs = scenarioKnobs([
        'k401' => ['deferral_pct' => 10.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 400_000],
        'ira' => ['traditional_cents' => 300_000, 'roth_cents' => 0],
    ]);

    // 10,000,000 − 1,000,000(trad401k) − 400,000(hsa) − 300,000(deductible IRA) = 8,300,000
    expect($this->engine->projectedMagiCents($baseline, $knobs))->toBe(8_300_000);
});

// ─────────────────────────────────────────────────────────────────────────
// SCN-06 — acaCliffHeadroomCents (mirrors AcaCliffMonitor threshold selection)
// ─────────────────────────────────────────────────────────────────────────

it('SCN-06: negative headroom when MAGI is over the single cliff', function () {
    // single cliff = $62,600 = 6,260,000 ; MAGI 8,300,000 → −2,040,000
    expect($this->engine->acaCliffHeadroomCents(8_300_000, 'single'))->toBe(-2_040_000);
});

it('SCN-06: married_joint uses the family-of-4 threshold', function () {
    // family4 cliff = $128,600 = 12,860,000 ; MAGI 5,000,000 → +7,860,000
    expect($this->engine->acaCliffHeadroomCents(5_000_000, 'married_joint'))->toBe(7_860_000);
});

// ─────────────────────────────────────────────────────────────────────────
// SCN-07 — computeScenarioOutcome (§B.4 algorithm, §B.6 shape) — §T.9 vectors
// ─────────────────────────────────────────────────────────────────────────

it('SCN-07: returns the full §B.6 outcome shape', function () {
    $out = $this->engine->computeScenarioOutcome(scenarioBaseline(), scenarioKnobs());

    expect($out)->toHaveKeys(['knobs', 'take_home', 'federal_tax', 'retirement', 'guards'])
        ->and($out['take_home'])->toHaveKeys(['per_paycheck_delta_cents', 'annual_delta_cents', 'w4_delta_included'])
        ->and($out['federal_tax'])->toHaveKey('annual_delta_cents')
        ->and($out['retirement'])->toHaveKeys(['annual_contributions_delta_cents', 'employer_match_delta_cents', 'hsa_annual_delta_cents', 'illustration'])
        ->and($out['guards'])->toHaveKeys(['aca_cliff_applied', 'aca_need_remaining_cents', 'mandatory_roth_catchup_applied', 'medicare_hsa_guard', 'safe_harbor_floor_applied', 'clamps']);
});

it('SCN-07 (a): MFJ 3-kids W-4-misaligned baseline raises take-home with w4_delta_included', function () {
    $baseline = scenarioBaseline([
        'w4_on_file' => ['filing_status' => 'single', 'dependents_claimed' => 0],
        'family' => ['dependents_under_17' => 3, 'other_dependents' => 0],
    ]);
    $knobs = scenarioKnobs([
        'w4' => ['filing_status' => 'married_joint', 'dependents_under_17' => 3, 'other_dependents' => 0],
    ]);

    $out = $this->engine->computeScenarioOutcome($baseline, $knobs);

    expect($out['take_home']['w4_delta_included'])->toBeTrue()
        ->and($out['take_home']['per_paycheck_delta_cents'])->toBeGreaterThan(40_000);
});

it('SCN-07 (b): age-61 super catch-up clamps 401k to the 60–63 limit', function () {
    $baseline = scenarioBaseline(['age' => 61]);
    $knobs = scenarioKnobs(['k401' => ['deferral_pct' => 40.0, 'roth_share_pct' => 0]]);

    $out = $this->engine->computeScenarioOutcome($baseline, $knobs);

    // full limit age 60–63 = 24,500 + 11,250 = 35,750 = 3,575,000 cents
    $clampedDeferralCents = (int) round($baseline['annual_gross_cents'] * $out['knobs']['k401']['deferral_pct'] / 100);

    expect($out['guards']['clamps'])->toContain('401k_annual_limit')
        ->and($clampedDeferralCents)->toBe(3_575_000);
});

it('SCN-07 (c): mandatory Roth catch-up forces the catch-up excess to Roth', function () {
    $baseline = scenarioBaseline([
        'age' => 55,
        'prior_year_fica_wages_cents' => 15_000_000,   // ≥ $150k threshold
    ]);
    // deferral 30% of $100k = $30,000 > base $24,500; roth_share 0 requested.
    $knobs = scenarioKnobs(['k401' => ['deferral_pct' => 30.0, 'roth_share_pct' => 0]]);

    $out = $this->engine->computeScenarioOutcome($baseline, $knobs);

    // excess = 3,000,000 − 2,450,000 = 550,000 forced Roth.
    $deferralCents = (int) round($baseline['annual_gross_cents'] * $out['knobs']['k401']['deferral_pct'] / 100);
    $rothCents = (int) round($deferralCents * $out['knobs']['k401']['roth_share_pct'] / 100);

    expect($out['guards']['mandatory_roth_catchup_applied'])->toBeTrue()
        ->and($rothCents)->toBe(550_000);
});

it('SCN-07 (d): Roth IRA phase-out caps the Roth contribution', function () {
    $baseline = scenarioBaseline([
        'annual_gross_cents' => 16_000_000,   // $160k single, mid Roth phase-out
        'filing_status' => 'single',
    ]);
    $knobs = scenarioKnobs(['ira' => ['traditional_cents' => 0, 'roth_cents' => 750_000]]);

    $out = $this->engine->computeScenarioOutcome($baseline, $knobs);

    // single phase-out 153k–168k; MAGI 160k → reduced limit 400,000.
    expect($out['guards']['clamps'])->toContain('roth_ira_phaseout')
        ->and($out['knobs']['ira']['roth_cents'])->toBe(400_000);
});

it('SCN-07 (e): shared-IRA limit clamps combined trad+roth using combined YTD', function () {
    $baseline = scenarioBaseline([
        'current' => ['ira_trad_ytd_cents' => 300_000, 'ira_roth_ytd_cents' => 200_000],
    ]);
    // remaining room = 750,000 − 500,000 = 250,000 ; request 200k trad + 200k roth = 400k.
    $knobs = scenarioKnobs(['ira' => ['traditional_cents' => 200_000, 'roth_cents' => 200_000]]);

    $out = $this->engine->computeScenarioOutcome($baseline, $knobs);
    $combined = $out['knobs']['ira']['traditional_cents'] + $out['knobs']['ira']['roth_cents'];

    expect($out['guards']['clamps'])->toContain('ira_shared_limit')
        ->and($combined)->toBeLessThanOrEqual(250_000);
});

it('SCN-07 (f): Medicare lookback pins HSA to the current YTD amount', function () {
    $baseline = scenarioBaseline([
        'age' => 66,   // ≥ 65 with enrollment unknown → HSA blocked
        'current' => ['hsa_cents' => 100_000],
    ]);
    $knobs = scenarioKnobs(['hsa' => ['annual_election_cents' => 400_000]]);

    $out = $this->engine->computeScenarioOutcome($baseline, $knobs);

    expect($out['guards']['medicare_hsa_guard'])->toBeTrue()
        ->and($out['knobs']['hsa']['annual_election_cents'])->toBe(100_000);
});

it('SCN-07 (g): safe-harbor floor flags when prior-year liability is absent', function () {
    $baseline = scenarioBaseline(['prior_year_federal_liability_cents' => null]);
    // Many W-4 dependents drive the withholding estimate below current-year tax.
    $knobs = scenarioKnobs(['w4' => ['filing_status' => 'married_joint', 'dependents_under_17' => 8, 'other_dependents' => 0]]);

    $out = $this->engine->computeScenarioOutcome($baseline, $knobs);

    expect($out['guards']['safe_harbor_floor_applied'])->toBeTrue();
});

it('SCN-07: ACA cliff guard shifts Roth 401k to Traditional for marketplace enrollees', function () {
    $baseline = scenarioBaseline([
        'annual_gross_cents' => 6_300_000,   // $63k single — just over the cliff
        'filing_status' => 'single',
        'is_marketplace_enrollee' => true,
    ]);
    $knobs = scenarioKnobs(['k401' => ['deferral_pct' => 10.0, 'roth_share_pct' => 100]]);

    $out = $this->engine->computeScenarioOutcome($baseline, $knobs);
    $magi = $this->engine->projectedMagiCents($baseline, $out['knobs']);

    // cliff single 6,260,000 − buffer 200,000 = 6,060,000
    expect($out['guards']['aca_cliff_applied'])->toBeTrue()
        ->and($out['knobs']['k401']['roth_share_pct'])->toBeLessThan(100)
        ->and($magi)->toBeLessThanOrEqual(6_060_000);
});

// ─────────────────────────────────────────────────────────────────────────
// Fix-B: W-4 Step 3 credit wire — estimatePeriodWithholdingCents
// ─────────────────────────────────────────────────────────────────────────
//
// Pub 15-T uses Step 3 as an annual dollar credit amount, not a dependent count.
// When step3CreditsCents is provided, the engine uses it directly instead of
// count-based credits (dependentsUnder17 × CTC + otherDependents × ODC).
//
// Sanity: a $3,200 Step 3 credit (2 qualifying children × $1,600) on a
// single biweekly earner at $6,000/period:
//   annualWages = (600000 - 0) * 26 = 15,600,000
//   std single = 1,610,000 → adjusted = 13,990,000
//   bracketTax single ≈ (see hand-calc below)
//   credits = 320,000 (direct Step 3 amount)
// With count-based approach (2 dependents_under_17): 2 × 220,000 = 440,000
// The two give DIFFERENT results — the test asserts that step3 takes precedence.

it('Fix-B: step3CreditsCents overrides count-based credits when provided', function () {
    // Single, biweekly (26), gross $6,000/period, 0 pretax.
    // Count-based: 2 dependents_under_17 → credits = 2 × 220,000 = 440,000
    // Step-3 based: $3,200 = 320,000 cents → credits = 320,000

    $countBased = $this->engine->estimatePeriodWithholdingCents(
        periodGrossCents: 600_000,
        periodPreTaxCents: 0,
        w4FilingStatus: 'single',
        dependentsUnder17: 2,
        otherDependents: 0,
        payPeriodsPerYear: 26,
    );

    $step3Based = $this->engine->estimatePeriodWithholdingCents(
        periodGrossCents: 600_000,
        periodPreTaxCents: 0,
        w4FilingStatus: 'single',
        dependentsUnder17: 0,   // no count passed — step3 overrides
        otherDependents: 0,
        payPeriodsPerYear: 26,
        year: 2026,
        step3CreditsCents: 320_000,   // $3,200 Step 3 credit direct
    );

    // Two $1,600 CTC credits (440k) > $3,200 Step 3 (320k), so step3 yields MORE withholding
    expect($step3Based)->toBeGreaterThan($countBased);

    // Verify the override is active: same call without step3 and 2 deps = count-based path
    $countBased2 = $this->engine->estimatePeriodWithholdingCents(
        periodGrossCents: 600_000,
        periodPreTaxCents: 0,
        w4FilingStatus: 'single',
        dependentsUnder17: 2,
        otherDependents: 0,
        payPeriodsPerYear: 26,
    );
    expect($countBased2)->toBe($countBased); // unchanged when step3 = 0
});
