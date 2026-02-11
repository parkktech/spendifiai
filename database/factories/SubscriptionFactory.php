<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 5, 50);

        return [
            'user_id' => User::factory(),
            'merchant_name' => fake()->company(),
            'merchant_normalized' => fake()->company(),
            'amount' => $amount,
            'frequency' => 'monthly',
            'category' => fake()->randomElement(['Streaming', 'Software', 'Fitness']),
            'status' => SubscriptionStatus::Active,
            'is_essential' => false,
            'last_charge_date' => fake()->dateTimeBetween('-30 days'),
            'next_expected_date' => fake()->dateTimeBetween('now', '+30 days'),
            'annual_cost' => round($amount * 12, 2),
            'charge_history' => [],
        ];
    }

    public function unused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Unused,
        ]);
    }

    public function essential(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_essential' => true,
        ]);
    }
}
