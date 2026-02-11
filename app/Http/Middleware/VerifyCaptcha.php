<?php

namespace App\Http\Middleware;

use App\Services\CaptchaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCaptcha
{
    public function __construct(protected CaptchaService $captcha) {}

    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        if (!config('spendwise.captcha.enabled')) {
            return $next($request);
        }

        $token = $request->input('captcha_token');

        if (!$token || !$this->captcha->verify($token, $action, $request->ip())) {
            return response()->json([
                'message' => 'CAPTCHA verification failed. Please try again.',
            ], 422);
        }

        return $next($request);
    }
}
