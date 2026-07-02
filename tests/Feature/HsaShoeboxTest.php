<?php

use App\Http\Controllers\Api\DocumentRequestController;
use App\Models\OptimizationFinding;
use App\Models\TaxDocument;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\HsaShoeboxService;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * STORE-03: HSA shoebox receipt accumulation via UserTaxFact.
 * DOC-05:   In-flow vault upload fulfillment moves docs_missing → docs_captured.
 */

// ─── STORE-03: HSA shoebox fact creation ─────────────────────────────────────

it('creates a shoebox fact with namespaced key prefix hsa_shoebox.', function () {
    $user = User::factory()->create();
    $service = app(HsaShoeboxService::class);

    $fact = $service->addReceipt(
        userId: $user->id,
        vaultDocumentId: 9999,
        amountCents: 5000,     // $50.00
        incurredOn: '2025-03-15',
        description: 'Urgent care co-pay',
        taxYear: 2025,
    );

    expect($fact->fact_key)->toStartWith('hsa_shoebox.')
        ->and($fact->fact_key)->toBe('hsa_shoebox.9999')
        ->and($fact->volatility)->toBe('permanent')
        ->and($fact->source_type)->toBe('user_edit')
        ->and($fact->is_current)->toBeTrue()
        ->and($fact->tax_year)->toBe(2025);
});

it('fact value is $hidden from toArray()', function () {
    $user = User::factory()->create();
    $service = app(HsaShoeboxService::class);

    $fact = $service->addReceipt(
        userId: $user->id,
        vaultDocumentId: 1234,
        amountCents: 10000,
        incurredOn: '2025-06-01',
        description: 'Lab work',
        taxYear: 2025,
    );

    $arr = $fact->toArray();

    // 'value' is in UserTaxFact::$hidden — must not appear in toArray()
    expect($arr)->not->toHaveKey('value');
});

it('metadata carries vault_document_id, incurred_on, and description', function () {
    $user = User::factory()->create();
    $service = app(HsaShoeboxService::class);

    $fact = $service->addReceipt(
        userId: $user->id,
        vaultDocumentId: 5678,
        amountCents: 7500,
        incurredOn: '2025-08-20',
        description: 'Specialist visit',
        taxYear: 2025,
    );

    expect($fact->metadata)->toHaveKey('vault_document_id', 5678)
        ->toHaveKey('incurred_on', '2025-08-20')
        ->toHaveKey('description', 'Specialist visit');
});

it('education copy constant matches the approved STORE-03 wording', function () {
    expect(HsaShoeboxService::EDUCATION_COPY)
        // Must mention "after opening your HSA" or similar establishment-only framing
        ->toContain('after')
        ->toContain('HSA')
        // Must use conditional framing: "may be reimbursable"
        ->toContain('may be reimbursable')
        // Must include professional disclaimer
        ->toContain('tax professional')
        // Must NOT say "you are entitled" or "you qualify" (absolute framing forbidden)
        ->not->toContain('you qualify')
        ->not->toContain('you are entitled')
        ->not->toContain('you can deduct');
});

it('listByUser returns shoebox facts ordered by tax_year desc', function () {
    $user = User::factory()->create();
    $service = app(HsaShoeboxService::class);

    $service->addReceipt($user->id, 1, 5000, '2024-03-01', 'Old receipt', 2024);
    $service->addReceipt($user->id, 2, 8000, '2025-06-01', 'New receipt', 2025);

    $facts = $service->listByUser($user->id);

    expect($facts)->toHaveCount(2)
        ->and($facts->first()->tax_year)->toBe(2025)   // newest year first
        ->and($facts->last()->tax_year)->toBe(2024);
});

it('listByUser does not return non-shoebox facts for the same user', function () {
    $user = User::factory()->create();
    $service = app(HsaShoeboxService::class);

    // Add a shoebox fact
    $service->addReceipt($user->id, 77, 3000, '2025-01-10', 'Rx', 2025);

    // Add a non-shoebox fact (e.g., from interview)
    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'retirement.traditional_401k_ytd_cents',
        value: '50000',
        sourceType: 'interview_answer',
        label: '401k contribution',
        volatility: 'annual',
        taxYear: 2025,
    );

    $facts = $service->listByUser($user->id);

    expect($facts)->toHaveCount(1)
        ->and($facts->first()->fact_key)->toStartWith('hsa_shoebox.');
});

