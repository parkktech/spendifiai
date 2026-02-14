<?php

namespace App\Http\Requests\Auth;

use App\Services\CaptchaService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'email' => 'required|string|email',
            'password' => 'required|string',
            'two_factor_code' => 'nullable|string',
        ];

        if (config('spendifiai.captcha.enabled')) {
            $rules['captcha_token'] = 'required|string';
        }

        return $rules;
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    public function withValidator($validator): void
    {
        if (config('spendifiai.captcha.enabled') && $this->captcha_token) {
            $validator->after(function ($validator) {
                $captcha = app(CaptchaService::class);
                if (! $captcha->verify($this->captcha_token, 'login', $this->ip())) {
                    $validator->errors()->add('captcha_token', 'CAPTCHA verification failed.');
                }
            });
        }
    }
}
