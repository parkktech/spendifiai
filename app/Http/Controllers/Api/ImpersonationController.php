<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountantActivityLog;
use App\Models\AccountantClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating a client.
     *
     * POST /api/v1/accountant/impersonate/{client}
     */
    public function start(Request $request, User $client): JsonResponse
    {
        $accountant = $request->user();

        // Verify active accountant-client relationship
        $exists = AccountantClient::where('accountant_id', $accountant->id)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'You do not have access to this client.'], 403);
        }

        // Create a Sanctum token for the client with a special name
        $tokenName = "impersonate:{$accountant->id}";
        $token = $client->createToken($tokenName)->plainTextToken;

        // Log the impersonation start
        AccountantActivityLog::create([
            'accountant_id' => $accountant->id,
            'client_id' => $client->id,
            'action' => 'impersonate_start',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'token' => $token,
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
            ],
        ]);
    }

    /**
     * Stop impersonating — called with the impersonation token.
     *
     * POST /api/v1/accountant/impersonate/stop
     */
    public function stop(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        $tokenName = $currentToken->name ?? '';

        if (! str_starts_with($tokenName, 'impersonate:')) {
            return response()->json(['message' => 'You are not currently impersonating anyone.'], 422);
        }

        // Extract accountant ID from token name
        $accountantId = (int) str_replace('impersonate:', '', $tokenName);

        // Log the impersonation end
        AccountantActivityLog::create([
            'accountant_id' => $accountantId,
            'client_id' => $user->id,
            'action' => 'impersonate_end',
            'ip_address' => $request->ip(),
        ]);

        // Delete the impersonation token
        $currentToken->delete();

        return response()->json(['message' => 'Impersonation ended.']);
    }

    /**
     * Check if currently impersonating.
     *
     * GET /api/v1/accountant/impersonate/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        $tokenName = $currentToken->name ?? '';

        $isImpersonating = str_starts_with($tokenName, 'impersonate:');
        $accountantId = null;
        $accountant = null;

        if ($isImpersonating) {
            $accountantId = (int) str_replace('impersonate:', '', $tokenName);
            $accountantUser = User::find($accountantId);
            if ($accountantUser) {
                $accountant = [
                    'id' => $accountantUser->id,
                    'name' => $accountantUser->name,
                ];
            }
        }

        return response()->json([
            'is_impersonating' => $isImpersonating,
            'accountant' => $accountant,
            'client' => $isImpersonating ? [
                'id' => $user->id,
                'name' => $user->name,
            ] : null,
        ]);
    }
}
