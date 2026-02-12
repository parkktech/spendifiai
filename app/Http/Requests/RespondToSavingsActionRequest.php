<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RespondToSavingsActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Ownership checked via policy in controller
    }

    public function rules(): array
    {
        return [
            'response_type' => 'required|in:cancelled,reduced,kept',
            'new_amount' => 'nullable|numeric|min:0|required_if:response_type,reduced',
            'reason' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_amount.required_if' => 'Please enter the new reduced amount.',
        ];
    }
}
