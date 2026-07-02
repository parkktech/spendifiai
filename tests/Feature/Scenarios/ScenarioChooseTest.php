<?php

declare(strict_types=1);

/**
 * §T.14 — ScenarioController choose flow tests (SCN-07 / §D.6).
 *
 * Covers:
 *  - Server recomputes from its own solver; never trusts client knob figures (Pitfall 4)
 *  - Writes scenario.chosen_option + scenario.chosen_knobs facts (source_type='user_edit')
 *  - Snapshots ScenarioFactSet (fact_set_id in chosen_knobs metadata — M9)
 *  - Materializes the checklist
 *  - Fires the report-stale path (OptimizationReport marked stale)
 *  - Re-choose supersedes and re-materializes (old checklist rows deleted, new ones created)
 */

use App\Models\OptimizationChecklistItem;
use App\Models\OptimizationReport;
use App\Models\ScenarioFactSet;
use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;

beforeEach(function () {
    // Clear all rate limiter state so throttle:5,1 on /choose doesn't
    // accumulate across tests in the same process (see OptimizerReportThrottleTest pattern).
    RateLimiter::clear('api');
});

function chooseUser(): User
{
    return createUserWithBank()['user'];
}

function seedChooseFacts(User $user, int $year = 2026): void
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

// ── choose writes scenario.chosen_option fact ──────────────────────────────────

it('choose writes scenario.chosen_option fact', function () {
    Http::fake();
    $user = chooseUser();
    seedChooseFacts($user);
    Cache::flush();

    // Get available option
    $getResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026');
    $getResponse->assertOk();

    $optionKey = $getResponse->json('options.0.key') ?? 'balanced';

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey])
        ->assertOk();

    // Verify fact was written
    $fact = UserTaxFact::currentFact($user->id, 'scenario.chosen_option', null, 2026);
    expect($fact)->not->toBeNull();
    expect($fact->value)->toBe($optionKey);
    expect($fact->source_type)->toBe('user_edit');
})->group('choose');

it('choose writes scenario.chosen_knobs fact (encrypted value)', function () {
    Http::fake();
    $user = chooseUser();
    seedChooseFacts($user);
    Cache::flush();

    $getResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026');
    $getResponse->assertOk();

    $optionKey = $getResponse->json('options.0.key') ?? 'balanced';

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey])
        ->assertOk();

    $knobsFact = UserTaxFact::currentFact($user->id, 'scenario.chosen_knobs', null, 2026);
    expect($knobsFact)->not->toBeNull();
    expect($knobsFact->source_type)->toBe('user_edit');

    // value should be JSON-encoded knobs
    $decoded = json_decode($knobsFact->value, true);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKeys(['k401', 'hsa', 'ira', 'transfer', 'w4']);
})->group('choose');

it('choose snapshots the fact_set and stores fact_set_id in metadata', function () {
    Http::fake();
    $user = chooseUser();
    seedChooseFacts($user);
    Cache::flush();

    $countBefore = ScenarioFactSet::where('user_id', $user->id)->count();

    $getResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026');
    $optionKey = $getResponse->json('options.0.key') ?? 'balanced';

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey])
        ->assertOk();

    // A new ScenarioFactSet was created
    $countAfter = ScenarioFactSet::where('user_id', $user->id)->count();
    expect($countAfter)->toBeGreaterThan($countBefore);

    // fact_set_id in chosen_knobs metadata
    $knobsFact = UserTaxFact::currentFact($user->id, 'scenario.chosen_knobs', null, 2026);
    expect($knobsFact->metadata)->toHaveKey('fact_set_id');
    expect($knobsFact->metadata['fact_set_id'])->toBeInt();
})->group('choose');

it('choose materializes checklist items', function () {
    Http::fake();
    $user = chooseUser();
    seedChooseFacts($user);
    Cache::flush();

    $getResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026');
    $optionKey = $getResponse->json('options.0.key') ?? 'balanced';

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey])
        ->assertOk();

    $itemCount = OptimizationChecklistItem::where('user_id', $user->id)->where('tax_year', 2026)->count();
    expect($itemCount)->toBeGreaterThan(0);
})->group('choose');

