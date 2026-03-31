<?php

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\TaxDocumentIntelligenceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

/**
 * Helper: create a TaxDocument for testing (no factory exists per Phase 7 decision).
 */
function createIntelDocument(int $userId, array $overrides = []): TaxDocument
{
    return TaxDocument::create(array_merge([
        'user_id' => $userId,
        'original_filename' => 'test-doc.pdf',
        'stored_path' => 'tax-vault/test/doc.pdf',
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', 'test-content-'.rand()),
        'tax_year' => 2025,
        'status' => DocumentStatus::Ready->value,
    ], $overrides));
}

// ─── Intelligence Endpoint Tests ───

it('returns missing W-2 when employment income exists without document', function () {
    // Create employment income transactions (amount < 0 = income in Plaid convention)
    Transaction::factory()->count(12)->create([
        'user_id' => $this->user->id,
        'amount' => -4500,
        'merchant_name' => 'ACME CORP PAYROLL',
        'plaid_detailed_category' => 'INCOME_WAGES',
        'transaction_date' => '2025-06-15',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tax-vault/intelligence?year=2025');

    $response->assertOk();
    $response->assertJsonPath('missing_documents.0.category', 'w2');
    expect($response->json('missing_documents'))->not->toBeEmpty();
});

it('does not flag missing document when document exists', function () {
    // Create employment income transactions
    Transaction::factory()->count(6)->create([
        'user_id' => $this->user->id,
        'amount' => -5000,
        'merchant_name' => 'ACME CORP PAYROLL',
        'plaid_detailed_category' => 'INCOME_WAGES',
        'transaction_date' => '2025-03-15',
    ]);

    // Upload a W-2 document
    createIntelDocument($this->user->id, [
        'category' => TaxDocumentCategory::W2->value,
        'tax_year' => 2025,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tax-vault/intelligence?year=2025');

    $response->assertOk();
    expect($response->json('missing_documents'))->toBeEmpty();
});

it('does not flag missing document below $600 threshold', function () {
    // Create contractor income below threshold
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'amount' => -500,
        'merchant_name' => 'FREELANCE CLIENT',
        'ai_category' => 'Contractor Income',
        'transaction_date' => '2025-04-15',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tax-vault/intelligence?year=2025');

    $response->assertOk();

    // Should not flag a 1099-NEC for $500 (below $600 threshold)
    $missing = collect($response->json('missing_documents'));
    $nec = $missing->where('category', '1099_nec');
    expect($nec)->toBeEmpty();
});

it('detects anomaly when deposits significantly exceed document wages', function () {
    // Create W-2 with wages of $50,000
    $doc = createIntelDocument($this->user->id, [
        'category' => TaxDocumentCategory::W2->value,
        'tax_year' => 2025,
        'extracted_data' => [
            'fields' => [
                'wages' => ['value' => '50000', 'confidence' => 0.95],
                'employer_name' => ['value' => 'BIGCO INC', 'confidence' => 0.90],
            ],
            'overall_confidence' => 0.92,
        ],
    ]);

    // Create deposits totaling $70,000 (exceeds W-2 by 40%)
    Transaction::factory()->count(14)->create([
        'user_id' => $this->user->id,
        'amount' => -5000,
        'merchant_name' => 'BIGCO INC PAYROLL',
        'plaid_detailed_category' => 'INCOME_WAGES',
        'transaction_date' => '2025-05-15',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tax-vault/intelligence?year=2025');

    $response->assertOk();
    $anomalies = $response->json('anomalies');
    expect($anomalies)->not->toBeEmpty();
    expect($anomalies[0]['severity'])->toBe('alert');
    expect($anomalies[0]['document_id'])->toBe($doc->id);
});

it('links transactions to matching documents', function () {
    // Create W-2 document with employer name
    $doc = createIntelDocument($this->user->id, [
        'category' => TaxDocumentCategory::W2->value,
        'tax_year' => 2025,
        'extracted_data' => [
            'fields' => [
                'employer_name' => ['value' => 'TESTCORP', 'confidence' => 0.95],
                'wages' => ['value' => '60000', 'confidence' => 0.90],
            ],
            'overall_confidence' => 0.92,
        ],
    ]);

    // Create matching employment transactions
    Transaction::factory()->count(5)->create([
        'user_id' => $this->user->id,
        'amount' => -5000,
        'merchant_name' => 'TESTCORP DIRECT DEPOSIT',
        'plaid_detailed_category' => 'INCOME_WAGES',
        'transaction_date' => '2025-06-15',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tax-vault/intelligence?year=2025');

    $response->assertOk();
    $links = $response->json('transaction_links');
    expect($links)->not->toBeEmpty();
    $docLink = collect($links)->firstWhere('document_id', $doc->id);
    expect($docLink)->not->toBeNull();
    expect($docLink['transaction_count'])->toBe(5);
});

it('requires authentication for intelligence endpoint', function () {
    $response = $this->getJson('/api/v1/tax-vault/intelligence?year=2025');

    $response->assertUnauthorized();
});

it('caches results and invalidates on document upload', function () {
    // Create some income to get predictable results
    Transaction::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'amount' => -2000,
        'merchant_name' => 'CACHECO PAYROLL',
        'plaid_detailed_category' => 'INCOME_WAGES',
        'transaction_date' => '2025-07-15',
    ]);

    // First call populates cache
    $response1 = $this->actingAs($this->user)
        ->getJson('/api/v1/tax-vault/intelligence?year=2025');
    $response1->assertOk();

    // Second call should return same result (cached)
    $response2 = $this->actingAs($this->user)
        ->getJson('/api/v1/tax-vault/intelligence?year=2025');
    $response2->assertOk();

    expect($response1->json())->toBe($response2->json());

    // Invalidate cache (simulates document upload)
    TaxDocumentIntelligenceService::invalidateCache($this->user->id, 2025);

    // Verify cache key was cleared
    expect(Cache::has("tax_intelligence_{$this->user->id}_2025"))->toBeFalse();
});

// ─── Vault Endpoint Coverage Tests ───

it('lists documents for authenticated user', function () {
    createIntelDocument($this->user->id, ['tax_year' => 2025]);
    createIntelDocument($this->user->id, ['tax_year' => 2025]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tax-vault/documents?year=2025');

    $response->assertOk();
    // response()->json(ResourceCollection) serializes to flat array
    $json = $response->json();
    $data = $json['data'] ?? $json;
    expect(count($data))->toBe(2);
});

it('uploads a document', function () {
    Storage::fake('local');
    Queue::fake();

    $file = \Illuminate\Http\UploadedFile::fake()->create('w2-test.pdf', 100, 'application/pdf');

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/tax-vault/documents', [
            'file' => $file,
            'tax_year' => 2025,
        ]);

    $response->assertCreated();
    $json = $response->json();
    $filename = $json['data']['original_filename'] ?? $json['original_filename'] ?? null;
    expect($filename)->toBe('w2-test.pdf');
});

it('shows a single document', function () {
    $doc = createIntelDocument($this->user->id);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/tax-vault/documents/{$doc->id}");

    $response->assertOk();
    // Single resource may be wrapped in 'data' or returned flat
    $id = $response->json('data.id') ?? $response->json('id');
    expect($id)->toBe($doc->id);
});

it('downloads a document via signed URL', function () {
    Storage::disk('local')->put('tax-vault/test/doc.pdf', 'fake-content');
    $doc = createIntelDocument($this->user->id);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/tax-vault/documents/{$doc->id}/download");

    $response->assertOk();
    expect($response->json('url'))->not->toBeNull();
});

it('deletes a document', function () {
    $doc = createIntelDocument($this->user->id);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/tax-vault/documents/{$doc->id}");

    $response->assertNoContent();
    expect(TaxDocument::find($doc->id))->toBeNull();
    expect(TaxDocument::withTrashed()->find($doc->id))->not->toBeNull();
});

it('prevents access to another user documents', function () {
    $otherUser = User::factory()->create();
    $doc = createIntelDocument($otherUser->id);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/tax-vault/documents/{$doc->id}");

    $response->assertForbidden();
});
