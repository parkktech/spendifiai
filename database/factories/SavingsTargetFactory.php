<?php

namespace Database\Factories;

use App\Models\SavingsTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsTarget>
 */
class SavingsTargetFactory extends Factory
{
    protected $model = SavingsTarget::class;

    public function definition(): array
    {
        $monthlyTarget = fake()->randomFloat(2, 100, 1000);

        return [
            'user_id' => User::factory(),
            'monthly_target' => $monthlyTarget,
            'motivation' => fake()->sentence(),
            'target_start_date' => now()->startOfMonth(),
            'target_end_date' => now()->addMonths(6)->endOfMonth(),
            'goal_total' => round($monthlyTarget * 6, 2),
            'is_active' => true,
        ];
    }
}
