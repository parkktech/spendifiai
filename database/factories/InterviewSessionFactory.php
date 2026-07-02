<?php

namespace Database\Factories;

use App\Models\InterviewSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterviewSession>
 */
class InterviewSessionFactory extends Factory
{
    protected $model = InterviewSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tax_year' => 2026,
            'status' => 'in_progress',
            'queue' => [],
            'asked' => [],
            'assertions' => null,
            'initial_cap' => 10,
        ];
    }

    public function created(): static
    {
        return $this->state(fn (array $attrs) => ['status' => 'created']);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attrs) => ['status' => 'paused']);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'completed',
            'queue' => [],
        ]);
    }
}
