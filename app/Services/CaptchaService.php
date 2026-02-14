<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CaptchaService
{
    /**
     * Verify a reCAPTCHA v3 token.
     *
     * @param  string  $token  The reCAPTCHA response token from frontend
     * @param  string|null  $action  Expected action name (login, register, etc.)
     * @param  string|null  $ip  Client IP for additional verification
     */
    public function verify(string $token, ?string $action = null, ?string $ip = null): bool
    {
        if (! config('spendifiai.captcha.enabled')) {
            return true; // Captcha disabled, always pass
        }

        try {
            $response = Http::asForm()->post(config('spendifiai.captcha.verify_url'), [
                'secret' => config('spendifiai.captcha.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);

            $result = $response->json();

            if (! ($result['success'] ?? false)) {
                Log::warning('reCAPTCHA verification failed', [
                    'error_codes' => $result['error-codes'] ?? [],
                    'action' => $action,
                    'ip' => $ip,
                ]);

                return false;
            }

            // Check score threshold (v3 is score-based: 0.0 = bot, 1.0 = human)
            $score = $result['score'] ?? 0;
            $threshold = config('spendifiai.captcha.threshold', 0.5);

            if ($score < $threshold) {
                Log::warning('reCAPTCHA score below threshold', [
                    'score' => $score,
                    'threshold' => $threshold,
                    'action' => $action,
                    'ip' => $ip,
                ]);

                return false;
            }

            // Verify action matches (prevents token reuse across forms)
            if ($action && isset($result['action']) && $result['action'] !== $action) {
                Log::warning('reCAPTCHA action mismatch', [
                    'expected' => $action,
                    'actual' => $result['action'],
                ]);

                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('reCAPTCHA verification error', ['exception' => $e->getMessage()]);

            // Fail open in production? Fail closed is safer:
            return false;
        }
    }
}
