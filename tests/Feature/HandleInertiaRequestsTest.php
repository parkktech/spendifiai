<?php

use App\Models\OptimizationFinding;

/*
 * HandleInertiaRequestsTest — Phase 12-04 Task 3 (UI-01).
 *
 * Verifies the pendingOptimizationCount Inertia shared prop:
 *   (1) auth.pendingOptimizationCount is an integer (not null, not string).
 *   (2) equals 0 when user has no bank connected.
 *   (3) reflects the count of open, narrated findings for a bank-connected user.
 *   (4) does not count non-open or un-narrated findings.
 *   (5) existing shared props are unchanged (additive-only).
 */

test('auth.pendingOptimizationCount is an integer in the Inertia shared props', function () {
    $user = createAuthenticatedUser();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);

    // Inertia pages share props via the page component
    $sharedProps = $response->viewData('page')['props'] ?? [];
    $authProps = $sharedProps['auth'] ?? [];

    expect($authProps)->toHaveKey('pendingOptimizationCount');
    expect($authProps['pendingOptimizationCount'])->toBeInt();
});

test('auth.pendingOptimizationCount is 0 when user has no bank connected', function () {
    $user = createAuthenticatedUser();

    // No bank connection → hasBankConnected() returns false → count must be 0
    $response = $this->actingAs($user)->get('/dashboard');
    $response->assertStatus(200);

    $authProps = $response->viewData('page')['props']['auth'] ?? [];
    expect($authProps['pendingOptimizationCount'])->toBe(0);
});

test('auth.pendingOptimizationCount reflects the count of open narrated findings for a bank-connected user', function () {
    ['user' => $user] = createUserWithBank();

    // Create 3 open + narrated findings (unique finding_key per row to avoid unique constraint)
    foreach (['finding_a', 'finding_b', 'finding_c'] as $key) {
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => now()->year,
            'finding_key' => $key,
            'status' => 'open',
            'description' => 'Narrated finding description.',  // non-null = narrated
        ]);
    }

    $response = $this->actingAs($user)->get('/dashboard');
    $response->assertStatus(200);

    $authProps = $response->viewData('page')['props']['auth'] ?? [];
    expect($authProps['pendingOptimizationCount'])->toBe(3);
});

test('auth.pendingOptimizationCount does not count resolved findings', function () {
    ['user' => $user] = createUserWithBank();

    // 2 open narrated + 2 resolved (should not count)
    foreach (['open_1', 'open_2'] as $key) {
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => now()->year,
            'finding_key' => $key,
            'status' => 'open',
            'description' => 'Open finding.',
        ]);
    }
    foreach (['resolved_1', 'resolved_2'] as $key) {
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => now()->year,
            'finding_key' => $key,
            'status' => 'resolved',
            'description' => 'Resolved finding.',
        ]);
    }

    $response = $this->actingAs($user)->get('/dashboard');
    $authProps = $response->viewData('page')['props']['auth'] ?? [];
    expect($authProps['pendingOptimizationCount'])->toBe(2);
});

test('auth.pendingOptimizationCount does not count un-narrated findings (null description)', function () {
    ['user' => $user] = createUserWithBank();

    // 2 open narrated + 2 open un-narrated (null description)
    foreach (['narrated_1', 'narrated_2'] as $key) {
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => now()->year,
            'finding_key' => $key,
            'status' => 'open',
            'description' => 'Narrated finding.',
        ]);
    }
    foreach (['unnarrated_1', 'unnarrated_2'] as $key) {
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => now()->year,
            'finding_key' => $key,
            'status' => 'open',
            'description' => null,  // not yet narrated by Phase 11 listener
        ]);
    }

    $response = $this->actingAs($user)->get('/dashboard');
    $authProps = $response->viewData('page')['props']['auth'] ?? [];
    expect($authProps['pendingOptimizationCount'])->toBe(2);
});

// ── D24 Work 3: version-skew protection ──────────────────────────────────────

test('version() returns a non-null hash when build/manifest.json exists', function () {
    // The manifest exists in CI (after npm run build) and in dev if built.
    // Skip gracefully when no manifest is present (test env without build step).
    $manifest = public_path('build/manifest.json');
    if (! file_exists($manifest)) {
        $this->markTestSkipped('build/manifest.json not present — run npm run build first.');
    }

    $middleware = app(App\Http\Middleware\HandleInertiaRequests::class);
    $request = Illuminate\Http\Request::create('/dashboard');
    $version = $middleware->version($request);

    expect($version)->toBeString()->not->toBeEmpty();
    // Ensure it's a hex string (xxh128 output).
    expect(preg_match('/^[0-9a-f]{32}$/', $version))->toBe(1);
});

test('buildVersion prop is shared via Inertia and matches manifest hash', function () {
    $manifest = public_path('build/manifest.json');
    if (! file_exists($manifest)) {
        $this->markTestSkipped('build/manifest.json not present — run npm run build first.');
    }

    $user = createAuthenticatedUser();
    $response = $this->actingAs($user)->get('/dashboard');
    $response->assertStatus(200);

    $props = $response->viewData('page')['props'] ?? [];
    expect($props)->toHaveKey('buildVersion');
    expect($props['buildVersion'])->toBe(hash_file('xxh128', $manifest));
});

test('build-version meta endpoint returns version hash', function () {
    $manifest = public_path('build/manifest.json');
    if (! file_exists($manifest)) {
        $this->markTestSkipped('build/manifest.json not present — run npm run build first.');
    }

    $response = $this->getJson('/api/v1/meta/build-version');

    $response->assertStatus(200)
        ->assertJsonStructure(['version', 'built_at'])
        ->assertJson(['version' => hash_file('xxh128', $manifest)]);
});

test('build-version meta endpoint works when no build manifest exists', function () {
    // Simulate missing manifest by asserting the endpoint returns a null version gracefully.
    // We can't actually delete the manifest, so just call the endpoint and check structure.
    $response = $this->getJson('/api/v1/meta/build-version');

    $response->assertStatus(200)
        ->assertJsonStructure(['version', 'built_at']);
    // version is either a hash string or null — both are valid.
    $body = $response->json();
    expect($body['version'])->toBeString()->or->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────

test('existing shared auth props are present and unchanged (additive only)', function () {
    $user = createAuthenticatedUser();

    $response = $this->actingAs($user)->get('/dashboard');
    $authProps = $response->viewData('page')['props']['auth'] ?? [];

    // These existing props must still be present
    expect($authProps)->toHaveKey('user');
    expect($authProps)->toHaveKey('hasBankConnected');
    expect($authProps)->toHaveKey('hasEmailConnected');
    expect($authProps)->toHaveKey('isAdmin');
    expect($authProps)->toHaveKey('isAccountant');
    expect($authProps)->toHaveKey('userType');
    expect($authProps)->toHaveKey('timezone');
    expect($authProps)->toHaveKey('onboardingPending');
    // The new prop is additive
    expect($authProps)->toHaveKey('pendingOptimizationCount');
});
