<?php

namespace Database\Factories;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIQuestion>
 */
class AIQuestionFactory extends Factory
{
    protected $model = AIQuestion::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'transaction_id' => Transaction::factory(),
            'question' => fake()->sentence() . '?',
            'options' => ['Personal', 'Business', 'Mixed', 'Skip'],
            'question_type' => QuestionType::BusinessPersonal,
            'ai_confidence' => fake()->randomFloat(2, 0.30, 0.59),
            'ai_best_guess' => fake()->randomElement(['Food & Groceries', 'Office Supplies']),
            'status' => QuestionStatus::Pending,
        ];
    }

    public function answered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuestionStatus::Answered,
            'user_answer' => 'Business',
            'answered_at' => now(),
        ]);
    }

    public function category(): static
    {
        return $this->state(fn (array $attributes) => [
            'question_type' => QuestionType::Category,
            'options' => ['Food & Groceries', 'Restaurant & Dining', 'Shopping (General)', 'Skip'],
        ]);
    }
}
