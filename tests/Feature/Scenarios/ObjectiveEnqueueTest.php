<?php

use App\Models\IncomeOptimizationProfile;
use App\Models\InterviewSession;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Http;

/*
 * ObjectiveEnqueueTest — SCN-03 zero-Claude proof + §E API contract.
 *
 * The template-question path must send ZERO HTTP to Anthropic (D17 assertNothingSent),
 * typed conversion must apply, wrong objective ids 404 (M4), and the objectives body
 * must never carry a money value (T-14-05-03).
 */

// ─── SCN-03 zero-Claude enqueue proof ─────────────────────────────────────────

it('enqueue + walking template gap questions sends zero HTTP to Anthropic', function () {
    Http::preventStrayRequests();
    Http::fake(); // any accidental call would be recorded (and asserted against)

    $user = createAuthenticatedUser();

    $res = $this->postJson('/api/v1/optimizer/objectives/2026/take_home/enqueue');
    $res->assertOk();
    expect($res->json('enqueued'))->not->toBeEmpty();

    $sessionId = $res->json('session.id');

    // Walk several gap questions — each must be a deterministic template (zero Claude).
    for ($i = 0; $i < 5; $i++) {
        $next = $this->getJson("/api/v1/optimizer/interview/{$sessionId}/next");
        $next->assertOk();
        if ($next->json('question') === null) {
            break;
        }
        expect($next->json('question.options.template'))->toBeTrue();
    }

    // SCN-03 binding acceptance: the template path never called Claude.
    Http::assertNothingSent();
});

// ─── Wrong objective id → 404 (M4 / Pitfall 2) ────────────────────────────────

it('enqueue with an unknown objective id returns 404', function () {
    createAuthenticatedUser();

    $this->postJson('/api/v1/optimizer/objectives/2026/income/enqueue')
        ->assertNotFound();
});

it('enqueue with the bonus_election scenario domain returns 404', function () {
    createAuthenticatedUser();

    $this->postJson('/api/v1/optimizer/objectives/2026/bonus_election/enqueue')
        ->assertNotFound();
});

// ─── Typed conversion via the answer endpoint ─────────────────────────────────

it('money template answer converts dollars to cents through the API', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['income.annual_gross_cents'],
        'asked' => [],
    ]);

    $next = $this->getJson("/api/v1/optimizer/interview/{$session->id}/next");
    $questionId = $next->json('question.id');
    expect($next->json('question.answer_type'))->toBe('money_dollars');

    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$questionId}/answer",
        ['answer' => '72500']
    )->assertOk();

    $fact = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'income.annual_gross_cents')
        ->where('is_current', true)
        ->first();

    expect($fact->value)->toBe('7250000');
});

it('choice template mismatch returns 422 through the API', function () {
    Http::preventStrayRequests();
    Http::fake();

    $user = createAuthenticatedUser();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['profile.filing_status'],
        'asked' => [],
    ]);

    $next = $this->getJson("/api/v1/optimizer/interview/{$session->id}/next");
    $questionId = $next->json('question.id');

    $this->postJson(
        "/api/v1/optimizer/interview/{$session->id}/questions/{$questionId}/answer",
        ['answer' => 'not_a_valid_status']
    )->assertStatus(422);
});

// ─── §E money-leak regression (T-14-05-03) ────────────────────────────────────

it('GET objectives body carries no money values', function () {
    $user = createAuthenticatedUser();

    // Seed a resolvable money snapshot — its cents value must NOT appear in the body.
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'w2_wages' => '7250000',
    ]);

    $res = $this->getJson('/api/v1/optimizer/objectives/2026');
    $res->assertOk();

    // Re-encode with UNESCAPED unicode so JSON escapes (e.g. — em-dash) are not
    // mistaken for 4-digit money runs — we only care about real numeric leakage.
    $body = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
    expect($body)->not->toContain('7250000');
    expect($body)->not->toContain('72500');

    // Strip the tax_year (the only legitimate 4-digit token) and assert no other
    // 4+ digit run (money) remains anywhere in the payload.
    $stripped = str_replace('2026', '', $body);
    expect($stripped)->not->toMatch('/\d{4,}/');
});
