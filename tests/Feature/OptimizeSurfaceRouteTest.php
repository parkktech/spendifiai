<?php

/*
 * OptimizeSurfaceRouteTest — Phase 12-05 Task 1 (UI-01).
 *
 * Verifies the two new Inertia routes are accessible and serve the
 * correct Inertia page components:
 *   GET /optimize     → Optimize/Index
 *   GET /user-profile → UserProfile/Index
 *
 * Also asserts that the existing Breeze /profile route is unaffected
 * (no regression on Pitfall 6 — /user-profile must NOT collide with /profile).
 */

use App\Models\User;

test('authenticated verified user can access /optimize and sees Optimize/Index', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user, 'sanctum')->get('/optimize');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Optimize/Index'));
});

test('authenticated verified user can access /user-profile and sees UserProfile/Index', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user, 'sanctum')->get('/user-profile');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('UserProfile/Index'));
});

test('unauthenticated user is redirected away from /optimize', function () {
    $response = $this->get('/optimize');

    // Redirected to login (not 200)
    $response->assertRedirect();
    $response->assertRedirectContains('login');
});

test('unauthenticated user is redirected away from /user-profile', function () {
    $response = $this->get('/user-profile');

    $response->assertRedirect();
    $response->assertRedirectContains('login');
});

test('Breeze /profile route is unaffected (no regression on Pitfall 6)', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    // /profile is the Breeze profile.edit route (not our new /user-profile)
    $response = $this->actingAs($user)->get('/profile');

    // Should resolve (not 404), confirming the Breeze route still exists
    $response->assertStatus(200);
});
