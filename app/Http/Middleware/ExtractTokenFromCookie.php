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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If no Authorization header is set but we have an auth_token cookie, use it
        if (!$request->bearerToken() && $request->cookie('auth_token')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->cookie('auth_token'));
            // Debug logging
            \Log::debug('✅ Token extracted from cookie and set as Authorization header');
        } elseif ($request->bearerToken()) {
            \Log::debug('ℹ️ Authorization header already set');
        } elseif (!$request->cookie('auth_token')) {
            \Log::debug('⚠️ No auth_token cookie found');
        }
        return $next($request);
    }
}
