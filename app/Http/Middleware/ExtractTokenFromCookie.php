<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExtractTokenFromCookie
{
    /**
     * Handle an incoming request.
     *
     * If no Authorization header is present, extract the auth_token from the
     * browser cookie and set it as a Bearer token for Sanctum.
     *
     * On authenticated responses, refresh the cookie to ensure persistence
     * across hard refreshes and browser sessions.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tokenFromCookie = null;

        if (! $request->bearerToken()) {
            $tokenFromCookie = $this->findValidToken($request);

            if ($tokenFromCookie) {
                $request->headers->set('Authorization', 'Bearer '.$tokenFromCookie);
            }
        }

        $response = $next($request);

        // Clear duplicate auth_token cookies (e.g. one on .domain and one on www.domain)
        // and consolidate to a single cookie on the bare domain
        $this->clearStaleCookies($request, $response, $tokenFromCookie);

        // Refresh the auth_token cookie on successful authenticated page loads
        if ($tokenFromCookie && $request->user() && $this->isPageRequest($request)) {
            $this->refreshCookie($response, $tokenFromCookie, $request);
        }

        return $response;
    }

    /**
     * Find a valid Sanctum token from the Cookie header.
     *
     * When duplicate auth_token cookies exist (e.g. one on .domain and one on www.domain),
     * the browser sends both. We parse all of them, validate each against Sanctum,
     * and return the one that actually authenticates.
     */
    protected function findValidToken(Request $request): ?string
    {
        // Parse ALL auth_token values from the raw Cookie header
        // (Laravel's $request->cookie() only returns one, which may be the stale one)
        $rawHeader = $request->header('Cookie', '');
        preg_match_all('/auth_token=([^;]+)/', $rawHeader, $matches);

        $candidates = [];
        foreach ($matches[1] ?? [] as $value) {
            $decoded = urldecode($value);
            if ($this->isValidSanctumToken($decoded)) {
                $candidates[] = $decoded;
            }
        }

        // If only one valid token, use it
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Multiple valid tokens — find the one that actually exists in the database
        // (the most recently created one is most likely correct)
        if (count($candidates) > 1) {
            foreach ($candidates as $candidate) {
                $id = explode('|', $candidate, 2)[0];
                if (\Laravel\Sanctum\PersonalAccessToken::find($id)) {
                    return $candidate;
                }
            }
        }

        // Fallback to Laravel's cookie accessor
        $cookie = $request->cookie('auth_token');
        if ($cookie && $this->isValidSanctumToken($cookie)) {
            return $cookie;
        }

        return null;
    }

    /**
     * Sanctum plain-text tokens are "id|hash" format.
     */
    protected function isValidSanctumToken(string $value): bool
    {
        return (bool) preg_match('/^\d+\|[A-Za-z0-9]+$/', $value);
    }

    /**
     * Check if this is a full page request (not AJAX/API).
     */
    protected function isPageRequest(Request $request): bool
    {
        return ! $request->ajax()
            && ! $request->wantsJson()
            && $request->isMethod('GET')
            && str_starts_with($request->getRequestUri(), '/');
    }

    /**
     * Refresh the auth_token cookie on the response to ensure persistence.
     * Sets on the bare domain (.spendifiai.com) to cover both www and non-www.
     */
    protected function refreshCookie(Response $response, string $token, Request $request): void
    {
        $host = $request->getHost();
        $bareDomain = preg_replace('/^www\./', '.', $host);
        $secure = $request->isSecure() ? '; secure' : '';
        $expires = gmdate('D, d M Y H:i:s T', time() + (30 * 24 * 60 * 60)); // 30 days

        $response->headers->set(
            'Set-Cookie',
            "auth_token={$token}; expires={$expires}; path=/; domain={$bareDomain}{$secure}; samesite=lax",
            false
        );
    }

    /**
     * Clear duplicate/stale auth_token cookies.
     *
     * Cookies set by JS without a domain are "host-only" — they can only be
     * cleared by setting the same cookie WITHOUT a domain attribute.
     * Cookies set with domain=.spendifiai.com need domain= to clear.
     * We clear BOTH patterns, then re-set a single cookie on the bare domain.
     */
    protected function clearStaleCookies(Request $request, Response $response, ?string $validToken): void
    {
        $rawHeader = $request->header('Cookie', '');
        preg_match_all('/auth_token=([^;]+)/', $rawHeader, $matches);

        $tokenCount = count($matches[1] ?? []);
        if ($tokenCount <= 1) {
            return;
        }

        $secure = $request->isSecure() ? '; secure' : '';
        $host = $request->getHost();
        $bareDomain = preg_replace('/^www\./', '.', $host);
        $expire = 'Thu, 01 Jan 1970 00:00:00 GMT';

        // 1. Clear host-only cookie (set WITHOUT domain — matches JS-set cookies on exact hostname)
        $response->headers->set('Set-Cookie',
            "auth_token=; expires={$expire}; path=/{$secure}; samesite=lax", false);

        // 2. Clear bare domain cookie (.spendifiai.com)
        $response->headers->set('Set-Cookie',
            "auth_token=; expires={$expire}; path=/; domain={$bareDomain}{$secure}; samesite=lax", false);

        // 3. Re-set the valid token on the bare domain only
        if ($validToken) {
            $expires = gmdate('D, d M Y H:i:s T', time() + (30 * 24 * 60 * 60));
            $response->headers->set('Set-Cookie',
                "auth_token={$validToken}; expires={$expires}; path=/; domain={$bareDomain}{$secure}; samesite=lax", false);
        }
    }
}
