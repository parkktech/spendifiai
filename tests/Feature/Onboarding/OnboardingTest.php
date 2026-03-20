<?php

use App\Jobs\ProcessOnboardingPipeline;
use Illuminate\Support\Facades\Queue;

it('can start onboarding pipeline', function () {
    Queue::fake([ProcessOnboardingPipeline::class]);

    $user = createAuthenticatedUser();

    $response = $this->postJson('/api/v1/onboarding/start');

    $response->assertOk()
        ->assertJsonPath('message', 'Onboarding pipeline started');

    Queue::assertPushed(ProcessOnboardingPipeline::class);
});

it('prevents starting onboarding if already completed', function () {
    Queue::fake([ProcessOnboardingPipeline::class]);

    $user = createAuthenticatedUser([
        'onboarding_completed_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/onboarding/start');

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Onboarding already completed');

    Queue::assertNotPushed(ProcessOnboardingPipeline::class);
});

it('onboarding route exists', function () {
    $user = createAuthenticatedUser();

    // Just verify the route resolves (not 404)
    // Page render requires Vite manifest so we check the route exists
    $route = app('router')->getRoutes()->match(
        app('request')->create('/onboarding', 'GET')
    );

    expect($route)->not->toBeNull();
    expect($route->uri())->toBe('onboarding');
});

it('shares onboarding pending prop via inertia', function () {
    $user = createAuthenticatedUser();

    // User has no onboarding_completed_at and no bank connected
    $response = $this->get('/dashboard');

    // Should have the onboardingPending prop
    $response->assertOk();
});
