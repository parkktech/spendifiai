<?php

use App\Models\OptimizationFinding;
use App\Models\User;
use App\Services\NarrationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('skips the Claude call gracefully (no HTTP, null return) when the daily budget cap is reached', function () {
    config()->set('services.anthropic.daily_budget_narration', 3);
    Http::fake();

    // Seed the day-counter AT the cap.
    $date = now()->toDateString();
    Cache::put("claude_calls_narration_{$date}", 3);

    $user = User::factory()->create();
    // Bespoke finding_type so it reaches the budget guard (templates short-circuit earlier).
    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'finding_type' => 'bespoke_capped_finding',
        'description' => null,
    ]);

    $service = new NarrationService;
    $result = $service->narrateFinding($finding);

    expect($result)->toBeNull();
    Http::assertNothingSent();

    // Counter is unchanged (guard returned before increment).
    expect((int) Cache::get("claude_calls_narration_{$date}"))->toBe(3);
});

it('increments the day-counter and proceeds when below the daily budget cap', function () {
    config()->set('services.anthropic.daily_budget_narration', 200);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'An educational note you may wish to review.']],
        ]),
    ]);

    $date = now()->toDateString();
    expect((int) Cache::get("claude_calls_narration_{$date}", 0))->toBe(0);

    $user = User::factory()->create();
    $finding = OptimizationFinding::factory()->create([
        'user_id' => $user->id,
        'finding_type' => 'bespoke_uncapped_finding',
        'description' => null,
    ]);

    $service = new NarrationService;
    $result = $service->narrateFinding($finding);

    expect($result)->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));

    // Counter incremented exactly once.
    expect((int) Cache::get("claude_calls_narration_{$date}"))->toBe(1);
});

it('treats a null/absent categorization budget as uncapped (throughput unchanged)', function () {
    config()->set('services.anthropic.daily_budget_categorization', null);

    $date = now()->toDateString();
    // Even with a very high existing counter, an uncapped purpose never blocks.
    Cache::put("claude_calls_categorization_{$date}", 999999);

    $service = new NarrationService;
    $method = new ReflectionMethod($service, 'checkAndIncrementBudget');
    $method->setAccessible(true);

    expect($method->invoke($service, 'categorization'))->toBeTrue();
    // And it still increments the counter for the admin surface.
    expect((int) Cache::get("claude_calls_categorization_{$date}"))->toBe(1000000);
});
