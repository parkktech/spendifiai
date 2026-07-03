<?php

/**
 * HardBlockRefusalTest — SAFE-06 integration: escape-hatch and chat routes
 * refuse abusive-scheme free text BEFORE any Claude call, returning the
 * structured D18/D19 refusal payload.
 *
 * RED gate: Http::preventStrayRequests() on abusive tests — any Anthropic call
 * causes the test to fail, proving the gate intercepts before Claude.
 *
 * Coverage:
 *   (1) Abusive escape-hatch answer → 200 refusal JSON, assertNothingSent()
 *   (2) Abusive chat message → 200 refusal JSON, assertNothingSent()
 *   (3) Legitimate escape-hatch → passes through to Claude (faked), no refusal key
 *   (4) Legitimate chat → passes through to Claude (faked), no refusal key
 */

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\InterviewSession;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Create an InterviewSession + choice-type AIQuestion for the escape-hatch path.
 *
 * Uses profile.filing_status which has answer_type='choice' in question_templates,
 * satisfying the $isChoiceStyle condition in recordAnswer().
 *
 * @return array{session: InterviewSession, question: AIQuestion}
 */
function makeEscapeHatchSetup(User $user): array
{
    $session = InterviewSession::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => [],
        'format_version' => 3,
    ]);

    // profile.filing_status → answer_type='choice' in optimization-objectives.php
    // question_templates block; resolveTemplate() will find it and set $isChoiceStyle.
    $question = AIQuestion::create([
        'user_id' => $user->id,
        'question' => 'What is your tax filing status?',
        'question_type' => QuestionType::Optimization->value,
        'options' => [
            'fact_key' => 'profile.filing_status',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'single',         'label' => 'Single'],
                ['value' => 'married_joint',  'label' => 'Married filing jointly'],
            ],
        ],
        'ai_confidence' => 0.70,
        'ai_best_guess' => 'profile.filing_status',
        'status' => QuestionStatus::Pending->value,
    ]);

    return compact('session', 'question');
}

/**
 * Create a transaction-linked AIQuestion for the chat path.
 * Requires a user+account from createUserWithBank().
 */
function makeChatQuestion(int $userId, int $accountId): AIQuestion
{
    $tx = Transaction::factory()->create([
        'user_id' => $userId,
        'bank_account_id' => $accountId,
        'merchant_name' => 'Netflix',
        'amount' => 15.99,
    ]);

    return AIQuestion::factory()->create([
        'user_id' => $userId,
        'transaction_id' => $tx->id,
        'question_type' => 'business_personal',
        'status' => QuestionStatus::Pending->value,
    ]);
}

// ── 1. Escape-hatch: abusive text → refusal with zero Anthropic calls ─────────

it('SAFE06: abusive escape-hatch answer returns refusal without calling Anthropic', function () {
    // Any HTTP request to Anthropic would throw — gate must intercept first.
    Http::preventStrayRequests();

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    ['session' => $session, 'question' => $question] = makeEscapeHatchSetup($user);

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => '__other__: I want to set up a Malta pension arrangement to shelter income']
    );

    Http::assertNothingSent();

    $response->assertOk()
        ->assertJsonPath('refused', true)
        ->assertJsonPath('blocked_reason', 'hard_block_safe06')
        ->assertJsonStructure(['refused', 'category', 'education', 'best_effort_disclaimer', 'blocked_reason']);

    // Category must reference Malta pension / abusive foreign trust cluster
    expect(mb_strtolower((string) $response->json('category')))->toContain('malta');

    // best_effort_disclaimer must be the verbatim config copy (non-empty)
    expect($response->json('best_effort_disclaimer'))->toBeString()->not->toBeEmpty();
});

// ── 2. Chat: abusive text → refusal with zero Anthropic calls ─────────────────

it('SAFE06: abusive chat message returns refusal without calling Anthropic', function () {
    Http::preventStrayRequests();

    ['user' => $user, 'account' => $account] = createUserWithBank();
    $question = makeChatQuestion($user->id, $account->id);

    $response = $this->postJson(
        "/api/v1/questions/{$question->id}/chat",
        ['message' => 'How do I set up a captive insurance arrangement to shelter profits?']
    );

    Http::assertNothingSent();

    $response->assertOk()
        ->assertJsonPath('refused', true)
        ->assertJsonPath('blocked_reason', 'hard_block_safe06')
        ->assertJsonStructure(['refused', 'category', 'education', 'best_effort_disclaimer', 'blocked_reason']);

    // Category must reference 831(b) / micro-captive cluster
    expect(mb_strtolower((string) $response->json('category')))->toContain('captive');

    // best_effort_disclaimer must be the verbatim config copy (non-empty)
    expect($response->json('best_effort_disclaimer'))->toBeString()->not->toBeEmpty();
});

// ── 3. Escape-hatch: legitimate text → passes through to Claude (faked) ────────

it('SAFE06: legitimate escape-hatch answer bypasses the refusal gate and reaches Claude', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'content' => [['text' => '{"value": "single"}']],
        ], 200),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    ['session' => $session, 'question' => $question] = makeEscapeHatchSetup($user);

    $response = $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$question->id}/answer",
        ['answer' => '__other__: I am not married and I file my taxes by myself']
    );

    // Should be a normal successful answer, NOT a refusal
    $response->assertOk();
    expect($response->json('refused'))->toBeNull();

    // Claude was reached (the faked response was consumed)
    Http::assertSentCount(1);
});

// ── 4. Chat: legitimate text → passes through to Claude (faked) ───────────────

it('SAFE06: legitimate chat message bypasses the refusal gate and reaches Claude', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'content' => [['text' => '{"category": "Utilities", "expense_type": "personal", "tax_deductible": false, "explanation": "Home internet bill."}']],
        ], 200),
    ]);

    ['user' => $user, 'account' => $account] = createUserWithBank();
    $question = makeChatQuestion($user->id, $account->id);

    $response = $this->postJson(
        "/api/v1/questions/{$question->id}/chat",
        ['message' => 'This is for my home wifi internet service']
    );

    // Should be a normal categorization response, NOT a refusal
    $response->assertOk();
    expect($response->json('refused'))->toBeNull();
    expect($response->json('category'))->toBe('Utilities');

    // Claude was reached
    Http::assertSentCount(1);
});
