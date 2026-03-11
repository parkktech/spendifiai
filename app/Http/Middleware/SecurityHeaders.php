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
            // Production CSP — conditionally whitelist GTM/GA4 if configured
            $scriptSrc = "'self' 'unsafe-inline' 'unsafe-eval' https://cdn.plaid.com";
            $connectSrc = "'self' https://*.plaid.com";
            $imgSrc = "'self' data: https://images.unsplash.com https://spendifiai.com https://*.googleusercontent.com";

            if (config('spendifiai.consent.gtm_container_id') || config('spendifiai.consent.ga4_measurement_id')) {
                $scriptSrc .= ' https://www.googletagmanager.com https://www.google-analytics.com';
                $connectSrc .= ' https://www.google-analytics.com https://analytics.google.com https://*.google-analytics.com https://*.analytics.google.com';
                $imgSrc .= ' https://www.googletagmanager.com';
            }

            $csp = "default-src 'self'; "
                ."script-src {$scriptSrc}; "
                ."style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
                ."font-src 'self' data: https://fonts.bunny.net; "
                ."img-src {$imgSrc}; "
                ."connect-src {$connectSrc}; "
                ."frame-src 'self' https://cdn.plaid.com https://accounts.google.com; "
                ."frame-ancestors 'none';";
        }

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
