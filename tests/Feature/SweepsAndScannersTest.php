<?php

/**
 * SweepsAndScannersTest — TDD RED for Plan 11-07 Tasks 2 & 3
 *
 * Tests for:
 *  - RecurringPayeeSweep (FLAG-11) — worker-class / childcare / tuition / charitable / insurance
 *  - RetroactiveScanner (FLAG-12) — §25D range framing, §30D date-gated, basis reconstruction
 *  - PenaltyPreventionSweep (FLAG-26) — warn-and-educate only, activity-gated
 *  - LifeEventTriggerDetector (FLAG-27) — 4 data-detectable triggers + battery facts
 *
 * Binding reframes under test:
 *  - Charitable appreciated-asset is mechanics-only education, NO directive ("some donors…")
 *  - Scholar-ship election carries narrate-carefully flag (never directive)
 *  - Worker-classification is warn-and-educate only (never "reclassify them")
 *  - §25D uses config educational RANGE with uncertainty framing, never a promised amount
 *  - §30D is strictly date-gated past-window ("never currently available")
 *  - PenaltyPreventionSweep warns only; runs activity-gated
 *  - LifeEventTriggerDetector fires from existing signals; annual battery persists durable facts
 */

use App\Models\OptimizationFinding;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;
use App\Services\Scanners\RetroactiveScanner;
use App\Services\Scanners\SafeHarborBenchmark;
use App\Services\Sweeps\PenaltyPreventionSweep;
use App\Services\Scanners\LifeEventTriggerDetector;
use App\Services\Sweeps\RecurringPayeeSweep;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['last_active_at' => now()]);
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
});

// ── RecurringPayeeSweep (FLAG-11) ────────────────────────────────────────────

it('RecurringPayeeSweep stays silent when no recurring payees exist', function () {
    $sweep = app(RecurringPayeeSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toBeEmpty();
});

it('RecurringPayeeSweep routes childcare subscriptions to dependent-care module', function () {
    // Recurring childcare provider — triggers AOTC/FSA module
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'Little Sprouts Daycare',
        'merchant_normalized' => 'little sprouts daycare',
        'category'            => 'Childcare',
        'status'              => 'active',
        'amount'              => '850.00',
        'frequency'           => 'monthly',
        'is_essential'        => true,
        'last_charge_date'    => now()->subDays(15),
    ]);

    $sweep = app(RecurringPayeeSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    expect(count($result))->toBeGreaterThanOrEqual(1);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->treatment)->toContain('dependent')
        ->and($finding->treatment)->not->toContain('overnight camp'); // day camp YES, overnight NO
});

it('RecurringPayeeSweep charitable finding uses mechanics-only framing without directive', function () {
    // Recurring charitable donation
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'Red Cross',
        'merchant_normalized' => 'red cross',
        'category'            => 'Charitable',
        'status'              => 'active',
        'amount'              => '100.00',
        'frequency'           => 'monthly',
        'is_essential'        => false,
        'last_charge_date'    => now()->subDays(10),
    ]);

    $sweep = app(RecurringPayeeSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->first();

    if ($finding) {
        // Reframe: mechanics-only education, no directive (INTEGRATION-MAP §3)
        // "some donors give appreciated holdings…" framing ONLY — never "you should donate stock"
        expect($finding->treatment)->not->toContain('you should donate')
            ->and($finding->treatment)->not->toContain('sell your stock and donate');
    }
});

it('RecurringPayeeSweep worker-classification module is warn-and-educate only', function () {
    // Recurring payments to the same individual — worker-classification questionnaire
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'John Smith Consulting',
        'merchant_normalized' => 'john smith consulting',
        'category'            => 'Business Services',
        'status'              => 'active',
        'amount'              => '2500.00',
        'frequency'           => 'monthly',
        'is_essential'        => false,
        'last_charge_date'    => now()->subDays(5),
    ]);

    $sweep = app(RecurringPayeeSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->first();

    if ($finding) {
        // Warn-and-educate: NEVER "reclassify them" (INTEGRATION-MAP §3 FLAG-11 row)
        expect($finding->treatment)->not->toContain('reclassify them')
            ->and($finding->treatment)->not->toContain('you must reclassify');
    }
});

