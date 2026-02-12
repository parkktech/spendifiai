<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RespondToRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'response_type' => 'required|in:cancelled,reduced,kept',
            'new_amount' => 'required_if:response_type,reduced|nullable|numeric|min:0',
            'reason' => 'required_if:response_type,kept|nullable|string|max:500',
        ];
    }
}
