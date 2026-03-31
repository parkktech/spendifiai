<?php

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Jobs\ExtractTaxDocument;
use App\Models\TaxDocument;
use App\Models\TaxVaultAuditLog;
use App\Models\User;
use App\Services\AI\TaxDocumentExtractorService;
use App\Services\TaxVaultAuditService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('tax-vault/1/2025/w2/test.pdf', 'fake-pdf-content');

    $this->user = User::factory()->create();
});

function createExtractionDocument(int $userId, array $overrides = []): TaxDocument
{
    return TaxDocument::create(array_merge([
        'user_id' => $userId,
        'original_filename' => 'test-w2.pdf',
        'stored_path' => 'tax-vault/1/2025/w2/test.pdf',
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', 'fake-pdf-content'),
        'tax_year' => 2025,
        'status' => 'upload',
    ], $overrides));
}

// ─── Job Pipeline Tests ───

it('transitions status classifying -> extracting -> ready on successful extraction', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push([
                'content' => [['text' => json_encode([
                    'category' => 'w2',
                    'confidence' => 0.95,
                    'reasoning' => 'Standard W-2 form',
                ])]],
                'stop_reason' => 'end_turn',
            ], 200)
            ->push([
                'content' => [['text' => json_encode([
                    'fields' => [
                        'employer_name' => ['value' => 'Acme Corp', 'confidence' => 0.98],
                        'wages' => ['value' => '52000.00', 'confidence' => 0.97],
                        'ssn_last4' => ['value' => '6789', 'confidence' => 0.99],
                    ],
                    'overall_confidence' => 0.92,
                ])]],
                'stop_reason' => 'end_turn',
            ], 200),
    ]);

    $document = createExtractionDocument($this->user->id);

    (new ExtractTaxDocument($document->id))->handle(
        app(TaxDocumentExtractorService::class),
        app(TaxVaultAuditService::class),
    );

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->category)->toBe(TaxDocumentCategory::W2)
        ->and($document->classification_confidence)->toBe('0.95')
        ->and($document->extracted_data['fields']['employer_name']['value'])->toBe('Acme Corp')
        ->and($document->extracted_data['fields']['wages']['value'])->toBe('52000.00');

    Http::assertSentCount(2);
});

it('sets status to failed when classification confidence is below gate', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([
                'category' => 'w2',
                'confidence' => 0.50,
                'reasoning' => 'Partially readable document',
            ])]],
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $document = createExtractionDocument($this->user->id);

    (new ExtractTaxDocument($document->id))->handle(
        app(TaxDocumentExtractorService::class),
        app(TaxVaultAuditService::class),
    );

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->extracted_data)->toBeNull();

    // Only classify was called, not extract
    Http::assertSentCount(1);
});

it('sets status to failed and does NOT call extract when classification returns error', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'This is not valid JSON at all']],
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $document = createExtractionDocument($this->user->id);

    (new ExtractTaxDocument($document->id))->handle(
        app(TaxDocumentExtractorService::class),
        app(TaxVaultAuditService::class),
    );

    $document->refresh();

    // parseJsonResponse will try to extract JSON, fail, and return ['error' => ...]
    // The job should set status to failed
    expect($document->status)->toBe(DocumentStatus::Failed);
});

// ─── Field Correction Tests ───

it('updates field value with confidence 1.0 and verified true via PATCH', function () {
    $document = createExtractionDocument($this->user->id, [
        'status' => DocumentStatus::Ready->value,
        'category' => TaxDocumentCategory::W2->value,
        'extracted_data' => [
            'fields' => [
                'employer_name' => ['value' => 'Acme Corp', 'confidence' => 0.85],
                'wages' => ['value' => '50000.00', 'confidence' => 0.90],
            ],
            'overall_confidence' => 0.87,
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/tax-vault/documents/{$document->id}/fields", [
            'field' => 'wages',
            'value' => '52000.00',
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $document->refresh();
    expect($document->extracted_data['fields']['wages']['value'])->toBe('52000.00')
        ->and((float) $document->extracted_data['fields']['wages']['confidence'])->toBe(1.0)
        ->and($document->extracted_data['fields']['wages']['verified'])->toBeTrue();
});

it('creates audit log entry with field_corrected action on PATCH', function () {
    $document = createExtractionDocument($this->user->id, [
        'status' => DocumentStatus::Ready->value,
        'category' => TaxDocumentCategory::W2->value,
        'extracted_data' => [
            'fields' => [
                'wages' => ['value' => '50000.00', 'confidence' => 0.90],
            ],
            'overall_confidence' => 0.90,
        ],
    ]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/tax-vault/documents/{$document->id}/fields", [
            'field' => 'wages',
            'value' => '52000.00',
        ]);

    $auditLog = TaxVaultAuditLog::where('tax_document_id', $document->id)
        ->where('action', 'field_corrected')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->user_id)->toBe($this->user->id)
        ->and($auditLog->metadata['field'])->toBe('wages')
        ->and($auditLog->metadata['old_value'])->toBe('50000.00')
        ->and($auditLog->metadata['new_value'])->toBe('52000.00');
});

it('returns 403 for non-owner attempting field correction', function () {
    $otherUser = User::factory()->create();

    $document = createExtractionDocument($this->user->id, [
        'status' => DocumentStatus::Ready->value,
        'category' => TaxDocumentCategory::W2->value,
        'extracted_data' => [
            'fields' => [
                'wages' => ['value' => '50000.00', 'confidence' => 0.90],
            ],
            'overall_confidence' => 0.90,
        ],
    ]);

    $response = $this->actingAs($otherUser)
        ->patchJson("/api/v1/tax-vault/documents/{$document->id}/fields", [
            'field' => 'wages',
            'value' => '52000.00',
        ]);

    $response->assertStatus(403);
});

// ─── Accept All Tests ───

it('marks all extracted fields as verified via accept-all endpoint', function () {
    $document = createExtractionDocument($this->user->id, [
        'status' => DocumentStatus::Ready->value,
        'category' => TaxDocumentCategory::W2->value,
        'extracted_data' => [
            'fields' => [
                'employer_name' => ['value' => 'Acme Corp', 'confidence' => 0.98],
                'wages' => ['value' => '52000.00', 'confidence' => 0.97],
                'ssn_last4' => ['value' => '6789', 'confidence' => 0.99],
            ],
            'overall_confidence' => 0.92,
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/tax-vault/documents/{$document->id}/accept-all");

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $document->refresh();
    foreach ($document->extracted_data['fields'] as $field) {
        expect($field['verified'])->toBeTrue();
    }
});

// ─── Retry Extraction Tests ───

it('re-dispatches extraction job for failed document via retry endpoint', function () {
    Queue::fake();

    $document = createExtractionDocument($this->user->id, [
        'status' => DocumentStatus::Failed->value,
        'category' => TaxDocumentCategory::W2->value,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/tax-vault/documents/{$document->id}/retry-extraction");

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    Queue::assertPushed(ExtractTaxDocument::class, function ($job) use ($document) {
        return $job->documentId === $document->id;
    });

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Upload);
});

it('returns 422 for retry-extraction on non-failed document', function () {
    $document = createExtractionDocument($this->user->id, [
        'status' => DocumentStatus::Ready->value,
        'category' => TaxDocumentCategory::W2->value,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/tax-vault/documents/{$document->id}/retry-extraction");

    $response->assertStatus(422);
});