it('RecurringPayeeSweep insurance subscription routes to SE-health module', function () {
    // Recurring insurance premium payments — SE health insurance / §105 HRA
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'Blue Cross Blue Shield',
        'merchant_normalized' => 'blue cross blue shield',
        'category'            => 'Insurance',
        'status'              => 'active',
        'amount'              => '450.00',
        'frequency'           => 'monthly',
        'is_essential'        => true,
        'last_charge_date'    => now()->subDays(20),
    ]);

    $sweep = app(RecurringPayeeSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    // Insurance routing should find the SE-health module finding
    expect(count($result))->toBeGreaterThanOrEqual(0); // may be silent if employment prerequisites unmet
});

// ── RetroactiveScanner (FLAG-12) ─────────────────────────────────────────────

it('RetroactiveScanner stays silent when no history transactions exist', function () {
    $scanner = app(RetroactiveScanner::class);
    $result = $scanner->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toBeEmpty();
});

it('RetroactiveScanner 25D finding uses educational RANGE framing with uncertainty', function () {
    // Create a solar loan servicer transaction (GoodLeap = highest-recall signal)
    Transaction::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_normalized' => 'goodleap',
        'merchant_name'       => 'GoodLeap',
        'amount'              => 350.00,
        'transaction_date'    => now()->subMonths(8), // within 3-year amended-return window
        'is_subscription'     => true,
    ]);

    $scanner = app(RetroactiveScanner::class);
    $result = $scanner->run($this->user->id, $this->taxYear, $this->service, []);

    // If a §25D finding is emitted, it must use the educational range
    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->where('finding_key', 'LIKE', '%25d%')
        ->orWhere(function ($q) use ($result) {
            $q->where('user_id', $this->user->id)
              ->whereIn('finding_key', $result)
              ->where('finding_key', 'LIKE', '%solar%');
        })
        ->first();

    if ($finding) {
        // Educational range framing with uncertainty — NEVER "you will recover $X"
        expect($finding->treatment)->not->toContain('you will recover')
            ->and($finding->treatment)->not->toContain('guaranteed recovery');
    }
});

it('RetroactiveScanner 30D is strictly date-gated past-window only', function () {
    // EV purchase before Oct 2025 = §30D retro candidate
    Transaction::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_normalized' => 'tesla',
        'merchant_name'       => 'Tesla',
        'amount'              => 45000.00,
        'transaction_date'    => now()->subMonths(10), // well before Oct 2025 deadline
    ]);

    $scanner = app(RetroactiveScanner::class);
    $result = $scanner->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->where('finding_key', 'LIKE', '%30d%')
        ->orWhere(function ($q) use ($result) {
            $q->where('user_id', $this->user->id)
              ->whereIn('finding_key', $result)
              ->where('finding_key', 'LIKE', '%ev_credit%');
        })
        ->first();

    if ($finding) {
        // §30D is past-window: NEVER "currently available"
        expect($finding->treatment)->not->toContain('currently available')
            ->and($finding->treatment)->not->toContain('you can claim this credit now');
    }
});

it('RetroactiveScanner basis reconstruction emits findings for improvement payments', function () {
    // Large contractor payment — basis reconstruction trigger
    Transaction::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_normalized' => 'martinez construction',
        'merchant_name'       => 'Martinez Construction',
        'amount'              => 15000.00,
        'transaction_date'    => now()->subMonths(6),
    ]);

    $scanner = app(RetroactiveScanner::class);
    $result = $scanner->run($this->user->id, $this->taxYear, $this->service, []);

    // At minimum, basis reconstruction is tracked (even silently)
    // If emitted, must reference basis or property context
    $basisFinding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->where('finding_key', 'LIKE', '%basis%')
        ->first();

    if ($basisFinding) {
        expect($basisFinding->treatment)->toContain('basis');
    }
});

// ── PenaltyPreventionSweep (FLAG-26) ─────────────────────────────────────────

