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
    ];

    protected $hidden = [
        'estimated_tax_bracket',  // Sensitive tax data
    ];

    protected function casts(): array
    {
        return [
            'has_home_office' => 'boolean',
            'monthly_income' => 'encrypted',  // Sensitive financial data — AES-256
            'monthly_savings_goal' => 'decimal:2',
            'custom_rules' => 'encrypted:array',  // May contain financial rules — encrypt the JSON blob
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
