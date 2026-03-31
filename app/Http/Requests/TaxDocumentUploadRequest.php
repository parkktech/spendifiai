<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaxDocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy handles document-level authorization
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:102400',
            'tax_year' => 'nullable|integer|min:2000|max:'.(date('Y') + 1),
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'File must be a PDF, JPG, or PNG.',
            'file.max' => 'File size must not exceed 100 MB.',
            'tax_year.required' => 'Tax year is required.',
            'tax_year.min' => 'Tax year must be 2000 or later.',
            'tax_year.max' => 'Tax year cannot be more than one year in the future.',
        ];
    }
}
