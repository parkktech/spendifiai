<?php

use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\InterviewSession;
use App\Models\OptimizationFinding;
use App\Models\UserTaxFact;
use App\Services\InterviewOrchestratorService;
use Illuminate\Support\Facades\Http;

/*
 * InterviewOrchestratorServiceTest — covers the orchestrator's Claude-call boundaries
 * and skip-logic correctness.
 *
 * Verifies:
 * - Claude is called ONLY for question wording (not for dollar amounts)
 * - HTTP payload excludes estimated_value_cents (SAFE-01 / SAFE-03)
 * - No stray requests to non-Anthropic hosts
 * - INT-04: backdoor-Roth probe is gated on IRA balance fact
 * - Skip-logic via answerableFields() + UserTaxFact proxy
 */

// ─── Claude wording-only constraint (SAFE-01 / SAFE-03) ──────────────────────

it('claude_wording_only: HTTP payload excludes estimated_value_cents and dollar figures', function () {
    Http::preventStrayRequests(); // catch any unexpected external calls

    $capturedPayload = null;
    Http::fake([
        'https://api.anthropic.com/*' => function (\Illuminate\Http\Client\Request $request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response(['content' => [['text' => 'Do you track your vehicle mileage?']]], 200);
        },
    ]);

    $user = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'vehicle_mileage_deduction',
        'band' => 'auto',
        'status' => 'open',
        'estimated_value_cents' => 150000,  // $1,500 — must NOT appear in Claude payload
        'treatment' => 'standard_mileage',
        'description' => null,
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['vehicle_mileage_deduction'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $service->nextQuestion($session);

    // Assert Claude was called
    expect($capturedPayload)->not->toBeNull();

    // Assert estimated_value_cents is NOT in the payload sent to Claude
    $payloadJson = json_encode($capturedPayload);
    expect($payloadJson)->not->toContain('estimated_value_cents');
    expect($payloadJson)->not->toContain('150000');  // the actual dollar value
    expect($payloadJson)->not->toContain('1500');     // even as a rounded string
});

it('claude_payload_excludes_dollar_figures_from_details', function () {
    Http::preventStrayRequests();

    $capturedMessages = null;
    Http::fake([
        'https://api.anthropic.com/*' => function (\Illuminate\Http\Client\Request $request) use (&$capturedMessages) {
            $capturedMessages = $request->data()['messages'] ?? [];

            return Http::response(['content' => [['text' => 'Do you use your vehicle for work?']]], 200);
        },
    ]);

    $user = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'auto_loan_interest',
        'band' => 'auto',
        'status' => 'open',
        'estimated_value_cents' => 80000,   // $800
        'details' => ['gap_cents' => 80000, 'w2_cents' => 5000000],
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['auto_loan_interest'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $service->nextQuestion($session);

    // The messages payload (system + user) must not contain any raw dollar figures
    $messagesJson = json_encode($capturedMessages);
    expect($messagesJson)->not->toContain('80000');
    expect($messagesJson)->not->toContain('5000000');
    // dollar figures from details should not be passed to Claude
});

// ─── INT-04: Prerequisite gating ─────────────────────────────────────────────

it('prerequisite_gating: backdoor-Roth probe is not queued until IRA balance is answered', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'test']]], 200)]);

    $user = createAuthenticatedUser();

    // Queue includes backdoor-Roth probe, but prerequisite (ira.balance_range) is not answered
    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['ira.backdoor_roth_eligible'],  // gated probe
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    // Backdoor-Roth probe should be SKIPPED (or return null / deferred)
    // because ira.balance_range has not been answered yet
    if ($question !== null) {
        // If a question IS returned, it must NOT be the backdoor-Roth probe
        expect($question->options['fact_key'] ?? null)->not->toBe('finding.ira.backdoor_roth_eligible');
    }

    // No optimization question should be created for backdoor-Roth without prerequisite
    $gatedQuestion = AIQuestion::where('user_id', $user->id)
        ->where('question_type', QuestionType::Optimization)
        ->whereRaw("options->>'fact_key' = ?", ['ira.backdoor_roth_eligible'])
        ->first();

    expect($gatedQuestion)->toBeNull();
});

it('prerequisite_gating: backdoor-Roth probe IS surfaced after IRA balance is answered', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Have you considered a backdoor Roth conversion?']]], 200)]);

    $user = createAuthenticatedUser();

    // Answer the prerequisite fact first
    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.balance_range',
        value: 'over_7000',
        sourceType: 'interview_answer',
    );

    // Now the backdoor-Roth probe should be surfaced
    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['ira.backdoor_roth_eligible'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    // A question should be returned now that the prerequisite is satisfied
    expect($question)->not->toBeNull();
});

