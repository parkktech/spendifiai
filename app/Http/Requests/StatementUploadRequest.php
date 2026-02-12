<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StatementUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:pdf,csv,txt|max:10240',
            'bank_name' => 'required|string|max:255',
            'account_type' => 'required|string|in:checking,savings,credit,investment',
            'nickname' => 'nullable|string|max:255',
        ];
    }
}
