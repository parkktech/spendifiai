<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use App\Services\AI\TaxDocumentExtractorService;
use App\Services\TaxVaultAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractTaxDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $documentId,
    ) {}

    public function handle(
        TaxDocumentExtractorService $extractor,
        TaxVaultAuditService $audit,
    ): void {
        $document = TaxDocument::findOrFail($this->documentId);

        Log::info('Tax document extraction starting', [
            'document_id' => $document->id,
            'filename' => $document->original_filename,
        ]);

        // Pass 1: Classification
        $document->update(['status' => DocumentStatus::Classifying->value]);

        $classification = $extractor->classify($document);

        if (isset($classification['error'])) {
            Log::error('Tax document classification failed', [
                'document_id' => $document->id,
                'error' => $classification['error'],
            ]);
            $document->update(['status' => DocumentStatus::Failed->value]);

            return;
        }

        $category = TaxDocumentCategory::tryFrom($classification['category']);
        $confidence = $classification['confidence'];

        $document->update([
            'category' => $category?->value,
            'classification_confidence' => $confidence,
        ]);

        // Confidence gate
        $gate = config('spendifiai.ai.extraction_thresholds.classification_gate', 0.70);
        if ($confidence < $gate) {
            Log::warning('Tax document classification below confidence gate', [
                'document_id' => $document->id,
                'confidence' => $confidence,
                'gate' => $gate,
                'category' => $classification['category'],
            ]);
            $document->update(['status' => DocumentStatus::Failed->value]);

            return;
        }

        // Pass 2: Extraction
        $document->update(['status' => DocumentStatus::Extracting->value]);

        $extraction = $extractor->extract($document);

        if (isset($extraction['error'])) {
            Log::error('Tax document extraction failed', [
                'document_id' => $document->id,
                'error' => $extraction['error'],
            ]);
            $document->update(['status' => DocumentStatus::Failed->value]);

            return;
        }

        $document->update([
            'extracted_data' => $extraction,
            'status' => DocumentStatus::Ready->value,
        ]);

        // Audit log
        $audit->log($document, $document->user, 'extraction_completed', null, [
            'category' => $category?->value,
            'overall_confidence' => $extraction['overall_confidence'] ?? null,
        ]);

        Log::info('Tax document extraction completed', [
            'document_id' => $document->id,
            'category' => $category?->value,
            'confidence' => $confidence,
            'fields_extracted' => count($extraction['fields'] ?? []),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Tax document extraction job failed permanently', [
            'document_id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $document = TaxDocument::find($this->documentId);
            $document?->update(['status' => DocumentStatus::Failed->value]);
        } catch (\Throwable $e) {
            Log::error('Could not update document status on failure', [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
