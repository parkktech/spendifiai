<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFinancialProfileRequest;
use App\Jobs\CategorizePendingTransactions;
use App\Models\UserFinancialProfile;
use App\Services\PlaidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserProfileController extends Controller
{
    /**
     * Update or create the user's financial profile.
     */
    public function updateFinancial(UpdateFinancialProfileRequest $request): JsonResponse
    {
        $profile = UserFinancialProfile::updateOrCreate(
            ['user_id' => auth()->id()],
            $request->validated()
        );

        // Re-categorize all transactions with the updated profile context
        CategorizePendingTransactions::dispatch(auth()->id(), true);

        return response()->json([
            'message' => 'Profile updated',
            'profile' => $profile,
        ]);
    }

    /**
     * Show the user's financial profile.
     */
    public function showFinancial(): JsonResponse
    {
        $profile = UserFinancialProfile::where('user_id', auth()->id())->first();

        if (!$profile) {
            return response()->json([
                'message' => 'No financial profile set up yet.',
                'profile' => null,
            ]);
        }

        return response()->json([
            'profile' => $profile,
        ]);
    }

    /**
     * Delete the user's account and all associated data.
     * GDPR/CCPA compliance.
     *
     * Requires password confirmation for security.
     * Revokes all Plaid access tokens before cascading data deletion.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|current_password',
        ]);

        $user = auth()->user();

        // 1. Disconnect all bank connections (revokes Plaid access tokens)
        $plaidService = app(PlaidService::class);
        $user->bankConnections->each(function ($connection) use ($plaidService) {
            try {
                $plaidService->disconnect($connection);
            } catch (\Exception $e) {
                // Log but continue -- don't block account deletion if Plaid fails
                Log::warning('Failed to disconnect bank during account deletion', [
                    'user_id'       => $connection->user_id,
                    'connection_id' => $connection->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        });

        // 2. Revoke all API tokens
        $user->tokens()->delete();

        // 3. Delete the user (cascade deletes handle all related data via foreign keys)
        $user->delete();

        return response()->json(null, 204);
    }
}
