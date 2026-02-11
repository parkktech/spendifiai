<?php

namespace Database\Factories;

use App\Models\BudgetGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetGoal>
 */
class BudgetGoalFactory extends Factory
{
    protected $model = BudgetGoal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_slug' => fake()->randomElement(['food-groceries', 'restaurant-dining', 'shopping-general', 'entertainment']),
            'monthly_limit' => fake()->randomFloat(2, 100, 1000),
        ];
    }
}
