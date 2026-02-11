<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\CategorizePendingTransactions;
use App\Models\BankConnection;
use App\Models\PlaidWebhookLog;
use App\Services\PlaidService;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaidWebhookController extends Controller
{
    public function __construct(
        private readonly PlaidService $plaidService,
    ) {}

    /**
     * Handle incoming Plaid webhooks.
     *
     * POST /api/v1/webhooks/plaid
     *
     * All responses return HTTP 200 to prevent Plaid from retrying.
     * Only signature verification failures return 401.
     */
    public function handle(Request $request): JsonResponse
    {
        $webhookType = $request->input('webhook_type', '');
        $webhookCode = $request->input('webhook_code', '');
        $itemId = $request->input('item_id', '');

        // 1. Verify JWT signature (skip in sandbox mode)
        if (config('services.plaid.env') !== 'sandbox') {
            if (!$this->verifyWebhookSignature($request)) {
                Log::warning('Plaid webhook signature verification failed', [
                    'webhook_type' => $webhookType,
                    'webhook_code' => $webhookCode,
                    'item_id' => $itemId,
                ]);

                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        // 2. Check idempotency: same item_id + webhook_code within 60 seconds
        if ($itemId && $webhookCode && $this->isDuplicate($itemId, $webhookCode)) {
            PlaidWebhookLog::create([
                'webhook_type' => $webhookType,
                'webhook_code' => $webhookCode,
                'item_id' => $itemId,
                'payload' => $request->all(),
                'status' => 'ignored',
                'processed_at' => now(),
            ]);

            return response()->json(['status' => 'duplicate']);
        }

        // 3. Look up BankConnection by plaid_item_id
        $connection = BankConnection::where('plaid_item_id', $itemId)->first();

        if (!$connection) {
            PlaidWebhookLog::create([
                'webhook_type' => $webhookType,
                'webhook_code' => $webhookCode,
                'item_id' => $itemId,
                'payload' => $request->all(),
                'status' => 'ignored',
                'processed_at' => now(),
            ]);

            Log::info('Plaid webhook received for unknown item', [
                'item_id' => $itemId,
                'webhook_type' => $webhookType,
                'webhook_code' => $webhookCode,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // 4. Log the webhook
        $log = PlaidWebhookLog::create([
            'webhook_type' => $webhookType,
            'webhook_code' => $webhookCode,
            'item_id' => $itemId,
            'payload' => $request->all(),
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        // 5. Route by webhook_type
        try {
            $result = match ($webhookType) {
                'TRANSACTIONS' => $this->handleTransactionWebhook($webhookCode, $connection),
                'ITEM' => $this->handleItemWebhook($webhookCode, $connection, $request),
                default => response()->json(['status' => 'unhandled']),
            };

            return $result;
        } catch (\Exception $e) {
            Log::error('Plaid webhook processing failed', [
                'webhook_type' => $webhookType,
                'webhook_code' => $webhookCode,
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            $log->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            // Still return 200 to prevent Plaid retries for handled webhooks
            return response()->json(['status' => 'error', 'message' => 'Processing failed']);
        }
    }

    /**
     * Handle TRANSACTIONS webhook types.
     */
    private function handleTransactionWebhook(string $webhookCode, BankConnection $connection): JsonResponse
    {
        return match ($webhookCode) {
            'SYNC_UPDATES_AVAILABLE',
            'DEFAULT_UPDATE',
            'INITIAL_UPDATE',
            'HISTORICAL_UPDATE' => $this->syncAndCategorize($connection),

            'TRANSACTIONS_REMOVED' => $this->syncAndCategorize($connection),

            default => response()->json(['status' => 'unhandled']),
        };
    }

    /**
     * Sync transactions and dispatch categorization if new transactions were added.
     */
    private function syncAndCategorize(BankConnection $connection): JsonResponse
    {
        $result = $this->plaidService->syncTransactions($connection);

        if (($result['added'] ?? 0) > 0) {
            CategorizePendingTransactions::dispatch($connection->user_id);
        }

        return response()->json([
            'status' => 'synced',
            'added' => $result['added'] ?? 0,
            'modified' => $result['modified'] ?? 0,
            'removed' => $result['removed'] ?? 0,
        ]);
    }

    /**
     * Handle ITEM webhook types.
     */
    private function handleItemWebhook(string $webhookCode, BankConnection $connection, Request $request): JsonResponse
    {
        return match ($webhookCode) {
            'ERROR' => $this->handleItemError($connection, $request),
            'PENDING_EXPIRATION', 'PENDING_DISCONNECT' => $this->handlePendingExpiration($connection, $webhookCode),
            'USER_PERMISSION_REVOKED' => $this->handlePermissionRevoked($connection),
            default => response()->json(['status' => 'unhandled']),
        };
    }

    /**
     * Handle ITEM ERROR webhook -- typically ITEM_LOGIN_REQUIRED.
     */
    private function handleItemError(BankConnection $connection, Request $request): JsonResponse
    {
        $error = $request->input('error', []);
        $errorCode = $error['error_code'] ?? 'UNKNOWN';
        $errorMessage = $error['display_message'] ?? $error['error_message'] ?? 'An error occurred with your bank connection';

        $connection->update([
            'status' => ConnectionStatus::Error,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        // TODO: Phase 4 - Notify user about connection error
        Log::warning('Plaid item error', [
            'connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        return response()->json(['status' => 'error_recorded']);
    }

    /**
     * Handle PENDING_EXPIRATION and PENDING_DISCONNECT webhooks.
     */
    private function handlePendingExpiration(BankConnection $connection, string $webhookCode): JsonResponse
    {
        $connection->update([
            'status' => ConnectionStatus::Error,
            'error_code' => $webhookCode,
            'error_message' => 'Bank connection requires re-authentication',
        ]);

        // TODO: Phase 4 - Notify user about pending expiration
        Log::info('Plaid connection pending expiration', [
            'connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'webhook_code' => $webhookCode,
        ]);

        return response()->json(['status' => 'expiration_recorded']);
    }

    /**
     * Handle USER_PERMISSION_REVOKED -- disconnect the bank connection.
     */
    private function handlePermissionRevoked(BankConnection $connection): JsonResponse
    {
        Log::info('Plaid user permission revoked, disconnecting', [
            'connection_id' => $connection->id,
            'user_id' => $connection->user_id,
        ]);

        $this->plaidService->disconnect($connection);

        return response()->json(['status' => 'disconnected']);
    }

    /**
     * Verify Plaid webhook JWT signature (ES256).
     *
     * @see https://plaid.com/docs/api/webhooks/webhook-verification/
     */
    private function verifyWebhookSignature(Request $request): bool
    {
        try {
            $signedJwt = $request->header('Plaid-Verification');

            if (!$signedJwt) {
                Log::warning('Plaid webhook missing Plaid-Verification header');
                return false;
            }

            // Decode JWT header to get kid and verify algorithm
            $tks = explode('.', $signedJwt);
            if (count($tks) !== 3) {
                Log::warning('Plaid webhook JWT has invalid format');
                return false;
            }

            $headerJson = json_decode(JWT::urlsafeB64Decode($tks[0]), true);
            if (!$headerJson || ($headerJson['alg'] ?? '') !== 'ES256') {
                Log::warning('Plaid webhook JWT has invalid algorithm', [
                    'alg' => $headerJson['alg'] ?? 'missing',
                ]);
                return false;
            }

            $kid = $headerJson['kid'] ?? null;
            if (!$kid) {
                Log::warning('Plaid webhook JWT missing kid in header');
                return false;
            }

            // Fetch and cache JWK from Plaid
            $jwk = Cache::remember("plaid_webhook_key:{$kid}", 86400, function () use ($kid) {
                $baseUrl = config('spendwise.plaid.base_url');
                $response = Http::timeout(10)->post("{$baseUrl}/webhook_verification_key/get", [
                    'client_id' => config('services.plaid.client_id'),
                    'secret' => config('services.plaid.secret'),
                    'key_id' => $kid,
                ]);

                if (!$response->successful()) {
                    throw new \RuntimeException('Failed to fetch Plaid webhook verification key');
                }

                return $response->json('key');
            });

            // Parse JWK and verify JWT
            $key = JWK::parseKey($jwk, 'ES256');
            $decoded = JWT::decode($signedJwt, $key);

            // Check iat is within 5 minutes of current time
            if (abs(time() - ($decoded->iat ?? 0)) > 300) {
                Log::warning('Plaid webhook JWT iat out of range', [
                    'iat' => $decoded->iat ?? 'missing',
                    'now' => time(),
                ]);
                return false;
            }

            // Verify request body SHA-256
            $bodyHash = hash('sha256', $request->getContent());
            if (!hash_equals($decoded->request_body_sha256 ?? '', $bodyHash)) {
                Log::warning('Plaid webhook body hash mismatch');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::warning('Plaid webhook signature verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if a webhook with the same item_id and webhook_code was processed
     * within the last 60 seconds (idempotency).
     */
    private function isDuplicate(string $itemId, string $webhookCode): bool
    {
        return PlaidWebhookLog::where('item_id', $itemId)
            ->where('webhook_code', $webhookCode)
            ->where('status', 'processed')
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();
    }
}
