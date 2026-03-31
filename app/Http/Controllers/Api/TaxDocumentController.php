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
use App\Services\AI\TaxDocumentIntelligenceService;
use App\Services\TaxVaultAuditService;
use App\Services\TaxVaultStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->taxDocuments()->latest();

        if ($request->has('year')) {
            $query->byYear((int) $request->query('year'));
        }

        return response()->json(
            TaxDocumentResource::collection($query->get()),
        );
    }

    /**
     * Upload a new tax document.
     */
    public function store(TaxDocumentUploadRequest $request): JsonResponse
    {
        $this->authorize('create', TaxDocument::class);

        $user = $request->user();
        $file = $request->file('file');
        $taxYear = (int) $request->validated('tax_year');

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
    public function show(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $this->auditService->log($document, $request->user(), 'view', $request);

        return response()->json(new TaxDocumentResource($document));
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
