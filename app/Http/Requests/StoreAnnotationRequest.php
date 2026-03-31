<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check happens in controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => 'required|string|max:2000',
            'parent_id' => 'nullable|integer|exists:document_annotations,id',
        ];
    }
}
