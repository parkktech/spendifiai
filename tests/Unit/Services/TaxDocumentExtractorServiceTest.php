<?php

use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use App\Models\User;
use App\Services\AI\TaxDocumentExtractorService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('tax-vault/1/2025/w2/test.pdf', 'fake-pdf-content');

    $this->user = User::factory()->create();
    $this->service = new TaxDocumentExtractorService;
});

function createTestDocument(int $userId, array $overrides = []): TaxDocument
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

// ─── classify() ───

it('classifies a document and returns category and confidence from Claude API', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([
                'category' => 'w2',
                'confidence' => 0.95,
                'reasoning' => 'Standard W-2 form with employer information',
            ])]],
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $document = createTestDocument($this->user->id);
    $result = $this->service->classify($document);

    expect($result)->toHaveKeys(['category', 'confidence', 'reasoning'])
        ->and($result['category'])->toBe('w2')
        ->and($result['confidence'])->toBe(0.95)
        ->and($result['reasoning'])->toBe('Standard W-2 form with employer information');

    Http::assertSentCount(1);
});

it('returns other with low confidence when Claude returns unrecognized document', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([
                'category' => 'other',
                'confidence' => 0.30,
                'reasoning' => 'Document does not match any known tax form',
            ])]],
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $document = createTestDocument($this->user->id);
    $result = $this->service->classify($document);

    expect($result['category'])->toBe('other')
        ->and($result['confidence'])->toBe(0.30);
});

// ─── extract() ───

it('extracts structured fields with per-field confidence for W-2 document', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([
                'fields' => [
                    'employer_name' => ['value' => 'Acme Corp', 'confidence' => 0.98],
                    'employer_ein' => ['value' => '12-3456789', 'confidence' => 0.95],
                    'wages' => ['value' => '52000.00', 'confidence' => 0.97],
                    'ssn_last4' => ['value' => '6789', 'confidence' => 0.99],
                    'federal_tax_withheld' => ['value' => '7800.00', 'confidence' => 0.96],
                ],
                'overall_confidence' => 0.92,
            ])]],
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $document = createTestDocument($this->user->id, [
        'category' => TaxDocumentCategory::W2->value,
    ]);
    $result = $this->service->extract($document);

    expect($result)->toHaveKeys(['fields', 'overall_confidence'])
        ->and($result['overall_confidence'])->toBe(0.92)
        ->and($result['fields']['employer_name']['value'])->toBe('Acme Corp')
        ->and($result['fields']['employer_name']['confidence'])->toBe(0.98)
        ->and($result['fields']['wages']['value'])->toBe('52000.00')
        ->and($result['fields']['ssn_last4']['value'])->toBe('6789');
});

it('extracts generic fields for Tier 2 form types', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([
                'fields' => [
                    'form_title' => ['value' => '1099-DIV', 'confidence' => 0.90],
                    'issuer_name' => ['value' => 'Vanguard', 'confidence' => 0.88],
                    'total_amount' => ['value' => '1250.00', 'confidence' => 0.85],
                    'tax_year' => ['value' => '2025', 'confidence' => 0.95],
                ],
                'overall_confidence' => 0.88,
            ])]],
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $document = createTestDocument($this->user->id, [
        'category' => TaxDocumentCategory::DIV_1099->value,
    ]);
    $result = $this->service->extract($document);

    expect($result['overall_confidence'])->toBe(0.88)
        ->and($result['fields']['form_title']['value'])->toBe('1099-DIV')
        ->and($result['fields']['issuer_name']['value'])->toBe('Vanguard');
});

// ─── sanitizeExtraction() ───

it('strips full SSN with dashes to last 4 digits', function () {
    $data = [
        'fields' => [
            'ssn_last4' => ['value' => '123-45-6789', 'confidence' => 0.95],
            'employer_name' => ['value' => 'Acme Corp', 'confidence' => 0.98],
        ],
        'overall_confidence' => 0.90,
    ];

    $result = $this->service->sanitizeExtraction($data, TaxDocumentCategory::W2);

    expect($result['fields']['ssn_last4']['value'])->toBe('6789')
        ->and($result['fields']['employer_name']['value'])->toBe('Acme Corp');
});

it('strips SSN without dashes to last 4 digits', function () {
    $data = [
        'fields' => [
            'ssn_last4' => ['value' => '123456789', 'confidence' => 0.90],
        ],
        'overall_confidence' => 0.85,
    ];

    $result = $this->service->sanitizeExtraction($data, TaxDocumentCategory::W2);

    expect($result['fields']['ssn_last4']['value'])->toBe('6789');
});

it('handles ssn_last4 field already being 4 digits as no-op', function () {
    $data = [
        'fields' => [
            'ssn_last4' => ['value' => '6789', 'confidence' => 0.99],
        ],
        'overall_confidence' => 0.95,
    ];

    $result = $this->service->sanitizeExtraction($data, TaxDocumentCategory::W2);

    expect($result['fields']['ssn_last4']['value'])->toBe('6789')
        ->and($result['fields']['ssn_last4']['confidence'])->toBe(0.99);
});

it('renames employee_ssn and ssn field names to ssn_last4 and strips to last 4', function () {
    $data = [
        'fields' => [
            'employee_ssn' => ['value' => '987-65-4321', 'confidence' => 0.92],
        ],
        'overall_confidence' => 0.88,
    ];

    $result = $this->service->sanitizeExtraction($data, TaxDocumentCategory::W2);

    expect($result['fields'])->not->toHaveKey('employee_ssn')
        ->and($result['fields'])->toHaveKey('ssn_last4')
        ->and($result['fields']['ssn_last4']['value'])->toBe('4321');
});

// ─── getFieldSchema() ───

it('returns W2_FIELDS for W2 category and TIER2_FIELDS for unknown', function () {
    $w2Schema = $this->service->getFieldSchema(TaxDocumentCategory::W2);
    expect($w2Schema)->toBe(TaxDocumentExtractorService::W2_FIELDS)
        ->and($w2Schema)->toContain('employer_name', 'wages', 'ssn_last4');

    $necSchema = $this->service->getFieldSchema(TaxDocumentCategory::NEC_1099);
    expect($necSchema)->toBe(TaxDocumentExtractorService::NEC_1099_FIELDS);

    $otherSchema = $this->service->getFieldSchema(TaxDocumentCategory::Other);
    expect($otherSchema)->toBe(TaxDocumentExtractorService::TIER2_FIELDS);

    $receiptsSchema = $this->service->getFieldSchema(TaxDocumentCategory::Receipts);
    expect($receiptsSchema)->toBe(TaxDocumentExtractorService::TIER2_FIELDS);
});
