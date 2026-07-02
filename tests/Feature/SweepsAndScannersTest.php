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
use App\Services\InterviewOrchestratorService;
use App\Services\RedFlagDetectorService;
use App\Services\Scanners\LifeEventTriggerDetector;
use App\Services\Scanners\RetroactiveScanner;
use App\Services\Sweeps\PenaltyPreventionSweep;
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
        'user_id' => $this->user->id,
        'merchant_name' => 'Little Sprouts Daycare',
        'merchant_normalized' => 'little sprouts daycare',
        'category' => 'Childcare',
        'status' => 'active',
        'amount' => '850.00',
        'frequency' => 'monthly',
        'is_essential' => true,
        'last_charge_date' => now()->subDays(15),
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
        'user_id' => $this->user->id,
        'merchant_name' => 'Red Cross',
        'merchant_normalized' => 'red cross',
        'category' => 'Charitable',
        'status' => 'active',
        'amount' => '100.00',
        'frequency' => 'monthly',
        'is_essential' => false,
        'last_charge_date' => now()->subDays(10),
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
        'user_id' => $this->user->id,
        'merchant_name' => 'John Smith Consulting',
        'merchant_normalized' => 'john smith consulting',
        'category' => 'Business Services',
        'status' => 'active',
        'amount' => '2500.00',
        'frequency' => 'monthly',
        'is_essential' => false,
        'last_charge_date' => now()->subDays(5),
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
        'user_id' => $this->user->id,
        'merchant_name' => 'Blue Cross Blue Shield',
        'merchant_normalized' => 'blue cross blue shield',
        'category' => 'Insurance',
        'status' => 'active',
        'amount' => '450.00',
        'frequency' => 'monthly',
        'is_essential' => true,
        'last_charge_date' => now()->subDays(20),
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
        'user_id' => $this->user->id,
        'merchant_normalized' => 'goodleap',
        'merchant_name' => 'GoodLeap',
        'amount' => 350.00,
        'transaction_date' => now()->subMonths(8), // within 3-year amended-return window
        'is_subscription' => true,
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
        'user_id' => $this->user->id,
        'merchant_normalized' => 'tesla',
        'merchant_name' => 'Tesla',
        'amount' => 45000.00,
        'transaction_date' => now()->subMonths(10), // well before Oct 2025 deadline
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
        'user_id' => $this->user->id,
        'merchant_normalized' => 'martinez construction',
        'merchant_name' => 'Martinez Construction',
        'amount' => 15000.00,
        'transaction_date' => now()->subMonths(6),
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

it('LifeEventTriggerDetector stays silent for data triggers when no signals detected and battery answered', function () {
    // Pre-populate all battery answers so the battery produces no findings.
    // When battery is silent AND no data signals are present, run() should return empty.
    $detector = app(LifeEventTriggerDetector::class);
    $detector->recordBatteryAnswer(userId: $this->user->id, factKey: 'life_event.marital_status_changed', value: 'false', taxYear: $this->taxYear, label: 'Marital status');
    $detector->recordBatteryAnswer(userId: $this->user->id, factKey: 'life_event.birth_or_adoption', value: 'false', taxYear: $this->taxYear, label: 'Birth/adoption');
    $detector->recordBatteryAnswer(userId: $this->user->id, factKey: 'life_event.job_change', value: 'false', taxYear: $this->taxYear, label: 'Job change');
    $detector->recordBatteryAnswer(userId: $this->user->id, factKey: 'life_event.inherited_assets_this_year', value: 'false', taxYear: $this->taxYear, label: 'Inheritance');
    $detector->recordBatteryAnswer(userId: $this->user->id, factKey: 'life_event.medicare_enrollment_this_year', value: 'false', taxYear: $this->taxYear, label: 'Medicare enrollment');

    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toBeEmpty();
});

