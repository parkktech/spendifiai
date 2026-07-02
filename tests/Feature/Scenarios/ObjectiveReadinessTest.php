<?php

use App\Models\IncomeOptimizationProfile;
use App\Models\InterviewSession;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\ObjectiveReadinessService;

/*
 * ObjectiveReadinessTest — SCN-01 / M5: the single readiness source.
 *
 * Covers §A.8.1 (two-tier readiness DTO), §A.4.3 (prerequisite→not_applicable),
 * §A.2 (conditional-blocking B5/B6 + R6/R7), M12 (T4 suppression), and §A.4.1
 * (enqueueGaps front-insert / dedupe / battery-last / initial_cap-not-applied /
 * idempotent).
 */

function readinessService(): ObjectiveReadinessService
{
    return app(ObjectiveReadinessService::class);
}

/** @return string[] fact keys in a readiness sub-array (blocking_missing / confirm_needed) */
function keysOf(array $list): array
{
    return array_map(fn ($e) => $e['fact_key'], $list);
}

// ─── Two-tier readiness ───────────────────────────────────────────────────────

it('marks unresolved blocking facts as blocking_missing and objective not ready', function () {
    $user = User::factory()->create();

    $r = readinessService()->readiness($user, 2026);

    expect($r)->toHaveKeys(['take_home', 'tax_burden', 'retirement']);
    expect($r['take_home']['ready'])->toBeFalse();
    expect(keysOf($r['take_home']['blocking_missing']))->toContain('profile.filing_status');
    expect($r['take_home']['questions_to_unlock'])->toBeGreaterThan(0);
});

it('does not iterate the bonus_election scenario domain', function () {
    $user = User::factory()->create();

    $r = readinessService()->readiness($user, 2026);

    expect($r)->not->toHaveKey('bonus_election');
});

it('a confirmed fact leaves blocking_missing and is not in confirm_needed', function () {
    $user = User::factory()->create();

    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'interview_answer',
    );

    $r = readinessService()->readiness($user, 2026);

    expect(keysOf($r['take_home']['blocking_missing']))->not->toContain('profile.filing_status');
    expect(keysOf($r['take_home']['confirm_needed']))->not->toContain('profile.filing_status');
});

it('a known-but-unconfirmed blocking fact lands in confirm_needed, not blocking_missing', function () {
    $user = User::factory()->create();

    // Snapshot value → resolves as `known` (confirmed=false)
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'filing_status' => 'single',
    ]);

    $r = readinessService()->readiness($user, 2026);

    expect(keysOf($r['take_home']['blocking_missing']))->not->toContain('profile.filing_status');
    expect(keysOf($r['take_home']['confirm_needed']))->toContain('profile.filing_status');
});

// ─── Prerequisite → not_applicable (§A.4.3) ───────────────────────────────────

it('prerequisite answered no flips dependent conditional-blocking facts to not_applicable', function () {
    $user = User::factory()->create();

    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.has_401k',
        value: 'no',
        sourceType: 'interview_answer',
    );

    $r = readinessService()->readiness($user, 2026);

    // match_pct / contribution_pct are conditional on has_401k=yes → not blocking now
    expect(keysOf($r['retirement']['blocking_missing']))->not->toContain('employer.match_pct');
    expect(keysOf($r['retirement']['blocking_missing']))->not->toContain('employer.contribution_pct');
    // has_401k itself is resolved
    expect(keysOf($r['retirement']['blocking_missing']))->not->toContain('employer.has_401k');
});

it('conditional-blocking fact blocks once its condition holds', function () {
    $user = User::factory()->create();

    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'interview_answer',
    );

    $r = readinessService()->readiness($user, 2026);

    // has_401k=yes → match_pct condition holds → it is now blocking & missing
    expect(keysOf($r['retirement']['blocking_missing']))->toContain('employer.match_pct');
});

// ─── T4 suppression (M12) ─────────────────────────────────────────────────────

it('T4 w4-on-file facts are optional-with-suppression, never blocking_missing', function () {
    $user = User::factory()->create();

    $r = readinessService()->readiness($user, 2026);

    expect(keysOf($r['take_home']['blocking_missing']))->not->toContain('w4.dependents_claimed');
    expect(keysOf($r['take_home']['blocking_missing']))->not->toContain('w4.filing_status');
});

// ─── completeness_pct ─────────────────────────────────────────────────────────

it('completeness_pct is 0..100 and rises as blocking facts resolve', function () {
    $user = User::factory()->create();

    $before = readinessService()->readiness($user, 2026)['take_home']['completeness_pct'];

    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'interview_answer',
    );

    $after = readinessService()->readiness($user, 2026)['take_home']['completeness_pct'];

    expect($before)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
    expect($after)->toBeGreaterThan($before);
});

// ─── enqueueGaps (§A.4.1) ─────────────────────────────────────────────────────

it('enqueueGaps front-inserts gap keys before existing queue keys', function () {
    $user = User::factory()->create();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['existing_finding_key'],
        'asked' => [],
    ]);

    $result = readinessService()->enqueueGaps($user, 2026, 'take_home');

    $queue = $session->fresh()->queue;
    expect($result['enqueued'])->not->toBeEmpty();
    // every gap key precedes the pre-existing finding key
    $existingPos = array_search('existing_finding_key', $queue, true);
    foreach ($result['enqueued'] as $key) {
        expect(array_search($key, $queue, true))->toBeLessThan($existingPos);
    }
});

it('enqueueGaps keeps a battery question last', function () {
    $user = User::factory()->create();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => ['some_finding', 'battery_marriage_check'],
        'asked' => [],
    ]);

    readinessService()->enqueueGaps($user, 2026, 'take_home');

    $queue = $session->fresh()->queue;
    expect(end($queue))->toBe('battery_marriage_check');
});

it('enqueueGaps ignores initial_cap and enqueues all templated blocking gaps', function () {
    $user = User::factory()->create();

    InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => [],
        'initial_cap' => 3,
    ]);

    $result = readinessService()->enqueueGaps($user, 2026, 'take_home');

    // more than the initial_cap of 3 templated blocking gaps must be enqueued
    expect(count($result['enqueued']))->toBeGreaterThan(3);
});

it('enqueueGaps is idempotent on a double call', function () {
    $user = User::factory()->create();

    InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => [],
    ]);

    $first = readinessService()->enqueueGaps($user, 2026, 'take_home');
    $second = readinessService()->enqueueGaps($user, 2026, 'take_home');

    expect($first['enqueued'])->not->toBeEmpty();
    expect($second['enqueued'])->toBeEmpty();
    expect($second['queue_size'])->toBe($first['queue_size']);
});

it('enqueueGaps dedupes against already-asked keys', function () {
    $user = User::factory()->create();

    $session = InterviewSession::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'status' => 'in_progress',
        'queue' => [],
        'asked' => ['profile.filing_status'],
    ]);

    $result = readinessService()->enqueueGaps($user, 2026, 'take_home');

    expect($result['enqueued'])->not->toContain('profile.filing_status');
    expect($session->fresh()->queue)->not->toContain('profile.filing_status');
});
