<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $cacheKey = "user_activity:{$user->id}";

            if (! Cache::has($cacheKey)) {
                $user->updateQuietly(['last_active_at' => now()]);
                Cache::put($cacheKey, true, 3600);
            }
        }

        return $next($request);
    }
}
