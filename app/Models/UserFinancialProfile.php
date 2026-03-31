<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFinancialProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'employment_type', 'business_type', 'has_home_office',
        'housing_status', 'tax_filing_status', 'estimated_tax_bracket',
        'monthly_income', 'monthly_savings_goal', 'custom_rules',
        // Student info
        'is_student', 'school_name', 'enrollment_status',
        // Spouse info
        'spouse_name', 'spouse_employment_type', 'spouse_income', 'spouse_user_id',
        // Tax-advantaged accounts
        'has_hsa', 'has_fsa', 'has_529_plan', 'has_ira', 'ira_type',
        // Additional deductions
        'has_student_loans', 'has_childcare_expenses', 'childcare_annual_cost',
        'is_military', 'has_rental_property', 'education_credits_eligible',
    ];

    protected $hidden = [
        'estimated_tax_bracket',  // Sensitive tax data
        'spouse_income',
        'childcare_annual_cost',
    ];

    protected function casts(): array
    {
        return [
            'has_home_office' => 'boolean',
            'monthly_income' => 'encrypted',
            'monthly_savings_goal' => 'decimal:2',
            'custom_rules' => 'encrypted:array',
            'spouse_income' => 'encrypted',
            'childcare_annual_cost' => 'encrypted',
            'is_student' => 'boolean',
            'has_hsa' => 'boolean',
            'has_fsa' => 'boolean',
            'has_529_plan' => 'boolean',
            'has_ira' => 'boolean',
            'has_student_loans' => 'boolean',
            'has_childcare_expenses' => 'boolean',
            'is_military' => 'boolean',
            'has_rental_property' => 'boolean',
            'education_credits_eligible' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spouse(): BelongsTo
    {
        return $this->belongsTo(User::class, 'spouse_user_id');
    }

    /**
     * Accessor: return monthly_income as a float for calculations.
     * The 'encrypted' cast stores/retrieves as string, so we cast on read.
     */
    public function getMonthlyIncomeDecimalAttribute(): ?float
    {
        return $this->monthly_income ? (float) $this->monthly_income : null;
    }
}
