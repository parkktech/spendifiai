<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFirmRequest;
use App\Http\Requests\UpdateFirmRequest;
use App\Models\AccountingFirm;
use App\Models\DocumentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountantFirmController extends Controller
{
    /**
     * Create a new accounting firm.
     */
    public function store(StoreFirmRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->accounting_firm_id) {
            return response()->json(['message' => 'You already belong to a firm.'], 409);
        }

        $firm = AccountingFirm::create($request->validated());

        $user->update(['accounting_firm_id' => $firm->id]);

        return response()->json($firm->makeVisible('invite_token'), 201);
    }

    /**
     * Show the authenticated user's firm.
     */
    public function show(Request $request): JsonResponse
    {
        $firm = $request->user()->accountingFirm;

        if (! $firm) {
            return response()->json(['message' => 'No firm found.'], 404);
        }

        return response()->json($firm);
    }

    /**
     * Update the firm.
     */
    public function update(UpdateFirmRequest $request): JsonResponse
    {
        $firm = $request->user()->accountingFirm;

        $firm->update($request->validated());

        return response()->json($firm->fresh());
    }

    /**
     * Get the branded invite link for the firm.
     */
    public function inviteLink(Request $request): JsonResponse
    {
        $firm = $request->user()->accountingFirm;

        if (! $firm) {
            return response()->json(['message' => 'No firm found.'], 404);
        }

        $url = config('app.url').'/invite/'.$firm->invite_token;

        return response()->json([
            'invite_url' => $url,
            'token' => $firm->invite_token,
        ]);
    }

    /**
     * Regenerate the invite link token.
     */
    public function regenerateInviteLink(Request $request): JsonResponse
    {
        $firm = $request->user()->accountingFirm;

        if (! $firm) {
            return response()->json(['message' => 'No firm found.'], 404);
        }

        $firm->update(['invite_token' => Str::random(64)]);

        $url = config('app.url').'/invite/'.$firm->invite_token;

        return response()->json([
            'invite_url' => $url,
            'token' => $firm->invite_token,
        ]);
    }

    /**
     * Accountant dashboard with firm stats.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $firm = $user->accountingFirm;

        if (! $firm) {
            return response()->json(['message' => 'No firm found.'], 404);
        }

        $firm->load('members');

        $clients = $firm->members
            ->where('id', '!=', $user->id)
            ->values()
            ->map(function ($client) use ($firm) {
                $totalRequests = DocumentRequest::where('accounting_firm_id', $firm->id)
                    ->where('client_id', $client->id)
                    ->count();

                $fulfilledRequests = DocumentRequest::where('accounting_firm_id', $firm->id)
                    ->where('client_id', $client->id)
                    ->whereNotNull('fulfilled_document_id')
                    ->count();

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'total_requests' => $totalRequests,
                    'fulfilled_requests' => $fulfilledRequests,
                    'completeness' => $totalRequests > 0
                        ? round(($fulfilledRequests / $totalRequests) * 100)
                        : 0,
                ];
            });

        $openRequests = DocumentRequest::where('accounting_firm_id', $firm->id)
            ->pending()
            ->count();

        $taxDeadlines = collect(config('spendifiai.tax_deadlines', []))
            ->filter(fn ($deadline) => now()->lt($deadline['date']))
            ->sortBy('date')
            ->values();

        return response()->json([
            'firm' => $firm->makeHidden('members'),
            'total_clients' => $clients->count(),
            'documents_pending_review' => $openRequests,
            'open_requests' => $openRequests,
            'upcoming_deadlines' => $taxDeadlines,
            'clients' => $clients,
        ]);
    }
}
