<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnotationRequest;
use App\Models\AccountantClient;
use App\Models\DocumentAnnotation;
use App\Models\TaxDocument;
use App\Services\TaxVaultAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DocumentAnnotationController extends Controller
{
    public function __construct(
        private readonly TaxVaultAuditService $auditService,
    ) {}

    /**
     * List top-level annotations with nested replies for a document.
     */
    public function index(Request $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $annotations = DocumentAnnotation::where('tax_document_id', $document->id)
            ->whereNull('parent_id')
            ->with(['author:id,name,email,user_type', 'replies.author:id,name,email,user_type'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($annotations);
    }

    /**
     * Create an annotation on a document.
     */
    public function store(StoreAnnotationRequest $request, TaxDocument $document): JsonResponse
    {
        $this->authorize('annotate', $document);

        $user = $request->user();
        $validated = $request->validated();

        // If parent_id provided, verify parent belongs to same document
        if (! empty($validated['parent_id'])) {
            $parent = DocumentAnnotation::where('id', $validated['parent_id'])
                ->where('tax_document_id', $document->id)
                ->first();

            if (! $parent) {
                return response()->json(['message' => 'Parent annotation does not belong to this document.'], 422);
            }
        }

        $annotation = DocumentAnnotation::create([
            'tax_document_id' => $document->id,
            'user_id' => $user->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'body' => $validated['body'],
        ]);

        // Notify the other party
        $this->notifyOtherParty($annotation, $document, $user);

        // Audit log
        $this->auditService->log($document, $user, 'annotation_created', $request, [
            'annotation_id' => $annotation->id,
            'parent_id' => $annotation->parent_id,
        ]);

        $annotation->load('author:id,name,email,user_type');

        return response()->json($annotation, 201);
    }

    /**
     * Notify the other party about a new annotation.
     */
    private function notifyOtherParty(DocumentAnnotation $annotation, TaxDocument $document, mixed $author): void
    {
        if ($author->isAccountant()) {
            // Notify document owner (client)
            $recipient = $document->user;
            if ($recipient && class_exists(\App\Mail\AnnotationNotifyMail::class)) {
                Mail::to($recipient)->queue(new \App\Mail\AnnotationNotifyMail($annotation, $recipient));
            }
        } else {
            // Notify all linked accountants
            $accountantLinks = AccountantClient::where('client_id', $author->id)
                ->where('status', 'active')
                ->with('accountant')
                ->get();

            foreach ($accountantLinks as $link) {
                if ($link->accountant && class_exists(\App\Mail\AnnotationNotifyMail::class)) {
                    Mail::to($link->accountant)->queue(new \App\Mail\AnnotationNotifyMail($annotation, $link->accountant));
                }
            }
        }
    }
}
