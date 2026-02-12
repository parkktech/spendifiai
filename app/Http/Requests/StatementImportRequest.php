<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StatementImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'upload_id' => 'required|integer|exists:statement_uploads,id',
            'transactions' => 'required|array|min:1',
            'transactions.*.date' => 'required|date',
            'transactions.*.description' => 'required|string',
            'transactions.*.amount' => 'required|numeric',
            'transactions.*.merchant_name' => 'required|string',
            'transactions.*.is_income' => 'required|boolean',
        ];
    }
}