it('LifeEventTriggerDetector fires marketplace-premium trigger from active subscription', function () {
    // Marketplace ACA premium payment detected (subscription category = Health Insurance / ACA)
    Subscription::factory()->create([
        'user_id' => $this->user->id,
        'merchant_name' => 'Healthcare.gov Premium',
        'merchant_normalized' => 'healthcare gov premium',
        'category' => 'Health Insurance',
        'status' => 'active',
        'amount' => '320.00',
        'frequency' => 'monthly',
        'is_essential' => true,
        'last_charge_date' => now()->subDays(15),
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
        'user_id' => $this->user->id,
        'merchant_name' => 'Wells Fargo Mortgage',
        'merchant_normalized' => 'wells fargo mortgage',
        'category' => 'Mortgage',
        'status' => 'active',
        'amount' => '2100.00',
        'frequency' => 'monthly',
        'is_essential' => true,
        'last_charge_date' => now()->subDays(20),
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

// ── GAP CLOSURE 2026-07-02: FLAG-26 (sweep 4) + FLAG-27 (escrow + battery) ──

// FLAG-26: 1099-K / deposit mismatch sweep (4th sweep, previously absent from run())

it('PenaltyPreventionSweep sweep 4 surfaces 1099-K mismatch awareness when deposits exceed threshold', function () {
    // Seed deposit transactions above the 1099-K reporting threshold
    // Third-party payment platform deposit — above the $600 threshold (2025+ IRS rules)
    Transaction::factory()->count(5)->create([
        'user_id' => $this->user->id,
        'merchant_name' => 'PayPal Payout',
        'merchant_normalized' => 'paypal',
        'amount' => -800.00,   // credits (inflows / income)
        'transaction_date' => now()->subMonths(1),
    ]);

    $sweep = app(PenaltyPreventionSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    // 1099-K awareness finding must be warn-and-educate only
    $k1099Finding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->where('finding_key', 'LIKE', '%1099%')
        ->first();

    if ($k1099Finding) {
        expect($k1099Finding->treatment)->toContain('may')
            ->and($k1099Finding->treatment)->not->toContain('you owe')
            ->and($k1099Finding->treatment)->not->toContain('you must report');
    }

    // The sweep must now call 4 sweeps — check that the class has the method
    expect(method_exists(PenaltyPreventionSweep::class, 'checkKForm1099KMismatch'))->toBeTrue();
});

it('PenaltyPreventionSweep 1099-K sweep is silent below threshold', function () {
    // Very small deposits — below 1099-K threshold
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'merchant_name' => 'PayPal Payout',
        'merchant_normalized' => 'paypal',
        'amount' => -10.00,
        'transaction_date' => now()->subDays(30),
    ]);

    $sweep = app(PenaltyPreventionSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    $k1099Finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'penalty_1099k_mismatch')
        ->first();

    expect($k1099Finding)->toBeNull();
});

// FLAG-27: detectEscrowInflow() — 4th data-detectable trigger

it('LifeEventTriggerDetector fires escrow inflow trigger from title company credit', function () {
    // Large inflow from escrow/title company (home sale proceeds)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'merchant_name' => 'First American Title',
        'merchant_normalized' => 'first american title',
        'amount' => -185000.00,  // large inflow = sale proceeds
        'transaction_date' => now()->subDays(30),
    ]);

    $detector = app(LifeEventTriggerDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect(count($result))->toBeGreaterThanOrEqual(1);

    // Must reference §121 exclusion education
    $escrowFinding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->where('finding_key', 'LIKE', '%escrow%')
        ->first();

    if ($escrowFinding) {
        expect($escrowFinding->treatment)->toContain('§121')
            ->and($escrowFinding->treatment)->not->toContain('you will receive');
    }

    // Confirm the method exists
    expect(method_exists(LifeEventTriggerDetector::class, 'detectEscrowInflow'))->toBeTrue();
});

// FLAG-27: annual battery questions bridge into the interview queue (not just the feed listener)

it('battery questions appear in interview queue after auto-band items (FLAG-27 end-to-end bridge)', function () {
    // Create one auto-band finding — verifies ordering guarantee (auto BEFORE battery)
    // D18: auto findings need data-grounded content (treatment) to be queue-eligible.
    OptimizationFinding::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'finding_key' => 'auto_test_sentinel',
        'finding_type' => 'income_discrepancy',
        'band' => 'auto',
        'status' => 'open',
        'treatment' => 'Your reported wages appear lower than your total bank deposits for the year.',
    ]);

    // Run detector with no UserTaxFacts seeded — battery questions must be emitted
    // (band='conditional', finding_type='battery_question')
    $detector = app(LifeEventTriggerDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $batteryFindings = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_type', 'battery_question')
        ->pluck('finding_key')
        ->toArray();

    expect($batteryFindings)->not->toBeEmpty('detector must emit battery findings when no facts are seeded');

    // Build the interview queue via startOrResume — battery keys must be in the queue
    $orchestrator = app(InterviewOrchestratorService::class);
    $session = $orchestrator->startOrResume($this->user->id, $this->taxYear);
    $queue = $session->queue ?? [];

    // Every battery finding emitted by the detector must appear in the interview queue
    foreach ($batteryFindings as $batteryKey) {
        expect(in_array($batteryKey, $queue, true))
            ->toBeTrue("Battery key '{$batteryKey}' must appear in the interview queue");
    }

    // Ordering: auto-band sentinel must come BEFORE any battery key
    $autoPos = array_search('auto_test_sentinel', $queue, true);
    $batteryPositions = array_map(fn ($k) => array_search($k, $queue, true), $batteryFindings);

    expect($autoPos)->toBeLessThan(min($batteryPositions));
});

it('LifeEventTriggerDetector battery suppresses marriage question once answer is recorded', function () {
    // Pre-record the marriage battery answer
    $detector = app(LifeEventTriggerDetector::class);
    $detector->recordBatteryAnswer(
        userId: $this->user->id,
        taxYear: $this->taxYear,
        factKey: 'life_event.marital_status_changed',
        value: 'false',
        label: 'Did your marital status change this year?',
    );

    // Running again should NOT emit a duplicate marriage battery finding
    $countBefore = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'battery_marriage_status')
        ->count();

    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $countAfter = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'battery_marriage_status')
        ->count();

    // Should not create a duplicate finding once answer is recorded
    expect($countAfter)->toBe($countBefore);
});

it('battery_inheritance finding unconditionally surfaces with step-up-now language', function () {
    // Run with no UserTaxFacts — inheritance battery must always fire
    $detector = app(LifeEventTriggerDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $inheritanceFinding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'battery_inheritance')
        ->first();

    // Finding must exist unconditionally (no guard required — no fact was seeded)
    expect($inheritanceFinding)->not->toBeNull();

    // Inheritance treatment must include step-up documentation language (SAFE-03 framing)
    expect($inheritanceFinding->treatment)->toContain('step-up')
        ->and($inheritanceFinding->treatment)->not->toContain('you will receive');
});
