<?php

/**
 * Morning Polish Batch — Item 2: Not-sure handling on typed fields
 *
 * (a) Server-side: recognizable not-sure phrases → not-sure path:
 *     - Record UserTaxFact with value='0' and metadata.unknown=true
 *     - Create DocumentRequest when the template has a doc_affordance
 *     - Return 200 with "No problem — we'll get this from your [doc]..." copy
 *
 * (b) Other unparseable text on typed fields → specific 422 validation:
 *     - money_dollars: "Please enter a dollar amount, like $4,250"
 *     - integer: "Please enter a whole number, like 3"
 *     (Never the Laravel default "Please enter a number.")
 *
 * D17 gate: not-sure detection is a phrase list, not a model call.
 *
 * Tests include the owner's exact trigger: "I don't remember"
 */

use App\Enums\DocumentRequestStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\DocumentRequest;
use App\Models\InterviewSession;
use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->session = InterviewSession::create([
        'user_id' => $this->user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => [],
        'format_version' => 3,
    ]);
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeTypedQuestion(User $user, string $answerType = 'money_dollars', ?string $docAffordance = null): AIQuestion
{
    $options = [
        'fact_key' => 'pay.gross_per_period_cents',
        'template' => true,
        'band' => 'conditional',
        'answer_type' => $answerType,
    ];
    if ($docAffordance !== null) {
        $options['doc_affordance'] = $docAffordance;
    }

    return AIQuestion::create([
        'user_id' => $user->id,
        'question' => 'How much is your gross pay each paycheck?',
        'question_type' => QuestionType::Optimization->value,
        'options' => $options,
        'ai_confidence' => 0.70,
        'ai_best_guess' => 'pay.gross_per_period_cents',
        'status' => QuestionStatus::Pending->value,
    ]);
}

function makeIntegerQuestion(User $user): AIQuestion
{
    return AIQuestion::create([
        'user_id' => $user->id,
        'question' => 'How many dependents do you support?',
        'question_type' => QuestionType::Optimization->value,
        'options' => [
            'fact_key' => 'family.dependents_count',
            'template' => true,
            'band' => 'conditional',
            'answer_type' => 'integer',
            'min' => 0,
            'max' => 15,
        ],
        'ai_confidence' => 0.70,
        'ai_best_guess' => 'family.dependents_count',
        'status' => QuestionStatus::Pending->value,
    ]);
}

// ── Item 2a: not-sure phrases → unknown fact recorded, 200 ───────────────────

it('Item2: "not sure" on money_dollars field → 200, records unknown fact', function () {
    $question = makeTypedQuestion($this->user, 'money_dollars');

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => 'not sure']
    );

    $response->assertOk();

    $fact = UserTaxFact::forUser($this->user->id)
        ->where('fact_key', 'pay.gross_per_period_cents')
        ->where('is_current', true)
        ->first();

    expect($fact)->not->toBeNull()
        ->and($fact->metadata['unknown'] ?? false)->toBeTrue();
});

it('Item2: owner exact phrase "I don\'t remember" on money_dollars → 200, records unknown fact', function () {
    $question = makeTypedQuestion($this->user, 'money_dollars');

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => "I don't remember"]
    );

    $response->assertOk();

    $fact = UserTaxFact::forUser($this->user->id)
        ->where('fact_key', 'pay.gross_per_period_cents')
        ->first();

    expect($fact)->not->toBeNull()
        ->and($fact->metadata['unknown'] ?? false)->toBeTrue();
});

it('Item2: "idk" on integer field → 200, records unknown fact', function () {
    $question = makeIntegerQuestion($this->user);

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => 'idk']
    );

    $response->assertOk();

    $fact = UserTaxFact::forUser($this->user->id)
        ->where('fact_key', 'family.dependents_count')
        ->first();

    expect($fact)->not->toBeNull()
        ->and($fact->metadata['unknown'] ?? false)->toBeTrue();
});

it('Item2: "?" on money_dollars field → 200, records unknown fact', function () {
    $question = makeTypedQuestion($this->user, 'money_dollars');

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => '?']
    );

    $response->assertOk();

    $fact = UserTaxFact::forUser($this->user->id)
        ->where('fact_key', 'pay.gross_per_period_cents')
        ->first();

    expect($fact)->not->toBeNull()
        ->and($fact->metadata['unknown'] ?? false)->toBeTrue();
});

// ── Item 2a(ii): DocumentRequest created when doc_affordance is set ───────────

it('Item2: not-sure on field with doc_affordance → creates DocumentRequest', function () {
    $question = makeTypedQuestion($this->user, 'money_dollars', 'pay_stub');

    $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => 'not sure']
    )->assertOk();

    $docRequest = DocumentRequest::where('client_id', $this->user->id)
        ->where('category', 'pay_stub')
        ->where('tax_year', 2026)
        ->first();

    expect($docRequest)->not->toBeNull()
        ->and($docRequest->status)->toBe(DocumentRequestStatus::Pending);
});

it('Item2: not-sure on field WITHOUT doc_affordance → no DocumentRequest created', function () {
    // Use a fact key not present in config (so no config-level doc_affordance override)
    // family.dependents_count has no doc_affordance in the config
    $question = makeIntegerQuestion($this->user);

    $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => 'not sure']
    )->assertOk();

    $docRequest = DocumentRequest::where('client_id', $this->user->id)->first();
    expect($docRequest)->toBeNull();
});

// ── Item 2a(iii): advance-copy in response ────────────────────────────────────

it('Item2: not-sure response includes helpful copy (not "Answer recorded.")', function () {
    $question = makeTypedQuestion($this->user, 'money_dollars', 'pay_stub');

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => 'not sure']
    );

    $response->assertOk();
    $msg = $response->json('message');
    // Should mention "No problem" or document source
    expect(strtolower($msg))->toContain('no problem');
});

// ── Item 2b: unparseable text → specific 422 message ─────────────────────────

it('Item2: "abc" on money_dollars field → 422 with specific dollar-amount message', function () {
    $question = makeTypedQuestion($this->user, 'money_dollars');

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => 'abc']
    );

    $response->assertStatus(422);
    $errors = $response->json('errors.answer.0') ?? $response->json('message');
    // Must contain specific guidance, not generic text
    expect(strtolower((string) $errors))->toContain('dollar');
});

it('Item2: "xyz" on integer field → 422 with specific whole-number message', function () {
    $question = makeIntegerQuestion($this->user);

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$this->session->id}/questions/{$question->id}/answer",
        ['answer' => 'xyz']
    );

    $response->assertStatus(422);
    $errors = $response->json('errors.answer.0') ?? $response->json('message');
    expect(strtolower((string) $errors))->toContain('whole number');
});
