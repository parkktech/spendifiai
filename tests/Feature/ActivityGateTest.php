<?php

use App\Events\OptimizationProfileBuilt;
use App\Jobs\GenerateOptimizationReport;
use App\Listeners\NarrateOptimizationFindings;
use App\Models\OptimizationFinding;
use App\Models\User;
use App\Services\NarrationService;
use App\Services\OptimizationReportGeneratorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

// ── D17 activity gate: inactive user → zero AI dispatch ────────────────────

it('GenerateOptimizationReport skips inactive users (>28d) with zero AI HTTP', function () {
    Http::fake();

    $user = User::factory()->create(['last_active_at' => now()->subDays(40)]);

    $job = new GenerateOptimizationReport($user->id, 2026);
    $job->handle(app(OptimizationReportGeneratorService::class));

    Http::assertNothingSent();
});

it('NarrateOptimizationFindings skips inactive users (>28d) with zero AI HTTP', function () {
    Http::fake();

    $user = User::factory()->create(['last_active_at' => now()->subDays(40)]);
    // A bespoke null-description finding would trigger a Claude call if NOT gated.
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_type' => 'bespoke_gate_finding',
        'description' => null,
    ]);

    $listener = new NarrateOptimizationFindings(app(NarrationService::class));
    $listener->handle(new OptimizationProfileBuilt($user->id, 2026, 1));

    Http::assertNothingSent();
});

it('NarrateOptimizationFindings does NOT gate a recently-active user', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'An educational note you may wish to review.']],
        ]),
    ]);

    $user = User::factory()->create(['last_active_at' => now()]);
    OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_type' => 'bespoke_active_finding',
        'description' => null,
    ]);

    $listener = new NarrateOptimizationFindings(app(NarrationService::class));
    $listener->handle(new OptimizationProfileBuilt($user->id, 2026, 1));

    // Active user passed the gate and reached the (Haiku) narration call.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
});

// ── D17 admin spend surface ────────────────────────────────────────────────

it('GET /api/admin/ai-usage returns per-purpose 7-day counters for an admin', function () {
    $date = now()->toDateString();
    Cache::put("claude_calls_narration_{$date}", 5);
    Cache::put("claude_calls_categorization_{$date}", 42);

    createAuthenticatedUser(['is_admin' => true]);

    $response = $this->getJson('/api/admin/ai-usage');

    $response->assertOk()
        ->assertJsonPath('window_days', 7)
        ->assertJsonPath('usage.narration.purpose', 'narration')
        ->assertJsonPath('usage.categorization.purpose', 'categorization');

    $data = $response->json('usage');
    expect($data)->toHaveKeys(['narration', 'wording', 'extraction', 'categorization']);
    expect($data['narration']['days'])->toHaveCount(7);

    // Today's counter is reflected (last entry of the window is today).
    $narrationDays = collect($data['narration']['days']);
    expect($narrationDays->last()['date'])->toBe($date)
        ->and($narrationDays->last()['count'])->toBe(5);
    expect(collect($data['categorization']['days'])->last()['count'])->toBe(42);
});

it('GET /api/admin/ai-usage returns 403 for a non-admin user', function () {
    createAuthenticatedUser(['is_admin' => false]);

    $this->getJson('/api/admin/ai-usage')->assertForbidden();
});
