<?php

use App\Jobs\BuildIncomeOptimizationProfile;
use App\Jobs\ExtractProfileFacts;
use App\Jobs\GenerateOptimizationReport;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationReport;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * OptimizationQueueFixTest
 *
 * RED/GREEN tests for the two root-cause bugs causing /optimize to spin forever:
 *
 * Bug 1 — Dead queue: all three optimization jobs call onQueue('optimization'),
 *          but the production worker only consumes the 'default' queue.
 *          Fix: remove onQueue() from all three job constructors.
 *
 * Bug 2 — First-visit chain gap: OptimizationReportController@show dispatches
 *          GenerateOptimizationReport even when no IncomeOptimizationProfile
 *          exists for the user+year, so the report generator runs with zero
 *          findings. Fix: detect the missing profile and dispatch
 *          BuildIncomeOptimizationProfile (which fires the full pipeline chain:
 *          profile → OptimizationProfileBuilt → findings → narration → report).
 */

// ── Bug 1: Queue name assertions ─────────────────────────────────────────────

test('BuildIncomeOptimizationProfile dispatches to the default queue (not optimization)', function () {
    Queue::fake();

    BuildIncomeOptimizationProfile::dispatch(1, now()->year);

    Queue::assertPushed(BuildIncomeOptimizationProfile::class, function ($job) {
        // After fix: queue property must be null (default queue).
        // Before fix this fails because onQueue('optimization') sets queue='optimization'.
        return is_null($job->queue);
    });
});

test('ExtractProfileFacts dispatches to the default queue (not optimization)', function () {
    Queue::fake();

    ExtractProfileFacts::dispatch(1);

    Queue::assertPushed(ExtractProfileFacts::class, function ($job) {
        return is_null($job->queue);
    });
});

test('GenerateOptimizationReport dispatches to the default queue (not optimization)', function () {
    Queue::fake();

    GenerateOptimizationReport::dispatch(1, now()->year);

    Queue::assertPushed(GenerateOptimizationReport::class, function ($job) {
        return is_null($job->queue);
    });
});

// ── Bug 2: First-visit self-healing ──────────────────────────────────────────

test('show endpoint dispatches BuildIncomeOptimizationProfile on first visit when no profile exists', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $year = now()->year;

    // No IncomeOptimizationProfile exists — first-ever visit.
    $response = $this->actingAs($user)->getJson("/api/v1/optimizer/report/{$year}");

    $response->assertStatus(200);

    // After fix: full pipeline is kicked via BuildIncomeOptimizationProfile.
    // Before fix: only GenerateOptimizationReport is dispatched (chain gap).
    Queue::assertPushed(BuildIncomeOptimizationProfile::class, function ($job) use ($user, $year) {
        return $job->userId === $user->id && $job->taxYear === $year;
    });
    Queue::assertNotPushed(GenerateOptimizationReport::class);
});

test('show endpoint returns generating status shape unchanged when dispatching profile build', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $year = now()->year;

    $response = $this->actingAs($user)->getJson("/api/v1/optimizer/report/{$year}");

    // Response shape must stay identical — no API contract change.
    $response->assertStatus(200);
    $response->assertJsonPath('report.status', 'generating');
    $response->assertJsonPath('report.tax_year', $year);
    $response->assertJsonStructure([
        'report' => [
            'id',
            'tax_year',
            'is_stale',
            'status',
            'sections',
            'executive_summary',
            'rebuilt_at',
            'stale_since',
        ],
    ]);
});

test('show endpoint dispatches GenerateOptimizationReport when profile exists but report is stale', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $year = now()->year;

    // Profile already exists — report staleness only needs report regen, not profile rebuild.
    IncomeOptimizationProfile::create([
        'user_id' => $user->id,
        'tax_year' => $year,
        'built_at' => now()->subDay(),
    ]);

    OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $year,
        'is_stale' => true,
        'sections' => [['section_key' => 'deductions', 'title' => 'Deductions', 'findings' => []]],
    ]);

    $this->actingAs($user)->getJson("/api/v1/optimizer/report/{$year}");

    // Profile exists → stale report → dispatch report job only, not profile rebuild.
    Queue::assertPushed(GenerateOptimizationReport::class);
    Queue::assertNotPushed(BuildIncomeOptimizationProfile::class);
});

test('regenerate endpoint dispatches BuildIncomeOptimizationProfile when no profile exists', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $year = now()->year;

    // No profile — explicit regeneration should also trigger the full pipeline.
    $response = $this->actingAs($user)->postJson("/api/v1/optimizer/report/{$year}/regenerate");

    $response->assertStatus(200);
    $response->assertJsonPath('status', 'generating');

    Queue::assertPushed(BuildIncomeOptimizationProfile::class, function ($job) use ($user, $year) {
        return $job->userId === $user->id && $job->taxYear === $year;
    });
    Queue::assertNotPushed(GenerateOptimizationReport::class);
});

test('regenerate endpoint dispatches GenerateOptimizationReport when profile already exists', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $year = now()->year;

    IncomeOptimizationProfile::create([
        'user_id' => $user->id,
        'tax_year' => $year,
        'built_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/optimizer/report/{$year}/regenerate");

    $response->assertStatus(200);
    Queue::assertPushed(GenerateOptimizationReport::class);
    Queue::assertNotPushed(BuildIncomeOptimizationProfile::class);
});
