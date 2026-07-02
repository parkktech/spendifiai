<?php

use App\Models\InterviewSession;
use App\Models\OptimizationFinding;
use Illuminate\Support\Facades\Http;

/*
 * InterviewSkipPersistenceTest — owner DEFECT 2 ("skip doesn't skip, just refreshes").
 *
 * Reproduces the reported infinite-loop-at-Q1 and proves the fix:
 *   - skip persists in session->skipped[]
 *   - next() advances past a skipped item
 *   - the stale-queue self-heal rebuild EXCLUDES skipped/asked/answered items, so a
 *     skipped item never re-surfaces at position 1 on resume/reload.
 */

it('skip advances to the next question and the skipped item never returns on resume', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'Does this apply?']]], 200)]);

    $user = createAuthenticatedUser();

    // Two finding-backed queue items (conditional, no template → finding path).
    foreach (['finding_probe_one', 'finding_probe_two'] as $key) {
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => 2026,
            'finding_key' => $key,
            'finding_type' => 'deduction_probe',
            'band' => 'conditional',
            'status' => 'open',
        ]);
    }

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['finding_probe_one', 'finding_probe_two'],
        'asked' => [],
        'skipped' => [],
    ]);

    // Q1
    $q1 = $this->getJson("/api/v1/optimizer/interview/{$session->id}/next");
    $q1->assertOk();
    $q1Id = $q1->json('question.id');
    expect($q1->json('question.ai_best_guess'))->toBe('finding_probe_one');

    // Skip Q1 → the skip endpoint advances and returns Q2.
    $skip = $this->postJson("/api/v1/optimizer/interview/{$session->id}/questions/{$q1Id}/skip");
    $skip->assertOk();
    expect($skip->json('question.ai_best_guess'))->toBe('finding_probe_two');

    // The skip is durably persisted.
    expect($session->fresh()->skipped)->toContain('finding_probe_one');

    // Resume/reload: startOrResume self-heal must NOT re-insert the skipped item.
    $this->postJson('/api/v1/optimizer/interview/start', ['tax_year' => 2026])->assertOk();

    $resumed = $session->fresh();
    expect($resumed->queue)->not->toContain('finding_probe_one');

    // next() after resume must never hand back the skipped Q1 (no infinite loop).
    $afterResume = $this->getJson("/api/v1/optimizer/interview/{$session->id}/next");
    expect($afterResume->json('question.ai_best_guess'))->not->toBe('finding_probe_one');
});

it('a skipped key is excluded from the stale-queue self-heal rebuild', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['text' => 'test?']]], 200)]);

    $user = createAuthenticatedUser();

    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'skipped_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
    ]);
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'fresh_probe',
        'finding_type' => 'deduction_probe',
        'band' => 'conditional',
        'status' => 'open',
    ]);

    // Empty queue (the stale-session bug state) + one already-skipped item.
    InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => [],
        'skipped' => ['skipped_probe'],
    ]);

    $service = app(\App\Services\InterviewOrchestratorService::class);
    $resumed = $service->startOrResume($user->id, 2026);

    expect($resumed->queue)->not->toContain('skipped_probe'); // excluded
    expect($resumed->queue)->toContain('fresh_probe');        // still surfaced
});