// ─── DOC-05: In-flow finding docs update ─────────────────────────────────────

it('DOC-05: vault upload moves entry from docs_missing to docs_captured on matching finding', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    // Create an OptimizationFinding with docs_missing = ['pay_stub']
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'test_finding_paystub',
        'finding_type' => 'missing_docs',
        'severity' => 'medium',
        'docs_missing' => ['pay_stub', 'w2'],
        'docs_captured' => [],
        'status' => 'open',
    ]);

    // Create a TaxDocument with category pay_stub
    Storage::disk('local')->put("tax-vault/{$user->id}/2025/pay_stub/test.pdf", 'fake');
    $document = TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'paystub.pdf',
        'stored_path' => "tax-vault/{$user->id}/2025/pay_stub/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 512,
        'file_hash' => hash('sha256', 'fake'),
        'tax_year' => 2025,
        'status' => 'ready',
        'category' => 'pay_stub',
    ]);

    // Trigger the DOC-05 fulfillment
    DocumentRequestController::updateFindingDocsOnFulfillment($document);

    // Assert: 'pay_stub' removed from docs_missing
    $finding->refresh();
    expect($finding->docs_missing)->not->toContain('pay_stub')
        ->and($finding->docs_missing)->toContain('w2')         // other items stay
        ->and($finding->docs_captured)->toContain($document->id);
});

it('DOC-05: does not update findings for a different user (scopeForUser guard)', function () {
    Storage::fake('local');

    $ownerUser = User::factory()->create();
    $otherUser = User::factory()->create();

    // Create a finding owned by otherUser
    $finding = OptimizationFinding::create([
        'user_id' => $otherUser->id,
        'tax_year' => 2025,
        'finding_key' => 'other_user_finding',
        'finding_type' => 'missing_docs',
        'severity' => 'low',
        'docs_missing' => ['pay_stub'],
        'docs_captured' => [],
        'status' => 'open',
    ]);

    // Upload a document as ownerUser
    Storage::disk('local')->put("tax-vault/{$ownerUser->id}/2025/pay_stub/test.pdf", 'fake');
    $document = TaxDocument::create([
        'user_id' => $ownerUser->id,  // different user!
        'original_filename' => 'paystub.pdf',
        'stored_path' => "tax-vault/{$ownerUser->id}/2025/pay_stub/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 512,
        'file_hash' => hash('sha256', 'fake'),
        'tax_year' => 2025,
        'status' => 'ready',
        'category' => 'pay_stub',
    ]);

    DocumentRequestController::updateFindingDocsOnFulfillment($document);

    // otherUser's finding must NOT be touched
    $finding->refresh();
    expect($finding->docs_missing)->toContain('pay_stub')
        ->and($finding->docs_captured)->toBeEmpty();
});

it('DOC-05: finding with no matching category in docs_missing is not updated', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'finding_key' => 'no_match_finding',
        'finding_type' => 'missing_docs',
        'severity' => 'low',
        'docs_missing' => ['w2', 'mortgage_1098'],  // no 'pay_stub'
        'docs_captured' => [],
        'status' => 'open',
    ]);

    Storage::disk('local')->put("tax-vault/{$user->id}/2025/pay_stub/test.pdf", 'fake');
    $document = TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'paystub.pdf',
        'stored_path' => "tax-vault/{$user->id}/2025/pay_stub/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 512,
        'file_hash' => hash('sha256', 'fake'),
        'tax_year' => 2025,
        'status' => 'ready',
        'category' => 'pay_stub',
    ]);

    DocumentRequestController::updateFindingDocsOnFulfillment($document);

    $finding->refresh();
    expect($finding->docs_missing)->toContain('w2')
        ->and($finding->docs_missing)->toContain('mortgage_1098')
        ->and($finding->docs_captured)->toBeEmpty();
});
