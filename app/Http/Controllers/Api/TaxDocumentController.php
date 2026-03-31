<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaxDocumentUploadRequest;
use App\Http\Resources\TaxDocumentResource;
use App\Models\TaxDocument;
use App\Services\TaxVaultAuditService;
use App\Services\TaxVaultStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxDocumentController extends Controller
{
    public function __construct(
        private readonly TaxVaultStorageService $storageService,
        private readonly TaxVaultAuditService $auditService,
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

        return response()->json(
            new TaxDocumentResource($document),
            201,
        );
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
