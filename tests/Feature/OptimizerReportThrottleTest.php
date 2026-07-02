<?php

use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationReport;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
 * OptimizerReportThrottleTest — Task 3 (12-08).
 *
 * Verifies the throttle split on the optimizer/report route group:
 *   GET  /api/v1/optimizer/report/{year}          → throttle:60,1  (was 5,1)
 *   POST /api/v1/optimizer/report/{year}/regenerate → throttle:5,1  (unchanged)
 *   GET  /api/v1/optimizer/report/{year}/download   → throttle:5,1  (unchanged)
 *   POST /api/v1/optimizer/report/finding/{id}/pro-review-export → throttle:5,1
 *
 * The test verifies that GET show does NOT 429 after 5 rapid requests
 * (which it would if still on the 5/min throttle).
 */

beforeEach(function () {
    Queue::fake();
    // Clear all rate limiter state so tests are isolated
    RateLimiter::clear('api');
});

test('GET report show does not 429 on 6 rapid requests (throttle:60,1)', function () {
    $user = createAuthenticatedUser();
    $year = now()->year;

    // Make 6 rapid GET requests — should all pass with throttle:60,1
    // (would fail on the 6th with the old throttle:5,1)
    for ($i = 0; $i < 6; $i++) {
        $response = $this->actingAs($user)->getJson("/api/v1/optimizer/report/{$year}");
        // Must never be 429 — if throttle is still 5/min this would fail on iteration 6
        $response->assertStatus(200);
    }
});

test('POST regenerate still uses tight throttle:5,1 (dispatching endpoint)', function () {
    $user = createAuthenticatedUser();
    $year = now()->year;

    // Exhaust the regenerate throttle (5 requests)
    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)->postJson("/api/v1/optimizer/report/{$year}/regenerate");
    }

    // 6th regenerate request must be rate-limited (429)
    $response = $this->actingAs($user)->postJson("/api/v1/optimizer/report/{$year}/regenerate");
    $response->assertStatus(429);
});

test('GET show throttle is independent from POST regenerate throttle', function () {
    $user = createAuthenticatedUser();
    $year = now()->year;

    // Create a ready report so GET returns 200
    OptimizationReport::create([
        'user_id' => $user->id,
        'tax_year' => $year,
        'is_stale' => false,
        'sections' => [['section_key' => 'test', 'title' => 'Test', 'findings' => []]],
        'rebuilt_at' => now(),
    ]);

    // Exhaust the POST regenerate throttle
    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)->postJson("/api/v1/optimizer/report/{$year}/regenerate");
    }

    // POST regenerate should now be throttled
    $regenResponse = $this->actingAs($user)->postJson("/api/v1/optimizer/report/{$year}/regenerate");
    $regenResponse->assertStatus(429);

    // But GET show must still respond 200 — throttles are independent
    $showResponse = $this->actingAs($user)->getJson("/api/v1/optimizer/report/{$year}");
    $showResponse->assertStatus(200);
});
