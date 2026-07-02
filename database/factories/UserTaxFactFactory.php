<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserTaxFact>
 */
class UserTaxFactFactory extends Factory
{
    protected $model = UserTaxFact::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'fact_key' => 'profile.filing_status',
            'value' => 'single',
            'label' => 'Filing status',
            'volatility' => 'stable',
            'tax_year' => null,
            'source_type' => 'interview_answer',
            'is_current' => true,
            'confirmed_at' => now(),
        ];
    }

    /**
     * A user_edit sourced fact (confirmed by definition).
     */
    public function userEdit(): static
    {
        return $this->state([
            'source_type' => 'user_edit',
            'confirmed_at' => now(),
        ]);
    }

    /**
     * A document_extraction proposal (not yet confirmed).
     */
    public function proposal(): static
    {
        return $this->state([
            'source_type' => 'document_extraction',
            'is_current' => false,
            'confirmed_at' => null,
        ]);
    }
}
