<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OptionalAuth
{
    /**
     * Attempt Sanctum authentication without redirecting on failure.
     *
     * Unlike auth:sanctum, this middleware lets the request proceed even if
     * authentication fails. The page renders with auth.user = null, and the
     * client-side can re-authenticate from localStorage.
     *
     * This prevents hard refresh (Ctrl+Shift+R) from redirecting to /login
     * when the cookie-based auth fails for any reason.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            Auth::shouldUse('sanctum');
            Auth::authenticate();
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            // Auth failed — let the request proceed unauthenticated.
            // HandleInertiaRequests will share auth.user = null.
        }

        return $next($request);
    }
}
