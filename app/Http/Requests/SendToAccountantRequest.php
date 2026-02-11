<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendToAccountantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year'             => 'required|integer|min:2020|max:' . now()->year,
            'accountant_email' => 'required|email|max:255',
            'accountant_name'  => 'nullable|string|max:255',
            'message'          => 'nullable|string|max:1000',
        ];
    }
}
