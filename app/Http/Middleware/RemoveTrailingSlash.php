<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RemoveTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if ($path !== '/' && str_ends_with($path, '/')) {
            $query = $request->getQueryString();
            $url = rtrim($path, '/').($query ? '?'.$query : '');

            return redirect($url, 301);
        }

        return $next($request);
    }
}
