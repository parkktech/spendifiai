<?php

/**
 * FactAwareSuppressionTest — TDD for fact-aware question suppression.
 *
 * Verifies:
 *  1. Serve-time gate: nextQuestion() skips probe questions whose target facts are confirmed.
 *  2. Serve-time gate: pre-existing AIQuestions (created by SurfaceHighPriorityRedFlags) are
 *     expired when target facts are confirmed, not returned to the user.
 *  3. Emission-time gate: SignalProbeMatrix does NOT emit probe_deferral_gap when
 *     employer.has_401k is already confirmed.
 *  4. Emission-time gate: SignalProbeMatrix does NOT emit probe_deferral_gap when user
 *     is maxing 401k via the paystub-extracted roth/traditional split-key facts.
 *  5. Backlog command: interview:sweep-fact-gate expires stale questions and reports counts.
 *  6. No regression: D17/D18 behaviour preserved — template questions without target-facts
 *     entries are not affected by the fact gate.
 */

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\IncomeOptimizationProfile;
use App\Models\InterviewSession;
use App\Models\OptimizationFinding;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\Detectors\SignalProbeMatrix;
use App\Services\InterviewOrchestratorService;
use App\Services\RedFlagDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
    $this->orchestrator = app(InterviewOrchestratorService::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// 1. Serve-time gate — nextQuestion() skips probe when target fact confirmed
// ──────────────────────────────────────────────────────────────────────────────

it('serve-time gate: nextQuestion skips probe_deferral_gap when employer.has_401k is confirmed', function () {
    // Arrange: confirm employer.has_401k
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    // Create the finding and session with probe in queue
    OptimizationFinding::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'finding_key' => 'probe_deferral_gap',
        'band' => 'auto',
        'status' => 'open',
        'treatment' => 'Your payroll deposits suggest you may have access to a 401(k) plan.',
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'queue' => ['probe_deferral_gap'],
        'asked' => [],
    ]);

    // Act: request next question
    $question = $this->orchestrator->nextQuestion($session);

    // Assert: no question served (fact gate suppressed it)
    expect($question)->toBeNull();
    // Session should be complete (queue exhausted)
    expect($session->fresh()->status)->toBe('completed');
});

it('serve-time gate: nextQuestion returns question when employer.has_401k is NOT confirmed', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Does your employer offer a 401(k)?']]], 200),
    ]);

    // No employer.has_401k fact → gate does not fire
    OptimizationFinding::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'finding_key' => 'probe_deferral_gap',
        'band' => 'auto',
        'status' => 'open',
        'treatment' => 'Your payroll deposits suggest you may have access to a 401(k) plan.',
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'queue' => ['probe_deferral_gap'],
        'asked' => [],
    ]);

    $question = $this->orchestrator->nextQuestion($session);

    // Assert: question IS served
    expect($question)->not->toBeNull();
    expect($question->ai_best_guess)->toBe('probe_deferral_gap');
});

// ──────────────────────────────────────────────────────────────────────────────
// 2. Serve-time gate — pre-existing AIQuestion expired when target fact confirmed
// ──────────────────────────────────────────────────────────────────────────────

it('serve-time gate: pre-existing pending AIQuestion is expired when employer.has_401k confirmed', function () {
    // Arrange: a pre-existing AIQuestion created before facts existed (e.g. by SurfaceHighPriorityRedFlags)
    $existingQ = AIQuestion::factory()->create([
        'user_id' => $this->user->id,
        'question_type' => QuestionType::Optimization->value,
        'ai_best_guess' => 'probe_deferral_gap',
        'status' => QuestionStatus::Pending->value,
        'question' => 'Your payroll suggests access to a 401(k). Does this reflect your situation?',
        'options' => ['fact_key' => 'finding.probe_deferral_gap', 'band' => 'auto'],
    ]);

    // Confirm the target fact as interview_answer (self-confirmed; this simulates the
    // owner confirming his 401k fact from a benefits guide after the question was created)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    OptimizationFinding::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'finding_key' => 'probe_deferral_gap',
        'band' => 'auto',
        'status' => 'open',
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'queue' => ['probe_deferral_gap'],
        'asked' => [],
    ]);

    // Act
    $question = $this->orchestrator->nextQuestion($session);

    // Assert: no question served (fact gate suppressed)
    expect($question)->toBeNull();

    // Pre-existing AIQuestion should be expired
    $existingQ->refresh();
    expect($existingQ->status)->toBe(QuestionStatus::Expired);
});

