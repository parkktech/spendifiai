<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\TaxDocument;
use App\Services\AI\TaxDocumentExtractorService;
use App\Services\PdfSplitterService;
use App\Services\TaxVaultAuditService;
use App\Services\TaxVaultStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SplitMultiDocumentPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /** @var int[] */
    public array $backoff = [15, 60];

    public function __construct(
        public readonly int $documentId,
    ) {}

    public function handle(
        TaxDocumentExtractorService $extractor,
        PdfSplitterService $splitter,
        TaxVaultStorageService $storageService,
        TaxVaultAuditService $auditService,
    ): void {
        $document = TaxDocument::findOrFail($this->documentId);
        $user = $document->user;

        Log::info('Multi-document PDF split starting', [
            'document_id' => $document->id,
            'filename' => $document->original_filename,
        ]);

        $document->update(['status' => DocumentStatus::Splitting->value]);

        // Step 1: Detect document boundaries via AI
        $boundaries = $extractor->detectDocumentBoundaries($document);

        if (isset($boundaries['error'])) {
            Log::error('Multi-document boundary detection failed', [
                'document_id' => $document->id,
                'error' => $boundaries['error'],
            ]);
            $document->update(['status' => DocumentStatus::Failed->value]);

            return;
        }

        $docs = $boundaries['documents'];

        if (count($docs) < 2) {
            Log::info('PDF not actually multi-document, proceeding with normal extraction', [
                'document_id' => $document->id,
                'boundaries_found' => count($docs),
            ]);
            // Not actually multi-doc — revert to normal extraction
            $document->update(['status' => DocumentStatus::Upload->value]);
            ExtractTaxDocument::dispatch($document->id);

            return;
        }

        Log::info('Detected document boundaries', [
            'document_id' => $document->id,
            'documents_found' => count($docs),
            'boundaries' => $docs,
        ]);

        // Step 2: Get the source PDF from storage
        $disk = Storage::disk($document->disk);
        $pdfContents = $disk->get($document->stored_path);
        $tempSourcePath = sys_get_temp_dir().'/tax_source_'.uniqid().'.pdf';
        file_put_contents($tempSourcePath, $pdfContents);

        try {
            $createdDocIds = [];

            // Step 3: Split and create individual documents
            foreach ($docs as $idx => $boundary) {
                $tempSplitPath = $splitter->extractPages(
                    $tempSourcePath,
                    $boundary['page_start'],
                    $boundary['page_end'],
                );

                $taxYear = $boundary['tax_year'] ?? $document->tax_year;
                $pageLabel = $boundary['page_start'] === $boundary['page_end']
                    ? "p{$boundary['page_start']}"
                    : "p{$boundary['page_start']}-{$boundary['page_end']}";
                $description = $boundary['description'] ?: ($boundary['category'] ?? 'document');
                $splitFilename = pathinfo($document->original_filename, PATHINFO_FILENAME)
                    ."_{$pageLabel}_{$description}.pdf";
                // Sanitize filename
                $splitFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $splitFilename);

                // Check for duplicate hash
                $splitHash = hash_file('sha256', $tempSplitPath);
                $existingDupe = $user->taxDocuments()
                    ->where('file_hash', $splitHash)
                    ->first();

                if ($existingDupe) {
                    Log::info('Split page duplicate skipped', [
                        'parent_id' => $document->id,
                        'duplicate_of' => $existingDupe->id,
                        'pages' => "{$boundary['page_start']}-{$boundary['page_end']}",
                    ]);
                    @unlink($tempSplitPath);

                    continue;
                }

                // Store via UploadedFile wrapper
                $uploadedFile = new UploadedFile(
                    $tempSplitPath,
                    $splitFilename,
                    'application/pdf',
                    null,
                    true,
                );

                $storageData = $storageService->store($uploadedFile, $user->id, $taxYear);

                $childDoc = TaxDocument::create([
                    'user_id' => $user->id,
                    'original_filename' => $splitFilename,
                    'stored_path' => $storageData['stored_path'],
                    'disk' => $storageData['disk'],
                    'mime_type' => 'application/pdf',
                    'file_size' => $storageData['file_size'],
                    'file_hash' => $storageData['file_hash'],
                    'tax_year' => $taxYear,
                    'status' => 'upload',
                    'metadata' => [
                        'split_from' => $document->id,
                        'source_pages' => "{$boundary['page_start']}-{$boundary['page_end']}",
                        'ai_description' => $boundary['description'],
                    ],
                ]);

                $auditService->log($childDoc, $user, 'split_created', null, [
                    'parent_document_id' => $document->id,
                    'parent_filename' => $document->original_filename,
                    'pages' => "{$boundary['page_start']}-{$boundary['page_end']}",
                    'detected_category' => $boundary['category'],
                ]);

                $createdDocIds[] = $childDoc->id;

                // Dispatch extraction for each child
                ExtractTaxDocument::dispatch($childDoc->id);

                @unlink($tempSplitPath);

                Log::info('Split document created', [
                    'parent_id' => $document->id,
                    'child_id' => $childDoc->id,
                    'filename' => $splitFilename,
                    'pages' => "{$boundary['page_start']}-{$boundary['page_end']}",
                    'category_hint' => $boundary['category'],
                ]);
            }

            // Step 4: Mark the original as split (soft-delete)
            $auditService->log($document, $user, 'split_completed', null, [
                'child_document_ids' => $createdDocIds,
                'total_split' => count($createdDocIds),
            ]);

            $document->update([
                'status' => DocumentStatus::Split->value,
                'metadata' => array_merge($document->metadata ?? [], [
                    'split_into' => $createdDocIds,
                    'split_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::info('Multi-document PDF split completed', [
                'document_id' => $document->id,
                'children_created' => count($createdDocIds),
                'child_ids' => $createdDocIds,
            ]);

        } finally {
            @unlink($tempSourcePath);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Multi-document PDF split job failed', [
            'document_id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $document = TaxDocument::find($this->documentId);
            $document?->update(['status' => DocumentStatus::Failed->value]);
        } catch (\Throwable $e) {
            Log::error('Could not update document status on split failure', [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
