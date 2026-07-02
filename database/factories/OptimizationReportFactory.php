<?php

namespace Database\Factories;

use App\Models\OptimizationReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptimizationReport>
 */
class OptimizationReportFactory extends Factory
{
    protected $model = OptimizationReport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tax_year' => 2026,
            'sections' => [],
            'executive_summary' => null,
            'is_stale' => false,
            'stale_since' => null,
            'rebuilt_at' => now()->subDays(10),
            'built_against' => [
                'income_cents' => 8_000_000,
                'savings_cents' => 500_000,
            ],
            'executive_summary_structured' => null,
        ];
    }

    /**
     * Mark the report as stale.
     */
    public function stale(): static
    {
        return $this->state([
            'is_stale' => true,
            'stale_since' => now()->subDays(5),
        ]);
    }
}
