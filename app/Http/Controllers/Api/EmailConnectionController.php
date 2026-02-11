<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailConnectionController extends Controller
{
    /**
     * Initiate email connection flow for a given provider.
     * Full implementation comes in Phase 3 (email parsing pipeline).
     */
    public function connect(Request $request, string $provider): JsonResponse
    {
        return response()->json([
            'message' => 'Email connection feature coming soon',
            'status'  => 'not_implemented',
        ], 501);
    }

    /**
     * OAuth callback for email provider.
     * Full implementation comes in Phase 3.
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        return response()->json([
            'message' => 'Email connection feature coming soon',
            'status'  => 'not_implemented',
        ], 501);
    }

    /**
     * Sync emails from connected provider.
     * Full implementation comes in Phase 3.
     */
    public function sync(): JsonResponse
    {
        return response()->json([
            'message' => 'Email sync feature coming soon',
            'status'  => 'not_implemented',
        ], 501);
    }

    /**
     * Disconnect an email connection.
     * Full implementation comes in Phase 3.
     */
    public function disconnect(string $connection): JsonResponse
    {
        return response()->json([
            'message' => 'Email disconnection feature coming soon',
            'status'  => 'not_implemented',
        ], 501);
    }
}