// ──────────────────────────────────────────────────────────────────────────────
// 3. Emission-time gate — probe_deferral_gap not emitted when has_401k confirmed
// ──────────────────────────────────────────────────────────────────────────────

it('emission-time gate: probe_deferral_gap is NOT emitted when employer.has_401k is confirmed', function () {
    // Arrange: payroll income present but employer.has_401k confirmed
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9000000',
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    // Assert: probe not emitted
    expect($result)->not->toContain('probe_deferral_gap');
    $finding = OptimizationFinding::where('finding_key', 'probe_deferral_gap')
        ->where('user_id', $this->user->id)
        ->first();
    expect($finding)->toBeNull();
});

it('emission-time gate: probe_deferral_gap IS emitted when employer.has_401k is not confirmed', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9000000',
    ]);
    // No employer.has_401k fact

    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toContain('probe_deferral_gap');
});

it('emission-time gate: probe_deferral_gap treatment text contains NO embedded question strings', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9000000',
    ]);

    $detector = app(SignalProbeMatrix::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'probe_deferral_gap')
        ->where('user_id', $this->user->id)
        ->first();
    expect($finding)->not->toBeNull();

    // D18 anatomy fix: treatment MUST NOT embed question strings
    expect($finding->treatment)->not->toContain('Does your employer offer a 401(k)?');
    expect($finding->treatment)->not->toContain('What is your current contribution percentage?');
    // Treatment MUST still have evidence lead
    expect($finding->treatment)->toContain('payroll deposits');
});

// ──────────────────────────────────────────────────────────────────────────────
// 4. Emission-time gate — isMaxing401k uses paystub split-key facts
// ──────────────────────────────────────────────────────────────────────────────

it('emission-time gate: probe_deferral_gap NOT emitted when maxing via paystub split-key facts', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9000000',
    ]);

    // Paystub extractor writes traditional + roth split keys
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.traditional_401k_ytd_cents',
        value: '2000000', // $20,000 traditional
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.roth_401k_ytd_cents',
        value: '500000', // $5,000 roth (total $25,000 > $24,500 max)
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    // Total YTD ($25,000) >= max deferral ($24,500) → not emitted
    expect($result)->not->toContain('probe_deferral_gap');
});

it('emission-time gate: probe_deferral_gap IS emitted when paystub split-key total is below max', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9000000',
    ]);

    // YTD below max: $10,000 total
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.traditional_401k_ytd_cents',
        value: '800000', // $8,000
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.roth_401k_ytd_cents',
        value: '200000', // $2,000 (total $10,000 < $24,500)
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toContain('probe_deferral_gap');
});

// ──────────────────────────────────────────────────────────────────────────────
// 5. Backlog command — sweep-fact-gate expires stale questions
// ──────────────────────────────────────────────────────────────────────────────

it('sweep-fact-gate command: expires pending probe questions whose target facts are confirmed', function () {
    // Arrange: stale pending AIQuestion for probe_deferral_gap
    $staleQ = AIQuestion::factory()->create([
        'user_id' => $this->user->id,
        'question_type' => QuestionType::Optimization->value,
        'ai_best_guess' => 'probe_deferral_gap',
        'status' => QuestionStatus::Pending->value,
        'question' => 'Does your employer offer a 401(k)?',
        'options' => ['fact_key' => 'finding.probe_deferral_gap', 'band' => 'auto'],
    ]);

    // Confirm employer.has_401k
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    // Act: run command for this user
    $this->artisan('interview:sweep-fact-gate', ['--user' => $this->user->id])
        ->assertExitCode(0);

    // Assert: stale question is now expired
    $staleQ->refresh();
    expect($staleQ->status)->toBe(QuestionStatus::Expired);
});

