<?php

namespace Database\Factories;

use App\Models\OptimizationChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptimizationChecklistItem>
 */
class OptimizationChecklistItemFactory extends Factory
{
    protected $model = OptimizationChecklistItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tax_year' => 2026,
            'source_type' => 'scenario_choice',
            'source_id' => null,
            'fact_set_id' => null,
            'knob' => 'k3',
            'step_key' => 'k3_directive',
            'kind' => 'directive',
            'benefit_line_params' => ['delta_paycheck' => 0, 'delta_annual' => 0],
            'position' => 0,
            'done_at' => null,
        ];
    }
}
