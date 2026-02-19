<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BulkImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'providers' => 'required|array|min:1|max:500',
            'providers.*.company_name' => 'required|string|max:255',
            'providers.*.aliases' => 'required|array|min:1',
            'providers.*.aliases.*' => 'string|max:255',
            'providers.*.cancellation_url' => 'nullable|url',
            'providers.*.difficulty' => 'required|in:easy,medium,hard',
            'providers.*.category' => 'nullable|string|max:100',
            'providers.*.is_essential' => 'boolean',
        ];
    }
}
