<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCharitableOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'website_url' => 'nullable|url|max:500',
            'donate_url' => 'nullable|url|max:500',
            'category' => 'nullable|string|max:100',
            'ein' => 'nullable|string|max:20',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ];
    }
}
