<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankConnection>
 */
class BankConnectionFactory extends Factory
{
    protected $model = BankConnection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plaid_item_id' => 'item_' . fake()->uuid(),
            'plaid_access_token' => 'access-sandbox-' . fake()->uuid(),
            'institution_name' => fake()->randomElement(['Chase', 'Bank of America', 'Wells Fargo', 'Capital One']),
            'institution_id' => 'ins_' . fake()->randomNumber(6),
            'status' => ConnectionStatus::Active,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConnectionStatus::Active,
            'last_synced_at' => now(),
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConnectionStatus::Error,
            'error_code' => 'ITEM_LOGIN_REQUIRED',
            'error_message' => 'Login required',
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConnectionStatus::Disconnected,
        ]);
    }
}
