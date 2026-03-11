<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $consentCookie = $request->cookie(config('spendifiai.consent.cookie_name', 'sw_consent'));
        $consentData = $consentCookie ? json_decode($consentCookie, true) : null;
        $consentVersion = config('spendifiai.consent.version', '1.0');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'hasBankConnected' => $request->user()?->hasBankConnected() ?? false,
                'hasEmailConnected' => $request->user()?->hasEmailConnected() ?? false,
                'isAdmin' => $request->user()?->isAdmin() ?? false,
                'isAccountant' => $request->user()?->isAccountant() ?? false,
                'userType' => $request->user()?->user_type?->value ?? 'personal',
                'companyName' => $request->user()?->company_name,
                'isImpersonating' => str_starts_with($request->user()?->currentAccessToken()?->name ?? '', 'impersonate:'),
                'impersonatingAccountantId' => (function () use ($request) {
                    $tokenName = $request->user()?->currentAccessToken()?->name ?? '';
                    if (str_starts_with($tokenName, 'impersonate:')) {
                        return (int) str_replace('impersonate:', '', $tokenName);
                    }

                    return null;
                })(),
                'timezone' => $request->user()?->timezone ?? 'America/New_York',
            ],
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'plaid_env' => config('services.plaid.env', 'sandbox'),
            'consent' => [
                'has_consent' => $consentData && ($consentData['v'] ?? null) === $consentVersion,
                'analytics' => (bool) ($consentData['a'] ?? false),
                'marketing' => (bool) ($consentData['m'] ?? false),
                'version' => $consentVersion,
                'gtm_id' => config('spendifiai.consent.gtm_container_id') ?: null,
                'ga4_id' => config('spendifiai.consent.ga4_measurement_id') ?: null,
            ],
        ];
    }
}
