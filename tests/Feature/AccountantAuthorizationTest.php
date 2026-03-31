<?php

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Enums\UserType;
use App\Models\AccountantClient;
use App\Models\AccountingFirm;
use App\Models\DocumentRequest;
use App\Models\TaxDocument;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createDocumentForUser(User $user): TaxDocument
{
    return TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'test-w2.pdf',
        'stored_path' => 'tax-vault/test/test-w2.pdf',
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', 'test-content'),
        'tax_year' => 2025,
        'category' => TaxDocumentCategory::W2,
        'status' => DocumentStatus::Ready,
    ]);
}

// ─── Document Access ───

it('allows owner to view own document', function () {
    $owner = User::factory()->create(['user_type' => UserType::Personal]);
    $document = createDocumentForUser($owner);

    $response = $this->actingAs($owner)
        ->getJson("/api/v1/tax-vault/documents/{$document->id}");

    $response->assertOk();
});

it('allows linked accountant to view client document', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);
    $document = createDocumentForUser($client);

    AccountantClient::create([
        'accountant_id' => $accountant->id,
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($accountant)
        ->getJson("/api/v1/tax-vault/documents/{$document->id}");

    $response->assertOk();
});

it('blocks unlinked accountant from viewing document', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);
    $document = createDocumentForUser($client);

    $response = $this->actingAs($accountant)
        ->getJson("/api/v1/tax-vault/documents/{$document->id}");

    $response->assertForbidden();
});

it('blocks pending-status accountant from viewing document', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);
    $document = createDocumentForUser($client);

    AccountantClient::create([
        'accountant_id' => $accountant->id,
        'client_id' => $client->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($accountant)
        ->getJson("/api/v1/tax-vault/documents/{$document->id}");

    $response->assertForbidden();
});

// ─── Annotation Access ───

it('allows owner to list annotations on own document', function () {
    $owner = User::factory()->create(['user_type' => UserType::Personal]);
    $document = createDocumentForUser($owner);

    $response = $this->actingAs($owner)
        ->getJson("/api/v1/tax-vault/documents/{$document->id}/annotations");

    $response->assertOk();
});

it('allows linked accountant to list annotations', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);
    $document = createDocumentForUser($client);

    AccountantClient::create([
        'accountant_id' => $accountant->id,
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($accountant)
        ->getJson("/api/v1/accountant/documents/{$document->id}/annotations");

    $response->assertOk();
});

it('allows owner to create annotation on own document', function () {
    $owner = User::factory()->create(['user_type' => UserType::Personal]);
    $document = createDocumentForUser($owner);

    $response = $this->actingAs($owner)
        ->postJson("/api/v1/tax-vault/documents/{$document->id}/annotations", [
            'body' => 'This is a test annotation',
        ]);

    $response->assertStatus(201);
});

it('allows linked accountant to create annotation', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);
    $document = createDocumentForUser($client);

    AccountantClient::create([
        'accountant_id' => $accountant->id,
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($accountant)
        ->postJson("/api/v1/accountant/documents/{$document->id}/annotations", [
            'body' => 'Accountant annotation',
        ]);

    $response->assertStatus(201);
});

it('blocks unlinked accountant from listing annotations', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);
    $document = createDocumentForUser($client);

    $response = $this->actingAs($accountant)
        ->getJson("/api/v1/accountant/documents/{$document->id}/annotations");

    $response->assertForbidden();
});

it('blocks unlinked accountant from creating annotation', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $accountant = User::factory()->create(['user_type' => UserType::Accountant]);
    $document = createDocumentForUser($client);

    $response = $this->actingAs($accountant)
        ->postJson("/api/v1/accountant/documents/{$document->id}/annotations", [
            'body' => 'Unauthorized annotation',
        ]);

    $response->assertForbidden();
});

// ─── Document Requests ───

it('allows linked accountant to create document request', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $firm = AccountingFirm::create(['name' => 'Test Firm']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
        'accounting_firm_id' => $firm->id,
    ]);

    AccountantClient::create([
        'accountant_id' => $accountant->id,
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($accountant)
        ->postJson("/api/v1/accountant/clients/{$client->id}/requests", [
            'description' => 'Please upload your W-2',
            'tax_year' => 2025,
        ]);

    $response->assertStatus(201);
});

it('blocks unlinked accountant from creating document request', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $firm = AccountingFirm::create(['name' => 'Test Firm']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
        'accounting_firm_id' => $firm->id,
    ]);

    $response = $this->actingAs($accountant)
        ->postJson("/api/v1/accountant/clients/{$client->id}/requests", [
            'description' => 'Please upload your W-2',
        ]);

    $response->assertForbidden();
});

it('blocks personal user from creating document request', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $otherPersonal = User::factory()->create(['user_type' => UserType::Personal]);

    $response = $this->actingAs($otherPersonal)
        ->postJson("/api/v1/accountant/clients/{$client->id}/requests", [
            'description' => 'Please upload your W-2',
        ]);

    $response->assertForbidden();
});

it('allows client to view their own document requests', function () {
    $client = User::factory()->create(['user_type' => UserType::Personal]);
    $firm = AccountingFirm::create(['name' => 'Test Firm']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
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
        'description' => 'Need your W-2',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($client)
        ->getJson('/api/v1/document-requests');

    $response->assertOk()
        ->assertJsonCount(1);
});

it('client does not see other clients document requests', function () {
    $client1 = User::factory()->create(['user_type' => UserType::Personal]);
    $client2 = User::factory()->create(['user_type' => UserType::Personal]);
    $firm = AccountingFirm::create(['name' => 'Test Firm']);
    $accountant = User::factory()->create([
        'user_type' => UserType::Accountant,
        'accounting_firm_id' => $firm->id,
    ]);

    DocumentRequest::create([
        'accounting_firm_id' => $firm->id,
        'accountant_id' => $accountant->id,
        'client_id' => $client1->id,
        'description' => 'Need your W-2',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($client2)
        ->getJson('/api/v1/document-requests');

    $response->assertOk()
        ->assertJsonCount(0);
});
