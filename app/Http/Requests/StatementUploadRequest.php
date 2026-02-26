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
            'bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
            'bank_name' => 'required_without:bank_account_id|string|max:255',
            'account_type' => 'required_without:bank_account_id|string|in:checking,savings,credit,investment',
            'nickname' => 'nullable|string|max:255',
        ];
    }
}
