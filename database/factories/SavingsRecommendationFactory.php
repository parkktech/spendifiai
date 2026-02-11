<?php

namespace Database\Factories;

use App\Models\SavingsRecommendation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsRecommendation>
 */
class SavingsRecommendationFactory extends Factory
{
    protected $model = SavingsRecommendation::class;

    public function definition(): array
    {
        $monthlySavings = fake()->randomFloat(2, 10, 200);

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'monthly_savings' => $monthlySavings,
            'annual_savings' => round($monthlySavings * 12, 2),
            'difficulty' => fake()->randomElement(['easy', 'medium', 'hard']),
            'impact' => fake()->randomElement(['low', 'medium', 'high']),
            'category' => fake()->randomElement(['subscriptions', 'dining', 'shopping']),
            'status' => 'active',
            'action_steps' => ['Step 1: Review charges', 'Step 2: Cancel unused'],
            'related_merchants' => ['Netflix', 'Spotify'],
            'generated_at' => now(),
        ];
    }
}
