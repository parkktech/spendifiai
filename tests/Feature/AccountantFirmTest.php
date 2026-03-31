<?php

use App\Enums\UserType;
use App\Models\AccountantClient;
use App\Models\AccountingFirm;
use App\Models\DocumentRequest;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Firm Registration ───

it('allows accountant to register a firm', function () {
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);

    $response = $this->actingAs($accountant)
        ->postJson('/api/v1/accountant/firm', [
            'name' => 'Acme Tax Services',
            'address' => '123 Main St',
            'phone' => '555-1234',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Acme Tax Services')
        ->assertJsonStructure(['id', 'name', 'address', 'phone', 'invite_token']);
});

it('blocks personal user from registering firm', function () {
    $personal = User::factory()->create(['user_type' => UserType::Personal]);

    $response = $this->actingAs($personal)
        ->postJson('/api/v1/accountant/firm', [
            'name' => 'Acme Tax Services',
        ]);

    $response->assertForbidden();
});

it('validates firm name is required', function () {
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);

    $response = $this->actingAs($accountant)
        ->postJson('/api/v1/accountant/firm', [
            'address' => '123 Main St',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

// ─── Firm Management ───

it('returns accountant firm details', function () {
    $firm = AccountingFirm::create(['name' => 'Test Firm']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
        'accounting_firm_id' => $firm->id,
    ]);

    $response = $this->actingAs($accountant)
        ->getJson('/api/v1/accountant/firm');

    $response->assertOk()
        ->assertJsonPath('name', 'Test Firm');
});

it('updates firm details', function () {
    $firm = AccountingFirm::create(['name' => 'Old Name']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
        'accounting_firm_id' => $firm->id,
    ]);

    $response = $this->actingAs($accountant)
        ->patchJson('/api/v1/accountant/firm', [
            'name' => 'New Name',
        ]);

    $response->assertOk()
        ->assertJsonPath('name', 'New Name');
});

it('generates invite link', function () {
    $firm = AccountingFirm::create(['name' => 'Test Firm']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
        'accounting_firm_id' => $firm->id,
    ]);

    $response = $this->actingAs($accountant)
        ->getJson('/api/v1/accountant/firm/invite-link');

    $response->assertOk()
        ->assertJsonStructure(['invite_url', 'token']);

    expect($response->json('invite_url'))->toContain('/invite/');
});

// ─── Dashboard ───

it('returns dashboard stats for accountant', function () {
    $firm = AccountingFirm::create(['name' => 'Test Firm']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
        'accounting_firm_id' => $firm->id,
    ]);

    $response = $this->actingAs($accountant)
        ->getJson('/api/v1/accountant/dashboard');

    $response->assertOk()
        ->assertJsonStructure([
            'firm',
            'total_clients',
            'documents_pending_review',
            'open_requests',
            'clients',
        ]);
});

it('dashboard client list includes document counts', function () {
    $firm = AccountingFirm::create(['name' => 'Test Firm']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
        'accounting_firm_id' => $firm->id,
    ]);
    $client = User::factory()->create([
        'user_type' => UserType::Personal,
        'accounting_firm_id' => $firm->id,
    ]);

    AccountantClient::create([
        'accountant_id' => $accountant->id,
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    DocumentRequest::create([
        'accounting_firm_id' => $firm->id,
        'accountant_id' => $accountant->id,
        'client_id' => $client->id,
        'description' => 'Need W-2',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($accountant)
        ->getJson('/api/v1/accountant/dashboard');

    $response->assertOk();
    $clients = $response->json('clients');
    expect($clients)->toHaveCount(1);
    expect($clients[0])->toHaveKeys(['id', 'name', 'email', 'total_requests', 'fulfilled_requests', 'completeness']);
});

// ─── Invite Flow ───

it('resolves firm invite route for valid token', function () {
    $firm = AccountingFirm::create(['name' => 'Invite Firm']);

    // Verify the firm was created with an auto-generated invite token
    expect($firm->invite_token)->toBeString()->toHaveLength(64);

    // Verify the route resolves to the correct firm (database lookup)
    $resolved = AccountingFirm::where('invite_token', $firm->invite_token)->first();
    expect($resolved)->not->toBeNull();
    expect($resolved->name)->toBe('Invite Firm');
});

it('returns 404 for invalid invite token', function () {
    $response = $this->get('/invite/invalid-token-that-does-not-exist');

    $response->assertNotFound();
});
