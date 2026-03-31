<?php

use App\Enums\ConnectionStatus;
use App\Models\BankConnection;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// ── Create Household ──

it('can create a household', function () {
    $user = createAuthenticatedUser();

    $response = $this->postJson('/api/v1/household');

    $response->assertStatus(201)
        ->assertJsonPath('household.name', 'My Household');

    $user->refresh();
    expect($user->household_id)->not->toBeNull();
    expect($user->household_role)->toBe('owner');
});

it('cannot create a household if already in one', function () {
    ['owner' => $owner] = createUserWithHousehold();

    $response = $this->postJson('/api/v1/household');

    $response->assertUnprocessable();
});

// ── Show Household ──

it('can view household info', function () {
    ['owner' => $owner, 'member' => $member, 'household' => $household] = createUserWithHousehold();

    $response = $this->getJson('/api/v1/household');

    $response->assertOk()
        ->assertJsonPath('household.name', 'Test Household')
        ->assertJsonPath('household.member_count', 2);
});

it('returns null household for solo user', function () {
    createAuthenticatedUser();

    $response = $this->getJson('/api/v1/household');

    $response->assertOk()
        ->assertJsonPath('household', null);
});

// ── Generate Invite ──

it('can generate an invite link', function () {
    ['owner' => $owner] = createUserWithHousehold();

    $response = $this->postJson('/api/v1/household/invite');

    $response->assertStatus(201)
        ->assertJsonStructure(['invite_url', 'expires_at']);
});

it('can generate an invite with email', function () {
    ['owner' => $owner] = createUserWithHousehold();

    $response = $this->postJson('/api/v1/household/invite', [
        'email' => 'spouse@example.com',
    ]);

    $response->assertStatus(201);
    expect(HouseholdInvitation::where('email', 'spouse@example.com')->exists())->toBeTrue();
});

// ── Accept Invite ──

it('can accept a valid invitation', function () {
    ['owner' => $owner, 'household' => $household] = createUserWithHousehold();

    $invitation = HouseholdInvitation::create([
        'household_id' => $household->id,
        'invited_by_user_id' => $owner->id,
        'token' => bin2hex(random_bytes(32)),
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    $newUser = User::factory()->create();
    Sanctum::actingAs($newUser);

    $response = $this->postJson("/api/v1/household/invite/{$invitation->token}/accept");

    $response->assertOk();
    $newUser->refresh();
    expect($newUser->household_id)->toBe($household->id);
});

it('cannot accept an expired invitation', function () {
    ['owner' => $owner, 'household' => $household] = createUserWithHousehold();

    $invitation = HouseholdInvitation::create([
        'household_id' => $household->id,
        'invited_by_user_id' => $owner->id,
        'token' => bin2hex(random_bytes(32)),
        'status' => 'pending',
        'expires_at' => now()->subDay(),
    ]);

    $newUser = User::factory()->create();
    Sanctum::actingAs($newUser);

    $response = $this->postJson("/api/v1/household/invite/{$invitation->token}/accept");

    $response->assertUnprocessable()
        ->assertJsonFragment(['message' => 'This invitation has expired.']);
});

// ── Validate Invite (public) ──

it('can validate a pending invitation without auth', function () {
    ['owner' => $owner, 'household' => $household] = createUserWithHousehold();

    $invitation = HouseholdInvitation::create([
        'household_id' => $household->id,
        'invited_by_user_id' => $owner->id,
        'token' => bin2hex(random_bytes(32)),
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    // No auth — public route
    $response = $this->getJson("/api/v1/household/invite/{$invitation->token}");

    $response->assertOk()
        ->assertJsonStructure(['household_name', 'invited_by', 'expires_at']);
});

// ── Remove Member ──

it('owner can remove a member', function () {
    ['owner' => $owner, 'member' => $member] = createUserWithHousehold();

    $response = $this->deleteJson("/api/v1/household/members/{$member->id}");

    $response->assertOk();
    $member->refresh();
    expect($member->household_id)->toBeNull();
});

it('member cannot remove another member', function () {
    ['member' => $member, 'owner' => $owner] = createUserWithHousehold();
    Sanctum::actingAs($member);

    $response = $this->deleteJson("/api/v1/household/members/{$owner->id}");

    $response->assertUnprocessable();
});

// ── Leave Household ──

it('member can leave household', function () {
    ['member' => $member, 'household' => $household] = createUserWithHousehold();
    Sanctum::actingAs($member);

    $response = $this->postJson('/api/v1/household/leave');

    $response->assertOk();
    $member->refresh();
    expect($member->household_id)->toBeNull();
});

// ── Household-Scoped Data ──

it('household member sees other member transactions', function () {
    ['owner' => $owner, 'member' => $member] = createUserWithHousehold();

    $connection = BankConnection::factory()->create([
        'user_id' => $owner->id,
        'status' => ConnectionStatus::Active,
        'last_synced_at' => now(),
    ]);
    $account = \App\Models\BankAccount::factory()->create([
        'user_id' => $owner->id,
        'bank_connection_id' => $connection->id,
    ]);

    Transaction::factory()->count(3)->create([
        'user_id' => $owner->id,
        'bank_account_id' => $account->id,
    ]);

    // Member should see owner's transactions
    Sanctum::actingAs($member);
    $response = $this->getJson('/api/v1/transactions');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('hasBankConnected returns true for household member', function () {
    ['owner' => $owner, 'member' => $member] = createUserWithHousehold();

    BankConnection::factory()->create([
        'user_id' => $owner->id,
        'status' => ConnectionStatus::Active,
    ]);

    expect($member->hasBankConnected())->toBeTrue();
});

it('solo user has no household scoping', function () {
    $user = createAuthenticatedUser();

    expect($user->householdUserIds())->toBe([$user->id]);
    expect($user->household_id)->toBeNull();
});
