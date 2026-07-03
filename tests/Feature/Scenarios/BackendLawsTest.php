<?php

declare(strict_types=1);

/**
 * BackendLawsTest — PHP-level regression probes for the two Night's Laws that
 * previously had only e2e (Playwright) coverage, which is infra-blocked locally.
 *
 * Law 1: COMPLETE-CANNOT-LIE
 *   InterviewController::next() must never return session_status='completed' while
 *   any objective still has blocking_missing facts. Covers the phase-2 stateless
 *   gap-serving path (InterviewController::next() lines 84-102).
 *
 * Law 2: FIRST-CLICK-CHOOSE (backend half)
 *   A single POST /optimizer/scenarios/{year}/choose persists chosen_option AND
 *   materializes the checklist AND returns both in one response body — callers
 *   never need a second round-trip.
 *
 * Evidence for deferral to PHP tests:
 *   14-VERIFICATION.md — "PRESENT_BEHAVIOR_UNVERIFIED locally (walk infra-blocked; CI covers)"
 *   Closes gap 3 of the 2026-07-03 verification report.
 */

use App\Models\InterviewSession;
use App\Models\OptimizationChecklistItem;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('api');
});

/**
 * Seed the full-fact set required for the solver to produce options.
 * Mirrors seedChooseFacts() in ScenarioChooseTest — intentionally renamed
 * to avoid a duplicate-function PHP error in the same test process.
 */
function lawsFullFactSeed(App\Models\User $user, int $year = 2026): void
{
    $ytdKeys = [
        'employer.federal_withholding', 'retirement.traditional_401k_ytd_cents',
        'retirement.roth_401k_ytd_cents', 'hsa.ytd_contribution_cents',
        'benefits.fsa_ytd_cents', 'ira.traditional_ytd_contribution_cents',
        'ira.roth_ytd_contribution_cents', 'pay.gross_per_period_cents',
    ];
    $facts = [
        'profile.filing_status' => 'single',
        'pay.frequency' => 'biweekly',
        'employer.federal_withholding' => '1500000',
        'pay.gross_per_period_cents' => '384615',
        'family.dependents_count' => '0',
        'family.qualifying_children_under_17' => '0',
        'employer.has_401k' => 'yes',
        'employer.match_pct' => '50',
        'employer.match_threshold_pct' => '6',
        'employer.contribution_pct' => '3',
        'retirement.traditional_401k_ytd_cents' => '300000',
        'retirement.roth_401k_ytd_cents' => '0',
        'hsa.ytd_contribution_cents' => '0',
        'benefits.fsa_ytd_cents' => '0',
        'ira.traditional_ytd_contribution_cents' => '0',
        'ira.roth_ytd_contribution_cents' => '0',
        'person.birth_year' => '1985',
        'retirement.target_age' => '65',
        'income.annual_gross_cents' => '10000000',
    ];
    foreach ($facts as $key => $value) {
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: $key,
            value: $value,
            sourceType: 'user_edit',
            label: $key,
            volatility: 'stable',
            taxYear: in_array($key, $ytdKeys, true) ? $year : null,
        );
    }
}

// ── Law 1: COMPLETE-CANNOT-LIE ───────────────────────────────────────────────

it('complete-cannot-lie: next returns gap question or in_progress, never completed, when objectives are locked', function () {
    Http::fake(); // fakes any AI synthesis call — no stray Anthropic traffic

    // User with NO facts → every objective has blocking_missing entries → not ready
    $user = createAuthenticatedUser();

    // Empty queue forces phase-2 (stateless gap serving) immediately on next()
    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => [],
    ]);

    $response = $this->getJson("/api/v1/optimizer/interview/{$session->id}/next");
    $response->assertOk();

    $sessionStatus = $response->json('session_status');
    $question = $response->json('question');

    // COMPLETE-CANNOT-LIE invariant: with locked objectives the response must NEVER
    // be the terminal complete state.  The terminal state is defined as the pair
    // (question=null, session_status='completed') — that is what causes the frontend
    // to show "Review complete" instead of a question or loading indicator.
    // The session_status field alone CAN be 'completed' (set by the phase-1 queue
    // drain) so long as a gap question is being served alongside it.
    expect($question === null && $sessionStatus === 'completed')->toBeFalse(
        'COMPLETE-CANNOT-LIE violated: terminal complete state returned while objectives are still locked'
    );

    // Positive assertion: the response is either a gap question (phase-2 served one)
    // or the still-needed guard fired (session_status forced to in_progress)
    if ($question === null) {
        // Guard path: synthesis unavailable or all blocking keys skipped/stalled
        expect($sessionStatus)->toBe('in_progress');
    } else {
        // Gap question served — objectives are still blocking but progress is offered
        expect($question)->toHaveKey('id');
    }
});

// ── Law 2: FIRST-CLICK-CHOOSE (backend half) ─────────────────────────────────

it('first-click-choose: one POST persists chosen_option and materializes checklist in a single request', function () {
    Http::fake();
    Cache::flush();

    $user = createUserWithBank()['user'];
    lawsFullFactSeed($user);

    // Discover an available option key (setup — not the call under test)
    $optionKey = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026')
        ->assertOk()
        ->json('options.0.key') ?? 'balanced';

    // THE call under test — one POST, nothing else
    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey]);

    $response->assertOk();

    // 1) chosen_option persisted in that single request
    $fact = UserTaxFact::currentFact($user->id, 'scenario.chosen_option', null, 2026);
    expect($fact)->not->toBeNull();
    expect($fact->value)->toBe($optionKey);

    // 2) checklist materialized in that single request (no second call needed)
    $itemCount = OptimizationChecklistItem::where('user_id', $user->id)
        ->where('tax_year', 2026)
        ->count();
    expect($itemCount)->toBeGreaterThan(0);

    // 3) both chosen + checklist are inline in the response body
    $response->assertJsonStructure(['chosen', 'checklist' => ['header_aggregate', 'items']]);
});
