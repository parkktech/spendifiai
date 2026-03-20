<?php

namespace Database\Factories;

use App\Models\BankConnection;
use App\Models\PlaidStatement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaidStatement>
 */
class PlaidStatementFactory extends Factory
{
    protected $model = PlaidStatement::class;

    public function definition(): array
    {
        $month = fake()->numberBetween(1, 12);
        $year = fake()->numberBetween(2024, 2026);

        return [
            'user_id' => User::factory(),
            'bank_connection_id' => BankConnection::factory(),
            'plaid_statement_id' => 'stmt_'.fake()->uuid(),
            'plaid_account_id' => 'acct_'.fake()->uuid(),
            'month' => $month,
            'year' => $year,
            'status' => 'pending',
        ];
    }

    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'complete',
            'total_extracted' => fake()->numberBetween(10, 50),
            'duplicates_found' => fake()->numberBetween(0, 5),
            'transactions_imported' => fake()->numberBetween(5, 45),
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'error',
            'error_message' => 'Failed to download statement',
        ]);
    }
}
