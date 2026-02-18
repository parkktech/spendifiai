<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'view' => 'sometimes|in:all,personal,business',
            'period_start' => 'sometimes|date|required_with:period_end|before_or_equal:period_end',
            'period_end' => 'sometimes|date|required_with:period_start|after_or_equal:period_start',
            'avg_mode' => 'sometimes|in:total,monthly_avg',
        ];
    }
}
