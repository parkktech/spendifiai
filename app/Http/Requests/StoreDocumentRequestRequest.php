<?php

namespace App\Http\Requests;

use App\Enums\TaxDocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAccountant() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => 'required|string|max:1000',
            'tax_year' => 'nullable|integer|min:2000|max:2099',
            'category' => [
                'nullable',
                'string',
                Rule::in(array_column(TaxDocumentCategory::cases(), 'value')),
            ],
        ];
    }
}
