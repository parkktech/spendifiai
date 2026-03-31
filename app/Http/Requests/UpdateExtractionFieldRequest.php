<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExtractionFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'field' => ['required', 'string', 'max:100'],
            'value' => ['required', 'string', 'max:1000'],
        ];
    }
}
