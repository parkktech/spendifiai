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
            'housing_status' => 'nullable|in:own,rent,other',
            'filing_status' => 'nullable|in:single,married_filing_jointly,married_filing_separately,head_of_household',
            'tax_filing_status' => 'nullable|in:single,married,head_of_household',
            'estimated_tax_bracket' => 'nullable|integer|in:10,12,22,24,32,35,37',
            'monthly_income' => 'nullable|numeric|min:0',
            'monthly_savings_goal' => 'nullable|numeric|min:0',
            'tax_year_start' => 'nullable|date',
            // Student info
            'is_student' => 'nullable|boolean',
            'school_name' => 'nullable|string|max:200',
            'enrollment_status' => 'nullable|in:full_time,half_time,less_than_half',
            // Spouse info
            'spouse_name' => 'nullable|string|max:100',
            'spouse_employment_type' => 'nullable|in:employed,self_employed,freelancer,business_owner,retired,student,unemployed,other',
            'spouse_income' => 'nullable|numeric|min:0',
            'spouse_user_id' => 'nullable|integer|exists:users,id',
            // Tax-advantaged accounts
            'has_hsa' => 'nullable|boolean',
            'has_fsa' => 'nullable|boolean',
            'has_529_plan' => 'nullable|boolean',
            'has_ira' => 'nullable|boolean',
            'ira_type' => 'nullable|in:traditional,roth,sep,simple',
            // Additional deductions
            'has_student_loans' => 'nullable|boolean',
            'has_childcare_expenses' => 'nullable|boolean',
            'childcare_annual_cost' => 'nullable|numeric|min:0',
            'is_military' => 'nullable|boolean',
            'has_rental_property' => 'nullable|boolean',
            'education_credits_eligible' => 'nullable|boolean',
        ];
    }
}
