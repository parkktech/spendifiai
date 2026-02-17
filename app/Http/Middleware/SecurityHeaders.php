<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        // In development, disable CSP to allow Vite dev server
        if (app()->environment('local', 'development')) {
            $csp = "default-src *; script-src * 'unsafe-inline' 'unsafe-eval'; style-src * 'unsafe-inline';";
        } else {
            // Production CSP - strict
            $csp = "default-src 'self'; "
                . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
                . "style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
                . "font-src 'self' https://fonts.bunny.net; "
                . "img-src 'self' data: https://images.unsplash.com https://spendifiai.com; "
                . "connect-src 'self'; "
                . "frame-ancestors 'none';";
        }

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
