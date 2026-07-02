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