// ─── Skip-logic via answerableFields() ───────────────────────────────────────

it('skips_known_facts: orchestrator skips fact keys already in UserTaxFact', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'test?']]], 200)]);

    $user = createAuthenticatedUser();

    // Pre-answer ira.has_ira so it shows as answerable
    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.has_ira',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['ira.has_ira'],   // already answered
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    // ira.has_ira is already answered — should be skipped → null returned
    expect($question)->toBeNull();
});

// ─── Task 2: conditional findings enter the queue ─────────────────────────────

it('conditional_findings_in_queue: buildInitialQueue includes band=conditional non-battery findings', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'test?']]], 200)]);

    $user = createAuthenticatedUser();

    // Create a conditional finding (NOT battery_question) — previously ignored by buildInitialQueue
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'home_office_deduction_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
    ]);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);
    $queue = $session->queue ?? [];

    // The conditional finding must appear in the interview queue
    expect($queue)->toContain('home_office_deduction_probe');
});

it('conditional_ordering: auto findings come before conditional in queue', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'test?']]], 200)]);

    $user = createAuthenticatedUser();

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'auto_sentinel',
        'finding_type' => 'income_discrepancy',
        'band' => 'auto',
        'status' => 'open',
    ]);

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'conditional_sentinel',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
    ]);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);
    $queue = $session->queue ?? [];

    $autoPos = array_search('auto_sentinel', $queue, true);
    $condPos = array_search('conditional_sentinel', $queue, true);

    expect($autoPos)->not->toBeFalse();
    expect($condPos)->not->toBeFalse();
    expect($autoPos)->toBeLessThan($condPos);
});

it('specialist_findings_excluded_from_queue', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'test?']]], 200)]);

    $user = createAuthenticatedUser();

    // Specialist findings must NOT enter the queue (they belong in pro-review section)
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'specialist_only_finding',
        'finding_type' => 'complex_entity_probe',
        'band' => 'specialist',
        'status' => 'open',
    ]);

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);
    $queue = $session->queue ?? [];

    expect($queue)->not->toContain('specialist_only_finding');
});

it('stale_queue_self_heal: session with empty queue + conditional findings yields questions on resume', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Do you have a home office?']]], 200)]);

    $user = createAuthenticatedUser();

    // Simulate the owner's stale session created during the pipeline outage —
    // queue is empty because findings were conditional and weren't included when it was built
    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],   // empty — the bug state
        'asked' => [],
    ]);

    // Conditional finding now exists (seeded after session was created)
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'home_office_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
    ]);

    $service = app(InterviewOrchestratorService::class);
    $resumedSession = $service->startOrResume($user->id, 2026);

    // Queue must have been rebuilt — not empty anymore
    expect($resumedSession->queue)->not->toBeEmpty();
    expect($resumedSession->queue)->toContain('home_office_probe');

    // nextQuestion must return a question (not null) — proving interview is unblocked
    $question = $service->nextQuestion($resumedSession);
    expect($question)->not->toBeNull();
});

it('stale_queue_self_heal_skips_already_asked_keys', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'test?']]], 200)]);

    $user = createAuthenticatedUser();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => ['already_answered_probe'],  // previously asked
    ]);

    // Two findings — one already asked, one new
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'already_answered_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
    ]);

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'new_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
    ]);

    $service = app(InterviewOrchestratorService::class);
    $resumed = $service->startOrResume($user->id, 2026);

    // already_answered_probe must NOT be re-added to the rebuilt queue
    expect($resumed->queue)->not->toContain('already_answered_probe');
    // new_probe must be in the queue
    expect($resumed->queue)->toContain('new_probe');
});

// ─── Session record-answer plumbing ──────────────────────────────────────────

it('recordAnswer: writes UserTaxFact and updates session asked array', function () {
    Http::fake();

    $user = createAuthenticatedUser();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['ira.balance_range', 'method.mileage'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $service->recordAnswer($session, 'ira.balance_range', 'under_25k');

    $session->refresh();

    // UserTaxFact should be written
    $fact = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'ira.balance_range')
        ->where('is_current', true)
        ->first();

    expect($fact)->not->toBeNull();
    expect($fact->source_type)->toBe('interview_answer');

    // Session asked array should include the answered key
    expect($session->asked)->toContain('ira.balance_range');
});
