<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCancellationProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:255',
            'aliases' => 'required|array|min:1',
            'aliases.*' => 'string|max:255',
            'cancellation_url' => 'nullable|url|max:500',
            'cancellation_phone' => 'nullable|string|max:50',
            'cancellation_instructions' => 'nullable|string|max:2000',
            'difficulty' => 'required|in:easy,medium,hard',
            'category' => 'nullable|string|max:100',
            'is_essential' => 'boolean',
        ];
    }
}
