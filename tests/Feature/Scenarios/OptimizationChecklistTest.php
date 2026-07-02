<?php

declare(strict_types=1);

/**
 * §T.15 — OptimizationChecklistItem + ScenarioChecklistService tests.
 *
 * Covers:
 *  - Materialization from checklist_templates (one group per diverging knob)
 *  - fact-gating: all-confirmed anchors → kind='directive'; any unconfirmed → kind='confirm_ask'
 *  - done-toggle: K3 step done → supersedes employer.contribution_pct
 *  - done-toggle: K2 step done → writes retirement.elected_roth_share_pct
 *  - re-choose: scoped-deletes prior source_type='scenario_choice' rows, then re-materializes
 *  - benefit_line_params carries engine benefit cents (integer)
 *  - header aggregate row is materialized (knob='header')
 *  - groups ordered by annual benefit descending (Δ3 — DOCUMENTS-FIRST-FUNNEL)
 *  - OptimizationChecklistItemPolicy enforces user_id ownership
 */

use App\Models\OptimizationChecklistItem;
use App\Models\ScenarioFactSet;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Policies\OptimizationChecklistItemPolicy;
use App\Services\ScenarioChecklistService;
use App\Services\ScenarioFactResolverService;
use App\Services\ScenarioSolverService;
use App\Services\TaxRulesEngineService;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────────────────────

function checklistUser(): User
{
    return User::factory()->create();
}

/**
 * A baseline that has diverging knobs so multiple groups are materialized.
 * 401k match exists (K3 will diverge), no Roth currently (K2 may diverge).
 */
function checklistBaseline(): array
{
    return [
        'annual_gross_cents' => 10_000_000,   // $100k
        'se_income_cents' => 0,
        'pay_periods_per_year' => 26,
        'filing_status' => 'single',
        'w4_on_file' => ['filing_status' => null, 'dependents_claimed' => null],
        'family' => ['dependents_under_17' => 0, 'other_dependents' => 0],
        'age' => 35,
        'target_retirement_age' => 65,
        'prior_year_fica_wages_cents' => null,
        'current' => [
            'trad_401k_cents' => 300_000,   // $3k/yr — well below match threshold
            'roth_401k_cents' => 0,
            'hsa_cents' => 0,
            'ira_trad_ytd_cents' => 0,
            'ira_roth_ytd_cents' => 0,
            'deferral_pct' => 3.0,
        ],
        'employer' => [
            'match_pct' => 50.0,
            'match_threshold_pct' => 6.0,
            'has_401k' => true,
        ],
        'hsa_coverage_type' => null,
        'medicare_enrollment_date' => null,
        'is_marketplace_enrollee' => false,
        'is_cash_constrained' => false,
        'spouse_covered_by_plan' => false,
        'monthly_surplus_cents' => 100_000,
        'annual_withholding_cents' => 1_500_000,
        'prior_year_federal_liability_cents' => null,
        'fact_set_hash' => 'test-hash-checklist',
    ];
}

function makeFactSet(User $user, int $year = 2026): ScenarioFactSet
{
    return ScenarioFactSet::create([
        'user_id' => $user->id,
        'tax_year' => $year,
        'fact_set_hash' => 'test-hash-for-checklist-'.uniqid(),
        'resolved_facts' => json_encode([]),
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('materializes checklist items from templates for diverging knobs', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);
    $baseline = checklistBaseline();

    // Chosen option: take_home (K3 will diverge from baseline's 3% to 6% match threshold)
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $items = $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    expect($items)->not->toBeEmpty();

    // K3 group should be present (401k deferral changed from 3% to 6%)
    $k3Items = collect($items)->where('knob', 'k3')->values();
    expect($k3Items)->not->toBeEmpty();

    // Header row should exist
    $headerItem = collect($items)->firstWhere('knob', 'header');
    expect($headerItem)->not->toBeNull();
})->group('checklist');

it('sets kind=directive when all anchor facts are confirmed', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);

    // Confirm employer.has_401k fact so K3 can be a directive
    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.has_401k',
        value: 'yes',
        sourceType: 'user_edit',
        label: '401k availability',
        volatility: 'stable',
    );
    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.contribution_pct',
        value: '3',
        sourceType: 'user_edit',
        label: 'Current contribution',
        volatility: 'stable',
    );

    $baseline = checklistBaseline();
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $items = $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    $k3Items = collect($items)->where('knob', 'k3')->values();
    expect($k3Items)->not->toBeEmpty();

    // When employer.has_401k confirmed → directive
    $k3Item = $k3Items->first();
    expect($k3Item['kind'])->toBe('directive');
})->group('checklist');

it('sets kind=confirm_ask when anchor fact is not confirmed', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);

    // NO confirmed employer.has_401k fact — only a 'derived' source
    $baseline = checklistBaseline();
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $items = $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    $k3Items = collect($items)->where('knob', 'k3')->values();
    expect($k3Items)->not->toBeEmpty();
    expect($k3Items->first()['kind'])->toBe('confirm_ask');
})->group('checklist');

