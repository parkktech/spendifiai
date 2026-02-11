<?php

namespace Database\Factories;

use App\Enums\AccountPurpose;
use App\Enums\ExpenseType;
use App\Enums\ReviewStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_account_id' => BankAccount::factory(),
            'plaid_transaction_id' => 'txn_' . fake()->uuid(),
            'merchant_name' => fake()->company(),
            'amount' => fake()->randomFloat(2, 1, 500),
            'transaction_date' => fake()->dateTimeBetween('-6 months'),
            'payment_channel' => fake()->randomElement(['online', 'in store', 'other']),
            'plaid_category' => fake()->randomElement(['FOOD_AND_DRINK', 'SHOPPING', 'TRANSPORTATION']),
            'expense_type' => ExpenseType::Personal,
            'account_purpose' => AccountPurpose::Personal,
            'review_status' => ReviewStatus::PendingAI,
            'tax_deductible' => false,
            'is_subscription' => false,
        ];
    }

    public function categorized(): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_category' => fake()->randomElement(['Food & Groceries', 'Restaurant & Dining', 'Shopping (General)', 'Transportation']),
            'ai_confidence' => fake()->randomFloat(2, 0.85, 0.99),
            'review_status' => ReviewStatus::AutoCategorized,
        ]);
    }

    public function needsReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_category' => 'Uncategorized',
            'ai_confidence' => fake()->randomFloat(2, 0.40, 0.59),
            'review_status' => ReviewStatus::NeedsReview,
        ]);
    }

    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => ExpenseType::Business,
            'account_purpose' => AccountPurpose::Business,
            'tax_deductible' => true,
        ]);
    }

    public function deductible(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_deductible' => true,
            'tax_category' => fake()->randomElement(['Office Supplies', 'Software & SaaS', 'Business Meals']),
        ]);
    }

    public function subscription(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_subscription' => true,
            'merchant_name' => fake()->randomElement(['NETFLIX', 'SPOTIFY', 'ADOBE']),
        ]);
    }
}
