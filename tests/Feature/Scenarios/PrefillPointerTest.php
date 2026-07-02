<?php

use App\Models\IncomeOptimizationProfile;
use App\Models\InterviewSession;
use App\Models\User;
use App\Services\InterviewOrchestratorService;
use Illuminate\Support\Facades\Http;

/*
 * PrefillPointerTest — SAFE-03 / T-14-05-01: suggested-confirm AIQuestions carry a
 * prefill_source POINTER, never a dollar value; answering 'confirm' records the
 * resolver-resolved value with interview_answer provenance.
 */

it('suggested-confirm question stores a prefill_source pointer, never a dollar value', function () {
    Http::preventStrayRequests(); // template path must not call Claude

    $user = User::factory()->create();

    // Snapshot value → resolves as known-but-unconfirmed → suggested-confirm.
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'w2_wages' => '7250000',   // $72,500 in cents
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['income.annual_gross_cents'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $question = $service->nextQuestion($session);

    expect($question)->not->toBeNull();
    expect($question->options['band'])->toBe('auto');
    expect($question->options)->toHaveKey('prefill_source');
    expect($question->options['prefill_source'])->toBeString();

    // No dollar value (4+ digit run) anywhere in the stored options JSON.
    $optionsJson = json_encode($question->options);
    expect($optionsJson)->not->toMatch('/\d{4,}/');
    // The raw cents value must not appear.
    expect($optionsJson)->not->toContain('7250000');
});

it('answering confirm records the resolver-resolved value as interview_answer', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'w2_wages' => '7250000',
    ]);

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['income.annual_gross_cents'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);

    // Answer with the confirm sentinel — the orchestrator resolves the pointer itself.
    $fact = $service->recordAnswer($session, 'income.annual_gross_cents', 'confirm');

    expect($fact->source_type)->toBe('interview_answer');
    expect($fact->value)->toBe('7250000'); // resolved server-side, cents-string
});

it('typed money answer converts dollars to cents-string on a template key', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['income.annual_gross_cents'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $fact = $service->recordAnswer($session, 'income.annual_gross_cents', '72500');

    expect($fact->value)->toBe('7250000'); // dollars → cents
    // tax_year_scoped template → fact carries the session tax year
    expect((int) $fact->tax_year)->toBe(2026);
});

it('a choice mismatch on a template key throws a 422 validation error', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['profile.filing_status'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);

    expect(fn () => $service->recordAnswer($session, 'profile.filing_status', 'not_a_status'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('non-template keys keep legacy record behavior (stable, no tax year)', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['ira.balance_range'],
        'asked' => [],
    ]);

    $service = app(InterviewOrchestratorService::class);
    $fact = $service->recordAnswer($session, 'ira.balance_range', 'under_25k');

    expect($fact->value)->toBe('under_25k'); // unchanged — no typed conversion
    expect($fact->tax_year)->toBeNull();
});
