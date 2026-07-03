<?php

namespace App\Http\Middleware;

use App\Models\OptimizationChecklistItem;
use App\Models\OptimizationFinding;
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
     * Determine the current asset version — D24 Work 3: version-skew protection.
     *
     * Hashes public/build/manifest.json so that when a new deployment replaces
     * the build manifest, Inertia detects the version change on the next SPA
     * navigation and forces a hard page reload (pulling fresh assets).
     *
     * The parent::version() already does this (it hashes build/manifest.json
     * via hash_file('xxh128', ...)), so we delegate. This override exists for:
     *  1. Explicitness — future maintainers can see the contract.
     *  2. Testability — HandleInertiaRequestsTest can assert non-null version.
     *  3. The D24 audit trail.
     *
     * If asset_url is set (CDN deploy), hash that instead (parent behaviour).
     */
    public function version(Request $request): ?string
    {
        // Explicit manifest hash — keeps the derivation visible.
        $manifest = public_path('build/manifest.json');
        if (file_exists($manifest)) {
            return hash_file('xxh128', $manifest);
        }

        // Fall back to parent for CDN or mix-manifest scenarios.
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
                'onboardingPending' => $request->user()
                    ? (! $request->user()->onboarding_completed_at && ! $request->user()->hasBankConnected() && ! $request->user()->isAccountant())
                    : false,
                // Phase 12-04 (UI-01): nav badge count — pending optimization findings.
                // Guarded by hasBankConnected: count is 0 when no bank connected to avoid
                // unnecessary DB queries for unauthenticated or bank-less sessions.
                // SECURITY (T-12-04-04): scopeForUser() ensures cross-user isolation.
                // Counts only 'open' findings with a non-null description (narrated and ready to surface).
                'pendingOptimizationCount' => ($request->user()?->hasBankConnected())
                    ? OptimizationFinding::forUser($request->user()->id)
                        ->where('status', 'open')
                        ->whereNotNull('description')
                        ->count()
                    : 0,
                // Phase 14-09 (ACT-01): nav badge count — unchecked Action Center checklist items.
                // ADDITIVE: leaves pendingOptimizationCount byte-for-byte unchanged (DRIFT-09).
                // Guarded by hasBankConnected (same gate as pendingOptimizationCount) — 0 when no bank.
                // SECURITY (T-14-09-04): forUser() scope ensures cross-user isolation.
                'pendingActionCount' => ($request->user()?->hasBankConnected())
                    ? OptimizationChecklistItem::forUser($request->user()->id)
                        ->whereNull('done_at')
                        ->where('knob', '!=', 'header')
                        ->count()
                    : 0,
                'household' => $request->user()?->household_id ? [
                    'id' => $request->user()->household_id,
                    'name' => $request->user()->household?->name,
                    'role' => $request->user()->household_role,
                    'memberCount' => $request->user()->household?->members()->count() ?? 0,
                ] : null,
            ],
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'plaid_env' => config('services.plaid.env', 'sandbox'),
            // D24 Work 3: expose build version to the SPA so NewVersionToast can compare
            // against /api/v1/meta/build-version polls. Public value — not sensitive.
            'buildVersion' => (function (): ?string {
                $manifest = public_path('build/manifest.json');

                return file_exists($manifest) ? hash_file('xxh128', $manifest) : null;
            })(),
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
