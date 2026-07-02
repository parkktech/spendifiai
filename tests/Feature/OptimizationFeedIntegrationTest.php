<?php

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Events\OptimizationProfileBuilt;
use App\Events\UserAnsweredQuestion;
use App\Listeners\SurfaceHighPriorityRedFlags;
use App\Listeners\UpdateOptimizationFromAnswer;
use App\Listeners\UpdateTransactionCategory;
use App\Models\AIQuestion;
use App\Models\OptimizationFinding;
use App\Models\UserTaxFact;
use App\Services\SubscriptionDetectorService;
use Illuminate\Support\Facades\Http;

/*
 * OptimizationFeedIntegrationTest — covers FEED-01..04 (zero-regression).
 *
 * FEED-04: UpdateTransactionCategory guard skips optimization questions.
 * FEED-02: SurfaceHighPriorityRedFlags creates AIQuestion(Optimization) for high-band findings.
 * FEED-03: UpdateOptimizationFromAnswer writes UserTaxFact (not just snapshot).
 * FEED-04 (index): AIQuestionController::index() cleanup never auto-resolves optimization questions.
 */

// ─── FEED-04: Guard skips subscription detection for optimization answers ─────

it('guard_skips_optimization: answering Optimization question does not trigger subscription detection', function () {
    $user = createAuthenticatedUser();

    // Create optimization AIQuestion (no transaction)
    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'question_type' => QuestionType::Optimization,
        'transaction_id' => null,
        'status' => QuestionStatus::Pending,
        'ai_best_guess' => 'test_finding_key',
        'options' => ['finding_id' => 1, 'fact_key' => 'finding.test', 'band' => 'auto'],
    ]);

    // Mock: SubscriptionDetectorService must NOT receive detectSubscriptions
    $detector = $this->mock(SubscriptionDetectorService::class);
    $detector->shouldNotReceive('detectSubscriptions');

    // Fire the event — UpdateTransactionCategory should return early (guard)
    $listener = app(UpdateTransactionCategory::class);
    $question->update([
        'user_answer' => 'Confirm',
        'status' => QuestionStatus::Answered,
        'answered_at' => now(),
    ]);
    $listener->handle(new UserAnsweredQuestion($question->fresh(), $user));

    // If guard works, the mock expectation above passes (detectSubscriptions not called)
    expect(true)->toBeTrue(); // assertion is in mock expectation
});

// ─── FEED-02: SurfaceHighPriorityRedFlags creates AIQuestion(Optimization) ───

it('creates_ai_question: high-band finding produces one AIQuestion(Optimization) in the feed', function () {
    Http::fake(); // handle any narration calls

    $user = createAuthenticatedUser();

    // Create a high-band (auto) finding
    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'vehicle_mileage_deduction',
        'band' => 'auto',
        'status' => 'open',
        'treatment' => 'standard_mileage',
        'description' => 'You may be able to deduct vehicle mileage.',
    ]);

    // Dispatch the event — SurfaceHighPriorityRedFlags should create AIQuestion
    $listener = app(SurfaceHighPriorityRedFlags::class);
    $listener->handle(new OptimizationProfileBuilt($user->id, 2026, 1));

    // Assert one AIQuestion(Optimization) was created for this finding
    $question = AIQuestion::where('user_id', $user->id)
        ->where('question_type', QuestionType::Optimization)
        ->first();

    expect($question)->not->toBeNull();
    expect($question->status)->toBe(QuestionStatus::Pending);
    expect($question->transaction_id)->toBeNull();
    expect($question->ai_best_guess)->toBe('vehicle_mileage_deduction');
});

it('creates_ai_question is idempotent: dispatching twice creates only one AIQuestion', function () {
    Http::fake();

    $user = createAuthenticatedUser();

    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'vehicle_mileage_deduction',
        'band' => 'auto',
        'status' => 'open',
        'description' => 'You may be able to deduct vehicle mileage.',
    ]);

    $listener = app(SurfaceHighPriorityRedFlags::class);
    $event = new OptimizationProfileBuilt($user->id, 2026, 1);

    $listener->handle($event);
    $listener->handle($event); // second dispatch (job retry simulation)

    $count = AIQuestion::where('user_id', $user->id)
        ->where('question_type', QuestionType::Optimization)
        ->where('ai_best_guess', 'vehicle_mileage_deduction')
        ->count();

    expect($count)->toBe(1);
});

