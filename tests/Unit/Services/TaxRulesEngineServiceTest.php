<?php

use App\Services\TaxRulesEngineService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

// ── No-Claude / No-HTTP Guard (TAX-07 / SAFE-03 foundation) ──────────────────
// This guard must run before any computation to prove zero outbound HTTP calls.

it('makes zero outbound HTTP calls during all computation paths (no-Claude guard)', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // Exercise every path that could theoretically make a network call
    $tax = $service->computeTax(5_000_000, 'single');
    $room401k = $service->remaining401kRoomCents(0, 61);
    $se = $service->selfEmploymentTax(10_000_000);
    $qbi = $service->qbiDeduction(5_000_000, 8_000_000, 'single', false);

    // If Http::preventStrayRequests() would have thrown — we'd never reach here.
    expect($tax)->toBeGreaterThanOrEqual(0)
        ->and($room401k)->toBeGreaterThanOrEqual(0)
        ->and($se['se_tax_cents'])->toBeGreaterThanOrEqual(0)
        ->and($qbi['eligible'])->toBeTrue();
});

// ── TAX-02: Bracket Tax Boundaries ───────────────────────────────────────────

it('taxes income of $12,399 entirely at 10% for single filer', function () {
    $service = new TaxRulesEngineService;

    // $12,399 is $1 below the 12% bracket threshold
    $incomeCents = Config::get('tax-rules.2026.brackets.single.0.to') * 100 - 100; // one dollar below
    $expectedCents = (int) round($incomeCents * 0.10);

    expect($service->computeTax($incomeCents, 'single'))->toBe($expectedCents);
});

it('marginal rate is 12% for single filer at the start of the 12% bracket', function () {
    $service = new TaxRulesEngineService;

    // The from-value of the second bracket (12%) in dollars
    $bracket12Start = Config::get('tax-rules.2026.brackets.single.1.from');
    $incomeCents = $bracket12Start * 100 + 1; // one cent inside the 12% bracket

    expect($service->marginalRate($incomeCents, 'single'))->toBe(0.12);
});

it('marginal rate is 22% for single filer at the start of the 22% bracket', function () {
    $service = new TaxRulesEngineService;

    $bracket22Start = Config::get('tax-rules.2026.brackets.single.2.from');
    $incomeCents = $bracket22Start * 100 + 1;

    expect($service->marginalRate($incomeCents, 'single'))->toBe(0.22);
});

it('marginal rate is correct at each bracket boundary for married_joint', function () {
    $service = new TaxRulesEngineService;

    $brackets = Config::get('tax-rules.2026.brackets.married_joint');

    foreach ($brackets as $i => $bracket) {
        if ($i === 0) {
            continue; // First bracket has no "start" to test
        }
        $incomeCents = $bracket['from'] * 100 + 1;
        expect($service->marginalRate($incomeCents, 'married_joint'))
            ->toBe((float) $bracket['rate'], "Bracket {$i} rate mismatch for married_joint");
    }
});

it('marginal rate is correct at each bracket boundary for married_separate', function () {
    $service = new TaxRulesEngineService;

    $brackets = Config::get('tax-rules.2026.brackets.married_separate');

    foreach ($brackets as $i => $bracket) {
        if ($i === 0) {
            continue;
        }
        $incomeCents = $bracket['from'] * 100 + 1;
        expect($service->marginalRate($incomeCents, 'married_separate'))
            ->toBe((float) $bracket['rate'], "Bracket {$i} rate mismatch for married_separate");
    }
});

it('marginal rate is correct at each bracket boundary for head_of_household', function () {
    $service = new TaxRulesEngineService;

    $brackets = Config::get('tax-rules.2026.brackets.head_of_household');

    foreach ($brackets as $i => $bracket) {
        if ($i === 0) {
            continue;
        }
        $incomeCents = $bracket['from'] * 100 + 1;
        expect($service->marginalRate($incomeCents, 'head_of_household'))
            ->toBe((float) $bracket['rate'], "Bracket {$i} rate mismatch for head_of_household");
    }
});

it('effective rate is less than or equal to marginal rate for any income', function () {
    $service = new TaxRulesEngineService;

    foreach ([500_000, 5_000_000, 15_000_000, 30_000_000, 70_000_000] as $incomeCents) {
        $effective = $service->effectiveRate($incomeCents, 'single');
        $marginal = $service->marginalRate($incomeCents, 'single');

        expect($effective)->toBeLessThanOrEqual($marginal,
            "Effective rate should never exceed marginal rate at {$incomeCents} cents");
    }
});

