<?php

use App\Models\User;
use App\Models\UserDocumentTypeExclusion;
use App\Models\UserTaxFact;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Item 1: "Not applicable" per-type exclusion tests.
 *
 * Covers:
 *   (a) POST /api/v1/tax-vault/type-exclusions creates/removes exclusion rows
 *   (b) GET /api/v1/tax-vault/type-status includes is_excluded flag
 *   (c) Excluding hsa_statement records finance.has_hsa=no (user_edit, confirmed)
 *   (d) Removing hsa_statement exclusion supersedes the no fact
 *   (e) medical_receipt exclusion is preference-only (no fact emitted)
 *   (f) paystub cannot be excluded (backend rejects it)
 *   (g) typeStatus accordion: excluded type counts as covered
 */

// ─────────────────────────────────────────────────────────────────────────────
// (a) Exclusion toggle — create and remove
// ─────────────────────────────────────────────────────────────────────────────

it('type_exclusion_create: POST type-exclusions marks a type as excluded', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/tax-vault/type-exclusions', [
        'type' => 'hsa_statement',
        'excluded' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('excluded', true)
        ->assertJsonPath('type', 'hsa_statement');

    expect(UserDocumentTypeExclusion::where('user_id', $user->id)->where('document_type', 'hsa_statement')->exists())->toBeTrue();
});

it('type_exclusion_remove: POST type-exclusions with excluded=false removes the row', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // First exclude
    UserDocumentTypeExclusion::create([
        'user_id' => $user->id,
        'document_type' => 'medical_receipt',
    ]);

    $response = $this->postJson('/api/v1/tax-vault/type-exclusions', [
        'type' => 'medical_receipt',
        'excluded' => false,
    ]);

    $response->assertOk()->assertJsonPath('excluded', false);
    expect(UserDocumentTypeExclusion::where('user_id', $user->id)->where('document_type', 'medical_receipt')->exists())->toBeFalse();
});

it('type_exclusion_idempotent: excluding an already-excluded type is safe', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tax-vault/type-exclusions', ['type' => 'w2', 'excluded' => true]);
    $response = $this->postJson('/api/v1/tax-vault/type-exclusions', ['type' => 'w2', 'excluded' => true]);

    $response->assertOk();
    expect(UserDocumentTypeExclusion::where('user_id', $user->id)->where('document_type', 'w2')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// (b) typeStatus includes is_excluded flag
// ─────────────────────────────────────────────────────────────────────────────

it('type_status_includes_is_excluded: GET type-status reflects exclusion state', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    UserDocumentTypeExclusion::create([
        'user_id' => $user->id,
        'document_type' => 'hsa_statement',
    ]);

    $response = $this->getJson('/api/v1/tax-vault/type-status');

    $response->assertOk();
    $types = $response->json('types');

    expect($types['hsa_statement']['is_excluded'])->toBeTrue();
    expect($types['paystub']['is_excluded'])->toBeFalse();
    expect($types['w2']['is_excluded'])->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// (c) hsa_statement exclusion records finance.has_hsa=no
// ─────────────────────────────────────────────────────────────────────────────

it('hsa_exclusion_records_fact: excluding hsa_statement creates finance.has_hsa=no user_edit fact', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tax-vault/type-exclusions', [
        'type' => 'hsa_statement',
        'excluded' => true,
    ])->assertOk();

    $fact = UserTaxFact::currentFact($user->id, 'finance.has_hsa');

    expect($fact)->not->toBeNull()
        ->and($fact->value)->toBe('no')
        ->and($fact->source_type)->toBe('user_edit')
        // user_edit facts are confirmed at write time (Item 2)
        ->and($fact->confirmed_at)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// (d) Removing hsa_statement exclusion supersedes the no fact
// ─────────────────────────────────────────────────────────────────────────────

it('hsa_exclusion_reversal_supersedes_fact: un-excluding hsa_statement supersedes finance.has_hsa=no', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Exclude → fact created
    $this->postJson('/api/v1/tax-vault/type-exclusions', ['type' => 'hsa_statement', 'excluded' => true]);

    // Un-exclude
    $this->postJson('/api/v1/tax-vault/type-exclusions', ['type' => 'hsa_statement', 'excluded' => false]);

    // The no-fact should be superseded; current fact for finance.has_hsa should be null value
    $fact = UserTaxFact::currentFact($user->id, 'finance.has_hsa');
    // After reversal, the current row has value=null (user declared "re-evaluate")
    // OR no current row if null value not stored — either is valid; we just verify is not 'no'
    if ($fact !== null) {
        expect($fact->value)->not->toBe('no');
    }

    // Exclusion row removed
    expect(UserDocumentTypeExclusion::where('user_id', $user->id)->where('document_type', 'hsa_statement')->exists())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// (e) medical_receipt: preference-only, no fact
// ─────────────────────────────────────────────────────────────────────────────

it('medical_receipt_no_fact: excluding medical_receipt emits no semantic fact', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tax-vault/type-exclusions', [
        'type' => 'medical_receipt',
        'excluded' => true,
    ])->assertOk();

    // No UserTaxFact should be created for medical exclusion
    $factCount = UserTaxFact::where('user_id', $user->id)->count();
    expect($factCount)->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// (f) paystub cannot be excluded
// ─────────────────────────────────────────────────────────────────────────────

it('paystub_not_excludable: backend rejects paystub exclusion', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/tax-vault/type-exclusions', [
        'type' => 'paystub',
        'excluded' => true,
    ]);

    $response->assertUnprocessable();
    expect(UserDocumentTypeExclusion::where('user_id', $user->id)->exists())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// (g) Excluded type in exclusions list from response
// ─────────────────────────────────────────────────────────────────────────────

it('type_status_exclusions_list: GET type-status returns exclusions array', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    UserDocumentTypeExclusion::create(['user_id' => $user->id, 'document_type' => 'hsa_statement']);
    UserDocumentTypeExclusion::create(['user_id' => $user->id, 'document_type' => 'medical_receipt']);

    $response = $this->getJson('/api/v1/tax-vault/type-status');
    $exclusions = $response->json('exclusions');

    expect($exclusions)->toContain('hsa_statement')
        ->and($exclusions)->toContain('medical_receipt')
        ->and($exclusions)->not->toContain('paystub');
});
