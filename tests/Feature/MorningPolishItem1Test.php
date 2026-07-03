<?php

/**
 * Morning Polish Batch — Item 1: Derived-value confirm-shape
 *
 * When a typed question has a KNOWN/DERIVED value in the resolver chain
 * (ScenarioFactResolverService::resolve returns confirmed=false), the
 * GET /next endpoint should include:
 *   derived_confirm: true       — signal to render the confirm UI shape
 *   prefill_display: "$X"       — humanized value for display
 *   prefill_value: "cents-str"  — raw value for pre-filling the input
 *   prefill_approximate: bool   — true when derivation has uncertainty
 *
 * When no resolved value exists the fields are absent / false (normal ask-shape).
 */

use App\Models\IncomeOptimizationProfile;
use App\Models\InterviewSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

// ── Helper ────────────────────────────────────────────────────────────────────

function polishSession(User $user, string $factKey = 'income.annual_gross_cents'): InterviewSession
{
    return InterviewSession::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [$factKey],
        'asked' => [],
        'format_version' => 3,
    ]);
}

// ── Item 1a: known snapshot value → derived_confirm + prefill_display ────────

it('Item1: known snapshot value for typed fact → derived_confirm=true and prefill_display in next response', function () {
    // Seed snapshot: $75,000 gross (stored as integer cents)
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => 2026,
        'w2_wages' => '7500000',  // $75,000 cents
        'self_employment_income' => null,
    ]);

    $session = polishSession($this->user, 'income.annual_gross_cents');

    $response = $this->getJson("/api/v1/optimizer/interview/{$session->id}/next");

    $response->assertOk()
        ->assertJsonPath('question.derived_confirm', true)
        ->assertJsonStructure(['question' => ['prefill_display', 'prefill_value', 'prefill_approximate']]);

    // Display value should be humanised dollar amount
    $display = $response->json('question.prefill_display');
    expect($display)->toContain('$');
    // Raw value is integer cents string
    expect($response->json('question.prefill_value'))->toBe('7500000');
    // Snapshot source is approximate (not user-confirmed)
    expect($response->json('question.prefill_approximate'))->toBeTrue();
});

// ── Item 1b: no resolved value → derived_confirm absent or false ──────────────

it('Item1: no resolved value for typed fact → derived_confirm false, normal ask-shape', function () {
    // No IncomeOptimizationProfile → resolver returns null
    $session = polishSession($this->user, 'income.annual_gross_cents');

    $response = $this->getJson("/api/v1/optimizer/interview/{$session->id}/next");

    $response->assertOk();

    // derived_confirm should be absent or false
    $derived = $response->json('question.derived_confirm');
    expect($derived)->toBeFalsy();

    // prefill_display should be null
    expect($response->json('question.prefill_display'))->toBeNull();
});

// ── Item 1c: choice question with known value → NO derived_confirm ────────────

it('Item1: choice question with known fact value does NOT get derived_confirm shape', function () {
    // profile.filing_status is a choice question
    // Even if it has a known value, the derived_confirm shape only applies to typed fields
    \App\Models\UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'profile_field',
        label: 'Filing status',
    );

    $session = polishSession($this->user, 'w4.filing_status');

    $response = $this->getJson("/api/v1/optimizer/interview/{$session->id}/next");

    $response->assertOk();

    // Choice questions never get derived_confirm (they use the normal choice UI)
    $derived = $response->json('question.derived_confirm');
    expect($derived)->toBeFalsy();
});
