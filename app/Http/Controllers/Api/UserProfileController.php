<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFinancialProfileRequest;
use App\Jobs\CategorizePendingTransactions;
use App\Models\UserFinancialProfile;
use Illuminate\Http\JsonResponse;

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
     */
    public function deleteAccount(): JsonResponse
    {
        $user = auth()->user();

        // Revoke all API tokens
        $user->tokens()->delete();

        // Delete the user (cascading deletes handle related data)
        $user->delete();

        return response()->json(null, 204);
    }
}
