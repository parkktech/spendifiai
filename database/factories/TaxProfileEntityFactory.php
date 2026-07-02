<?php

namespace Database\Factories;

use App\Models\TaxProfileEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxProfileEntity>
 */
class TaxProfileEntityFactory extends Factory
{
    protected $model = TaxProfileEntity::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'entity_type' => fake()->randomElement(['vehicle', 'property', 'business']),
            'label' => fake()->words(3, true),
            'attributes' => [],
            'is_current' => true,
            'superseded_by_id' => null,
        ];
    }

    public function vehicle(): static
    {
        return $this->state([
            'entity_type' => 'vehicle',
            'label' => fake()->randomElement(['My Truck', 'Work Van', 'Business Car']),
        ]);
    }

    public function property(): static
    {
        return $this->state([
            'entity_type' => 'property',
            'label' => fake()->address(),
        ]);
    }

    public function business(): static
    {
        return $this->state([
            'entity_type' => 'business',
            'label' => fake()->company(),
        ]);
    }
}
