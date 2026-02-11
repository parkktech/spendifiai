<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserFinancialProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserFinancialProfile>
 */
class UserFinancialProfileFactory extends Factory
{
    protected $model = UserFinancialProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employment_type' => fake()->randomElement(['self_employed', 'employed', 'freelancer']),
            'business_type' => fake()->randomElement(['consulting', 'technology', 'creative']),
            'has_home_office' => fake()->boolean(70),
            'tax_filing_status' => fake()->randomElement(['single', 'married_jointly', 'head_of_household']),
            'estimated_tax_bracket' => fake()->randomElement([12, 22, 24, 32]),
            'monthly_income' => (string) fake()->randomFloat(2, 3000, 10000),
            'monthly_savings_goal' => fake()->randomFloat(2, 200, 1000),
            'custom_rules' => null,
        ];
    }
}
