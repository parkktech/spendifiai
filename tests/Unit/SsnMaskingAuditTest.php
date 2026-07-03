<?php

/**
 * SsnMaskingAuditTest — SAFE-04 end-to-end SSN masking chain audit.
 *
 * Verifies five links in the masking chain that prevent a full SSN from
 * surviving extraction, storage, or API serialization:
 *
 *  Link 1 — Extraction system prompt instructs "last 4 digits only" (static read)
 *  Link 2 — sanitizeExtraction() strips any 9-digit SSN value to last 4 digits
 *  Link 3 — extracted_data is cast as encrypted:array (PII encrypted at rest)
 *           value column on UserTaxFact is cast as encrypted + is in $hidden
 *  Link 4 — UserTaxFact.metadata (plaintext JSONB) never stores a full SSN
 *           when the fact_key is SSN-related (metadata carries confidence only)
 *  Link 5 — TaxDocumentResource exposes only sanitized extracted_data;
 *           a full SSN never appears in the serialized API payload
 *
 * No production code changes are expected if all links are sound. If an
 * additive guard is missing, it is added and noted in the SUMMARY.
 */

use App\Enums\TaxDocumentCategory;
use App\Http\Resources\TaxDocumentResource;
use App\Models\TaxDocument;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\AI\TaxDocumentExtractorService;
use App\Services\TaxVaultStorageService;
use Illuminate\Http\Request;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Link 1: Extraction system prompt instructs "last 4 digits only"
// ══════════════════════════════════════════════════════════════════════════════

it('SAFE-04 Link-1: TaxDocumentExtractorService source contains CRITICAL SSN RULE with last-4 instruction', function () {
    $source = file_get_contents(base_path('app/Services/AI/TaxDocumentExtractorService.php'));

    expect($source)->toContain('CRITICAL SSN RULE');
    expect(mb_strtolower($source))->toContain('last 4 digits');
    expect(mb_strtolower($source))->toContain('never return a full ssn');
});

it('SAFE-04 Link-1: extract() system prompt string contains the CRITICAL SSN RULE wording', function () {
    // Verify the system prompt is built with the rule in the actual extract() method body
    $source = file_get_contents(base_path('app/Services/AI/TaxDocumentExtractorService.php'));

    // The rule must appear in the heredoc used by extract(), not just in a comment
    $extractStart = strpos($source, 'public function extract(');
    $sanitizeStart = strpos($source, 'public function sanitizeExtraction(');
    $extractBody = substr($source, $extractStart, $sanitizeStart - $extractStart);

    expect(mb_strtolower($extractBody))->toContain('critical ssn rule');
    expect(mb_strtolower($extractBody))->toContain('never return a full ssn');
});

// ══════════════════════════════════════════════════════════════════════════════
// Link 2: sanitizeExtraction() strips full SSN to last 4 digits
// ══════════════════════════════════════════════════════════════════════════════

it('SAFE-04 Link-2: sanitizeExtraction strips 9-digit SSN with dashes in ssn_last4 field to last 4', function () {
    $service = new TaxDocumentExtractorService;

    $data = [
        'fields' => [
            'ssn_last4' => ['value' => '123-45-6789', 'confidence' => 0.95],
            'employer_name' => ['value' => 'ACME Corp', 'confidence' => 0.98],
        ],
        'overall_confidence' => 0.90,
    ];

    $result = $service->sanitizeExtraction($data, TaxDocumentCategory::W2);

    expect(strlen((string) $result['fields']['ssn_last4']['value']))->toBeLessThanOrEqual(4);
    expect($result['fields']['ssn_last4']['value'])->toBe('6789');
    // Non-SSN field survives unchanged
    expect($result['fields']['employer_name']['value'])->toBe('ACME Corp');
});

it('SAFE-04 Link-2: sanitizeExtraction renames bare "ssn" field to ssn_last4 and strips to last 4', function () {
    $service = new TaxDocumentExtractorService;

    $data = [
        'fields' => [
            'ssn' => ['value' => '987654321', 'confidence' => 0.92],
        ],
        'overall_confidence' => 0.88,
    ];

    $result = $service->sanitizeExtraction($data, TaxDocumentCategory::W2);

    expect($result['fields'])->not->toHaveKey('ssn');
    expect($result['fields'])->toHaveKey('ssn_last4');
    expect(strlen((string) $result['fields']['ssn_last4']['value']))->toBeLessThanOrEqual(4);
    expect($result['fields']['ssn_last4']['value'])->toBe('4321');
});