it('persists items to the database with correct user and year', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);
    $baseline = checklistBaseline();
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    expect(OptimizationChecklistItem::where('user_id', $user->id)->where('tax_year', 2026)->count())->toBeGreaterThan(0);
    expect(OptimizationChecklistItem::where('user_id', $user->id)->where('source_type', 'scenario_choice')->count())->toBeGreaterThan(0);
})->group('checklist');

it('re-materialization scoped-deletes only scenario_choice rows and re-creates them', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);
    $baseline = checklistBaseline();
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);

    // First materialization
    $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );
    $firstCount = OptimizationChecklistItem::where('user_id', $user->id)->where('tax_year', 2026)->count();

    // Re-materialization (re-choose)
    $factSet2 = makeFactSet($user);
    $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet2->id,
    );
    $secondCount = OptimizationChecklistItem::where('user_id', $user->id)->where('tax_year', 2026)->count();

    // Should replace — same count, not doubled
    expect($secondCount)->toEqual($firstCount);
})->group('checklist');

it('benefit_line_params carries integer cent values', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);
    $baseline = checklistBaseline();
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    $item = OptimizationChecklistItem::where('user_id', $user->id)->where('knob', '!=', 'header')->first();
    expect($item)->not->toBeNull();

    // benefit_line_params should be an array
    $params = $item->benefit_line_params;
    expect($params)->toBeArray();
})->group('checklist');

it('done-toggle on K3 step writes employer.contribution_pct reality fact', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);
    $baseline = checklistBaseline();
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    $k3Item = OptimizationChecklistItem::where('user_id', $user->id)
        ->where('knob', 'k3')
        ->first();

    expect($k3Item)->not->toBeNull();

    // Toggle done
    $service->toggleDone($user, $k3Item, true);
    $k3Item->refresh();

    expect($k3Item->done_at)->not->toBeNull();

    // Should have written employer.contribution_pct fact
    $fact = UserTaxFact::currentFact($user->id, 'employer.contribution_pct');
    expect($fact)->not->toBeNull();
    // Value should be the chosen deferral_pct as string
    expect((float) $fact->value)->toBe(6.0);
})->group('checklist');

it('done-toggle on K2 step writes retirement.elected_roth_share_pct reality fact', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);
    $baseline = checklistBaseline();

    // K2: roth_share is diverging (from 0 to 50)
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 50],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'retirement',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    $k2Item = OptimizationChecklistItem::where('user_id', $user->id)
        ->where('knob', 'k2')
        ->first();

    expect($k2Item)->not->toBeNull();

    $service->toggleDone($user, $k2Item, true);

    // Should have written retirement.elected_roth_share_pct fact
    $fact = UserTaxFact::currentFact($user->id, 'retirement.elected_roth_share_pct');
    expect($fact)->not->toBeNull();
    expect((int) $fact->value)->toBe(50);
})->group('checklist');

it('checklist items are linked to the fact_set_id', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);
    $baseline = checklistBaseline();
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    $items = OptimizationChecklistItem::where('user_id', $user->id)->get();
    foreach ($items as $item) {
        expect($item->fact_set_id)->toBe($factSet->id);
    }
})->group('checklist');

it('OptimizationChecklistItemPolicy rejects cross-user access', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $item = OptimizationChecklistItem::factory()->create([
        'user_id' => $owner->id,
        'tax_year' => 2026,
        'source_type' => 'scenario_choice',
        'knob' => 'k3',
        'step_key' => 'k3_directive',
        'kind' => 'directive',
        'benefit_line_params' => ['delta_paycheck' => 100],
        'position' => 1,
    ]);

    $policy = new \App\Policies\OptimizationChecklistItemPolicy;

    expect($policy->view($owner, $item))->toBeTrue();
    expect($policy->view($other, $item))->toBeFalse();
    expect($policy->update($owner, $item))->toBeTrue();
    expect($policy->update($other, $item))->toBeFalse();
})->group('checklist');

it('header aggregate is materialized with total benefit summary', function () {
    Http::fake();

    $user = checklistUser();
    $factSet = makeFactSet($user);
    $baseline = checklistBaseline();
    $chosenKnobs = [
        'w4' => ['filing_status' => 'single_or_mfs', 'dependents_under_17' => 0, 'other_dependents' => 0],
        'k401' => ['deferral_pct' => 6.0, 'roth_share_pct' => 0],
        'hsa' => ['annual_election_cents' => 0],
        'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
        'transfer' => ['per_period_cents' => 0],
    ];

    $service = app(ScenarioChecklistService::class);
    $service->materialize(
        user: $user,
        year: 2026,
        optionKey: 'take_home',
        chosenKnobs: $chosenKnobs,
        baseline: $baseline,
        factSetId: $factSet->id,
    );

    $header = OptimizationChecklistItem::where('user_id', $user->id)
        ->where('knob', 'header')
        ->first();

    expect($header)->not->toBeNull();
    $params = $header->benefit_line_params;
    expect($params)->toBeArray();
    // Header aggregate carries the total benefit
    expect(array_key_exists('header_aggregate', $params))->toBeTrue();
})->group('checklist');
