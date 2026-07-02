<?php

use App\Events\TaxDocumentExtracted;
use App\Jobs\ExtractProfileFacts;
use App\Jobs\ExtractTaxDocument;
use App\Models\TaxDocument;
use App\Models\User;
use App\Services\AI\TaxDocumentExtractorService;
use App\Services\TaxVaultAuditService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * DOC-03: ExtractTaxDocument ready-path fires TaxDocumentExtracted exactly once
 * and dispatches ExtractProfileFacts only for PayStub / BenefitsGuide categories.
 */
function makeDispatchTestDocument(int $userId, string $category = 'pay_stub'): TaxDocument
{
    Storage::fake('local');
    Storage::disk('local')->put("tax-vault/{$userId}/2025/{$category}/test.pdf", 'fake-content');

    return TaxDocument::create([
        'user_id'          => $userId,
        'original_filename' => "test-{$category}.pdf",
        'stored_path'      => "tax-vault/{$userId}/2025/{$category}/test.pdf",
        'disk'             => 'local',
        'mime_type'        => 'application/pdf',
        'file_size'        => 1024,
        'file_hash'        => hash('sha256', 'fake-content'),
        'tax_year'         => 2025,
        'status'           => 'upload',
    ]);
}

function mockReadyExtractor(string $category, array $fields = []): TaxDocumentExtractorService
{
    $extractor = Mockery::mock(TaxDocumentExtractorService::class);
    $extractor->shouldReceive('classify')->once()->andReturn([
        'category'        => $category,
        'confidence'      => 0.95,
        'is_multi_document' => false,
    ]);
    $extractor->shouldReceive('extract')->once()->andReturn([
        'fields'             => $fields ?: ['employer_name' => ['value' => 'Acme Corp', 'confidence' => 0.95]],
        'overall_confidence' => 0.92,
    ]);

    return $extractor;
}

function mockAudit(): TaxVaultAuditService
{
    $audit = Mockery::mock(TaxVaultAuditService::class);
    $audit->shouldReceive('log')->once();

    return $audit;
}

// ─── Ready-path event/dispatch assertions ────────────────────────────────────

it('fires TaxDocumentExtracted and dispatches ExtractProfileFacts for PayStub', function () {
    Event::fake();
    Queue::fake();

    $user     = User::factory()->create();
    $document = makeDispatchTestDocument($user->id, 'pay_stub');

    (new ExtractTaxDocument($document->id))->handle(
        mockReadyExtractor('pay_stub'),
        mockAudit(),
    );

    Event::assertDispatched(TaxDocumentExtracted::class, 1);
    Queue::assertPushed(ExtractProfileFacts::class, 1);
});

it('fires TaxDocumentExtracted and dispatches ExtractProfileFacts for BenefitsGuide', function () {
    Event::fake();
    Queue::fake();

    $user     = User::factory()->create();
    $document = makeDispatchTestDocument($user->id, 'benefits_guide');

    (new ExtractTaxDocument($document->id))->handle(
        mockReadyExtractor('benefits_guide', ['has_401k' => ['value' => 'true', 'confidence' => 0.9]]),
        mockAudit(),
    );

    Event::assertDispatched(TaxDocumentExtracted::class, 1);
    Queue::assertPushed(ExtractProfileFacts::class, 1);
});

it('fires TaxDocumentExtracted but does NOT dispatch ExtractProfileFacts for W2', function () {
    Event::fake();
    Queue::fake();

    $user     = User::factory()->create();
    $document = makeDispatchTestDocument($user->id, 'w2');

    (new ExtractTaxDocument($document->id))->handle(
        mockReadyExtractor('w2', ['wages' => ['value' => '72000.00', 'confidence' => 0.97]]),
        mockAudit(),
    );

    Event::assertDispatched(TaxDocumentExtracted::class, 1);
    Queue::assertNotPushed(ExtractProfileFacts::class);
});

// ─── Non-ready paths must NOT fire the event ─────────────────────────────────

it('does NOT fire TaxDocumentExtracted on classification error (splitter path)', function () {
    Event::fake();
    Queue::fake();

    $user     = User::factory()->create();
    $document = makeDispatchTestDocument($user->id, 'w2');

    $extractor = Mockery::mock(TaxDocumentExtractorService::class);
    $extractor->shouldReceive('classify')->once()->andReturn([
        'error' => 'PDF too large for single-pass classification, routing to splitter',
    ]);

    $audit = Mockery::mock(TaxVaultAuditService::class);

    (new ExtractTaxDocument($document->id))->handle($extractor, $audit);

    Event::assertNotDispatched(TaxDocumentExtracted::class);
    Queue::assertNotPushed(ExtractProfileFacts::class);
});

it('does NOT fire TaxDocumentExtracted when classification confidence is below gate', function () {
    Event::fake();
    Queue::fake();

    $user     = User::factory()->create();
    $document = makeDispatchTestDocument($user->id, 'w2');

    $extractor = Mockery::mock(TaxDocumentExtractorService::class);
    $extractor->shouldReceive('classify')->once()->andReturn([
        'category'          => 'w2',
        'confidence'        => 0.50,   // Below 0.70 gate
        'is_multi_document' => false,
    ]);

    $audit = Mockery::mock(TaxVaultAuditService::class);

    (new ExtractTaxDocument($document->id))->handle($extractor, $audit);

    Event::assertNotDispatched(TaxDocumentExtracted::class);
    Queue::assertNotPushed(ExtractProfileFacts::class);
});

it('does NOT fire TaxDocumentExtracted on extraction error', function () {
    Event::fake();
    Queue::fake();

    $user     = User::factory()->create();
    $document = makeDispatchTestDocument($user->id, 'w2');

    $extractor = Mockery::mock(TaxDocumentExtractorService::class);
    $extractor->shouldReceive('classify')->once()->andReturn([
        'category'          => 'w2',
        'confidence'        => 0.95,
        'is_multi_document' => false,
    ]);
    $extractor->shouldReceive('extract')->once()->andReturn([
        'error' => 'Claude API error',
    ]);

    $audit = Mockery::mock(TaxVaultAuditService::class);

    (new ExtractTaxDocument($document->id))->handle($extractor, $audit);

    Event::assertNotDispatched(TaxDocumentExtracted::class);
    Queue::assertNotPushed(ExtractProfileFacts::class);
});

// ─── Event carries correct document reference ─────────────────────────────────

it('TaxDocumentExtracted event carries the correct document', function () {
    Event::fake();
    Queue::fake();

    $user     = User::factory()->create();
    $document = makeDispatchTestDocument($user->id, 'pay_stub');

    (new ExtractTaxDocument($document->id))->handle(
        mockReadyExtractor('pay_stub'),
        mockAudit(),
    );

    Event::assertDispatched(TaxDocumentExtracted::class, function (TaxDocumentExtracted $event) use ($document) {
        return $event->document->id === $document->id
            && $event->document->user_id === $document->user_id
            && $event->document->tax_year === $document->tax_year;
    });
});
