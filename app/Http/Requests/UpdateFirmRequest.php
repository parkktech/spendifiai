<?php

namespace App\Http\Requests;

use App\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->user_type === UserType::Accountant
            && $user->accounting_firm_id !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'primary_color.regex' => 'The primary color must be a valid hex color (e.g. #0D9488).',
        ];
    }
}
