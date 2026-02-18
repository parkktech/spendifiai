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
     * Handles edge case where duplicate cookies exist (e.g. one on www.domain
     * and another on .domain) by parsing the raw Cookie header and finding
     * a valid Sanctum token (format: "number|hash").
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            $token = $this->findValidToken($request);

            if ($token) {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        $response = $next($request);

        // Clear any stale encrypted auth_token cookie on the bare domain
        // (legacy cookies from before the encryptCookies exclusion)
        $this->clearStaleCookie($request, $response);

        return $response;
    }

    /**
     * Find a valid Sanctum token from the Cookie header.
     *
     * Sanctum plain-text tokens look like "123|abcdef..." — if we find one,
     * use it. This handles the case where an old encrypted cookie coexists
     * with the correct plain-text one.
     */
    protected function findValidToken(Request $request): ?string
    {
        // Try the standard Laravel cookie accessor first
        $cookie = $request->cookie('auth_token');
        if ($cookie && $this->isValidSanctumToken($cookie)) {
            return $cookie;
        }

        // Fallback: parse the raw Cookie header for duplicate auth_token values
        $rawHeader = $request->header('Cookie', '');
        preg_match_all('/auth_token=([^;]+)/', $rawHeader, $matches);

        foreach ($matches[1] ?? [] as $value) {
            $decoded = urldecode($value);
            if ($this->isValidSanctumToken($decoded)) {
                return $decoded;
            }
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
     * If we detect a stale encrypted cookie on the bare domain, clear it.
     */
    protected function clearStaleCookie(Request $request, Response $response): void
    {
        $rawHeader = $request->header('Cookie', '');
        preg_match_all('/auth_token=([^;]+)/', $rawHeader, $matches);

        $hasEncrypted = false;
        foreach ($matches[1] ?? [] as $value) {
            $decoded = urldecode($value);
            if (! $this->isValidSanctumToken($decoded) && strlen($decoded) > 50) {
                $hasEncrypted = true;
                break;
            }
        }

        if ($hasEncrypted) {
            // Expire the stale cookie on the bare domain (.spendifiai.com)
            $host = $request->getHost();
            $bareDomain = preg_replace('/^www\./', '.', $host);

            $response->headers->set(
                'Set-Cookie',
                "auth_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain={$bareDomain}; secure; samesite=lax",
                false // false = don't replace, add alongside existing Set-Cookie headers
            );
        }
    }
}
