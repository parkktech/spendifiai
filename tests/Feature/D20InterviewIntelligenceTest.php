<?php

/**
 * D20 Interview Intelligence — Eligibility Predicates, Escape Hatch Question Detection,
 * and Document-Source Choice.
 *
 * D20.1: Tier ordering + when predicates + format_version invalidation
 * D20.2: isQuestion() detection + answerHatchQuestion() structured response
 * D20.3: handleDocSourceAnswer() files a DocumentRequest + marks question doc_pending
 */

use App\Enums\DocumentRequestStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\DocumentRequest;
use App\Models\InterviewSession;
use App\Models\OptimizationFinding;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\InterviewOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->orchestrator = app(InterviewOrchestratorService::class);
});

// ── D20.1 — Format version invalidation ──────────────────────────────────────

it('D20.1: startOrResume stamps format_version on new sessions', function () {
    Http::fake(); // no Claude calls for startOrResume
    $session = $this->orchestrator->startOrResume($this->user->id, 2026);

    expect($session->format_version)->toBe(InterviewOrchestratorService::FORMAT_VERSION);
});

it('D20.1: startOrResume rebuilds queue when format_version is outdated', function () {
    Http::fake();

    // Create an old-format session (no format_version = null → v0)
    $session = InterviewSession::create([
        'user_id' => $this->user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['old_key_1', 'old_key_2'],
        'asked' => [],
        'format_version' => null,   // pre-D20 (v0)
    ]);

    expect($session->queue)->toHaveCount(2);

    // startOrResume detects stale version → clears queue, bumps format_version
    $resumed = $this->orchestrator->startOrResume($this->user->id, 2026);

    expect($resumed->format_version)->toBe(InterviewOrchestratorService::FORMAT_VERSION)
        ->and($resumed->queue)->toBe([]); // cleared for rebuild
});

it('D20.1: startOrResume does NOT rebuild queue when format_version is current', function () {
    Http::fake();

    $session = InterviewSession::create([
        'user_id' => $this->user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['profile.filing_status', 'person.birth_year'],
        'asked' => [],
        'format_version' => InterviewOrchestratorService::FORMAT_VERSION,
    ]);

    $resumed = $this->orchestrator->startOrResume($this->user->id, 2026);

    // Queue must be untouched
    expect($resumed->queue)->toBe(['profile.filing_status', 'person.birth_year']);
});

// ── D20.1 — Eligibility predicates (when) ─────────────────────────────────

it('D20.1: passesEligibilityPredicate returns true when no when clause on template', function () {
    $method = new ReflectionMethod($this->orchestrator, 'passesEligibilityPredicate');
    $method->setAccessible(true);

    // filing_status has no 'when' predicate → always eligible
    expect($method->invoke($this->orchestrator, 'profile.filing_status', $this->user->id))->toBeTrue();
});

it('D20.1: passesEligibilityPredicate returns true when fact is unknown (unevaluable)', function () {
    $method = new ReflectionMethod($this->orchestrator, 'passesEligibilityPredicate');
    $method->setAccessible(true);

    // spouse.annual_income_cents has 'when' => 'profile.filing_status = married_joint'
    // but profile.filing_status has NOT been answered yet → unevaluable → true (may ask)
    expect($method->invoke($this->orchestrator, 'spouse.annual_income_cents', $this->user->id))->toBeTrue();
});

it('D20.1: passesEligibilityPredicate returns false when predicate evaluates to false', function () {
    $method = new ReflectionMethod($this->orchestrator, 'passesEligibilityPredicate');
    $method->setAccessible(true);

    // Record filing_status = 'single' — spouse questions should be filtered out
    UserTaxFact::recordFact($this->user->id, 'profile.filing_status', 'single', 'interview_answer');

    // spouse.annual_income_cents when 'profile.filing_status = married_joint' → false (single != married_joint)
    expect($method->invoke($this->orchestrator, 'spouse.annual_income_cents', $this->user->id))->toBeFalse();
});

it('D20.1: passesEligibilityPredicate returns true when predicate evaluates to true', function () {
    $method = new ReflectionMethod($this->orchestrator, 'passesEligibilityPredicate');
    $method->setAccessible(true);

    // Record filing_status = 'married_joint' — spouse questions should pass
    UserTaxFact::recordFact($this->user->id, 'profile.filing_status', 'married_joint', 'interview_answer');

    expect($method->invoke($this->orchestrator, 'spouse.annual_income_cents', $this->user->id))->toBeTrue();
});

