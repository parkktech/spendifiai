<?php

use App\Services\TaxRulesEngineService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

// ── No-Claude / No-HTTP Guard ─────────────────────────────────────────────────
// validateRule() and passesMaterialityGate() must make ZERO outbound HTTP calls.

it('makes zero outbound HTTP calls when validating rules and materiality gate (no-Claude guard)', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // Exercise all new paths
    $result = $service->validateRule('tips_deduction');
    $passes = $service->passesMaterialityGate(100_00, false, 0); // $100 single
    $severity = $service->bandToSeverity('auto');

    expect($result)->toBeArray()
        ->and($passes)->toBeBool()
        ->and($severity)->toBeString();
});

// ── TAX-09: Expired Rule → suppressed=true ────────────────────────────────────

it('validateRule suppresses a rule whose effective_end has passed', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // residential_energy_credit_25d expired 2025-12-31; current date is 2026-07-01
    $result = $service->validateRule('residential_energy_credit_25d');

    expect($result['suppressed'])->toBeTrue()
        ->and($result['status'])->toBe('expired');
});

it('validateRule suppresses ev_credit_30d which expired 2025-09-30', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    $result = $service->validateRule('ev_credit_30d');

    expect($result['suppressed'])->toBeTrue()
        ->and($result['status'])->toBe('expired');
});

// ── TAX-09: band=suppress → suppressed=true ───────────────────────────────────

it('validateRule suppresses a rule with band=suppress regardless of dates', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    $result = $service->validateRule('residential_solar_2026_primary_home');

    expect($result['suppressed'])->toBeTrue()
        ->and($result['band'])->toBe('suppress');
});

it('validateRule suppresses gambling_losses_fully_deductible (band=suppress)', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    $result = $service->validateRule('gambling_losses_fully_deductible');

    expect($result['suppressed'])->toBeTrue()
        ->and($result['band'])->toBe('suppress');
});

// ── TAX-09: Simulated hard_block band → suppressed=true ──────────────────────

it('validateRule suppresses a rule with band=hard_block', function () {
    Http::preventStrayRequests();

    // Override config to inject a hard_block test rule
    Config::set('tax-detection.rules.test_hard_block', [
        'rule_id' => 'test_hard_block',
        'authority' => 'Test',
        'effective_start' => '2020-01-01',
        'effective_end' => '2030-12-31',    // Not expired
        'phaseouts' => [],
        'inflation_adjusted' => false,
        'source_url' => 'https://example.com',
        'last_verified' => '2026-07-01',
        'status' => 'verified',
        'band' => 'hard_block',
    ]);

    $service = new TaxRulesEngineService;
    $result = $service->validateRule('test_hard_block');

    expect($result['suppressed'])->toBeTrue()
        ->and($result['band'])->toBe('hard_block');
});

// ── TAX-09: Stale last_verified → stale=true ─────────────────────────────────

it('validateRule returns stale=true when last_verified exceeds staleness_days', function () {
    Http::preventStrayRequests();

    // Override config to inject a rule with an old last_verified date
    $staleDays = config('tax-detection.staleness_days', 90);
    $staleDate = now()->subDays($staleDays + 10)->format('Y-m-d');

    Config::set('tax-detection.rules.test_stale', [
        'rule_id' => 'test_stale',
        'authority' => 'Test',
        'effective_start' => '2025-01-01',
        'effective_end' => '2028-12-31',
        'phaseouts' => [],
        'inflation_adjusted' => false,
        'source_url' => 'https://example.com',
        'last_verified' => $staleDate,
        'status' => 'verified',
        'band' => 'auto',
    ]);

    $service = new TaxRulesEngineService;
    $result = $service->validateRule('test_stale');

    expect($result['stale'])->toBeTrue()
        ->and($result['suppressed'])->toBeFalse(); // stale != suppressed
});

it('validateRule returns stale=false for a recently verified rule', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // tips_deduction has last_verified = 2026-07-01, staleness_days = 90
    $result = $service->validateRule('tips_deduction');

    expect($result['stale'])->toBeFalse();
});

// ── TAX-09: Active Rule Within Effective Window → suppressed=false ────────────

it('validateRule returns suppressed=false for a currently effective rule', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // tips_deduction: effective 2025-01-01 to 2028-12-31; current date is 2026-07-01
    $result = $service->validateRule('tips_deduction');

    expect($result['suppressed'])->toBeFalse()
        ->and($result['band'])->toBe('auto')
        ->and($result['status'])->toBe('verified');
});

