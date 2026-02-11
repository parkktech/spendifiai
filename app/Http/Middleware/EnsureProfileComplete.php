<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->hasProfileComplete()) {
            return response()->json([
                'message' => 'Please complete your financial profile for accurate tax and spending analysis.',
                'action'  => 'complete_profile',
                'url'     => '/settings/profile',
            ], 403);
        }

        return $next($request);
    }
}
