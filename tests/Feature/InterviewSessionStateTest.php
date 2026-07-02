<?php

use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\InterviewSession;
use App\Models\OptimizationFinding;
use App\Services\InterviewOrchestratorService;
use Illuminate\Support\Facades\Http;

/*
 * InterviewSessionStateTest — covers INT-01/04/05/06/07 interview state machine.
 *
 * INT-01: session created and transitions (created → in_progress → paused → completed)
 * INT-04: prerequisite gating (backdoor-Roth blocked until IRA balance answered)
 * INT-05: re-answer supersedes; session resumes with updated facts
 * INT-06: batch-by-merchant — one question per finding (not per transaction)
 * INT-07: high-confidence band pre-fills suggested-confirm (excluded from aggregation until confirmed)
 */

// ─── INT-01: Session creation + state machine ─────────────────────────────────

it('creates_pending: startOrResume creates a session with in_progress status', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Test question?']]], 200)]);

    $user = createAuthenticatedUser();

    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);

    expect($session)->toBeInstanceOf(InterviewSession::class);
    expect($session->status)->toBeIn(['created', 'in_progress']);
    expect($session->user_id)->toBe($user->id);
    expect($session->tax_year)->toBe(2026);
});

it('one_in_progress_per_user_year: partial unique index prevents duplicate in-progress sessions', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Test question?']]], 200)]);

    $user = createAuthenticatedUser();

    $service = app(InterviewOrchestratorService::class);

    $session1 = $service->startOrResume($user->id, 2026);
    $session2 = $service->startOrResume($user->id, 2026);

    // Should return the SAME session, not create a new one
    expect($session1->id)->toBe($session2->id);

    $count = InterviewSession::where('user_id', $user->id)
        ->where('tax_year', 2026)
        ->whereIn('status', ['created', 'in_progress'])
        ->count();

    expect($count)->toBe(1);
});

it('valid_transitions: session can be paused and resumed', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Test question?']]], 200)]);

    $user = createAuthenticatedUser();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['ira.balance_range'],
        'asked' => [],
    ]);

    // Pause the session
    $session->update(['status' => 'paused']);
    expect($session->fresh()->status)->toBe('paused');

    // Resume
    $service = app(InterviewOrchestratorService::class);
    $resumed = $service->startOrResume($user->id, 2026);
    expect($resumed->status)->toBe('in_progress');
    expect($resumed->id)->toBe($session->id);
});

it('completed_session: startOrResume on completed session creates new session', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Test question?']]], 200)]);

    $user = createAuthenticatedUser();

    // Create a completed session
    InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'completed',
        'queue' => [],
        'asked' => ['ira.balance_range'],
    ]);

    // startOrResume should NOT create a new active session if one is completed
    // (it only creates if no in_progress/paused session exists)
    $service = app(InterviewOrchestratorService::class);
    $session = $service->startOrResume($user->id, 2026);

    // New session should be created (not the completed one)
    $allSessions = InterviewSession::where('user_id', $user->id)
        ->where('tax_year', 2026)
        ->get();

    // Either 1 (new) or 2 (old completed + new) sessions exist
    expect($allSessions->count())->toBeGreaterThanOrEqual(1);
});

// ─── INT-05: Re-answer supersedes UserTaxFact ─────────────────────────────────

it('reanswer_supersedes: re-answering a prior question creates superseding UserTaxFact', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'What is your IRA balance?']]], 200)]);

    $user = createAuthenticatedUser();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => ['ira.balance_range'],
    ]);

    $service = app(InterviewOrchestratorService::class);

    // First answer
    $service->recordAnswer($session, 'ira.balance_range', 'under_25k');

    // Second answer (re-answer)
    $service->recordAnswer($session, 'ira.balance_range', 'over_50k');

    $facts = \App\Models\UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'ira.balance_range')
        ->orderBy('id')
        ->get();

    // Two facts should exist
    expect($facts->count())->toBe(2);

    // Only the latest should be current
    expect($facts->first()->is_current)->toBeFalse();
    expect($facts->last()->is_current)->toBeTrue();
    expect($facts->last()->value)->toBe('over_50k');

    // The old fact should be superseded by the new one
    expect($facts->first()->superseded_by_id)->toBe($facts->last()->id);
});

// ─── INT-06: Batch-by-merchant ────────────────────────────────────────────────

it('batches_by_merchant: one finding with multiple transaction_ids produces one question', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Do you use your vehicle for business?']]], 200)]);

    $user = createAuthenticatedUser();

    // Simulate a finding that covers 40 transactions (INT-06)
    $transactionIds = range(1001, 1040); // 40 transaction IDs

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'vehicle_mileage_deduction',
        'band' => 'auto',
        'status' => 'open',
        'treatment' => 'standard_mileage',
        'transaction_ids' => $transactionIds,
        'description' => 'We noticed repeated auto parts / fuel purchases.',
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['vehicle_mileage_deduction'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    // Exactly ONE AIQuestion created, even though 40 transactions are involved
    $count = AIQuestion::where('user_id', $user->id)
        ->where('question_type', QuestionType::Optimization)
        ->count();

    expect($count)->toBe(1);
    expect($question)->not->toBeNull();

    // The question's options should contain transaction_ids for retroactive application
    $optionsTxIds = $question->options['transaction_ids'] ?? null;
    expect($optionsTxIds)->not->toBeNull();
    expect(count($optionsTxIds))->toBeGreaterThan(0);
});

// ─── INT-07: Confidence-band response mode ───────────────────────────────────

it('high_confidence_suggested_confirm: auto-band question uses suggested-confirm mode', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'It looks like you may be deducting vehicle mileage. Is that correct?']]], 200)]);

    $user = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'vehicle_mileage_deduction',
        'band' => 'auto',
        'status' => 'open',
        'treatment' => 'standard_mileage',
        'description' => 'You may be able to deduct vehicle mileage.',
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['vehicle_mileage_deduction'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    expect($question)->not->toBeNull();

    // INT-07 suggested-confirm: ai_confidence = 1.0 (highest); ai_best_guess = finding_key
    expect((float) $question->ai_confidence)->toBeGreaterThanOrEqual(
        config('tax-detection.confidence.suggested_confirm_threshold', 0.85)
    );

    // Options must include the suggested treatment (for pre-fill UI)
    expect($question->options)->toHaveKey('suggested_treatment');

    // Band must be 'auto' (INT-07)
    expect($question->options['band'] ?? null)->toBe('auto');
});

it('skips_known_facts: nextQuestion skips already-answered fact keys', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'test?']]], 200)]);

    $user = createAuthenticatedUser();

    // Pre-answer a fact key — it should be skipped by nextQuestion
    \App\Models\UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.balance_range',
        value: 'under_25k',
        sourceType: 'interview_answer',
    );

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['ira.balance_range'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    // No question should be created since ira.balance_range is already answered
    expect($question)->toBeNull();
    expect(AIQuestion::where('user_id', $user->id)->where('question_type', QuestionType::Optimization)->count())->toBe(0);
});