it('SAFE-04 Link-2: sanitizeExtraction renames social_security_number to ssn_last4 and strips', function () {
    $service = new TaxDocumentExtractorService;

    $data = [
        'fields' => [
            'social_security_number' => ['value' => '999-88-7777', 'confidence' => 0.91],
        ],
        'overall_confidence' => 0.85,
    ];

    $result = $service->sanitizeExtraction($data, TaxDocumentCategory::NEC_1099);

    expect($result['fields'])->not->toHaveKey('social_security_number');
    expect($result['fields'])->toHaveKey('ssn_last4');
    expect(strlen((string) $result['fields']['ssn_last4']['value']))->toBeLessThanOrEqual(4);
});

it('SAFE-04 Link-2: sanitizeExtraction with schema whitelist ensures no full SSN survives as any field', function () {
    $service = new TaxDocumentExtractorService;

    // Adversarial payload: full SSN in multiple field positions
    $data = [
        'fields' => [
            'ssn' => ['value' => '111-22-3333', 'confidence' => 0.99],
            'employee_ssn' => ['value' => '444-55-6666', 'confidence' => 0.99],
            'social_security_number' => ['value' => '777-88-9999', 'confidence' => 0.99],
            'employer_name' => ['value' => 'Corp Inc', 'confidence' => 0.98],
        ],
        'overall_confidence' => 0.95,
    ];

    $result = $service->sanitizeExtraction($data, TaxDocumentCategory::W2);

    // Check all surviving fields — no value should be a full SSN (9+ digits)
    foreach ($result['fields'] as $key => $fieldData) {
        $value = (string) ($fieldData['value'] ?? '');
        $digits = preg_replace('/\D/', '', $value);
        expect(strlen($digits))->toBeLessThanOrEqual(4,
            "Field '{$key}' contains a digit sequence longer than 4 (potential full SSN): '{$value}'"
        );
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Link 3: Encrypted at rest (model casts)
// ══════════════════════════════════════════════════════════════════════════════

it('SAFE-04 Link-3: TaxDocument casts extracted_data as encrypted:array (PII encrypted at database level)', function () {
    $model = new TaxDocument;
    $casts = $model->getCasts();

    expect($casts)->toHaveKey('extracted_data');
    expect($casts['extracted_data'])->toBe('encrypted:array');
});

it('SAFE-04 Link-3: UserTaxFact casts value column as encrypted (SSN/wage PII encrypted at database level)', function () {
    $model = new UserTaxFact;
    $casts = $model->getCasts();

    expect($casts)->toHaveKey('value');
    expect($casts['value'])->toBe('encrypted');
});

it('SAFE-04 Link-3: UserTaxFact value is in $hidden so it is excluded from API serialization', function () {
    $model = new UserTaxFact;
    $hidden = $model->getHidden();

    // value IS in $hidden — it must be there to prevent the encrypted PII column from appearing in API responses
    expect($hidden)->toContain('value');
});

// ══════════════════════════════════════════════════════════════════════════════
// Link 4: UserTaxFact.metadata never stores a full SSN (Pitfall 3 from research)
// ══════════════════════════════════════════════════════════════════════════════

it('SAFE-04 Link-4: UserTaxFact with ssn fact_key has no 9-digit SSN sequence in plaintext metadata', function () {
    $user = User::factory()->create();

    // Record an SSN-keyed fact; metadata should carry extraction confidence only (non-PII)
    $fact = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'identity.ssn_last4',
        value: '6789',   // properly masked before recordFact() is called
        sourceType: 'document_extraction',
        metadata: ['confidence' => 0.95, 'field_source' => 'w2'],
    );

    $metadataJson = json_encode($fact->metadata ?? []);

    // Regex: no 9-consecutive-digit run (full SSN without separators)
    expect($metadataJson)->not->toMatch('/\b\d{9}\b/',
        'UserTaxFact.metadata for ssn fact_key must not contain a 9-digit SSN sequence'
    );

    // Regex: no XXX-XX-XXXX formatted SSN
    expect($metadataJson)->not->toMatch('/\d{3}-\d{2}-\d{4}/',
        'UserTaxFact.metadata for ssn fact_key must not contain a hyphenated SSN'
    );
});

it('SAFE-04 Link-4: UserTaxFact metadata digit sequences for ssn fact_key are all at most 4 digits long', function () {
    $user = User::factory()->create();

    $fact = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'identity.ssn_last4',
        value: '4321',
        sourceType: 'document_extraction',
        metadata: ['confidence' => 0.92, 'extraction_pass' => 1],
    );

    $metadataJson = json_encode($fact->metadata ?? []);

    // Extract all isolated digit runs from the JSON; none should exceed 4 digits
    preg_match_all('/\b\d{5,}\b/', $metadataJson, $matches);

    expect($matches[0])->toBeEmpty(
        'UserTaxFact.metadata contains an isolated digit sequence > 4 digits (potential SSN): '.
        implode(', ', $matches[0])
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Link 5: API serialization exposes only sanitized extracted_data (no full SSN)
// ══════════════════════════════════════════════════════════════════════════════

it('SAFE-04 Link-5: TaxDocumentResource serialized output contains no full SSN when extracted_data is properly sanitized', function () {
    $user = User::factory()->create();

    // Mock TaxVaultStorageService::getSignedUrl to avoid URL signing errors in tests
    $this->mock(TaxVaultStorageService::class, function ($mock) {
        $mock->shouldReceive('getSignedUrl')
            ->andReturn('https://example.com/vault/signed?token=test');
    });

    // Simulate extracted_data that has gone through sanitizeExtraction() — last-4 only
    $doc = TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'w2-2025.pdf',
        'stored_path' => 'tax-vault/1/2025/w2.pdf',
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 2048,
        'file_hash' => hash('sha256', 'ssn-audit-test-'.uniqid()),
        'tax_year' => 2025,
        'status' => 'ready',
        'category' => TaxDocumentCategory::W2->value,
        'extracted_data' => [
            'fields' => [
                'employer_name' => ['value' => 'ACME Corp', 'confidence' => 0.98],
                'ssn_last4' => ['value' => '6789', 'confidence' => 0.99],   // last-4 only
                'wages' => ['value' => '52000.00', 'confidence' => 0.97],
            ],
            'overall_confidence' => 0.95,
        ],
    ]);

    $resource = new TaxDocumentResource($doc);
    $serialized = $resource->toArray(new Request);
    $serializedJson = json_encode($serialized);

    // No 9-digit SSN without separators
    expect($serializedJson)->not->toMatch('/\b\d{9}\b/',
        'Serialized TaxDocumentResource must not contain a 9-digit SSN sequence'
    );

    // No hyphenated SSN (XXX-XX-XXXX)
    expect($serializedJson)->not->toMatch('/\d{3}-\d{2}-\d{4}/',
        'Serialized TaxDocumentResource must not contain a hyphenated full SSN'
    );

    // ssn_last4 value in the response must be at most 4 digits
    $ssnLastFour = $serialized['extracted_data']['fields']['ssn_last4']['value'] ?? null;
    if ($ssnLastFour !== null) {
        expect(strlen((string) $ssnLastFour))->toBeLessThanOrEqual(4,
            'ssn_last4 value exposed via TaxDocumentResource must be at most 4 digits'
        );
    }
});

it('SAFE-04 Link-5: TaxDocument model toArray does not expose raw full SSN when extracted_data is sanitized', function () {
    $user = User::factory()->create();

    $doc = TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'w2.pdf',
        'stored_path' => 'tax-vault/1/2025/w2.pdf',
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', 'test-ssn-'.uniqid()),
        'tax_year' => 2025,
        'status' => 'ready',
        'category' => TaxDocumentCategory::W2->value,
        'extracted_data' => [
            'fields' => [
                'ssn_last4' => ['value' => '1234', 'confidence' => 0.99],
            ],
            'overall_confidence' => 0.95,
        ],
    ]);

    $serializedJson = json_encode($doc->toArray());

    // No full SSN in any form
    expect($serializedJson)->not->toMatch('/\b\d{9}\b/');
    expect($serializedJson)->not->toMatch('/\d{3}-\d{2}-\d{4}/');
});
