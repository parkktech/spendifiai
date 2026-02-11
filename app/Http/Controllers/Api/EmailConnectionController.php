<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailConnection;
use App\Jobs\ProcessOrderEmails;
use App\Services\Email\GmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailConnectionController extends Controller
{
    public function __construct(
        private readonly GmailService $gmailService,
    ) {}

    /**
     * Initiate email connection flow for a given provider.
     * Returns the OAuth authorization URL for the user to visit.
     */
    public function connect(Request $request, string $provider): JsonResponse
    {
        if ($provider !== 'gmail') {
            return response()->json(['error' => 'Unsupported email provider. Only Gmail is supported.'], 400);
        }

        $authUrl = $this->gmailService->getAuthUrl();
        return response()->json(['auth_url' => $authUrl]);
    }

    /**
     * OAuth callback for email provider.
     * Exchanges the authorization code for tokens and stores the connection.
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        if ($provider !== 'gmail') {
            return response()->json(['error' => 'Unsupported email provider'], 400);
        }

        $request->validate(['code' => 'required|string']);

        $connection = $this->gmailService->handleCallback(auth()->id(), $request->code);

        return response()->json([
            'message' => 'Gmail connected successfully',
            'email' => $connection->email_address,
        ]);
    }

    /**
     * Sync emails from connected provider.
     * Dispatches background job, skipping connections that are mid-sync.
     */
    public function sync(): JsonResponse
    {
        $connection = EmailConnection::where('user_id', auth()->id())
            ->where('sync_status', '!=', 'syncing')
            ->firstOrFail();

        ProcessOrderEmails::dispatch($connection);

        return response()->json(['message' => 'Email sync started. Orders will be processed in the background.']);
    }

    /**
     * Disconnect an email connection.
     */
    public function disconnect(string $connection): JsonResponse
    {
        $emailConnection = EmailConnection::where('id', $connection)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $emailConnection->delete();

        return response()->json(['message' => 'Email connection removed']);
    }
}
