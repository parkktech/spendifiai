<?php

namespace Database\Factories;

use App\Models\IncomeOptimizationProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeOptimizationProfile>
 */
class IncomeOptimizationProfileFactory extends Factory
{
    protected $model = IncomeOptimizationProfile::class;

    public function definition(): array
    {
        // All money fields are stored as integer CENTS in encrypted strings.
        // e.g. '7500000' == $75,000.00
        $w2Cents = (string) fake()->numberBetween(3_000_000, 15_000_000);   // $30k–$150k
        $bankCents = (string) (int) ($w2Cents * fake()->randomFloat(2, 0.80, 1.10));

        return [
            'user_id' => User::factory(),
            'tax_year' => fake()->randomElement([2024, 2025, 2026]),
            // Income signals
            'w2_wages' => $w2Cents,
            'self_employment_income' => null,
            'interest_income' => (string) fake()->numberBetween(0, 50_000),
            'dividend_income' => (string) fake()->numberBetween(0, 100_000),
            'retirement_distributions' => null,
            'bank_deposit_total' => $bankCents,
            // Deduction signals
            'mortgage_interest' => null,
            'property_tax_paid' => null,
            'student_loan_interest' => null,
            'charitable_contributions' => (string) fake()->numberBetween(0, 200_000),
            // Retirement contribution signals
            'traditional_401k_ytd' => null,
            'roth_401k_ytd' => null,
            'ira_ytd' => null,
            'hsa_ytd' => null,
            // Non-sensitive flags
            'filing_status' => fake()->randomElement(['single', 'married_joint', 'married_separate', 'head_of_household']),
            'has_home_office' => false,
            'has_self_employment' => false,
            'has_hsa_eligible_plan' => false,
            'has_ira' => false,
            'ira_type' => null,
            'employment_type' => fake()->randomElement(['employed', 'self_employed', 'freelancer']),
            'estimated_age' => null,
            // Metadata
            'data_sources' => ['doc_ids' => [], 'transaction_range' => []],
            'doc_count' => 0,
            'profile_hash' => hash('sha256', fake()->uuid()),
            'built_at' => now(),
        ];
    }

    /**
     * Profile for a self-employed user with home office.
     */
    public function selfEmployed(): static
    {
        $seCents = (string) fake()->numberBetween(4_000_000, 20_000_000);

        return $this->state(fn (array $attrs) => [
            'w2_wages' => null,
            'self_employment_income' => $seCents,
            'has_self_employment' => true,
            'has_home_office' => true,
            'employment_type' => 'self_employed',
            'filing_status' => 'single',
        ]);
    }

    /**
     * Profile with full deduction signal set.
     */
    public function withDeductions(): static
    {
        return $this->state(fn (array $attrs) => [
            'mortgage_interest' => (string) fake()->numberBetween(500_000, 2_000_000),
            'property_tax_paid' => (string) fake()->numberBetween(100_000, 800_000),
            'charitable_contributions' => (string) fake()->numberBetween(10_000, 300_000),
            'student_loan_interest' => (string) fake()->numberBetween(0, 250_000),
        ]);
    }
}
