<?php

namespace Database\Factories;

use App\Models\SavingsProgress;
use App\Models\SavingsTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsProgress>
 */
class SavingsProgressFactory extends Factory
{
    protected $model = SavingsProgress::class;

    public function definition(): array
    {
        $income = fake()->randomFloat(2, 3000, 8000);
        $totalSpending = fake()->randomFloat(2, 2000, 6000);
        $actualSavings = round($income - $totalSpending, 2);
        $targetSavings = fake()->randomFloat(2, 200, 800);

        return [
            'user_id' => User::factory(),
            'savings_target_id' => SavingsTarget::factory(),
            'month' => now()->format('Y-m'),
            'income' => $income,
            'total_spending' => $totalSpending,
            'actual_savings' => $actualSavings,
            'target_savings' => $targetSavings,
            'target_met' => $actualSavings >= $targetSavings,
        ];
    }
}