it('validateRule returns suppressed=false for salt_deduction_cap (expires 2029)', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    $result = $service->validateRule('salt_deduction_cap');

    expect($result['suppressed'])->toBeFalse();
});

// ── TAX-09: Unknown Rule → throws InvalidArgumentException ───────────────────

it('validateRule throws on an unknown rule_id', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    expect(fn () => $service->validateRule('nonexistent_rule_xyz'))
        ->toThrow(InvalidArgumentException::class);
});

// ── TAX-09: Sunset Boundary Assertions ───────────────────────────────────────
// Test both sides of each sunset date: one day after → suppressed; one day before → active.

it('tips_deduction is suppressed one day after 2028-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2029-01-01'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('tips_deduction');
        expect($result['suppressed'])->toBeTrue()
            ->and($result['status'])->toBe('expired');
    } finally {
        Carbon::setTestNow(); // restore
    }
});

it('tips_deduction is active one day before 2028-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2028-12-30'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('tips_deduction');
        expect($result['suppressed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('ot_deduction is suppressed one day after 2028-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2029-01-01'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('ot_deduction');
        expect($result['suppressed'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('ot_deduction is active one day before 2028-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2028-12-30'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('ot_deduction');
        expect($result['suppressed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('senior_deduction is suppressed one day after 2028-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2029-01-01'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('senior_deduction');
        expect($result['suppressed'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('senior_deduction is active one day before 2028-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2028-12-30'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('senior_deduction');
        expect($result['suppressed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('auto_loan_interest is suppressed one day after 2028-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2029-01-01'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('auto_loan_interest');
        expect($result['suppressed'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('auto_loan_interest is active one day before 2028-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2028-12-30'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('auto_loan_interest');
        expect($result['suppressed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('salt_deduction_cap is suppressed one day after 2029-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2030-01-01'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('salt_deduction_cap');
        expect($result['suppressed'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('salt_deduction_cap is active one day before 2029-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2029-12-30'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('salt_deduction_cap');
        expect($result['suppressed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('qof_recognition is suppressed one day after 2026-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2027-01-01'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('qof_recognition');
        expect($result['suppressed'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('qof_recognition is active one day before 2026-12-31 sunset', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2026-12-30'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('qof_recognition');
        expect($result['suppressed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('residential_energy_credit_25d is suppressed one day after 2025-12-31', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2026-01-01'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('residential_energy_credit_25d');
        expect($result['suppressed'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('residential_energy_credit_25d is active one day before 2025-12-31', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2025-12-30'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('residential_energy_credit_25d');
        // The rule is not suppressed by date (not expired yet), but band=conditional
        // so it's not suppressed — only expired rules or suppress/hard_block bands suppress
        expect($result['suppressed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('ev_credit_30d is suppressed one day after 2025-09-30 (pre-Oct-2025 window)', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2025-10-01'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('ev_credit_30d');
        expect($result['suppressed'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('ev_credit_30d is active one day before 2025-09-30 expiry', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2025-09-29'));

    try {
        $service = new TaxRulesEngineService;
        $result = $service->validateRule('ev_credit_30d');
        expect($result['suppressed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

// ── FLAG-08: Materiality Boundary Tests ──────────────────────────────────────

it('passesMaterialityGate rejects a $99 single non-recurring transaction', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // $99 = 9900 cents — below the auto-floor ($100 = 10,000 cents)
    $amountCents = 99_00;

    expect($service->passesMaterialityGate($amountCents, false, 0))->toBeFalse();
});

it('passesMaterialityGate accepts a $1,000 single non-recurring transaction', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // $1,000 = 100,000 cents — meets the single_txn_interrogate_cents threshold
    $amountCents = 1_000_00;

    expect($service->passesMaterialityGate($amountCents, false, 0))->toBeTrue();
});

it('passesMaterialityGate accepts a recurring pattern with $500/yr annual total', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // $500/yr = 50,000 cents — exactly meets the recurring_pattern_annual_cents threshold
    $amountCents = 42_00;         // $42/month recurring txn (above auto-floor)
    $annualCents = 500_00;        // $500 annualized

    expect($service->passesMaterialityGate($amountCents, true, $annualCents))->toBeTrue();
});

it('passesMaterialityGate rejects a recurring pattern below $500/yr', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // $480/yr = 48,000 cents — below the recurring_pattern_annual_cents threshold
    $amountCents = 40_00;
    $annualCents = 480_00;

    expect($service->passesMaterialityGate($amountCents, true, $annualCents))->toBeFalse();
});

it('passesMaterialityGate always accepts address-matched transactions', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // Even $1 address-matched should pass
    expect($service->passesMaterialityGate(1_00, false, 0, true, false))->toBeTrue();
    expect($service->passesMaterialityGate(50_00, false, 0, true, false))->toBeTrue();
});

it('passesMaterialityGate always accepts loan-servicer transactions', function () {
    Http::preventStrayRequests();

    $service = new TaxRulesEngineService;

    // Even $1 loan-servicer should pass
    expect($service->passesMaterialityGate(1_00, false, 0, false, true))->toBeTrue();
});

it('passesMaterialityGate reads thresholds from config, not hardcoded literals', function () {
    Http::preventStrayRequests();

    // This test verifies that passesMaterialityGate respects config overrides —
    // proving it reads from config, not literals.
    // Override materiality thresholds to non-standard values
    Config::set('tax-detection.materiality.single_txn_auto_floor_cents', 20_000);   // $200
    Config::set('tax-detection.materiality.single_txn_interrogate_cents', 200_000); // $2,000
    Config::set('tax-detection.materiality.recurring_pattern_annual_cents', 100_000); // $1,000/yr

    $service = new TaxRulesEngineService;

    // $150 = 15,000 cents: above old $100 floor, below new $200 floor → should FAIL (config-driven)
    expect($service->passesMaterialityGate(150_00, false, 0))->toBeFalse();

    // $1,500 = 150,000 cents: above old $1,000 threshold, below new $2,000 → should FAIL
    expect($service->passesMaterialityGate(1_500_00, false, 0))->toBeFalse();

    // $2,000 = 200,000 cents → should PASS at new threshold
    expect($service->passesMaterialityGate(2_000_00, false, 0))->toBeTrue();
});

// ── FLAG-06: bandToSeverity Mapping ──────────────────────────────────────────

it('bandToSeverity maps auto to high', function () {
    Http::preventStrayRequests();
    $service = new TaxRulesEngineService;
    expect($service->bandToSeverity('auto'))->toBe('high');
});

it('bandToSeverity maps conditional to medium', function () {
    Http::preventStrayRequests();
    $service = new TaxRulesEngineService;
    expect($service->bandToSeverity('conditional'))->toBe('medium');
});

it('bandToSeverity maps specialist to medium', function () {
    Http::preventStrayRequests();
    $service = new TaxRulesEngineService;
    expect($service->bandToSeverity('specialist'))->toBe('medium');
});

it('bandToSeverity maps suppress to suppressed', function () {
    Http::preventStrayRequests();
    $service = new TaxRulesEngineService;
    expect($service->bandToSeverity('suppress'))->toBe('suppressed');
});

it('bandToSeverity maps hard_block to blocked', function () {
    Http::preventStrayRequests();
    $service = new TaxRulesEngineService;
    expect($service->bandToSeverity('hard_block'))->toBe('blocked');
});

it('bandToSeverity throws on unknown band', function () {
    Http::preventStrayRequests();
    $service = new TaxRulesEngineService;
    expect(fn () => $service->bandToSeverity('unknown_band'))
        ->toThrow(InvalidArgumentException::class);
});

it('bandToSeverity maps deterministically — all five bands return consistent results', function () {
    Http::preventStrayRequests();
    $service = new TaxRulesEngineService;

    $expected = [
        'auto' => 'high',
        'conditional' => 'medium',
        'specialist' => 'medium',
        'suppress' => 'suppressed',
        'hard_block' => 'blocked',
    ];

    foreach ($expected as $band => $severity) {
        expect($service->bandToSeverity($band))->toBe($severity, "Band '{$band}' should map to '{$severity}'");
    }
});

// ── SAFE-03: No literals in passesMaterialityGate method body ─────────────────

it('passesMaterialityGate method body contains no raw threshold literals (SAFE-03 grep gate)', function () {
    $serviceFile = file_get_contents(app_path('Services/TaxRulesEngineService.php'));

    // Extract only the passesMaterialityGate method body
    $methodStart = strpos($serviceFile, 'public function passesMaterialityGate(');
    $methodEnd = strpos($serviceFile, 'public function bandToSeverity(', $methodStart);
    $methodBody = substr($serviceFile, $methodStart, $methodEnd - $methodStart);

    // The raw cent values that should NOT appear as literals
    // $100 = 10000, $500 = 50000, $1000 = 100000
    expect($methodBody)->not->toContain('10000')
        ->not->toContain('50000')
        ->not->toContain('100000');
});
