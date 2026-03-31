<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequestRequest;
use App\Models\AccountantClient;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DocumentRequestController extends Controller
{
    /**
     * List all document requests for a client from the accountant's firm.
     */
    public function index(Request $request, User $client): JsonResponse
    {
        $accountant = $request->user();

        $this->verifyAccountantClientLink($accountant, $client);

        $requests = DocumentRequest::where('client_id', $client->id)
            ->where('accounting_firm_id', $accountant->accounting_firm_id)
            ->with(['accountant:id,name,email', 'fulfilledDocument:id,original_filename'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($requests);
    }

    /**
     * Create a new document request for a client.
     */
    public function store(StoreDocumentRequestRequest $request, User $client): JsonResponse
    {
        $accountant = $request->user();

        $this->verifyAccountantClientLink($accountant, $client);

        $validated = $request->validated();

        $docRequest = DocumentRequest::create([
            'accounting_firm_id' => $accountant->accounting_firm_id,
            'accountant_id' => $accountant->id,
            'client_id' => $client->id,
            'description' => $validated['description'],
            'tax_year' => $validated['tax_year'] ?? null,
            'category' => $validated['category'] ?? null,
            'status' => DocumentRequestStatus::Pending,
        ]);

        // Notify client
        if (class_exists(\App\Mail\DocumentRequestMail::class)) {
            Mail::to($client)->queue(new \App\Mail\DocumentRequestMail($docRequest, $accountant));
        }

        return response()->json($docRequest->load('accountant:id,name,email'), 201);
    }

    /**
     * Dismiss a document request (accountant action).
     */
    public function dismiss(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $accountant = $request->user();

        if ($documentRequest->accounting_firm_id !== $accountant->accounting_firm_id) {
            abort(403, 'You do not have access to this request.');
        }

        $documentRequest->update(['status' => DocumentRequestStatus::Dismissed]);

        return response()->json(['message' => 'Request dismissed.']);
    }

    /**
     * List pending document requests for the authenticated client.
     */
    public function myRequests(Request $request): JsonResponse
    {
        $user = $request->user();

        $requests = DocumentRequest::where('client_id', $user->id)
            ->pending()
            ->with(['accountant:id,name,email'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($requests);
    }

    /**
     * Verify the accountant is linked to the client.
     */
    private function verifyAccountantClientLink(User $accountant, User $client): void
    {
        $exists = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            abort(403, 'You do not have access to this client.');
        }
    }
}
