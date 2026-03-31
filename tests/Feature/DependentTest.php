<?php

use App\Models\Dependent;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// ── CRUD ──

it('can add a dependent', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/dependents', [
        'name' => 'Emma Smith',
        'date_of_birth' => '2018-05-15',
        'relationship' => 'child',
        'is_student' => false,
        'is_disabled' => false,
        'lives_with_you' => true,
        'months_lived_with_you' => 12,
        'is_claimed' => true,
        'tax_year' => 2025,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('dependent.name', 'Emma Smith');
});

it('can list dependents', function () {
    $user = createAuthenticatedUser();

    Dependent::create([
        'user_id' => $user->id,
        'name' => 'Test Child',
        'date_of_birth' => '2019-03-10',
        'relationship' => 'child',
        'tax_year' => 2025,
    ]);

    $response = $this->getJson('/api/v1/dependents');

    $response->assertOk()
        ->assertJsonCount(1, 'dependents');
});

it('can update a dependent', function () {
    $user = createAuthenticatedUser();

    $dependent = Dependent::create([
        'user_id' => $user->id,
        'name' => 'Test Child',
        'date_of_birth' => '2019-03-10',
        'relationship' => 'child',
        'tax_year' => 2025,
    ]);

    $response = $this->patchJson("/api/v1/dependents/{$dependent->id}", [
        'name' => 'Updated Child',
        'is_student' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('dependent.name', 'Updated Child');
});

it('can delete a dependent', function () {
    $user = createAuthenticatedUser();

    $dependent = Dependent::create([
        'user_id' => $user->id,
        'name' => 'Test Child',
        'date_of_birth' => '2019-03-10',
        'relationship' => 'child',
        'tax_year' => 2025,
    ]);

    $response = $this->deleteJson("/api/v1/dependents/{$dependent->id}");

    $response->assertOk();
    expect(Dependent::find($dependent->id))->toBeNull();
});

// ── Validation ──

it('requires name and date_of_birth', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/dependents', [
        'relationship' => 'child',
        'tax_year' => 2025,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'date_of_birth']);
});

// ── Household Scoping ──

it('household members see each others dependents', function () {
    ['owner' => $owner, 'member' => $member, 'household' => $household] = createUserWithHousehold();

    Dependent::create([
        'user_id' => $owner->id,
        'household_id' => $household->id,
        'name' => 'Owner Child',
        'date_of_birth' => '2020-01-01',
        'relationship' => 'child',
        'tax_year' => 2025,
    ]);

    Sanctum::actingAs($member);

    $response = $this->getJson('/api/v1/dependents');

    $response->assertOk()
        ->assertJsonCount(1, 'dependents');
});

// ── Authorization ──

it('non-household user cannot access another users dependent', function () {
    $user1 = createAuthenticatedUser();

    $dependent = Dependent::create([
        'user_id' => $user1->id,
        'name' => 'User1 Child',
        'date_of_birth' => '2020-01-01',
        'relationship' => 'child',
        'tax_year' => 2025,
    ]);

    $user2 = User::factory()->create();
    Sanctum::actingAs($user2);

    $response = $this->patchJson("/api/v1/dependents/{$dependent->id}", [
        'name' => 'Hacked',
    ]);

    $response->assertForbidden();
});

// ── Child Tax Credit ──

it('calculates child tax credit eligibility', function () {
    $dependent = new Dependent([
        'date_of_birth' => now()->subYears(10)->format('Y-m-d'),
        'relationship' => 'child',
        'lives_with_you' => true,
        'months_lived_with_you' => 12,
        'is_claimed' => true,
        'tax_year' => now()->year,
    ]);

    expect($dependent->qualifiesForChildTaxCredit(now()->year))->toBeTrue();
});

it('child over 17 does not qualify for CTC', function () {
    $dependent = new Dependent([
        'date_of_birth' => now()->subYears(18)->format('Y-m-d'),
        'relationship' => 'child',
        'lives_with_you' => true,
        'months_lived_with_you' => 12,
        'is_claimed' => true,
        'tax_year' => now()->year,
    ]);

    expect($dependent->qualifiesForChildTaxCredit(now()->year))->toBeFalse();
});
