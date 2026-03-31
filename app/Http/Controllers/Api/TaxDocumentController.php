<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaxDocumentUploadRequest;
use App\Http\Requests\UpdateExtractionFieldRequest;
use App\Http\Resources\TaxDocumentResource;
use App\Jobs\ExtractTaxDocument;
use App\Models\AccountantClient;
use App\Models\DocumentRequest;
use App\Models\TaxDocument;
use App\Models\Transaction;
use App\Services\AI\TaxDocumentIntelligenceService;
use App\Services\TaxVaultAuditService;
use App\Services\TaxVaultStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class TaxDocumentController extends Controller
{
    public function __construct(
        private readonly TaxVaultStorageService $storageService,
        private readonly TaxVaultAuditService $auditService,
        private readonly TaxDocumentIntelligenceService $intelligenceService,
    ) {}

    /**
     * List user's tax documents, optionally filtered by year.
     */
    public function index(Request $request)
    {
        $query = $request->user()->taxDocuments()->latest();

        if ($request->has('year')) {
            $query->byYear((int) $request->query('year'));
        }

        return TaxDocumentResource::collection($query->get());
    }

    /**
     * Bulk download all documents for a given year as a ZIP file.
     */
    public function downloadYear(Request $request, int $year)
    {
        $user = $request->user();

        // Include all docs that have files stored — exclude only 'split' parent shells
        $docs = $user->taxDocuments()
            ->where('tax_year', $year)
            ->where('status', '!=', DocumentStatus::Split->value)
            ->get();

        if ($docs->isEmpty()) {
            return response()->json(['error' => 'No documents found for this year'], 404);
        }

        $zipName = "TaxDocuments_{$year}_{$user->name}.zip";
        $tempPath = sys_get_temp_dir().'/'.uniqid('tax_zip_').'.zip';

        $zip = new \ZipArchive;
        if ($zip->open($tempPath, \ZipArchive::CREATE) !== true) {
            return response()->json(['error' => 'Could not create ZIP file'], 500);
        }

        foreach ($docs as $doc) {
            $disk = Storage::disk($doc->disk);
            $contents = $disk->get($doc->stored_path);
            if (! $contents) {
                continue;
            }

            // Organize by category folder
            $categoryFolder = $doc->category?->label() ?? 'Other';
            $filename = $doc->original_filename;

            $zip->addFromString("{$categoryFolder}/{$filename}", $contents);
        }

        $zip->close();

        $this->auditService->log($docs->first(), $user, 'bulk_download', $request, [
            'year' => $year,
            'document_count' => $docs->count(),
        ]);

        return response()->download($tempPath, $zipName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Upload a new tax document.
     */
    public function store(TaxDocumentUploadRequest $request): JsonResponse
    {
        $this->authorize('create', TaxDocument::class);

        $user = $request->user();
        $file = $request->file('file');
        $taxYear = $request->validated('tax_year')
            ? (int) $request->validated('tax_year')
            : (int) date('Y');

        // Check for duplicate by file hash before storing
        $fileHash = hash_file('sha256', $file->getRealPath());
        $existing = $user->taxDocuments()
            ->where('file_hash', $fileHash)
            ->first();

        if ($existing) {
            Log::info('Tax document upload rejected as duplicate', [
                'user_id' => $user->id,
                'filename' => $file->getClientOriginalName(),
                'file_hash' => $fileHash,
                'duplicate_of' => $existing->id,
                'existing_category' => $existing->category?->value,
                'existing_year' => $existing->tax_year,
            ]);

            return response()->json([
                'message' => 'This document has already been uploaded.',
                'duplicate_of' => new TaxDocumentResource($existing),
            ], 409);
        }

        $storageData = $this->storageService->store($file, $user->id, $taxYear);

        $document = TaxDocument::create([
            'user_id' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storageData['stored_path'],
            'disk' => $storageData['disk'],
            'mime_type' => $storageData['mime_type'],
            'file_size' => $storageData['file_size'],
            'file_hash' => $storageData['file_hash'],
            'tax_year' => $taxYear,
            'status' => 'upload',
        ]);

        $this->auditService->log($document, $user, 'upload', $request, [
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $storageData['file_size'],
            'mime_type' => $storageData['mime_type'],
        ]);

        ExtractTaxDocument::dispatch($document->id);

        // Invalidate intelligence cache for this tax year
        TaxDocumentIntelligenceService::invalidateCache($user->id, $taxYear);

        // Auto-fulfill matching pending document requests
        $this->autoFulfillRequests($document);

        // Notify linked accountants about the upload
        $this->notifyLinkedAccountants($document, $user);

        return response()->json(
            new TaxDocumentResource($document),
            201,
        );
    }

    /**
     * Auto-fulfill pending document requests that match the uploaded document.
     */
    private function autoFulfillRequests(TaxDocument $document): void
    {
        $pendingRequests = DocumentRequest::where('client_id', $document->user_id)
            ->pending()
            ->get();

        foreach ($pendingRequests as $request) {
            // If request specifies tax_year, it must match
            if ($request->tax_year !== null && $request->tax_year !== $document->tax_year) {
                continue;
            }

            // If request specifies category, it must match document category
            if ($request->category !== null && $request->category !== $document->category?->value) {
                continue;
            }

            $request->update([
                'status' => 'uploaded',
                'fulfilled_document_id' => $document->id,
            ]);

            // Notify the accountant that the request was fulfilled
            if (class_exists(\App\Mail\RequestFulfilledMail::class)) {
                $accountant = $request->accountant;
                if ($accountant) {
                    Mail::to($accountant)->queue(new \App\Mail\RequestFulfilledMail($request, $document));
                }
            }
        }
    }

    /**
     * Notify all linked accountants about a document upload.
     */
    private function notifyLinkedAccountants(TaxDocument $document, mixed $user): void
    {
        if (! class_exists(\App\Mail\DocumentUploadedMail::class)) {
            return;
        }

        $accountantLinks = AccountantClient::where('client_id', $user->id)
            ->where('status', 'active')
            ->with('accountant')
            ->get();

        foreach ($accountantLinks as $link) {
            if ($link->accountant) {
                Mail::to($link->accountant)->queue(new \App\Mail\DocumentUploadedMail($document, $user));
            }
        }
    }

    /**
     * Show a single tax document.
     */
    public function show(Request $request, TaxDocument $document)
    {
        $this->authorize('view', $document);

        $this->auditService->log($document, $request->user(), 'view', $request);

        return new TaxDocumentResource($document);
    }

    /**
     * Download a tax document via signed URL.
     */
    public function download(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $this->auditService->log($document, $request->user(), 'download', $request);

        return response()->json([
            'url' => $this->storageService->getSignedUrl($document),
        ]);
    }

    /**
     * Stream document content inline for in-browser viewing.
     */
    public function stream(Request $request, TaxDocument $document)
    {
        $this->authorize('view', $document);

        $disk = Storage::disk($document->disk);
        $contents = $disk->get($document->stored_path);

        if (! $contents) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response($contents, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.$document->original_filename.'"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    /**
     * Soft-delete a tax document (owner only).
     */
    public function destroy(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $this->auditService->log($document, $request->user(), 'delete', $request);

        $document->delete();

        return response()->json(null, 204);
    }

    /**
     * Correct a single extracted field value.
     */
    public function updateField(UpdateExtractionFieldRequest $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $validated = $request->validated();
        $data = $document->extracted_data ?? ['fields' => []];
        $oldValue = $data['fields'][$validated['field']]['value'] ?? null;

        $data['fields'][$validated['field']] = [
            'value' => $validated['value'],
            'confidence' => 1.0,
            'verified' => true,
        ];

        $document->update(['extracted_data' => $data]);

        $this->auditService->log($document, $request->user(), 'field_corrected', $request, [
            'field' => $validated['field'],
            'old_value' => $oldValue,
            'new_value' => $validated['value'],
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Mark all extracted fields as verified.
     */
    public function acceptAll(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $data = $document->extracted_data ?? ['fields' => []];

        foreach ($data['fields'] as $fieldName => &$field) {
            if (! ($field['verified'] ?? false)) {
                $field['verified'] = true;
            }
        }

        $document->update(['extracted_data' => $data]);

        $this->auditService->log($document, $request->user(), 'fields_accepted', $request);

        return response()->json(['success' => true]);
    }

    /**
     * Cross-reference intelligence: missing documents, anomalies, transaction links.
     */
    public function intelligence(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year', (string) now()->year));

        if ($year < 2000 || $year > 2099) {
            return response()->json(['error' => 'Year must be between 2000 and 2099'], 422);
        }

        $result = $this->intelligenceService->analyze($request->user()->id, $year);

        return response()->json($result);
    }

    /**
     * Link transactions to a tax document as related business expenses (e.g., contract labor for 1099-NEC).
     * Marks linked transactions as tax deductible with the specified category.
     */
    public function linkTransactions(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $validated = $request->validate([
            'transaction_ids' => 'required|array|min:1',
            'transaction_ids.*' => 'integer|exists:transactions,id',
            'link_reason' => 'required|string|max:100',
            'tax_category' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $linkedCount = 0;

        foreach ($validated['transaction_ids'] as $txId) {
            $tx = Transaction::where('id', $txId)->where('user_id', $user->id)->first();
            if (! $tx) {
                continue;
            }

            // Create or update the pivot link
            $document->transactions()->syncWithoutDetaching([
                $txId => ['link_reason' => $validated['link_reason']],
            ]);

            // Mark transaction as tax deductible + business expense
            $updateData = [
                'tax_deductible' => true,
                'expense_type' => 'business',
            ];

            // Set tax category if provided (e.g., "Contract Labor")
            if (! empty($validated['tax_category'])) {
                $updateData['tax_category'] = $validated['tax_category'];
            }

            $tx->update($updateData);
            $linkedCount++;
        }

        $this->auditService->log($document, $user, 'transactions_linked', $request, [
            'linked_count' => $linkedCount,
            'link_reason' => $validated['link_reason'],
            'transaction_ids' => $validated['transaction_ids'],
        ]);

        return response()->json([
            'success' => true,
            'linked' => $linkedCount,
            'message' => "Linked {$linkedCount} transaction(s) as {$validated['link_reason']}",
        ]);
    }

    /**
     * Get linked transactions for a tax document.
     */
    public function linkedTransactions(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $transactions = $document->transactions()
            ->select('transactions.id', 'merchant_name', 'amount', 'transaction_date', 'tax_category', 'description')
            ->orderBy('transaction_date', 'desc')
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->id,
                'date' => $tx->transaction_date->format('Y-m-d'),
                'merchant' => $tx->merchant_name,
                'amount' => (float) $tx->amount,
                'category' => $tx->tax_category,
                'description' => $tx->description,
                'link_reason' => $tx->pivot->link_reason,
            ]);

        return response()->json(['data' => $transactions]);
    }

    /**
     * Unlink a transaction from a tax document.
     */
    public function unlinkTransaction(Request $request, TaxDocument $document, Transaction $transaction): JsonResponse
    {
        $this->authorize('view', $document);

        $document->transactions()->detach($transaction->id);

        // Remove tax category if it was set by linking
        if ($transaction->tax_category === 'Contract Labor') {
            $transaction->update([
                'tax_deductible' => false,
                'tax_category' => null,
            ]);
        }

        $this->auditService->log($document, $request->user(), 'transaction_unlinked', $request, [
            'transaction_id' => $transaction->id,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Find candidate transactions that could be related to a tax document.
     * Searches by: document payer/issuer name, user query, wire/ACH/invoice keywords.
     */
    public function findRelatedExpenses(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $user = $request->user();
        $year = $document->tax_year;
        $search = $request->query('search', '');

        // Extract payer/issuer name from document for auto-matching
        $fields = $document->extracted_data['fields'] ?? [];
        $docSource = $fields['employer_name']['value']
            ?? $fields['payer_name']['value']
            ?? $fields['pse_name']['value']
            ?? $fields['lender_name']['value']
            ?? $fields['filer_name']['value']
            ?? $fields['issuer_name']['value']
            ?? '';

        // Already-linked IDs to exclude
        $linkedIds = $document->transactions()->pluck('transactions.id')->toArray();

        $query = Transaction::where('user_id', $user->id)
            ->where('amount', '>', 0)
            ->whereYear('transaction_date', $year);

        if (! empty($linkedIds)) {
            $query->whereNotIn('id', $linkedIds);
        }

        // Apply search filters
        $query->where(function ($q) use ($search, $docSource) {
            // Always include wire/invoice/contract keyword matches
            $q->where('merchant_name', 'like', '%WIRE%')
                ->orWhere('description', 'like', '%WIRE%')
                ->orWhere('description', 'like', '%CONTRACT%')
                ->orWhere('description', 'like', '%CONSULTANT%')
                ->orWhere('description', 'like', '%INVOICE%')
                ->orWhere('ai_category', 'like', '%Contract%')
                ->orWhere('tax_category', 'like', '%Contract%');

            // Match by document payer/issuer name
            if ($docSource) {
                // Use first significant word from source name
                $words = array_filter(explode(' ', $docSource), fn ($w) => strlen($w) > 3);
                foreach (array_slice($words, 0, 2) as $word) {
                    $q->orWhere('merchant_name', 'ilike', "%{$word}%")
                        ->orWhere('description', 'ilike', "%{$word}%");
                }
            }

            // User search query
            if ($search) {
                $q->orWhere('merchant_name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            }
        });

        $candidates = $query->orderBy('transaction_date', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->id,
                'date' => $tx->transaction_date->format('Y-m-d'),
                'merchant' => $tx->merchant_name,
                'amount' => (float) $tx->amount,
                'category' => $tx->ai_category,
                'description' => $tx->description,
                'already_deductible' => (bool) $tx->tax_deductible,
            ]);

        return response()->json(['data' => $candidates]);
    }

    /**
     * Retry extraction for a failed document.
     */
    public function retryExtraction(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        if ($document->status !== DocumentStatus::Failed) {
            return response()->json(['error' => 'Document is not in failed state'], 422);
        }

        $document->update(['status' => DocumentStatus::Upload->value]);

        ExtractTaxDocument::dispatch($document->id);

        return response()->json(['success' => true, 'message' => 'Extraction re-queued']);
    }

    /**
     * Retry extraction for all failed documents belonging to the user.
     */
    public function retryAllFailed(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = $request->query('year') ? (int) $request->query('year') : null;

        $query = $user->taxDocuments()->where('status', DocumentStatus::Failed->value);
        if ($year) {
            $query->where('tax_year', $year);
        }

        $failedDocs = $query->get();

        if ($failedDocs->isEmpty()) {
            return response()->json(['success' => true, 'retried' => 0, 'message' => 'No failed documents to retry']);
        }

        foreach ($failedDocs as $doc) {
            $doc->update(['status' => DocumentStatus::Upload->value]);
            ExtractTaxDocument::dispatch($doc->id);
        }

        return response()->json([
            'success' => true,
            'retried' => $failedDocs->count(),
            'message' => "Re-queued {$failedDocs->count()} document(s) for extraction",
        ]);
    }

    /**
     * Admin-only: permanently purge a soft-deleted document.
     * Removes the file from storage and force-deletes the database record.
     */
    public function purge(Request $request, int $document): JsonResponse
    {
        $this->authorize('purge', TaxDocument::class);

        $doc = TaxDocument::withTrashed()->findOrFail($document);

        $this->auditService->log($doc, $request->user(), 'purge', $request, [
            'original_filename' => $doc->original_filename,
            'file_hash' => $doc->file_hash,
        ]);

        $this->storageService->delete($doc);
        $doc->forceDelete();

        return response()->json(null, 204);
    }
}
