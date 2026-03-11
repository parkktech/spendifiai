<?php

namespace App\Services;

use App\Enums\ConsentRegion;
use App\Models\CookieConsent;
use Illuminate\Http\Request;

class CookieConsentService
{
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO',
        'CH', 'GB',
    ];

    public function detectRegion(Request $request): ConsentRegion
    {
        // CloudFlare header
        $country = $request->header('CF-IPCountry');

        // Fallback to other CDN headers
        if (! $country) {
            $country = $request->header('X-Country-Code')
                ?? $request->header('X-Geo-Country');
        }

        if (! $country) {
            return ConsentRegion::Other;
        }

        $country = strtoupper(trim($country));

        // EU/EEA/UK check
        $euCountries = config('spendifiai.consent.eu_countries', self::EU_COUNTRIES);
        if (in_array($country, $euCountries, true)) {
            return ConsentRegion::EU;
        }

        // California check (US + region header)
        if ($country === 'US') {
            $region = $request->header('CF-IPRegion')
                ?? $request->header('X-Region-Code');

            if ($region && strtoupper(trim($region)) === 'CA') {
                return ConsentRegion::California;
            }
        }

        return ConsentRegion::Other;
    }

    public function recordConsent(
        string $visitorId,
        ?int $userId,
        ConsentRegion $region,
        bool $analytics,
        bool $marketing,
        string $action,
        Request $request,
        ?int $adminUserId = null,
    ): CookieConsent {
        return CookieConsent::create([
            'visitor_id' => $visitorId,
            'user_id' => $userId,
            'consent_version' => config('spendifiai.consent.version', '1.0'),
            'region' => $region->value,
            'necessary' => true,
            'analytics' => $analytics,
            'marketing' => $marketing,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'action' => $action,
            'admin_user_id' => $adminUserId,
            'created_at' => now(),
        ]);
    }

    public function getCurrentConsent(string $visitorId): ?CookieConsent
    {
        return CookieConsent::latestForVisitor($visitorId)->first();
    }

    public function getUserConsent(int $userId): ?CookieConsent
    {
        return CookieConsent::latestForUser($userId)->first();
    }

    public function linkVisitorToUser(string $visitorId, int $userId): void
    {
        CookieConsent::where('visitor_id', $visitorId)
            ->whereNull('user_id')
            ->update(['user_id' => $userId]);
    }

    public function needsReconsent(?CookieConsent $consent): bool
    {
        if (! $consent) {
            return true;
        }

        return $consent->consent_version !== config('spendifiai.consent.version', '1.0');
    }

    public function getAuditTrail(int $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return CookieConsent::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function adminRevokeConsent(int $targetUserId, int $adminUserId, Request $request): CookieConsent
    {
        $currentConsent = $this->getUserConsent($targetUserId);
        $visitorId = $currentConsent?->visitor_id ?? 'admin-revoked-'.$targetUserId;

        return $this->recordConsent(
            visitorId: $visitorId,
            userId: $targetUserId,
            region: $currentConsent?->region ?? ConsentRegion::Other,
            analytics: false,
            marketing: false,
            action: 'admin_override',
            request: $request,
            adminUserId: $adminUserId,
        );
    }
}
