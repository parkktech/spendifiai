<?php

namespace App\Http\Requests\Auth;

use App\Services\CaptchaService;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'email'           => 'required|string|email',
            'password'        => 'required|string',
            'two_factor_code' => 'nullable|string',
        ];

        if (config('spendwise.captcha.enabled')) {
            $rules['captcha_token'] = 'required|string';
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        if (config('spendwise.captcha.enabled') && $this->captcha_token) {
            $validator->after(function ($validator) {
                $captcha = app(CaptchaService::class);
                if (!$captcha->verify($this->captcha_token, 'login', $this->ip())) {
                    $validator->errors()->add('captcha_token', 'CAPTCHA verification failed.');
                }
            });
        }
    }
}
