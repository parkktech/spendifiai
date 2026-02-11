<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RespondToPlanActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Ownership checked via savings_target relationship in controller
    }

    public function rules(): array
    {
        return [
            'response'         => 'required|in:accept,reject',
            'rejection_reason' => 'nullable|string|max:500',
        ];
    }
}
