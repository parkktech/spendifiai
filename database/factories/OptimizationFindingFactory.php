<?php

namespace Database\Factories;

use App\Models\OptimizationFinding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptimizationFinding>
 */
class OptimizationFindingFactory extends Factory
{
    protected $model = OptimizationFinding::class;

    public function definition(): array
    {
        $w2Cents = fake()->numberBetween(5_000_000, 15_000_000);
        $bankCents = (int) ($w2Cents * fake()->randomFloat(2, 0.60, 0.80)); // 20-40% gap
        $gapCents = abs($w2Cents - $bankCents);
        $gapPct = $gapCents / max($w2Cents, $bankCents);

        return [
            'user_id' => User::factory(),
            'tax_year' => fake()->randomElement([2024, 2025, 2026]),
            'finding_key' => 'w2_deposit_mismatch',
            'finding_type' => 'income_discrepancy',
            'severity' => fake()->randomElement(['low', 'medium', 'high']),
            'details' => [
                'w2_cents' => $w2Cents,
                'bank_cents' => $bankCents,
                'gap_cents' => $gapCents,
                'gap_pct' => $gapPct,
            ],
            'description' => null,  // Phase 11 fills this via Claude narration
            'status' => 'open',
        ];
    }

    /**
     * Finding for a self-employment income vs deposits discrepancy.
     */
    public function seIncomeMismatch(): static
    {
        $seCents = fake()->numberBetween(3_000_000, 12_000_000);
        $bankCents = (int) ($seCents * fake()->randomFloat(2, 0.60, 0.75));
        $gapCents = abs($seCents - $bankCents);
        $gapPct = $gapCents / max($seCents, $bankCents);

        return $this->state(fn (array $attrs) => [
            'finding_key' => 'se_income_deposit_mismatch',
            'finding_type' => 'income_discrepancy',
            'details' => [
                'se_income_cents' => $seCents,
                'bank_cents' => $bankCents,
                'gap_cents' => $gapCents,
                'gap_pct' => $gapPct,
            ],
        ]);
    }

    /**
     * Finding that is already resolved (dismissed or closed).
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'resolved',
        ]);
    }
}
