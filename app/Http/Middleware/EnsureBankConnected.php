<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBankConnected
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->hasBankConnected()) {
            return response()->json([
                'message' => 'Please connect a bank account first.',
                'action'  => 'connect_bank',
                'url'     => '/connect',
            ], 403);
        }

        return $next($request);
    }
}
