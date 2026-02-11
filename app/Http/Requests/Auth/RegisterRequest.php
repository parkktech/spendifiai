<?php

namespace App\Http\Requests\Auth;

use App\Services\CaptchaService;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public registration
    }

    public function rules(): array
    {
        $rules = [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
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
                if (!$captcha->verify($this->captcha_token, 'register', $this->ip())) {
                    $validator->errors()->add('captcha_token', 'CAPTCHA verification failed. Please try again.');
                }
            });
        }
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
            'password.min'  => 'Password must be at least 8 characters.',
        ];
    }
}
