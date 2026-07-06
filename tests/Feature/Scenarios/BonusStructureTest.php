<?php

declare(strict_types=1);

/**
 * BonusStructureTest — bonus grounding + structure precedence (2026-07-06).
 *
 * Owner decisions:
 *   1. The engine grounds annual tax math on real income (base + bonus).
 *   2. Users express their yearly bonus either way — percent of base salary or
 *      flat annual amount (profile "Yearly Bonus" setting).
 *   3. PRECEDENCE: income.bonus_structure_pct (recomputes with income) beats
 *      the YTD-derived income.bonus_annual_cents dollar lump.
 *
 * Fixture mirrors user 1's production state: gross $7,608.75/period biweekly
 * (base annual $197,827.50), 25% annual bonus.
 */

use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\ScenarioSolverService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const BONUS_YEAR = 2026;
const BONUS_BASE_ANNUAL_CENTS = 760875 * 26; // $197,827.50

function seedBonusPayFacts(int $userId): void
{
    foreach ([
        ['pay.gross_per_period_cents', '760875'],
        ['pay.frequency', 'biweekly'],
    ] as [$key, $value]) {
        UserTaxFact::recordFact(
            userId: $userId,
            factKey: $key,
            value: $value,
            sourceType: 'document_extraction',
            label: $key,
            volatility: 'annual',
            taxYear: BONUS_YEAR,
        );
        UserTaxFact::where('user_id', $userId)->where('fact_key', $key)
            ->update(['is_current' => true, 'confirmed_at' => now()]);
    }
}

function seedBonusFact(int $userId, string $key, string $value): void
{
    UserTaxFact::recordFact(
        userId: $userId,
        factKey: $key,
        value: $value,
        sourceType: 'user_edit',
        label: $key,
        volatility: 'annual',
        taxYear: BONUS_YEAR,
    );
}

it('uses the structure percent times base annual when set', function () {
    $user = User::factory()->create();
    seedBonusPayFacts($user->id);
    seedBonusFact($user->id, 'income.bonus_structure_pct', '25');

    $baseline = app(ScenarioSolverService::class)->assembleBaseline($user, BONUS_YEAR);

    expect($baseline['bonus_annual_cents'])
        ->toBe((int) round(BONUS_BASE_ANNUAL_CENTS * 0.25)); // $49,456.88
});

it('falls back to the derived dollar lump when no structure percent exists', function () {
    $user = User::factory()->create();
    seedBonusPayFacts($user->id);
    seedBonusFact($user->id, 'income.bonus_annual_cents', '10788472');

    $baseline = app(ScenarioSolverService::class)->assembleBaseline($user, BONUS_YEAR);

    expect($baseline['bonus_annual_cents'])->toBe(10788472);
});

it('structure percent wins over the derived lump when both exist', function () {
    $user = User::factory()->create();
    seedBonusPayFacts($user->id);
    seedBonusFact($user->id, 'income.bonus_annual_cents', '10788472'); // stale YTD-pace lump
    seedBonusFact($user->id, 'income.bonus_structure_pct', '25');

    $baseline = app(ScenarioSolverService::class)->assembleBaseline($user, BONUS_YEAR);

    expect($baseline['bonus_annual_cents'])
        ->toBe((int) round(BONUS_BASE_ANNUAL_CENTS * 0.25));
});

it('no bonus facts means zero bonus (backward compat)', function () {
    $user = User::factory()->create();
    seedBonusPayFacts($user->id);

    $baseline = app(ScenarioSolverService::class)->assembleBaseline($user, BONUS_YEAR);

    expect($baseline['bonus_annual_cents'])->toBe(0);
});

it('profile save with percent bonus mirrors the structure fact', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/profile/financial', [
        'bonus_structure_type' => 'percent',
        'bonus_structure_pct' => 25,
    ])->assertOk();

    $fact = UserTaxFact::currentFact($user->id, 'income.bonus_structure_pct', null, now()->year);
    expect($fact)->not->toBeNull();
    expect((float) $fact->value)->toBe(25.0);
    expect($fact->source_type)->toBe('user_edit');

    $this->assertDatabaseHas('user_financial_profiles', [
        'user_id' => $user->id,
        'bonus_structure_type' => 'percent',
    ]);
});

it('profile save with flat bonus mirrors the dollar fact and clears the percent structure', function () {
    $user = User::factory()->create();

    // Pre-existing percent structure (e.g. detected from paystub, confirmed)
    seedBonusFact($user->id, 'income.bonus_structure_pct', '25');
    UserTaxFact::where('user_id', $user->id)->where('fact_key', 'income.bonus_structure_pct')
        ->update(['tax_year' => now()->year]);

    $this->actingAs($user)->postJson('/api/v1/profile/financial', [
        'bonus_structure_type' => 'flat',
        'bonus_structure_amount' => 10000,
    ])->assertOk();

    $flat = UserTaxFact::currentFact($user->id, 'income.bonus_annual_cents', null, now()->year);
    expect($flat)->not->toBeNull();
    expect((int) $flat->value)->toBe(1_000_000); // $10,000

    // Percent structure zeroed so the flat amount governs the fallback path.
    $pct = UserTaxFact::currentFact($user->id, 'income.bonus_structure_pct', null, now()->year);
    expect((float) $pct->value)->toBe(0.0);
});

it('percent validation rejects out-of-range values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/profile/financial', [
        'bonus_structure_type' => 'percent',
        'bonus_structure_pct' => 500,
    ])->assertStatus(422);
});
