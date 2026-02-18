<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOrderEmails;
use App\Models\EmailConnection;
use App\Services\Email\GmailService;
use App\Services\Email\ImapEmailService;
use App\Services\Email\MicrosoftOutlookService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailConnectionController extends Controller
{
    public function __construct(
        private readonly GmailService $gmailService,
        private readonly ImapEmailService $imapService,
        private readonly MicrosoftOutlookService $microsoftService,
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
        if ($provider === 'gmail') {
            return response()->json(['auth_url' => $this->gmailService->getAuthUrl()]);
        }

        if ($provider === 'outlook') {
            if (! config('services.microsoft.client_id')) {
                return response()->json(['error' => 'Microsoft OAuth is not configured. Please add MICROSOFT_CLIENT_ID and MICROSOFT_CLIENT_SECRET to your .env file.'], 500);
            }

            return response()->json(['auth_url' => $this->microsoftService->getAuthUrl(auth()->id())]);
        }

        return response()->json(['error' => 'For this provider, use the IMAP connection endpoint.'], 400);
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
     * OAuth callback for email provider (authenticated — for Gmail API flow).
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        if ($provider === 'gmail') {
            $connection = $this->gmailService->handleCallback(auth()->id(), $request->code);

            return response()->json([
                'message' => 'Gmail connected successfully',
                'email' => $connection->email_address,
            ]);
        }

        return response()->json(['error' => 'Unsupported OAuth provider'], 400);
    }

    /**
     * Microsoft OAuth callback (public route — no auth:sanctum).
     *
     * User identity is verified via encrypted state parameter
     * that was set when the OAuth flow was initiated.
     */
    public function outlookCallback(Request $request): RedirectResponse
    {
        try {
            // Microsoft sends error params if user cancelled or denied
            if ($request->has('error')) {
                $error = $request->input('error_description', 'Authentication was cancelled or denied.');

                return redirect('/connect?email_error='.urlencode($error));
            }

            $request->validate([
                'code' => 'required|string',
                'state' => 'required|string',
            ]);

            $state = decrypt($request->input('state'));

            if (! isset($state['user_id']) || ! isset($state['expires_at'])) {
                return redirect('/connect?email_error='.urlencode('Invalid authentication state. Please try again.'));
            }

            if ($state['expires_at'] < now()->timestamp) {
                return redirect('/connect?email_error='.urlencode('Authentication expired. Please try again.'));
            }

            $connection = $this->microsoftService->handleCallback(
                $state['user_id'],
                $request->input('code')
            );

            return redirect('/connect?email_connected='.urlencode($connection->email_address));
        } catch (DecryptException) {
            return redirect('/connect?email_error='.urlencode('Invalid authentication state. Please try again.'));
        } catch (\RuntimeException $e) {
            return redirect('/connect?email_error='.urlencode($e->getMessage()));
        } catch (\Exception $e) {
            Log::error('Microsoft OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect('/connect?email_error='.urlencode('Authentication failed. Please try again.'));
        }
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
