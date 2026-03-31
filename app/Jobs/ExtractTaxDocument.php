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
            // Oversized PDFs — too large for single-pass classification
            // Route to splitter which will break it into smaller parts
            if (
                str_contains($classification['error'], 'too long')
                && $document->mime_type === 'application/pdf'
            ) {
                Log::info('PDF too large for single-pass classification, routing to splitter', [
                    'document_id' => $document->id,
                    'filename' => $document->original_filename,
                    'error' => $classification['error'],
                ]);
                SplitMultiDocumentPdf::dispatch($document->id);

                return;
            }

            Log::error('Tax document classification failed', [
                'document_id' => $document->id,
                'error' => $classification['error'],
            ]);
            $document->update(['status' => DocumentStatus::Failed->value]);

            return;
        }

        $category = TaxDocumentCategory::tryFrom($classification['category']);
        $confidence = $classification['confidence'];
        $detectedYear = $classification['detected_tax_year'] ?? null;

        $updateData = [
            'category' => $category?->value,
            'classification_confidence' => $confidence,
        ];

        // Update tax_year if AI detected a different year from the document content
        if ($detectedYear !== null && $detectedYear !== $document->tax_year) {
            Log::info('Tax document year auto-corrected by AI', [
                'document_id' => $document->id,
                'original_year' => $document->tax_year,
                'detected_year' => $detectedYear,
            ]);
            $updateData['tax_year'] = $detectedYear;
        }

        $document->update($updateData);

        // Multi-document PDF detection — hand off to splitter
        if (
            ($classification['is_multi_document'] ?? false)
            && $document->mime_type === 'application/pdf'
        ) {
            Log::info('Multi-document PDF detected, dispatching split job', [
                'document_id' => $document->id,
                'filename' => $document->original_filename,
            ]);
            SplitMultiDocumentPdf::dispatch($document->id);

            return;
        }

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

        // Content-based duplicate detection (same employer/payer + SSN + category + year)
        $this->detectContentDuplicate($document, $extraction);

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

    /**
     * Detect content-level duplicates: same form type + issuer + recipient + year.
     * Flags the document metadata but does NOT delete — leaves it for the user.
     */
    private function detectContentDuplicate(TaxDocument $document, array $extraction): void
    {
        $fields = $extraction['fields'] ?? [];

        // Build a content fingerprint from key identifier fields
        $issuer = $fields['employer_ein']['value']
            ?? $fields['payer_tin']['value']
            ?? $fields['pse_tin']['value']
            ?? $fields['lender_tin']['value']
            ?? $fields['filer_tin']['value']
            ?? $fields['issuer_tin']['value']
            ?? null;

        $recipient = $fields['ssn_last4']['value'] ?? null;

        if (! $issuer || ! $recipient) {
            return;
        }

        // Find other ready docs with same category + year + issuer + recipient
        $duplicates = TaxDocument::where('user_id', $document->user_id)
            ->where('tax_year', $document->tax_year)
            ->where('category', $document->category?->value)
            ->where('status', DocumentStatus::Ready->value)
            ->where('id', '!=', $document->id)
            ->whereNotNull('extracted_data')
            ->get()
            ->filter(function (TaxDocument $other) use ($issuer, $recipient) {
                $of = $other->extracted_data['fields'] ?? [];
                $otherIssuer = $of['employer_ein']['value']
                    ?? $of['payer_tin']['value']
                    ?? $of['pse_tin']['value']
                    ?? $of['lender_tin']['value']
                    ?? $of['filer_tin']['value']
                    ?? $of['issuer_tin']['value']
                    ?? null;
                $otherRecipient = $of['ssn_last4']['value'] ?? null;

                return $otherIssuer === $issuer && $otherRecipient === $recipient;
            });

        if ($duplicates->isNotEmpty()) {
            Log::warning('Content-duplicate tax document detected', [
                'document_id' => $document->id,
                'duplicates_of' => $duplicates->pluck('id')->toArray(),
                'category' => $document->category?->value,
                'issuer_tin' => $issuer,
                'ssn_last4' => $recipient,
            ]);

            $document->update([
                'metadata' => array_merge($document->metadata ?? [], [
                    'possible_duplicate_of' => $duplicates->first()->id,
                    'duplicate_reason' => 'Same issuer TIN + recipient SSN + form type + tax year',
                ]),
            ]);
        }
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
