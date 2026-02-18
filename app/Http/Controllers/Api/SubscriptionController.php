<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RespondToSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\AI\AlternativeSuggestionService;
use App\Services\SavingsTrackingService;
use App\Services\SubscriptionDetectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionDetectorService $detector,
        private readonly AlternativeSuggestionService $alternativeService,
        private readonly SavingsTrackingService $trackingService,
    ) {}

    /**
     * List all detected subscriptions with summary stats.
     */
    public function index(): JsonResponse
    {
        $subs = Subscription::where('user_id', auth()->id())
            ->orderByDesc('amount')
            ->get();

        return response()->json([
            'subscriptions' => SubscriptionResource::collection($subs),
            'total_monthly' => $subs->where('status', 'active')->sum('amount'),
            'total_annual' => $subs->where('status', 'active')->sum('annual_cost'),
            'unused_monthly' => $subs->where('status', 'unused')->sum('amount'),
            'unused_count' => $subs->where('status', 'unused')->count(),
        ]);
    }

    /**
     * Trigger subscription detection for the authenticated user.
     */
    public function detect(): JsonResponse
    {
        $result = $this->detector->detectSubscriptions(auth()->id());

        return response()->json($result);
    }

    /**
     * Respond to a subscription (cancelled, reduced, or kept).
     */
    public function respond(RespondToSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $this->authorize('update', $subscription);

        $validated = $request->validated();
        $responseType = $validated['response_type'];
        $savings = 0;

        switch ($responseType) {
            case 'cancelled':
                $previousAmount = (float) $subscription->amount;
                $subscription->update([
                    'status' => 'cancelled',
                    'response_type' => 'cancelled',
                    'previous_amount' => $previousAmount,
                    'responded_at' => now(),
                ]);
                $savings = $previousAmount;
                break;

            case 'reduced':
                $previousAmount = (float) $subscription->amount;
                $newAmount = (float) $validated['new_amount'];
                $savings = max($previousAmount - $newAmount, 0);
                $subscription->update([
                    'response_type' => 'reduced',
                    'previous_amount' => $previousAmount,
                    'amount' => $newAmount,
                    'annual_cost' => round($newAmount * 12, 2),
                    'responded_at' => now(),
                ]);
                break;

            case 'kept':
                $subscription->update([
                    'response_type' => 'kept',
                    'response_reason' => $validated['reason'] ?? null,
                    'is_essential' => true,
                    'responded_at' => now(),
                ]);
                break;
        }

        // Record in savings ledger if there are actual savings
        if ($savings > 0) {
            $this->trackingService->recordSavings(
                userId: auth()->id(),
                sourceType: 'subscription',
                sourceId: $subscription->id,
                actionTaken: $responseType,
                monthlySavings: $savings,
                previousAmount: (float) $subscription->previous_amount,
                newAmount: $responseType === 'reduced' ? (float) $validated['new_amount'] : 0,
                notes: $subscription->merchant_name,
            );
        }

        // Clear dashboard cache
        $userId = auth()->id();
        Cache::forget("dashboard:{$userId}:all");
        Cache::forget("dashboard:{$userId}:personal");
        Cache::forget("dashboard:{$userId}:business");

        return response()->json([
            'subscription' => new SubscriptionResource($subscription->fresh()),
            'projected_savings' => $this->trackingService->getProjectedSavings($userId),
        ]);
    }

    /**
     * Dismiss a subscription (mark as "not a subscription").
     * Removes it from the user's subscription list.
     */
    public function dismiss(Subscription $subscription): JsonResponse
    {
        $this->authorize('update', $subscription);

        $subscription->delete();

        return response()->json(['message' => 'Subscription dismissed']);
    }

    /**
     * Get AI-generated alternatives for a subscription.
     */
    public function alternatives(Subscription $subscription): JsonResponse
    {
        $this->authorize('view', $subscription);

        $alternatives = $this->alternativeService->getSubscriptionAlternatives($subscription);

        return response()->json([
            'alternatives' => $alternatives,
        ]);
    }
}