it('effective rate is 0 for zero income', function () {
    $service = new TaxRulesEngineService;

    expect($service->effectiveRate(0, 'single'))->toBe(0.0);
});

// ── TAX-03: Standard Deduction ────────────────────────────────────────────────

it('standard deduction cents for single equals config value times 100', function () {
    Config::set('tax-rules.2026.standard_deduction.single', 16_100);

    $service = new TaxRulesEngineService;
    $expected = Config::get('tax-rules.2026.standard_deduction.single') * 100;

    expect($service->standardDeductionCents('single'))->toBe($expected);
});

it('standard deduction cents for married_joint equals config value times 100', function () {
    $service = new TaxRulesEngineService;
    $expected = Config::get('tax-rules.2026.standard_deduction.married_joint') * 100;

    expect($service->standardDeductionCents('married_joint'))->toBe($expected);
});

it('standard deduction adds senior addition for age 65', function () {
    $service = new TaxRulesEngineService;

    $base = Config::get('tax-rules.2026.standard_deduction.single') * 100;
    $senior = Config::get('tax-rules.2026.standard_deduction_senior_addition.single') * 100;
    $expected = $base + $senior;

    expect($service->standardDeductionCents('single', age: 65))->toBe($expected);
});

it('standard deduction does not add senior addition for age 64', function () {
    $service = new TaxRulesEngineService;

    $base = Config::get('tax-rules.2026.standard_deduction.single') * 100;

    expect($service->standardDeductionCents('single', age: 64))->toBe($base);
});

it('compareStandardVsItemized recommends standard when itemized is lower', function () {
    $service = new TaxRulesEngineService;

    $standardCents = Config::get('tax-rules.2026.standard_deduction.single') * 100;
    $itemizedCents = $standardCents - 100_00; // $100 less than standard

    $result = $service->compareStandardVsItemized($itemizedCents, 'single');

    expect($result['recommendation'])->toBe('standard')
        ->and($result['standard_cents'])->toBe($standardCents)
        ->and($result['itemized_cents'])->toBe($itemizedCents);
});

it('compareStandardVsItemized recommends itemized when itemized is higher', function () {
    $service = new TaxRulesEngineService;

    $standardCents = Config::get('tax-rules.2026.standard_deduction.single') * 100;
    $itemizedCents = $standardCents + 100_00; // $100 more than standard

    $result = $service->compareStandardVsItemized($itemizedCents, 'single');

    expect($result['recommendation'])->toBe('itemized');
});

// ── TAX-04: Contribution Headroom ─────────────────────────────────────────────

it('401k room with no contributions equals base deferral limit for age 45', function () {
    Config::set('tax-rules.2026.401k.employee_deferral', 24_500);

    $service = new TaxRulesEngineService;
    $expected = Config::get('tax-rules.2026.401k.employee_deferral') * 100;

    expect($service->remaining401kRoomCents(0, age: 45))->toBe($expected);
});

it('401k room for age 50 includes 50-plus catch-up', function () {
    $service = new TaxRulesEngineService;

    $base = Config::get('tax-rules.2026.401k.employee_deferral') * 100;
    $catchup = Config::get('tax-rules.2026.401k.catchup_age_50_plus') * 100;
    $expected = $base + $catchup;

    expect($service->remaining401kRoomCents(0, age: 50))->toBe($expected);
});

it('401k room for age 59 includes 50-plus catch-up (not super catch-up)', function () {
    $service = new TaxRulesEngineService;

    $base = Config::get('tax-rules.2026.401k.employee_deferral') * 100;
    $catchup = Config::get('tax-rules.2026.401k.catchup_age_50_plus') * 100;
    $expected = $base + $catchup;

    expect($service->remaining401kRoomCents(0, age: 59))->toBe($expected);
});

it('401k room for age 61 includes super catch-up (SECURE 2.0 §109)', function () {
    $service = new TaxRulesEngineService;

    $base = Config::get('tax-rules.2026.401k.employee_deferral') * 100;
    $superCatchup = Config::get('tax-rules.2026.401k.catchup_age_60_to_63') * 100;
    $expected = $base + $superCatchup;

    expect($service->remaining401kRoomCents(0, age: 61))->toBe($expected);
});

