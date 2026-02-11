<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Enforce2FA
{
    /**
     * Optional middleware: if 2FA is enabled on the user's account,
     * ensure their session includes a verified 2FA flag.
     * (For SPA + token auth, 2FA is checked at login time in AuthController.)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->hasTwoFactorEnabled() && !session('2fa_verified', false)) {
            // Token-based auth handles 2FA at login, so this is mainly for session-based
            if ($request->bearerToken()) {
                return $next($request); // Token auth: 2FA was verified at login
            }

            return response()->json([
                'message'             => 'Two-factor authentication required.',
                'two_factor_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
