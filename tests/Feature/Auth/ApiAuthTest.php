<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['spendifiai.captcha.enabled' => false]);
});

it('can register via API', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['token', 'user']);

    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

it('can login via API', function () {
    User::factory()->create([
        'email' => 'login@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'login@example.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user']);
});

it('login fails with wrong password', function () {
    User::factory()->create([
        'email' => 'wrong@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'wrong@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
});

it('can logout via API', function () {
    $user = createAuthenticatedUser();

    $this->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out.');

    // Verify the token was deleted from the database
    expect($user->tokens()->count())->toBe(0);
});

it('can get current user', function () {
    $user = createAuthenticatedUser(['email' => 'me@example.com']);

    $response = $this->getJson('/api/auth/me');

    $response->assertOk()
        ->assertJsonPath('user.email', 'me@example.com');
});

it('login prompts for 2FA when enabled', function () {
    User::factory()->withTwoFactor()->create([
        'email' => '2fa@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => '2fa@example.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonPath('two_factor_required', true);
});

it('unauthenticated request returns 401', function () {
    $this->getJson('/api/auth/me')
        ->assertUnauthorized();
});
