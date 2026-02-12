<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOrderEmails;
use App\Models\EmailConnection;
use App\Services\Email\GmailService;
use App\Services\Email\ImapEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailConnectionController extends Controller
{
    public function __construct(
        private readonly GmailService $gmailService,
        private readonly ImapEmailService $imapService,
    ) {}

    /**
     * List user's email connections.
     */
    public function index(): JsonResponse
    {
        $connections = EmailConnection::where('user_id', auth()->id())
            ->select('id', 'provider', 'connection_type', 'email_address', 'status', 'last_synced_at', 'sync_status')
            ->get();

        return response()->json(['connections' => $connections]);
    }

    /**
     * Initiate OAuth email connection flow for a given provider.
     */
    public function connect(Request $request, string $provider): JsonResponse
    {
        if ($provider !== 'gmail') {
            return response()->json(['error' => 'For non-Gmail providers, use the IMAP connection endpoint.'], 400);
        }

        $authUrl = $this->gmailService->getAuthUrl();

        return response()->json(['auth_url' => $authUrl]);
    }

    /**
     * Connect via IMAP (works for any email provider).
     */
    public function connectImap(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'imap_host' => 'nullable|string',
            'imap_port' => 'nullable|integer',
            'imap_encryption' => 'nullable|in:ssl,tls',
        ]);

        try {
            $connection = $this->imapService->connect(
                userId: auth()->id(),
                email: $request->email,
                password: $request->password,
                host: $request->imap_host,
                port: $request->imap_port ? (int) $request->imap_port : null,
                encryption: $request->imap_encryption,
            );

            return response()->json([
                'message' => 'Email connected successfully via IMAP',
                'email' => $connection->email_address,
                'provider' => $connection->provider,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Test IMAP connection without saving.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'imap_host' => 'nullable|string',
            'imap_port' => 'nullable|integer',
            'imap_encryption' => 'nullable|in:ssl,tls',
        ]);

        $result = $this->imapService->testConnection(
            email: $request->email,
            password: $request->password,
            host: $request->imap_host,
            port: $request->imap_port ? (int) $request->imap_port : null,
            encryption: $request->imap_encryption,
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Get setup instructions for a provider.
     */
    public function setupInstructions(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $provider = $this->imapService->detectProvider($request->email);
        $settings = $this->imapService->detectSettings($request->email);
        $instructions = $this->imapService->getSetupInstructions($provider);

        return response()->json([
            'provider' => $provider,
            'settings' => $settings,
            'instructions' => $instructions,
        ]);
    }

    /**
     * OAuth callback for email provider.
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        if ($provider !== 'gmail') {
            return response()->json(['error' => 'Unsupported OAuth provider'], 400);
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
     */
    public function sync(Request $request): JsonResponse
    {
        $connectionId = $request->input('connection_id');

        $query = EmailConnection::where('user_id', auth()->id())
            ->where('sync_status', '!=', 'syncing');

        if ($connectionId) {
            $query->where('id', $connectionId);
        }

        $connection = $query->firstOrFail();

        ProcessOrderEmails::dispatch($connection);

        return response()->json(['message' => 'Email sync started. Orders will be processed in the background.']);
    }

    /**
     * Disconnect an email connection.
     */
    public function disconnect(string $emailConnection): JsonResponse
    {
        $emailConnection = EmailConnection::where('id', $emailConnection)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $emailConnection->delete();

        return response()->json(['message' => 'Email connection removed']);
    }
}
