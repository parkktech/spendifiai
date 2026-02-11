<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetSavingsTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monthly_target' => 'required|numeric|min:1|max:100000',
            'motivation'     => 'nullable|string|max:200',
            'goal_total'     => 'nullable|numeric|min:0',
        ];
    }
}
