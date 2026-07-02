<?php

use App\Models\TaxProfileEntity;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Guard: no HTTP calls touch entities ─────────────────────────────────────
beforeEach(function () {
    Http::preventStrayRequests();
});

// ─── RED phase stub: class must exist and addBasisEntry must work ─────────────

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
    $entries = $entity->attributes['basis_entries'] ?? [];

    expect(count($entries))->toBe(1);
    expect($entries[0]['kind'])->toBe('improvement');
    expect($entries[0]['amount_cents'])->toBe(500000);
});
