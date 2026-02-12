<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFinancialProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employment_type' => 'nullable|in:employed,self_employed,freelancer,business_owner,retired,student,other',
            'business_type' => 'nullable|string|max:200',
            'has_home_office' => 'nullable|boolean',
            'filing_status' => 'nullable|in:single,married_filing_jointly,married_filing_separately,head_of_household',
            'tax_filing_status' => 'nullable|in:single,married,head_of_household',
            'estimated_tax_bracket' => 'nullable|integer|in:10,12,22,24,32,35,37',
            'monthly_income' => 'nullable|numeric|min:0',
            'monthly_savings_goal' => 'nullable|numeric|min:0',
            'tax_year_start' => 'nullable|date',
        ];
    }
}