it('401k room for age 64 uses 50-plus catch-up (not super catch-up)', function () {
    $service = new TaxRulesEngineService;

    $base = Config::get('tax-rules.2026.401k.employee_deferral') * 100;
    $catchup = Config::get('tax-rules.2026.401k.catchup_age_50_plus') * 100;
    $expected = $base + $catchup;

    expect($service->remaining401kRoomCents(0, age: 64))->toBe($expected);
});

it('401k room is never negative when ytd exceeds limit', function () {
    $service = new TaxRulesEngineService;

    $overLimit = 99_999_999_00; // wildly over any limit

    expect($service->remaining401kRoomCents($overLimit, age: 45))->toBe(0);
});

it('IRA room for age 55 includes 50-plus catch-up', function () {
    $service = new TaxRulesEngineService;

    $base = Config::get('tax-rules.2026.ira.annual_limit') * 100;
    $catchup = Config::get('tax-rules.2026.ira.catchup_age_50_plus') * 100;
    $expected = $base + $catchup;

    expect($service->remainingIraRoomCents(0, age: 55))->toBe($expected);
});

it('IRA room for age 49 does not include catch-up', function () {
    $service = new TaxRulesEngineService;

    $expected = Config::get('tax-rules.2026.ira.annual_limit') * 100;

    expect($service->remainingIraRoomCents(0, age: 49))->toBe($expected);
});

it('IRA room is never negative', function () {
    $service = new TaxRulesEngineService;

    expect($service->remainingIraRoomCents(99_999_999_00, age: 55))->toBe(0);
});

it('HSA family room for age 56 includes 55-plus catch-up', function () {
    $service = new TaxRulesEngineService;

    $base = Config::get('tax-rules.2026.hsa.family') * 100;
    $catchup = Config::get('tax-rules.2026.hsa.catchup_age_55_plus') * 100;
    $expected = $base + $catchup;

    expect($service->remainingHsaRoomCents(0, 'family', age: 56))->toBe($expected);
});

it('HSA self_only room for age 54 does not include catch-up', function () {
    $service = new TaxRulesEngineService;

    $expected = Config::get('tax-rules.2026.hsa.self_only') * 100;

    expect($service->remainingHsaRoomCents(0, 'self_only', age: 54))->toBe($expected);
});

// ── TAX-05: Roth vs Traditional Band ─────────────────────────────────────────

it('rothVsTraditionalBand returns roth at the prefer_roth_at_or_below threshold', function () {
    $service = new TaxRulesEngineService;

    $rate = Config::get('tax-rules.2026.roth_optimization.prefer_roth_at_or_below');

    expect($service->rothVsTraditionalBand($rate))->toBe('roth');
});

it('rothVsTraditionalBand returns roth for rate below prefer_roth_at_or_below', function () {
    $service = new TaxRulesEngineService;

    expect($service->rothVsTraditionalBand(0.10))->toBe('roth');
});

it('rothVsTraditionalBand returns traditional at the prefer_traditional_at_or_above threshold', function () {
    $service = new TaxRulesEngineService;

    $rate = Config::get('tax-rules.2026.roth_optimization.prefer_traditional_at_or_above');

    expect($service->rothVsTraditionalBand($rate))->toBe('traditional');
});

it('rothVsTraditionalBand returns split for 22% rate', function () {
    $service = new TaxRulesEngineService;

    expect($service->rothVsTraditionalBand(0.22))->toBe('split');
});

it('rothVsTraditionalBand returns split for 24% rate', function () {
    $service = new TaxRulesEngineService;

    expect($service->rothVsTraditionalBand(0.24))->toBe('split');
});

it('requiresMandatoryRothCatchup returns true when wages are at the threshold', function () {
    $service = new TaxRulesEngineService;

    // Read threshold from config — this test will fail if config changes, which is intentional (TAX-07)
    $threshold = Config::get('tax-rules.2026.401k.mandatory_roth_catchup_threshold');
    $wagesCents = $threshold * 100;

    expect($service->requiresMandatoryRothCatchup($wagesCents))->toBeTrue();
});

it('requiresMandatoryRothCatchup returns true when wages are above the threshold', function () {
    $service = new TaxRulesEngineService;

    $threshold = Config::get('tax-rules.2026.401k.mandatory_roth_catchup_threshold');
    $wagesCents = ($threshold + 10_000) * 100;

    expect($service->requiresMandatoryRothCatchup($wagesCents))->toBeTrue();
});

