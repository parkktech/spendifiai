<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dependent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'household_id',
        'name',
        'date_of_birth',
        'relationship',
        'ssn_last_four',
        'is_student',
        'is_disabled',
        'lives_with_you',
        'months_lived_with_you',
        'gross_income',
        'is_claimed',
        'tax_year',
    ];

    protected $hidden = [
        'ssn_last_four',
        'gross_income',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_student' => 'boolean',
            'is_disabled' => 'boolean',
            'lives_with_you' => 'boolean',
            'is_claimed' => 'boolean',
            'ssn_last_four' => 'encrypted',
            'gross_income' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Get the dependent's age as of a given date (defaults to end of tax year).
     */
    public function getAgeAttribute(): int
    {
        $asOf = Carbon::create($this->tax_year, 12, 31);

        return (int) $this->date_of_birth->diffInYears($asOf);
    }

    /**
     * Check if dependent qualifies for Child Tax Credit for the given tax year.
     * Must be under 17 at end of tax year, live with taxpayer 6+ months, claimed.
     */
    public function qualifiesForChildTaxCredit(?int $taxYear = null): bool
    {
        $year = $taxYear ?? $this->tax_year;
        $ageAtEndOfYear = $this->date_of_birth->diffInYears(Carbon::create($year, 12, 31));

        return $ageAtEndOfYear < 17
            && $this->lives_with_you
            && $this->months_lived_with_you >= 6
            && $this->is_claimed;
    }
}
