<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => 'required|integer|min:2020|max:' . now()->year,
        ];
    }
}
