<?php

namespace Database\Factories;

use App\Enums\AccountPurpose;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_connection_id' => BankConnection::factory(),
            'plaid_account_id' => 'acc_' . fake()->uuid(),
            'name' => fake()->randomElement(['Checking', 'Savings', 'Credit Card']),
            'type' => 'depository',
            'subtype' => 'checking',
            'mask' => fake()->numerify('####'),
            'purpose' => AccountPurpose::Personal,
            'current_balance' => fake()->randomFloat(2, 100, 10000),
            'available_balance' => fake()->randomFloat(2, 100, 10000),
            'is_active' => true,
        ];
    }

    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => AccountPurpose::Business,
        ]);
    }

    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => AccountPurpose::Personal,
        ]);
    }
}
