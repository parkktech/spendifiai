<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'date_of_birth' => 'required|date|before:today',
            'relationship' => 'required|string|in:child,stepchild,foster_child,sibling,parent,grandparent,grandchild,other',
            'ssn_last_four' => 'nullable|string|size:4',
            'is_student' => 'nullable|boolean',
            'is_disabled' => 'nullable|boolean',
            'lives_with_you' => 'nullable|boolean',
            'months_lived_with_you' => 'nullable|integer|min:0|max:12',
            'gross_income' => 'nullable|numeric|min:0',
            'is_claimed' => 'nullable|boolean',
            'tax_year' => 'required|integer|min:2020|max:'.(date('Y') + 1),
        ];
    }
}
