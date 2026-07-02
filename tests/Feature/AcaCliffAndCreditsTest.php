<?php

/**
 * AcaCliffAndCreditsTest — Task 3 RED (FLAG-22, FLAG-23)
 *
 * Tests for:
 *  - AcaCliffMonitor (FLAG-22): cliff awareness, never computed subsidy/clawback;
 *    MAGI-management sequenced BEFORE Trad-vs-Roth for marketplace enrollees
 *  - RefundableCreditScanner (FLAG-23): "may be eligible" never "you qualify";
 *    Saver's Match date-gated to 2027; EITC investment-income caveat
 *
 * ALL assertions follow INTEGRATION-MAP.md binding rules and 11-CONTEXT.md reframes.
 * These tests intentionally fail (RED) until the implementation classes are created.
 */

use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
});

// ── AcaCliffMonitor (FLAG-22) ────────────────────────────────────────────────

it('AcaCliffMonitor fires when marketplace enrollee income approaches FPL cliff', function () {
    // Income near 400% FPL for a single person (~$62,600 in 2026)
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5800000', // $58,000 — approaching $62,600 single cliff
        'filing_status' => 'single',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'marketplace.pays_marketplace_premiums',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\AcaCliffMonitor::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'aca_cliff_awareness')->first();
    expect($finding)->not->toBeNull();
    expect($finding->treatment)->toContain('400%');
    expect($finding->treatment)->toContain('marketplace');
});

it('AcaCliffMonitor finding never contains a computed subsidy or clawback dollar amount', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5800000',
        'filing_status' => 'single',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'marketplace.pays_marketplace_premiums',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\AcaCliffMonitor::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'aca_cliff_awareness')->first();
    expect($finding)->not->toBeNull();

    // BINDING (FLAG-22, T-11-08-02): never emit a computed subsidy or clawback amount
    // Test: treatment must not contain dollar amounts that look like a subsidy/clawback
    expect($finding->treatment)->not->toContain('your subsidy is $');
    expect($finding->treatment)->not->toContain('you owe $');
    expect($finding->treatment)->not->toContain('clawback of $');
    expect($finding->treatment)->not->toContain('you will repay $');

    // Must be awareness framing
    expect($finding->treatment)->toContain('consider');
});

it('AcaCliffMonitor includes MAGI-management education for marketplace enrollees', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5800000',
        'filing_status' => 'single',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'marketplace.pays_marketplace_premiums',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\AcaCliffMonitor::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    // BINDING (FLAG-22, 2B.1): MAGI-management education emitted for marketplace enrollees
    // The monitor must surface MAGI-management awareness (pre-tax contributions reduce MAGI)
    $magiMgmtFinding = OptimizationFinding::where('finding_key', 'aca_magi_management')->first();
    expect($magiMgmtFinding)->not->toBeNull();
    expect($magiMgmtFinding->treatment)->toContain('MAGI');
    expect($magiMgmtFinding->treatment)->toContain('pre-tax');
});

it('AcaCliffMonitor MAGI-management finding has higher severity than cliff awareness', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5800000',
        'filing_status' => 'single',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'marketplace.pays_marketplace_premiums',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\AcaCliffMonitor::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    // BINDING (FLAG-22, 2B.1): MAGI-management must sequence BEFORE Trad-vs-Roth narration
    // Proxy: MAGI-management finding is at 'auto' band (higher severity = surfaced first)
    $magiMgmt = OptimizationFinding::where('finding_key', 'aca_magi_management')->first();
    expect($magiMgmt)->not->toBeNull();
    expect($magiMgmt->band)->toBe('auto'); // auto → high severity → sequences before conditional findings
});

it('AcaCliffMonitor does not fire for users with no marketplace premiums', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5800000',
        'filing_status' => 'single',
        'has_self_employment' => false,
    ]);

    // No marketplace.pays_marketplace_premiums fact set

    app(\App\Services\Detectors\AcaCliffMonitor::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'aca_cliff_awareness')->first();
    expect($finding)->toBeNull();
});

// ── RefundableCreditScanner (FLAG-23) ─────────────────────────────────────────