it('D20.1: HSA-gated question filtered when HSA not eligible', function () {
    $method = new ReflectionMethod($this->orchestrator, 'passesEligibilityPredicate');
    $method->setAccessible(true);

    UserTaxFact::recordFact($this->user->id, 'health.hsa_eligible', 'no', 'interview_answer');

    // hsa.ytd_contribution_cents has 'when' => 'health.hsa_eligible = yes'
    expect($method->invoke($this->orchestrator, 'hsa.ytd_contribution_cents', $this->user->id))->toBeFalse();
});

it('D20.1: numeric age predicate evaluates correctly from birth_year', function () {
    $method = new ReflectionMethod($this->orchestrator, 'evaluateWhenPredicate');
    $method->setAccessible(true);

    // User born 1965 → age ~61 (relative to test date 2026-07-02)
    UserTaxFact::recordFact($this->user->id, 'person.birth_year', '1965', 'interview_answer');

    // person.estimated_age >= 60 → true
    expect($method->invoke($this->orchestrator, 'person.estimated_age >= 60', $this->user->id))->toBeTrue();
    // person.estimated_age >= 70 → false (61 < 70)
    expect($method->invoke($this->orchestrator, 'person.estimated_age >= 70', $this->user->id))->toBeFalse();
    // person.estimated_age >= 64 → false (61 < 64) — Medicare gate
    expect($method->invoke($this->orchestrator, 'person.estimated_age >= 64', $this->user->id))->toBeFalse();
});

it('D20.1: tierFor returns correct tier for known fact keys', function () {
    $method = new ReflectionMethod($this->orchestrator, 'tierFor');
    $method->setAccessible(true);

    expect($method->invoke($this->orchestrator, 'profile.filing_status'))->toBe(1);   // identity
    expect($method->invoke($this->orchestrator, 'employer.has_401k'))->toBe(2);        // big-dollar
    expect($method->invoke($this->orchestrator, 'pay.frequency'))->toBe(3);            // income classification
    expect($method->invoke($this->orchestrator, 'vehicle.usage_log_status'))->toBe(4); // micro-probe
});

// ── D20.2 — Escape hatch question detection ───────────────────────────────

it('D20.2: isQuestion detects text ending in question mark', function () {
    expect($this->orchestrator->isQuestion('What is an HSA?'))->toBeTrue();
    expect($this->orchestrator->isQuestion('Does my employer match contributions?'))->toBeTrue();
});

it('D20.2: isQuestion detects text with question openers', function () {
    expect($this->orchestrator->isQuestion('How does this work'))->toBeTrue();
    expect($this->orchestrator->isQuestion('what is the limit'))->toBeTrue();
    expect($this->orchestrator->isQuestion('Can I contribute if I have an FSA'))->toBeTrue();
});

it('D20.2: isQuestion returns false for plain answer text', function () {
    expect($this->orchestrator->isQuestion('yes'))->toBeFalse();
    expect($this->orchestrator->isQuestion('married_joint'))->toBeFalse();
    expect($this->orchestrator->isQuestion('I contribute 500 per month to my 401k'))->toBeFalse();
});

it('D20.2: answerHatchQuestion returns null at budget cap', function () {
    config()->set('services.anthropic.daily_budget_wording', 0); // cap at 0
    Http::fake(); // no calls should happen

    // config() dot notation can't traverse fact keys that contain dots; use array access
    $templates = (array) config('optimization-objectives.question_templates', []);
    $template = $templates['profile.filing_status'];
    $result = $this->orchestrator->answerHatchQuestion($template, 'What does married filing jointly mean?');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

it('D20.2: answerHatchQuestion returns structured educational response (JSON from Claude)', function () {
    // Return valid {educational_answer, interpreted_value} JSON
    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'educational_answer' => 'Married filing jointly combines both spouses income on one return and may lower your overall tax rate.',
                'interpreted_value' => 'married_joint',
            ])]],
        ], 200),
    ]);

    $templates = (array) config('optimization-objectives.question_templates', []);
    $template = $templates['profile.filing_status'];
    $result = $this->orchestrator->answerHatchQuestion($template, 'What is married filing jointly?');

    expect($result)->not->toBeNull()
        ->and($result)->toHaveKeys(['educational_answer', 'interpreted_value'])
        ->and($result['interpreted_value'])->toBe('married_joint')
        ->and($result['educational_answer'])->toContain('tax');
});