it('does not surface conditional-band findings as optimization questions', function () {
    Http::fake();

    $user = createAuthenticatedUser();

    // Create a conditional (medium) band finding
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'auto_loan_interest',
        'band' => 'conditional',
        'status' => 'open',
    ]);

    $listener = app(SurfaceHighPriorityRedFlags::class);
    $listener->handle(new OptimizationProfileBuilt($user->id, 2026, 1));

    // Should NOT create AIQuestion for conditional-band findings
    $count = AIQuestion::where('user_id', $user->id)
        ->where('question_type', QuestionType::Optimization)
        ->count();

    expect($count)->toBe(0);
});

// ─── FEED-03: UpdateOptimizationFromAnswer writes UserTaxFact ────────────────

it('answer_writes_tax_fact: answering optimization question writes UserTaxFact via UpdateOptimizationFromAnswer', function () {
    $user = createAuthenticatedUser();

    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'question_type' => QuestionType::Optimization,
        'transaction_id' => null,
        'status' => QuestionStatus::Pending,
        'ai_best_guess' => 'vehicle_mileage_deduction',
        'options' => [
            'finding_id' => 99,
            'fact_key' => 'finding.vehicle_mileage_deduction',
            'band' => 'auto',
        ],
    ]);

    $question->update([
        'user_answer' => 'Confirm',
        'status' => QuestionStatus::Answered,
        'answered_at' => now(),
    ]);

    // Fire UpdateOptimizationFromAnswer listener directly
    $listener = app(UpdateOptimizationFromAnswer::class);
    $listener->handle(new UserAnsweredQuestion($question->fresh(), $user));

    // Assert a UserTaxFact was written
    $fact = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'finding.vehicle_mileage_deduction')
        ->where('is_current', true)
        ->first();

    expect($fact)->not->toBeNull();
    expect($fact->source_type)->toBe('interview_answer');
});

it('answer_writes_tax_fact: listener does NOT write estimated_value_cents (SAFE-03)', function () {
    $user = createAuthenticatedUser();

    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'question_type' => QuestionType::Optimization,
        'transaction_id' => null,
        'status' => QuestionStatus::Answered,
        'user_answer' => 'Confirm',
        'answered_at' => now(),
        'ai_best_guess' => 'vehicle_mileage_deduction',
        'options' => [
            'fact_key' => 'finding.vehicle_mileage_deduction',
            'band' => 'auto',
        ],
    ]);

    $listener = app(UpdateOptimizationFromAnswer::class);
    $listener->handle(new UserAnsweredQuestion($question->fresh(), $user));

    // No OptimizationFinding should have estimated_value_cents written by this listener
    // (SAFE-03: only TaxRulesEngineService may write that column)
    $findingWithValue = OptimizationFinding::where('user_id', $user->id)
        ->whereNotNull('estimated_value_cents')
        ->exists();

    expect($findingWithValue)->toBeFalse();
});

it('answer_skips_non_optimization_questions: listener ignores non-optimization questions', function () {
    $user = createAuthenticatedUser();

    // Non-optimization question
    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'question_type' => QuestionType::Category,
        'status' => QuestionStatus::Answered,
        'user_answer' => 'Food & Groceries',
        'answered_at' => now(),
    ]);

    $initialFactCount = UserTaxFact::where('user_id', $user->id)->count();

    $listener = app(UpdateOptimizationFromAnswer::class);
    $listener->handle(new UserAnsweredQuestion($question->fresh(), $user));

    // No UserTaxFact should be created for non-optimization questions
    expect(UserTaxFact::where('user_id', $user->id)->count())->toBe($initialFactCount);
});

// ─── FEED-04: index() cleanup excludes optimization questions ─────────────────

it('index_cleanup_excludes_optimization: null-transaction optimization question is never auto-resolved', function () {
    $data = createUserWithBank();
    $user = $data['user'];

    // Create a null-transaction optimization AIQuestion (pending)
    $optQuestion = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'question_type' => QuestionType::Optimization,
        'transaction_id' => null,
        'status' => QuestionStatus::Pending,
        'ai_best_guess' => 'test_finding',
        'options' => ['fact_key' => 'finding.test', 'band' => 'auto'],
    ]);

    // Call the /api/v1/questions index endpoint
    $response = $this->getJson('/api/v1/questions');
    $response->assertStatus(200);

    // The optimization question must still be pending (not auto-resolved)
    expect($optQuestion->fresh()->status)->toBe(QuestionStatus::Pending);
});
