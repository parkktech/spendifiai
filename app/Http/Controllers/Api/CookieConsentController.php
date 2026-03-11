<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CookieConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CookieConsentController extends Controller
{
    public function __construct(
        private CookieConsentService $consentService
    ) {}

    /**
     * Get consent configuration + region detection.
     *
     * GET /api/v1/consent/config
     */
    public function config(Request $request): JsonResponse
    {
        $region = $this->consentService->detectRegion($request);
        $visitorId = $request->cookie(config('spendifiai.consent.visitor_cookie', 'sw_visitor_id'));
        $currentConsent = $visitorId ? $this->consentService->getCurrentConsent($visitorId) : null;

        return response()->json([
            'region' => $region->value,
            'region_label' => $region->label(),
            'requires_opt_in' => $region->requiresExplicitOptIn(),
            'requires_opt_out_notice' => $region->requiresOptOutNotice(),
            'consent_version' => config('spendifiai.consent.version', '1.0'),
            'has_consent' => $currentConsent && ! $this->consentService->needsReconsent($currentConsent),
            'current_preferences' => $currentConsent ? [
                'analytics' => $currentConsent->analytics,
                'marketing' => $currentConsent->marketing,
            ] : null,
        ]);
    }

    /**
     * Record consent choice (anonymous or authenticated).
     *
     * POST /api/v1/consent
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'analytics' => 'required|boolean',
            'marketing' => 'required|boolean',
            'region' => 'required|string|in:eu,california,other',
        ]);

        $visitorCookieName = config('spendifiai.consent.visitor_cookie', 'sw_visitor_id');
        $visitorId = $request->cookie($visitorCookieName) ?? Str::uuid()->toString();
        $userId = $request->user()?->id;

        $region = \App\Enums\ConsentRegion::from($validated['region']);

        $consent = $this->consentService->recordConsent(
            visitorId: $visitorId,
            userId: $userId,
            region: $region,
            analytics: $validated['analytics'],
            marketing: $validated['marketing'],
            action: 'grant',
            request: $request,
        );

        $cookieLifetimeDays = config('spendifiai.consent.cookie_lifetime_days', 365);
        $consentCookieName = config('spendifiai.consent.cookie_name', 'sw_consent');

        $consentCookieValue = json_encode([
            'v' => config('spendifiai.consent.version', '1.0'),
            'n' => true,
            'a' => $validated['analytics'],
            'm' => $validated['marketing'],
            't' => time(),
        ]);

        return response()->json([
            'message' => 'Consent recorded.',
            'consent' => [
                'analytics' => $consent->analytics,
                'marketing' => $consent->marketing,
                'version' => $consent->consent_version,
            ],
        ])->withCookie(cookie(
            $visitorCookieName,
            $visitorId,
            $cookieLifetimeDays * 1440,
            '/',
            null,
            true,
            false,
            false,
            'Lax',
        ))->withCookie(cookie(
            $consentCookieName,
            $consentCookieValue,
            $cookieLifetimeDays * 1440,
            '/',
            null,
            true,
            false,
            false,
            'Lax',
        ));
    }

    /**
     * Get authenticated user's current consent preferences.
     *
     * GET /api/v1/consent/preferences
     */
    public function preferences(Request $request): JsonResponse
    {
        $consent = $this->consentService->getUserConsent($request->user()->id);

        if (! $consent) {
            return response()->json([
                'has_consent' => false,
                'preferences' => null,
            ]);
        }

        return response()->json([
            'has_consent' => true,
            'preferences' => [
                'analytics' => $consent->analytics,
                'marketing' => $consent->marketing,
                'version' => $consent->consent_version,
                'region' => $consent->region->value,
                'updated_at' => $consent->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update authenticated user's consent preferences.
     *
     * PUT /api/v1/consent/preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'analytics' => 'required|boolean',
            'marketing' => 'required|boolean',
        ]);

        $user = $request->user();
        $currentConsent = $this->consentService->getUserConsent($user->id);
        $visitorId = $currentConsent?->visitor_id
            ?? $request->cookie(config('spendifiai.consent.visitor_cookie', 'sw_visitor_id'))
            ?? Str::uuid()->toString();
        $region = $currentConsent?->region ?? $this->consentService->detectRegion($request);

        $consent = $this->consentService->recordConsent(
            visitorId: $visitorId,
            userId: $user->id,
            region: $region,
            analytics: $validated['analytics'],
            marketing: $validated['marketing'],
            action: 'update',
            request: $request,
        );

        $consentCookieName = config('spendifiai.consent.cookie_name', 'sw_consent');
        $cookieLifetimeDays = config('spendifiai.consent.cookie_lifetime_days', 365);

        $consentCookieValue = json_encode([
            'v' => config('spendifiai.consent.version', '1.0'),
            'n' => true,
            'a' => $validated['analytics'],
            'm' => $validated['marketing'],
            't' => time(),
        ]);

        return response()->json([
            'message' => 'Preferences updated.',
            'preferences' => [
                'analytics' => $consent->analytics,
                'marketing' => $consent->marketing,
            ],
        ])->withCookie(cookie(
            $consentCookieName,
            $consentCookieValue,
            $cookieLifetimeDays * 1440,
            '/',
            null,
            true,
            false,
            false,
            'Lax',
        ));
    }

    /**
     * Revoke all consent (analytics + marketing = false).
     *
     * DELETE /api/v1/consent/preferences
     */
    public function revokeConsent(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentConsent = $this->consentService->getUserConsent($user->id);
        $visitorId = $currentConsent?->visitor_id
            ?? $request->cookie(config('spendifiai.consent.visitor_cookie', 'sw_visitor_id'))
            ?? Str::uuid()->toString();
        $region = $currentConsent?->region ?? $this->consentService->detectRegion($request);

        $this->consentService->recordConsent(
            visitorId: $visitorId,
            userId: $user->id,
            region: $region,
            analytics: false,
            marketing: false,
            action: 'revoke',
            request: $request,
        );

        $consentCookieName = config('spendifiai.consent.cookie_name', 'sw_consent');
        $cookieLifetimeDays = config('spendifiai.consent.cookie_lifetime_days', 365);

        $consentCookieValue = json_encode([
            'v' => config('spendifiai.consent.version', '1.0'),
            'n' => true,
            'a' => false,
            'm' => false,
            't' => time(),
        ]);

        return response()->json([
            'message' => 'Consent revoked.',
        ])->withCookie(cookie(
            $consentCookieName,
            $consentCookieValue,
            $cookieLifetimeDays * 1440,
            '/',
            null,
            true,
            false,
            false,
            'Lax',
        ));
    }
}