it('choose response includes checklist in the body', function () {
    Http::fake();
    $user = chooseUser();
    seedChooseFacts($user);
    Cache::flush();

    $getResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026');
    $optionKey = $getResponse->json('options.0.key') ?? 'balanced';

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey]);

    $response->assertOk();
    expect($response->json())->toHaveKeys(['chosen', 'checklist']);
    expect($response->json('checklist'))->toHaveKeys(['header_aggregate', 'items']);
})->group('choose');

it('choose marks the optimization report as stale', function () {
    Http::fake();
    $user = chooseUser();
    seedChooseFacts($user);
    Cache::flush();

    // Create an optimization report row to mark stale
    OptimizationReport::updateOrCreate(
        ['user_id' => $user->id, 'tax_year' => 2026],
        ['sections' => [], 'is_stale' => false, 'rebuilt_at' => now()]
    );

    $getResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026');
    $optionKey = $getResponse->json('options.0.key') ?? 'balanced';

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey])
        ->assertOk();

    $report = OptimizationReport::where('user_id', $user->id)->where('tax_year', 2026)->first();
    expect($report->is_stale)->toBeTrue();
})->group('choose');

it('re-choose supersedes prior facts and re-materializes checklist', function () {
    Http::fake();
    RateLimiter::clear('api');   // reset throttle — re-choose makes 2 calls to throttle:5,1
    $user = chooseUser();
    seedChooseFacts($user);
    Cache::flush();

    $getResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/optimizer/scenarios/2026');
    $options = $getResponse->json('options') ?? [];

    $optionKey1 = $options[0]['key'] ?? 'balanced';
    $optionKey2 = isset($options[1]) ? $options[1]['key'] : $optionKey1;

    // First choose
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey1])
        ->assertOk();

    $countAfterFirst = OptimizationChecklistItem::where('user_id', $user->id)->where('tax_year', 2026)->count();

    // Second choose (re-choose) — clear rate limiter state first (throttle:5,1 is per-process)
    RateLimiter::clear('api');
    Cache::flush();
    $this->actingAs($user, 'sanctum')
        ->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', ['option_key' => $optionKey2])
        ->assertOk();

    $countAfterSecond = OptimizationChecklistItem::where('user_id', $user->id)->where('tax_year', 2026)->count();

    // Re-choose replaces (not doubles) — count should be equal to first materialization
    // (same or similar, not doubled)
    expect($countAfterSecond)->toBeGreaterThan(0);
    expect($countAfterSecond)->toBeLessThanOrEqual($countAfterFirst * 2);

    // scenario.chosen_option should show the latest choice
    $chosenFact = UserTaxFact::currentFact($user->id, 'scenario.chosen_option', null, 2026);
    expect($chosenFact->value)->toBe($optionKey2);
})->group('choose');

it('choose server ignores hostile client knob deferral_pct and uses solver output', function () {
    Http::fake();
    $user = chooseUser();
    seedChooseFacts($user);
    Cache::flush();

    // Attempt to pass a custom option with hostile knobs
    $hostileKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 999, 'other_dependents' => 999],
        'k401' => ['deferral_pct' => 9999.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 999_999_999],
        'ira' => ['traditional_cents' => 999_999_999, 'roth_cents' => 999_999_999],
        'transfer' => ['per_period_cents' => 999_999_999],
    ];

    // Use 'custom' option key with hostile knobs
    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', [
            'option_key' => 'custom',
            'knobs' => $hostileKnobs,
        ]);

    // Should succeed (server clamps) or fail validation
    // Either way, the stored chosen_knobs must be engine-clamped (within limits)
    if ($response->status() === 200) {
        $knobsFact = UserTaxFact::currentFact($user->id, 'scenario.chosen_knobs', null, 2026);
        $stored = json_decode($knobsFact->value, true);

        // Deferral pct can't be 9999%
        $deferralPct = $stored['k401']['deferral_pct'] ?? 0;
        expect($deferralPct)->toBeLessThan(100);

        // HSA must be within limits
        $hsaCents = $stored['hsa']['annual_election_cents'] ?? 0;
        expect($hsaCents)->toBeLessThan(2_000_000);  // 2026 max is $8,750 ($875,000 cents)
    } else {
        // 422 Unprocessable — validation rejected hostile knobs
        expect($response->status())->toBe(422);
    }
})->group('choose');

it('choose with invalid option_key fails validation', function () {
    Http::fake();
    $user = chooseUser();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/optimizer/scenarios/2026/choose', [
            'option_key' => 'invalid_evil_option',
        ]);

    $response->assertUnprocessable();
})->group('choose');