it('requiresMandatoryRothCatchup returns false when wages are below the threshold', function () {
    $service = new TaxRulesEngineService;

    $threshold = Config::get('tax-rules.2026.401k.mandatory_roth_catchup_threshold');
    $wagesCents = ($threshold - 1) * 100;

    expect($service->requiresMandatoryRothCatchup($wagesCents))->toBeFalse();
});

it('requiresMandatoryRothCatchup result flips when config threshold is changed (TAX-07 wiring test)', function () {
    $service = new TaxRulesEngineService;

    // Override threshold to $200,000 for this test
    Config::set('tax-rules.2026.401k.mandatory_roth_catchup_threshold', 200_000);

    $wagesCents = 150_000 * 100; // Would trigger with default config, but not with $200k threshold

    expect($service->requiresMandatoryRothCatchup($wagesCents))->toBeFalse();
});

// ── TAX-06: SE Tax and QBI ────────────────────────────────────────────────────

it('selfEmploymentTax computes correctly using config multiplier and rates', function () {
    $service = new TaxRulesEngineService;

    $netProfitCents = 100_000 * 100; // $100,000

    $cfg = Config::get('tax-rules.2026.se_tax');
    $netEarnings = (int) round($netProfitCents * $cfg['net_earnings_multiplier']);
    $wageBase = $cfg['ss_wage_base'] * 100;
    $expectedSs = (int) round(min($netEarnings, $wageBase) * $cfg['ss_rate']);
    $expectedMedicare = (int) round($netEarnings * $cfg['medicare_rate']);
    $expectedSeTax = $expectedSs + $expectedMedicare;
    $expectedDeductible = (int) round($expectedSeTax * $cfg['deductible_fraction']);

    $result = $service->selfEmploymentTax($netProfitCents);

    expect($result['se_tax_cents'])->toBe($expectedSeTax)
        ->and($result['deductible_half_cents'])->toBe($expectedDeductible);
});

it('selfEmploymentTax caps SS portion at the wage base', function () {
    $service = new TaxRulesEngineService;

    $wageBaseDollars = Config::get('tax-rules.2026.se_tax.ss_wage_base');
    // Net earnings after multiplier will exceed wage base
    $multiplier = Config::get('tax-rules.2026.se_tax.net_earnings_multiplier');
    // Choose profit such that net_earnings > ss_wage_base
    $netProfitCents = (int) round(($wageBaseDollars * 100 / $multiplier) * 2);

    $cfg = Config::get('tax-rules.2026.se_tax');
    $netEarnings = (int) round($netProfitCents * $cfg['net_earnings_multiplier']);
    $wageBaseCents = $cfg['ss_wage_base'] * 100;

    // SS should be capped
    $expectedSs = (int) round($wageBaseCents * $cfg['ss_rate']);
    $expectedMedicare = (int) round($netEarnings * $cfg['medicare_rate']);
    $expectedSeTax = $expectedSs + $expectedMedicare;

    $result = $service->selfEmploymentTax($netProfitCents);

    expect($result['se_tax_cents'])->toBe($expectedSeTax);
});

it('qbiDeduction below threshold returns 20% of QBI', function () {
    $service = new TaxRulesEngineService;

    $qbiRate = Config::get('tax-rules.2026.qbi.rate');
    $qbiCents = 50_000 * 100;
    $taxableCents = 80_000 * 100; // well below $201,750 single threshold

    $expected = (int) round($qbiCents * $qbiRate);

    $result = $service->qbiDeduction($qbiCents, $taxableCents, 'single', false);

    expect($result['eligible'])->toBeTrue()
        ->and($result['deduction_cents'])->toBe($expected)
        ->and($result['reason'])->toBe('below_phase_out_threshold');
});

it('qbiDeduction above threshold for non-SSTB returns professional-review sentinel', function () {
    $service = new TaxRulesEngineService;

    $phaseOutStart = Config::get('tax-rules.2026.qbi.phase_out_start_single') * 100;
    $phaseOutWindow = Config::get('tax-rules.2026.qbi.phase_out_window_single') * 100;
    $aboveThreshold = $phaseOutStart + $phaseOutWindow + 1_000_00; // above full phase-out

    $result = $service->qbiDeduction(50_000 * 100, $aboveThreshold, 'single', false);

    expect($result['eligible'])->toBeTrue()
        ->and($result['deduction_cents'])->toBeNull()
        ->and($result['reason'])->toBe('above_threshold_requires_professional_review');
});