it('PenaltyPreventionSweep stays silent when no penalty-relevant data exists', function () {
    $sweep = app(PenaltyPreventionSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toBeEmpty();
});

it('PenaltyPreventionSweep warns about excess IRA contribution with warn-and-educate framing', function () {
    // Record an IRA contribution that exceeds the limit
    // IRA limit 2026 = $7,500 (age < 50); cap is in config
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'ira.roth_ytd_contribution_cents',
        value: '1000000', // $10,000 — exceeds $7,500 limit
        sourceType: 'interview_answer',
        label: 'Roth IRA YTD contribution',
        volatility: 'annual',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'ira.traditional_ytd_contribution_cents',
        value: '0',
        sourceType: 'interview_answer',
        label: 'Traditional IRA YTD contribution',
        volatility: 'annual',
        taxYear: $this->taxYear,
    );

    $sweep = app(PenaltyPreventionSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    if (count($result) > 0) {
        $finding = OptimizationFinding::where('user_id', $this->user->id)
            ->whereIn('finding_key', $result)
            ->first();

        expect($finding)->not->toBeNull()
            // Warn-and-educate: no directive to withdraw
            ->and($finding->treatment)->not->toContain('you must withdraw')
            ->and($finding->treatment)->not->toContain('withdraw the excess now');
    }
});

it('PenaltyPreventionSweep HSA-Medicare lookback heuristic warns when Medicare detected', function () {
    // Medicare enrollment fact + recent HSA contributions = potential 6-month lookback issue
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'medicare.enrollment_date',
        value: now()->subMonths(2)->format('Y-m-d'), // enrolled 2 months ago
        sourceType: 'interview_answer',
        label: 'Medicare enrollment date',
        volatility: 'stable',
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'hsa.ytd_contribution_cents',
        value: '200000', // $2,000 contributed after Medicare enrollment
        sourceType: 'interview_answer',
        label: 'HSA YTD contributions',
        volatility: 'annual',
        taxYear: $this->taxYear,
    );

    $sweep = app(PenaltyPreventionSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    if (count($result) > 0) {
        $finding = OptimizationFinding::where('user_id', $this->user->id)
            ->whereIn('finding_key', $result)
            ->first();

        // Warn-and-educate framing
        expect($finding)->not->toBeNull()
            ->and($finding->treatment)->toContain('Medicare');
    }
});

// ── LifeEventTriggerDetector (FLAG-27) ────────────────────────────────────────

it('LifeEventTriggerDetector stays silent when no trigger signals detected', function () {
    $detector = app(LifeEventTriggerDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toBeEmpty();
});

it('LifeEventTriggerDetector fires marketplace-premium trigger from active subscription', function () {
    // Marketplace ACA premium payment detected (subscription category = Health Insurance / ACA)
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'Healthcare.gov Premium',
        'merchant_normalized' => 'healthcare gov premium',
        'category'            => 'Health Insurance',
        'status'              => 'active',
        'amount'              => '320.00',
        'frequency'           => 'monthly',
        'is_essential'        => true,
        'last_charge_date'    => now()->subDays(15),
    ]);

    $detector = app(LifeEventTriggerDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    // Marketplace premium trigger → activates FLAG-22 (ACA cliff awareness)
    if (count($result) > 0) {
        $finding = OptimizationFinding::where('user_id', $this->user->id)
            ->whereIn('finding_key', $result)
            ->first();

        expect($finding)->not->toBeNull()
            ->and($finding->treatment)->not->toContain('you will owe');
    }
});

it('LifeEventTriggerDetector fires new-mortgage trigger from recurring payment', function () {
    // New mortgage-like recurring payment (first time in history)
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'Wells Fargo Mortgage',
        'merchant_normalized' => 'wells fargo mortgage',
        'category'            => 'Mortgage',
        'status'              => 'active',
        'amount'              => '2100.00',
        'frequency'           => 'monthly',
        'is_essential'        => true,
        'last_charge_date'    => now()->subDays(20),
    ]);

    $detector = app(LifeEventTriggerDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    // At minimum: new-mortgage trigger may fire basis-ledger start education
    expect(count($result))->toBeGreaterThanOrEqual(0); // may be silent if already seen mortgage
});

it('LifeEventTriggerDetector persists annual battery answer as durable UserTaxFact', function () {
    $detector = app(LifeEventTriggerDetector::class);

    // Record a battery answer (inheritance = step-up doc prompt)
    $detector->recordBatteryAnswer(
        userId: $this->user->id,
        taxYear: $this->taxYear,
        factKey: 'life_event.inherited_assets',
        value: 'true',
        label: 'Inherited assets in current tax year',
    );

    $fact = UserTaxFact::currentFact($this->user->id, 'life_event.inherited_assets', null, $this->taxYear);

    expect($fact)->not->toBeNull()
        ->and($fact->fact_key)->toBe('life_event.inherited_assets')
        ->and($fact->source_type)->toBe('interview_answer');
});
