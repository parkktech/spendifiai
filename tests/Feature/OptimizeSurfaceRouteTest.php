<?php

/*
 * OptimizeSurfaceRouteTest — Phase 12-05 Task 1 (UI-01), updated Phase 12-06.
 *
 * Verifies the Inertia routes are accessible and serve the correct behavior:
 *   GET /optimize     → Optimize/Index (200)
 *   GET /user-profile → redirects to /settings (302) — Decision 5-SUPERSEDED 2026-07-02
 *                       (Settings and My Profile merged into one page; /user-profile
 *                        stays alive as a permanent redirect so shipped URLs still work)
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

test('authenticated verified user hitting /user-profile is redirected to /settings (Decision 5-SUPERSEDED)', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user, 'sanctum')->get('/user-profile');

    // /user-profile now redirects to /settings — the route is preserved for URL
    // backwards-compatibility but Settings is the canonical merged page per owner
    // Decision 5-SUPERSEDED (2026-07-02).
    $response->assertRedirect('/settings');
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
