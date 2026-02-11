<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plaid Webhook Handler (stub).
 *
 * Full implementation in Phase 2, Plan 03.
 */
class PlaidWebhookController extends Controller
{
    /**
     * Handle incoming Plaid webhooks.
     *
     * POST /api/v1/webhooks/plaid
     */
    public function handle(Request $request): JsonResponse
    {
        // TODO: Implement in 02-03-PLAN (Plaid webhook handler)
        // - Verify Plaid webhook JWT signature
        // - Handle SYNC_UPDATES_AVAILABLE, ITEM_LOGIN_REQUIRED, PENDING_EXPIRATION, TRANSACTIONS_REMOVED
        // - Idempotency via webhook_id tracking

        return response()->json(['status' => 'received'], 200);
    }
}
