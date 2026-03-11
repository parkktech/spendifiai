<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CookieConsent;
use App\Models\User;
use App\Services\CookieConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentAdminController extends Controller
{
    public function __construct(
        private CookieConsentService $consentService
    ) {}

    /**
     * Aggregate consent statistics.
     *
     * GET /api/admin/consent/stats
     */
    public function stats(): JsonResponse
    {
        $totalUsers = User::count();

        $usersWithConsent = CookieConsent::whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        // Get latest consent per user for analytics/marketing counts
        $latestConsents = DB::table('cookie_consents as cc')
            ->whereNotNull('cc.user_id')
            ->whereRaw('cc.created_at = (SELECT MAX(cc2.created_at) FROM cookie_consents cc2 WHERE cc2.user_id = cc.user_id)')
            ->select('cc.analytics', 'cc.marketing', 'cc.region')
            ->get();

        $analyticsEnabled = $latestConsents->where('analytics', true)->count();
        $marketingEnabled = $latestConsents->where('marketing', true)->count();

        $regionBreakdown = $latestConsents->groupBy('region')->map->count();

        return response()->json([
            'total_users' => $totalUsers,
            'users_with_consent' => $usersWithConsent,
            'analytics_enabled' => $analyticsEnabled,
            'marketing_enabled' => $marketingEnabled,
            'region_breakdown' => $regionBreakdown,
        ]);
    }

    /**
     * Search users with consent status.
     *
     * GET /api/admin/consent/search?q=
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');

        $users = User::where(function ($q) use ($query) {
            $q->where('email', 'ilike', "%{$query}%")
                ->orWhere('name', 'ilike', "%{$query}%");
        })
            ->limit(25)
            ->get(['id', 'name', 'email', 'created_at']);

        $results = $users->map(function ($user) {
            $consent = $this->consentService->getUserConsent($user->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at->toIso8601String(),
                'consent' => $consent ? [
                    'analytics' => $consent->analytics,
                    'marketing' => $consent->marketing,
                    'region' => $consent->region->value,
                    'version' => $consent->consent_version,
                    'last_updated' => $consent->created_at->toIso8601String(),
                    'action' => $consent->action,
                ] : null,
            ];
        });

        return response()->json(['users' => $results]);
    }

    /**
     * Full consent audit trail for a user.
     *
     * GET /api/admin/consent/user/{user}/history
     */
    public function userHistory(User $user): JsonResponse
    {
        $history = $this->consentService->getAuditTrail($user->id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'history' => $history->map(fn ($consent) => [
                'id' => $consent->id,
                'action' => $consent->action,
                'analytics' => $consent->analytics,
                'marketing' => $consent->marketing,
                'region' => $consent->region->value,
                'version' => $consent->consent_version,
                'admin_user_id' => $consent->admin_user_id,
                'created_at' => $consent->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Admin revokes a user's consent.
     *
     * POST /api/admin/consent/user/{user}/revoke
     */
    public function revokeUserConsent(Request $request, User $user): JsonResponse
    {
        $this->consentService->adminRevokeConsent(
            targetUserId: $user->id,
            adminUserId: $request->user()->id,
            request: $request,
        );

        return response()->json([
            'message' => "Consent revoked for {$user->email}.",
        ]);
    }

    /**
     * Record a GDPR cookie data deletion request.
     *
     * DELETE /api/admin/consent/user/{user}/cookies
     */
    public function deleteCookieData(Request $request, User $user): JsonResponse
    {
        // Record the deletion action in audit trail
        $currentConsent = $this->consentService->getUserConsent($user->id);
        $visitorId = $currentConsent?->visitor_id ?? 'gdpr-deleted-'.$user->id;

        $this->consentService->recordConsent(
            visitorId: $visitorId,
            userId: $user->id,
            region: $currentConsent?->region ?? \App\Enums\ConsentRegion::Other,
            analytics: false,
            marketing: false,
            action: 'admin_override',
            request: $request,
            adminUserId: $request->user()->id,
        );

        return response()->json([
            'message' => "Cookie data deletion recorded for {$user->email}.",
        ]);
    }
}
