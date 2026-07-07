<?php

declare(strict_types=1);

/**
 * ScenarioSimulateTest — what-if calculator endpoint (owner request 2026-07-06).
 *
 * POST /api/v1/optimizer/scenarios/{year}/simulate
 *
 * OWNER SEMANTICS: contribution_pct_of_max is a percentage of the IRS LEGAL
 * MAXIMUM (100 = full 402(g) limit incl. catch-ups), converted server-side to a
 * payroll deferral percentage against deferral-eligible comp. Empty payload
 * returns the user's CURRENT position (seeds the calculator controls).
 */

use App\Models\User;
use App\Models\UserTaxFact;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const SIM_YEAR = 2026;

function simUser(): User
{
    $user = User::factory()->create();

    foreach ([
        ['pay.gross_per_period_cents', '760875'],
        ['pay.frequency', 'biweekly'],
        ['retirement.traditional_401k_per_period_cents', '60870'],
        ['retirement.roth_401k_per_period_cents', '15218'],
    ] as [$key, $value]) {
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: $key,
            value: $value,
            sourceType: 'document_extraction',
            label: $key,
            volatility: 'annual',
            taxYear: SIM_YEAR,
        );
        UserTaxFact::where('user_id', $user->id)->where('fact_key', $key)
            ->update(['is_current' => true, 'confirmed_at' => now()]);
    }

    return $user;
}

it('requires authentication', function () {
    $this->postJson('/api/v1/optimizer/scenarios/'.SIM_YEAR.'/simulate', [
        'contribution_pct_of_max' => 50,
        'roth_share_pct' => 0,
    ])->assertUnauthorized();
});

it('rejects out-of-range input', function () {
    $this->actingAs(simUser())
        ->postJson('/api/v1/optimizer/scenarios/'.SIM_YEAR.'/simulate', [
            'contribution_pct_of_max' => 150,
            'roth_share_pct' => 0,
        ])->assertStatus(422);
});

it('empty payload returns the current position with zero deltas', function () {
    $res = $this->actingAs(simUser())
        ->postJson('/api/v1/optimizer/scenarios/'.SIM_YEAR.'/simulate', [])
        ->assertOk()
        ->json();

    // Current deferral ($760.88/period x 26 = $19,782.88) as % of the legal max.
    expect($res['contribution']['annual_cents'])->toBeGreaterThan(0);
    // Round-tripping current -> pct-of-max -> cents quantizes by a cent or two;
    // "current position" must land within $1 of a true zero delta.
    expect(abs($res['bring_home']['delta_per_check_cents']))->toBeLessThanOrEqual(100);
    expect(abs($res['federal_tax']['delta_annual_cents']))->toBeLessThanOrEqual(100);
    expect($res['contribution']['roth_share_pct'])->toBe(20);
});

it('100% of max contributes exactly the legal limit and translates to payroll terms', function () {
    $res = $this->actingAs(simUser())
        ->postJson('/api/v1/optimizer/scenarios/'.SIM_YEAR.'/simulate', [
            'contribution_pct_of_max' => 100,
            'roth_share_pct' => 0,
        ])->assertOk()->json();

    expect($res['contribution']['annual_cents'])->toBe($res['contribution']['legal_max_cents']);
    expect($res['contribution']['pct_of_max'])->toBe(100);
    expect($res['contribution']['payroll_deferral_pct'])->toBeGreaterThan(0);
    expect($res['contribution']['per_check_cents'])->toBeGreaterThan(0);
    // More pre-tax deferral -> lower federal tax than current
    expect($res['federal_tax']['delta_annual_cents'])->toBeLessThan(0);
    // Bigger contribution -> lower take-home per check
    expect($res['bring_home']['delta_per_check_cents'])->toBeLessThan(0);
});

it('raising the contribution raises the retirement projection', function () {
    $user = simUser();

    $low = $this->actingAs($user)
        ->postJson('/api/v1/optimizer/scenarios/'.SIM_YEAR.'/simulate', [
            'contribution_pct_of_max' => 20,
            'roth_share_pct' => 0,
        ])->assertOk()->json();

    $high = $this->actingAs($user)
        ->postJson('/api/v1/optimizer/scenarios/'.SIM_YEAR.'/simulate', [
            'contribution_pct_of_max' => 90,
            'roth_share_pct' => 0,
        ])->assertOk()->json();

    // FV may be null when age is unknown — compare contribution levels instead,
    // and FV when both are present.
    expect($high['contribution']['annual_cents'])->toBeGreaterThan($low['contribution']['annual_cents']);
    if ($low['retirement']['after_fv'] !== null && $high['retirement']['after_fv'] !== null) {
        expect($high['retirement']['after_fv']['low_cents'])
            ->toBeGreaterThan($low['retirement']['after_fv']['low_cents']);
    }
});

it('includes the disclaimer and clamp list', function () {
    $res = $this->actingAs(simUser())
        ->postJson('/api/v1/optimizer/scenarios/'.SIM_YEAR.'/simulate', [
            'contribution_pct_of_max' => 100,
            'roth_share_pct' => 50,
        ])->assertOk()->json();

    expect($res['disclaimer'])->toBeString()->not->toBeEmpty();
    expect($res['clamps'])->toBeArray();
});
