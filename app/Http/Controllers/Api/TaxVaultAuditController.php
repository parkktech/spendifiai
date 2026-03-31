<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaxVaultAuditLogResource;
use App\Models\TaxDocument;
use App\Services\TaxVaultAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxVaultAuditController extends Controller
{
    public function __construct(
        private readonly TaxVaultAuditService $auditService,
    ) {}

    /**
     * Get audit log for a specific document.
     */
    public function index(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('viewAuditLog', $document);

        $logs = $this->auditService->getLogForDocument($document, $request->user());

        return response()->json(
            TaxVaultAuditLogResource::collection($logs),
        );
    }

    /**
     * Verify the hash chain integrity for a document's audit trail (admin only).
     */
    public function verifyChain(Request $request, TaxDocument $document): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $result = $this->auditService->verifyChain($document);

        return response()->json($result);
    }
}