it('RefundableCreditScanner EITC finding uses may-be-eligible framing never you-qualify', function () {
    // Low-to-moderate income single parent — EITC eligible range
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '3500000', // $35,000 — in EITC range with one child
        'filing_status' => 'single',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'family.dependents_count',
        value: '1',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    // Low investment income (under $11,950 limit for 2026 EITC)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'finance.investment_income_cents',
        value: '0',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\RefundableCreditScanner::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'credit_eitc')->first();
    expect($finding)->not->toBeNull();

    // BINDING (FLAG-23): "may be eligible" framing — never assert eligibility
    expect($finding->treatment)->toContain('may be eligible');
    expect($finding->treatment)->not->toContain('you qualify');
    expect($finding->treatment)->not->toContain('you are eligible');
    expect($finding->treatment)->not->toContain('you will receive');

    // BINDING (FLAG-23, 2B.6.6): EITC investment-income limit caveat is mandatory
    expect($finding->treatment)->toContain('investment income');
});

it('RefundableCreditScanner EITC suppresses when investment income exceeds limit', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '3500000',
        'filing_status' => 'single',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'family.dependents_count',
        value: '1',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    // Investment income ABOVE the EITC limit ($11,950 for 2026) → EITC ineligible
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'finance.investment_income_cents',
        value: '1200000', // $12,000 — above limit
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\RefundableCreditScanner::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'credit_eitc')->first();
    expect($finding)->toBeNull(); // suppressed: investment income disqualifies
});

it('RefundableCreditScanner CTC finding uses may-be-eligible framing', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '7000000', // $70,000
        'filing_status' => 'single',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'family.qualifying_children_under_17',
        value: '2',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\RefundableCreditScanner::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'credit_ctc')->first();
    expect($finding)->not->toBeNull();
    expect($finding->treatment)->toContain('may be eligible');
    expect($finding->treatment)->not->toContain('you qualify');
});

it('RefundableCreditScanner Savers Credit fires for 2026 tax year', function () {
    // Saver's Credit (current law) — income-gated
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '2500000', // $25,000 — within Saver's Credit range
        'filing_status' => 'single',
        'has_self_employment' => false,
        'traditional_401k_ytd' => '50000', // has some retirement contributions
    ]);

    app(\App\Services\Detectors\RefundableCreditScanner::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'credit_savers')->first();
    expect($finding)->not->toBeNull();
    expect($finding->treatment)->toContain('may be eligible');
    expect($finding->treatment)->not->toContain('you qualify');

    // BINDING (FLAG-23, 2B.6.6): Saver's Match content date-gated to 2027
    // For taxYear=2026, Saver's Match content MUST be suppressed
    expect($finding->treatment)->not->toContain('Saver\'s Match');
    expect($finding->treatment)->not->toContain('government matching');
    expect($finding->treatment)->not->toContain('employer-like match from the government');
});

it('RefundableCreditScanner Savers Match content appears for 2027 tax year', function () {
    // Saver's Match arrives in 2027 (SECURE 2.0 Act — government matching contribution)
    $user2 = User::factory()->create();

    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user2->id,
        'tax_year' => 2027,
        'w2_wages' => '2500000',
        'filing_status' => 'single',
        'has_self_employment' => false,
        'traditional_401k_ytd' => '50000',
    ]);

    app(\App\Services\Detectors\RefundableCreditScanner::class)
        ->run($user2->id, 2027, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $user2->id)
        ->where('finding_key', 'credit_savers')
        ->first();
    expect($finding)->not->toBeNull();
    // 2027: Saver's Match content is now date-unlocked
    expect($finding->treatment)->toContain('Saver\'s Match');
});

it('RefundableCreditScanner does not assert eligibility for any credit', function () {
    // Comprehensive banned-phrase test across all credit findings
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '2500000',
        'filing_status' => 'single',
        'has_self_employment' => false,
        'traditional_401k_ytd' => '50000',
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'family.dependents_count',
        value: '1',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'finance.investment_income_cents',
        value: '0',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\RefundableCreditScanner::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $findings = OptimizationFinding::where('user_id', $this->user->id)->get();
    expect($findings->count())->toBeGreaterThan(0);

    foreach ($findings as $finding) {
        // BINDING (FLAG-23): no assertive eligibility across all credit findings
        expect($finding->treatment)->not->toContain('you qualify');
        expect($finding->treatment)->not->toContain('you are eligible');
        expect($finding->treatment)->not->toContain('you will receive');
    }
});
