<?php

declare(strict_types=1);

/**
 * §T.16 — Zero-Claude guard for objectives/scenarios/checklist endpoints.
 *
 * Every endpoint in the optimizer/scenarios and optimizer/checklist groups
 * must make ZERO HTTP calls to Anthropic (or any external API).
 *
 * Http::fake() intercepts all outbound HTTP; Http::assertNothingSent() verifies none went out.
 */

use App\Models\OptimizationChecklistItem;
use App\Models\ScenarioFactSet;
use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function noClaude_user(): User
{
    return createUserWithBank()['user'];
}

function noClaude_seedFacts(User $user, int $year = 2026): void
{
    $facts = [
        'profile.filing_status' => 'single',
        'pay.frequency' => 'biweekly',
        'employer.federal_withholding' => '1500000',
        'pay.gross_per_period_cents' => '384615',
        'family.dependents_count' => '0',
        'family.qualifying_children_under_17' => '0',
        'employer.has_401k' => 'yes',
        'employer.match_pct' => '50',
        'employer.match_threshold_pct' => '6',
        'employer.contribution_pct' => '3',
        'retirement.traditional_401k_ytd_cents' => '300000',
        'retirement.roth_401k_ytd_cents' => '0',
        'hsa.ytd_contribution_cents' => '0',
        'benefits.fsa_ytd_cents' => '0',
        'ira.traditional_ytd_contribution_cents' => '0',
        'ira.roth_ytd_contribution_cents' => '0',
        'person.birth_year' => '1985',
        'retirement.target_age' => '65',
        'income.annual_gross_cents' => '10000000',
    ];

    foreach ($facts as $key => $value) {
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: $key,
            value: $value,
            sourceType: 'user_edit',
            label: $key,
            volatility: 'stable',
            taxYear: str_contains($key, '_ytd_') ? $year : null,
        );
    }
}

it('GET /optimizer/scenarios/{year} makes zero HTTP calls', function () {
    Http::fake();
    $user = noClaude_user();
    Cache::flush();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026')
        ->assertOk();

    Http::assertNothingSent();
})->group('no-claude');

it('POST /optimizer/scenarios/{year}/compute makes zero HTTP calls', function () {
    Http::fake();
    $user = noClaude_user();
    noClaude_seedFacts($user);

    $knobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 5.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/compute', ['knobs' => $knobs])
        ->assertOk();

    Http::assertNothingSent();
})->group('no-claude');

it('POST /optimizer/scenarios/{year}/choose makes zero HTTP calls', function () {
    Http::fake();
    $user = noClaude_user();
    noClaude_seedFacts($user);
    Cache::flush();

    $getResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026');
    Http::assertNothingSent();

    $optionKey = $getResponse->json('options.0.key') ?? 'balanced';

    Http::fake();   // reset for the choose call
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey])
        ->assertOk();

    Http::assertNothingSent();
})->group('no-claude');

it('GET /optimizer/checklist/{year} makes zero HTTP calls', function () {
    Http::fake();
    $user = noClaude_user();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/checklist/2026')
        ->assertOk();

    Http::assertNothingSent();
})->group('no-claude');

it('PATCH /optimizer/checklist/items/{item} makes zero HTTP calls', function () {
    Http::fake();
    $user = noClaude_user();

    // Create a checklist item directly
    $factSet = ScenarioFactSet::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'fact_set_hash' => 'no-claude-test-hash',
        'resolved_facts' => json_encode([]),
    ]);

    $item = OptimizationChecklistItem::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'source_type' => 'scenario_choice',
        'fact_set_id' => $factSet->id,
        'knob' => 'k6',
        'step_key' => 'k6_directive',
        'kind' => 'directive',
        'benefit_line_params' => ['amount' => 10000, 'period_label' => '2 weeks', 'annual' => 260000],
        'position' => 1,
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/optimizer/checklist/items/' . $item->id, ['done' => true])
        ->assertOk();

    Http::assertNothingSent();
})->group('no-claude');

it('GET /optimizer/objectives/{year} makes zero HTTP calls', function () {
    Http::fake();
    $user = noClaude_user();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/objectives/2026')
        ->assertOk();

    Http::assertNothingSent();
})->group('no-claude');
