<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOnboardingPipeline;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    /**
     * Start the onboarding processing pipeline.
     *
     * POST /api/v1/onboarding/start
     */
    public function start(): JsonResponse
    {
        $user = auth()->user();

        // Don't dispatch if already completed
        if ($user->onboarding_completed_at) {
            return response()->json(['message' => 'Onboarding already completed'], 422);
        }

        ProcessOnboardingPipeline::dispatch($user->id);

        return response()->json(['message' => 'Onboarding pipeline started']);
    }

    /**
     * Mark onboarding as complete (e.g. when user skips steps and goes to dashboard).
     *
     * POST /api/v1/onboarding/complete
     */
    public function complete(): JsonResponse
    {
        $user = auth()->user();

        if (! $user->onboarding_completed_at) {
            $user->update(['onboarding_completed_at' => now()]);
        }

        return response()->json(['message' => 'Onboarding marked complete']);
    }
}
