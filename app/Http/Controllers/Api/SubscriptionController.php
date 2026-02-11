<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionDetectorService;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionDetectorService $detector,
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
            'subscriptions'  => SubscriptionResource::collection($subs),
            'total_monthly'  => $subs->where('status', 'active')->sum('amount'),
            'total_annual'   => $subs->where('status', 'active')->sum('annual_cost'),
            'unused_monthly' => $subs->where('status', 'unused')->sum('amount'),
            'unused_count'   => $subs->where('status', 'unused')->count(),
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
}
