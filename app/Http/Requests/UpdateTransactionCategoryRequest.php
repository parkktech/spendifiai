<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('transaction'));
    }

    public function rules(): array
    {
        return [
            'category'       => 'required|string|max:100',
            'expense_type'   => 'nullable|in:personal,business,mixed',
            'tax_deductible' => 'nullable|boolean',
        ];
    }
}
