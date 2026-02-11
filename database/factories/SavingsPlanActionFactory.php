<?php

namespace Database\Factories;

use App\Models\SavingsPlanAction;
use App\Models\SavingsTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsPlanAction>
 */
class SavingsPlanActionFactory extends Factory
{
    protected $model = SavingsPlanAction::class;

    public function definition(): array
    {
        $currentSpending = fake()->randomFloat(2, 50, 300);
        $monthlySavings = fake()->randomFloat(2, 10, 100);

        return [
            'user_id' => User::factory(),
            'savings_target_id' => SavingsTarget::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'how_to' => fake()->paragraph(),
            'monthly_savings' => $monthlySavings,
            'current_spending' => $currentSpending,
            'recommended_spending' => round($currentSpending - $monthlySavings, 2),
            'category' => fake()->randomElement(['Streaming', 'Dining', 'Shopping']),
            'difficulty' => fake()->randomElement(['easy', 'medium', 'hard']),
            'impact' => fake()->randomElement(['low', 'medium', 'high']),
            'priority' => fake()->numberBetween(1, 5),
            'status' => 'pending',
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => 'Not feasible',
        ]);
    }
}