it('D20.2: answerHatchQuestion uses the wording (Haiku) model', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'educational_answer' => 'An HSA is a tax-advantaged savings account for medical expenses.',
                'interpreted_value' => 'yes',
            ])]],
        ], 200),
    ]);

    $template = ['question' => 'Are you enrolled in a high-deductible health plan?', 'choices' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]];
    $this->orchestrator->answerHatchQuestion($template, 'What is an HSA?');

    Http::assertSent(function ($request) {
        $decoded = json_decode($request->body(), true);
        // Must use wording (Haiku) model, not the full Sonnet model
        return str_contains($request->url(), 'api.anthropic.com')
            && ($decoded['model'] ?? '') === config('services.anthropic.model_wording');
    });
});

// ── D20.3 — Doc-source choice ─────────────────────────────────────────────

it('D20.3: handleDocSourceAnswer returns false when template has no doc_affordance', function () {
    $session = InterviewSession::create([
        'user_id' => $this->user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['profile.filing_status'],
        'asked' => [],
        'format_version' => InterviewOrchestratorService::FORMAT_VERSION,
    ]);

    $question = AIQuestion::create([
        'user_id' => $this->user->id,
        'question' => 'What is your filing status?',
        'question_type' => QuestionType::Optimization->value,
        'options' => ['fact_key' => 'profile.filing_status'],
        'ai_confidence' => 0.7,
        'ai_best_guess' => 'profile.filing_status',
        'status' => QuestionStatus::Pending->value,
    ]);

    $template = ['question' => 'What is your filing status?']; // no doc_affordance
    $result = $this->orchestrator->handleDocSourceAnswer($session, $question, $template);

    expect($result)->toBeFalse();
    expect(DocumentRequest::count())->toBe(0);
});

it('D20.3: handleDocSourceAnswer files a DocumentRequest and marks question doc_pending', function () {
    $session = InterviewSession::create([
        'user_id' => $this->user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['pay.gross_per_period_cents'],
        'asked' => [],
        'format_version' => InterviewOrchestratorService::FORMAT_VERSION,
    ]);

    $question = AIQuestion::create([
        'user_id' => $this->user->id,
        'question' => 'How much is your gross pay each paycheck?',
        'question_type' => QuestionType::Optimization->value,
        'options' => ['fact_key' => 'pay.gross_per_period_cents'],
        'ai_confidence' => 0.7,
        'ai_best_guess' => 'pay.gross_per_period_cents',
        'status' => QuestionStatus::Pending->value,
    ]);

    $template = [
        'question' => 'How much is your gross pay each paycheck?',
        'doc_affordance' => 'pay_stub',
        'label' => 'Gross pay per paycheck',
    ];
    $result = $this->orchestrator->handleDocSourceAnswer($session, $question, $template);

    expect($result)->toBeTrue();

    // DocumentRequest must be filed
    $docReq = DocumentRequest::where('client_id', $this->user->id)->first();
    expect($docReq)->not->toBeNull()
        ->and($docReq->category)->toBe('pay_stub')
        ->and($docReq->tax_year)->toBe(2026)
        ->and($docReq->accounting_firm_id)->toBeNull() // self-initiated
        ->and($docReq->accountant_id)->toBeNull();

    // Question options must have doc_pending = true
    $fresh = $question->fresh();
    expect($fresh->options['doc_pending'])->toBeTrue()
        ->and($fresh->options['doc_category'])->toBe('pay_stub');

    // Question fact_key dequeued from session
    $freshSession = $session->fresh();
    expect($freshSession->queue)->not->toContain('pay.gross_per_period_cents');
});

it('D20.3: handleDocSourceAnswer is idempotent (no duplicate DocumentRequests)', function () {
    $session = InterviewSession::create([
        'user_id' => $this->user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['retirement.statement_balance_cents'],
        'asked' => [],
        'format_version' => InterviewOrchestratorService::FORMAT_VERSION,
    ]);

    $template = [
        'question' => 'What is your retirement balance?',
        'doc_affordance' => 'retirement_statement',
        'label' => 'Retirement balance',
    ];

    $question = AIQuestion::create([
        'user_id' => $this->user->id,
        'question' => $template['question'],
        'question_type' => QuestionType::Optimization->value,
        'options' => ['fact_key' => 'retirement.statement_balance_cents'],
        'ai_confidence' => 0.7,
        'ai_best_guess' => 'retirement.statement_balance_cents',
        'status' => QuestionStatus::Pending->value,
    ]);

    $this->orchestrator->handleDocSourceAnswer($session, $question, $template);
    $this->orchestrator->handleDocSourceAnswer($session, $question->fresh(), $template);

    // Only 1 DocumentRequest (firstOrCreate idempotent)
    expect(DocumentRequest::where('client_id', $this->user->id)->count())->toBe(1);
});