it('qbiDeduction for SSTB above full phase-out returns zero and ineligible', function () {
    $service = new TaxRulesEngineService;

    $phaseOutStart = Config::get('tax-rules.2026.qbi.phase_out_start_single') * 100;
    $phaseOutWindow = Config::get('tax-rules.2026.qbi.phase_out_window_single') * 100;
    $aboveThreshold = $phaseOutStart + $phaseOutWindow + 1_000_00;

    $result = $service->qbiDeduction(50_000 * 100, $aboveThreshold, 'single', true);

    expect($result['eligible'])->toBeFalse()
        ->and($result['deduction_cents'])->toBe(0)
        ->and($result['reason'])->toBe('sstb_fully_phased_out');
});

it('qbiDeduction applies config-driven rate (TAX-07 wiring)', function () {
    Config::set('tax-rules.2026.qbi.rate', 0.25); // Override to 25%

    $service = new TaxRulesEngineService;

    $qbiCents = 10_000 * 100;
    $taxableCents = 50_000 * 100;

    $result = $service->qbiDeduction($qbiCents, $taxableCents, 'single', false);

    expect($result['deduction_cents'])->toBe((int) round($qbiCents * 0.25));
});

// ── Property Tests ────────────────────────────────────────────────────────────

it('marginal rate is monotonic non-decreasing as income rises (single)', function () {
    $service = new TaxRulesEngineService;

    $incomes = [
        100 * 100,
        5_000 * 100,
        15_000 * 100,
        60_000 * 100,
        120_000 * 100,
        250_000 * 100,
        700_000 * 100,
    ];

    $prev = 0.0;
    foreach ($incomes as $incomeCents) {
        $rate = $service->marginalRate($incomeCents, 'single');
        expect($rate)->toBeGreaterThanOrEqual($prev,
            "Marginal rate should not decrease as income increases: {$incomeCents} cents");
        $prev = $rate;
    }
});

it('401k headroom is never negative for any ytd amount', function () {
    $service = new TaxRulesEngineService;

    foreach ([0, 1_000_00, 10_000_00, 100_000_00, 99_999_999_00] as $ytd) {
        expect($service->remaining401kRoomCents($ytd, age: 50))->toBeGreaterThanOrEqual(0);
    }
});

it('IRA headroom is never negative for any ytd amount', function () {
    $service = new TaxRulesEngineService;

    foreach ([0, 500_00, 10_000_00, 99_999_999_00] as $ytd) {
        expect($service->remainingIraRoomCents($ytd, age: 55))->toBeGreaterThanOrEqual(0);
    }
});

it('HSA headroom is never negative for any ytd amount', function () {
    $service = new TaxRulesEngineService;

    foreach ([0, 100_00, 5_000_00, 99_999_999_00] as $ytd) {
        expect($service->remainingHsaRoomCents($ytd, 'family', age: 60))->toBeGreaterThanOrEqual(0);
    }
});

// ── Input Validation ──────────────────────────────────────────────────────────

it('throws InvalidArgumentException for unknown filing status', function () {
    $service = new TaxRulesEngineService;

    expect(fn () => $service->computeTax(5_000_00, 'invalid_status'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException for negative income', function () {
    $service = new TaxRulesEngineService;

    expect(fn () => $service->computeTax(-1, 'single'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException for negative income in marginalRate', function () {
    $service = new TaxRulesEngineService;

    expect(fn () => $service->marginalRate(-100, 'single'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException for unsupported tax year', function () {
    $service = new TaxRulesEngineService;

    expect(fn () => $service->computeTax(5_000_00, 'single', 1900))
        ->toThrow(InvalidArgumentException::class);
});

// ── taxSavingsFromDeductionCents ──────────────────────────────────────────────

it('taxSavingsFromDeductionCents equals deduction times marginal rate', function () {
    $service = new TaxRulesEngineService;

    $incomeCents = 70_000 * 100; // In the 22% bracket for single
    $deductionCents = 5_000 * 100;

    $rate = $service->marginalRate($incomeCents, 'single');
    $expected = (int) round($deductionCents * $rate);

    expect($service->taxSavingsFromDeductionCents($deductionCents, $incomeCents, 'single'))
        ->toBe($expected);
});
