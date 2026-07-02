<?php

use App\Models\TaxProfileEntity;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Guard: no HTTP calls touch entities ─────────────────────────────────────
beforeEach(function () {
    Http::preventStrayRequests();
});

// ─────────────────────────────────────────────────────────────────────────────
// STORE-02: Basis ledger — property entity accumulates improvement entries
// ─────────────────────────────────────────────────────────────────────────────

it('basis_ledger_accumulates: a property TaxProfileEntity accumulates improvement entries', function () {
    $user = User::factory()->create();

    $entity = TaxProfileEntity::create([
        'user_id' => $user->id,
        'entity_type' => 'property',
        'label' => '123 Main Street',
        'attributes' => ['basis_entries' => []],
    ]);

    $entity->addBasisEntry([
        'kind' => 'improvement',
        'amount_cents' => 500000,  // $5,000 new roof
        'incurred_on' => '2024-03-15',
        'tax_document_id' => null,
        'is_rebate' => false,
        'recapture_year' => null,
    ]);

    $entity->refresh();
    $entries = ($entity->getAttribute('attributes') ?? [])['basis_entries'] ?? [];

    expect(count($entries))->toBe(1);
    expect($entries[0]['kind'])->toBe('improvement');
    expect($entries[0]['amount_cents'])->toBe(500000);
    expect($entries[0]['is_rebate'])->toBeFalse();
    expect($entries[0]['incurred_on'])->toBe('2024-03-15');
});

it('basis_ledger_rebate: a rebate entry reduces the net basis', function () {
    $user = User::factory()->create();

    $entity = TaxProfileEntity::create([
        'user_id' => $user->id,
        'entity_type' => 'property',
        'label' => '456 Solar House',
        'attributes' => ['basis_entries' => []],
    ]);

    // Capital improvement: $30,000 solar panel installation
    $entity->addBasisEntry([
        'kind' => 'improvement',
        'amount_cents' => 3_000_000,
        'incurred_on' => '2024-06-01',
        'tax_document_id' => null,
        'is_rebate' => false,
        'recapture_year' => null,
    ]);

    // Utility rebate reduces basis by $1,000
    $entity->addBasisEntry([
        'kind' => 'rebate',
        'amount_cents' => 100_000,
        'incurred_on' => '2024-07-15',
        'tax_document_id' => null,
        'is_rebate' => true,
        'recapture_year' => null,
    ]);

    $entity->refresh();

    // Net basis = improvement $30,000 - rebate $1,000 = $29,000
    $netBasis = $entity->computeNetBasisCents();
    expect($netBasis)->toBe(2_900_000);  // $29,000
});

it('basis_ledger_maintenance_excluded: maintenance entries are rejected by addBasisEntry', function () {
    $user = User::factory()->create();

    $entity = TaxProfileEntity::create([
        'user_id' => $user->id,
        'entity_type' => 'property',
        'label' => '789 Elm Drive',
        'attributes' => ['basis_entries' => []],
    ]);

    // Maintenance does not increase basis — must be rejected
    expect(fn () => $entity->addBasisEntry([
        'kind' => 'maintenance',
        'amount_cents' => 50000,
        'incurred_on' => '2024-05-01',
        'tax_document_id' => null,
        'is_rebate' => false,
        'recapture_year' => null,
    ]))->toThrow(\InvalidArgumentException::class, 'Maintenance entries do not increase basis');
});

it('basis_ledger_recapture_year: each qualifying entry tracks its recapture year', function () {
    $user = User::factory()->create();

    $entity = TaxProfileEntity::create([
        'user_id' => $user->id,
        'entity_type' => 'property',
        'label' => '100 Solar Boulevard',
        'attributes' => ['basis_entries' => []],
    ]);

    // Improvement with §1250 unrecaptured depreciation — recapture_year=2024
    $entity->addBasisEntry([
        'kind' => 'improvement',
        'amount_cents' => 2_500_000,
        'incurred_on' => '2024-01-10',
        'tax_document_id' => 42,
        'is_rebate' => false,
        'recapture_year' => 2024,
    ]);

    $entity->refresh();
    $entries = ($entity->getAttribute('attributes') ?? [])['basis_entries'] ?? [];

    expect($entries[0]['recapture_year'])->toBe(2024);
    expect($entries[0]['tax_document_id'])->toBe(42);
});

it('basis_ledger_tax_document_id: each entry references its Vault receipt by tax_document_id', function () {
    $user = User::factory()->create();

    $entity = TaxProfileEntity::create([
        'user_id' => $user->id,
        'entity_type' => 'property',
        'label' => '200 Contractor Lane',
        'attributes' => ['basis_entries' => []],
    ]);

    $entity->addBasisEntry([
        'kind' => 'improvement',
        'amount_cents' => 1_200_000,
        'incurred_on' => '2023-11-20',
        'tax_document_id' => 99,   // references TaxDocument.id in the Vault
        'is_rebate' => false,
        'recapture_year' => null,
    ]);

    $entity->refresh();
    $entries = ($entity->getAttribute('attributes') ?? [])['basis_entries'] ?? [];

    expect($entries[0]['tax_document_id'])->toBe(99);
});

it('basis_ledger_entity_type_guard: addBasisEntry rejects non-property entities', function () {
    $user = User::factory()->create();

    $vehicle = TaxProfileEntity::create([
        'user_id' => $user->id,
        'entity_type' => 'vehicle',
        'label' => '2020 Toyota Camry',
        'attributes' => [],
    ]);

    expect(fn () => $vehicle->addBasisEntry([
        'kind' => 'improvement',
        'amount_cents' => 100000,
        'incurred_on' => '2024-01-01',
        'tax_document_id' => null,
        'is_rebate' => false,
        'recapture_year' => null,
    ]))->toThrow(\InvalidArgumentException::class, 'only valid for entity_type=property');
});

it('basis_ledger_attributes_encrypted: attributes column is hidden from toArray()', function () {
    $user = User::factory()->create();

    $entity = TaxProfileEntity::create([
        'user_id' => $user->id,
        'entity_type' => 'property',
        'label' => '300 Privacy Way',
        'attributes' => ['basis_entries' => [], 'purchase_price_cents' => 50_000_000],
    ]);

    $arr = $entity->toArray();

    // attributes is in $hidden — must not appear in API responses
    expect($arr)->not->toHaveKey('attributes');
    // entity_type and label are visible (non-sensitive)
    expect($arr)->toHaveKey('entity_type');
    expect($arr)->toHaveKey('label');
});