it('sweep-fact-gate command: does NOT expire questions whose target facts are not confirmed', function () {
    $activeQ = AIQuestion::factory()->create([
        'user_id' => $this->user->id,
        'question_type' => QuestionType::Optimization->value,
        'ai_best_guess' => 'probe_deferral_gap',
        'status' => QuestionStatus::Pending->value,
        'question' => 'Does your employer offer a 401(k)?',
        'options' => ['fact_key' => 'finding.probe_deferral_gap', 'band' => 'auto'],
    ]);

    // NO employer.has_401k fact → should NOT expire

    $this->artisan('interview:sweep-fact-gate', ['--user' => $this->user->id])
        ->assertExitCode(0);

    $activeQ->refresh();
    expect($activeQ->status)->toBe(QuestionStatus::Pending);
});

it('sweep-fact-gate command dry-run: does not modify questions', function () {
    $staleQ = AIQuestion::factory()->create([
        'user_id' => $this->user->id,
        'question_type' => QuestionType::Optimization->value,
        'ai_best_guess' => 'probe_deferral_gap',
        'status' => QuestionStatus::Pending->value,
        'question' => 'Does your employer offer a 401(k)?',
        'options' => ['fact_key' => 'finding.probe_deferral_gap', 'band' => 'auto'],
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    $this->artisan('interview:sweep-fact-gate', ['--user' => $this->user->id, '--dry-run' => true])
        ->assertExitCode(0);

    // Status unchanged in dry-run
    $staleQ->refresh();
    expect($staleQ->status)->toBe(QuestionStatus::Pending);
});

it('sweep-fact-gate command: does NOT touch categorization questions (out of scope)', function () {
    // A regular categorization question — must remain untouched
    $categorizationQ = AIQuestion::factory()->create([
        'user_id' => $this->user->id,
        'question_type' => QuestionType::Category->value,
        'ai_best_guess' => 'food', // a category answer
        'status' => QuestionStatus::Pending->value,
        'question' => 'Was this a food expense?',
        'options' => [],
    ]);

    // Confirm a fact that would trigger the gate if it looked at this question
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    $this->artisan('interview:sweep-fact-gate', ['--user' => $this->user->id])
        ->assertExitCode(0);

    $categorizationQ->refresh();
    // Categorization question must remain pending (out of scope)
    expect($categorizationQ->status)->toBe(QuestionStatus::Pending);
});

// ──────────────────────────────────────────────────────────────────────────────
// 6. D17/D18 no-regression: template questions unaffected by fact gate
// ──────────────────────────────────────────────────────────────────────────────

it('no-regression: template question for employer.has_401k is served when fact NOT confirmed', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'question text']]], 200),
    ]);

    // employer.has_401k is a template question (in FACT_TIER_MAP, handled by isAlreadyAnswered)
    // When fact is NOT confirmed, it should be served normally via the template path.
    // No TARGET_FACTS_MAP entry for employer.has_401k itself.
    $session = InterviewSession::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'queue' => ['employer.has_401k'],
        'asked' => [],
    ]);

    $question = $this->orchestrator->nextQuestion($session);

    // Template question is served (target-facts gate does not apply)
    expect($question)->not->toBeNull();
    expect($question->ai_best_guess)->toBe('employer.has_401k');
});

it('no-regression: template question for employer.has_401k is skipped when fact IS confirmed', function () {
    // employer.has_401k confirmed → isAlreadyAnswered() skips it (existing behaviour)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    $session = InterviewSession::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'queue' => ['employer.has_401k'],
        'asked' => [],
    ]);

    $question = $this->orchestrator->nextQuestion($session);

    // isAlreadyAnswered() handles template questions — no question served
    expect($question)->toBeNull();
});
