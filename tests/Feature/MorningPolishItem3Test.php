<?php

/**
 * Morning Polish Batch — Item 3: Checklist activation UX
 *
 * Gated checklist items (kind='confirm_ask') must include the per-item
 * blocking unconfirmed fact keys with labels + display values, so the
 * frontend can render:
 *   "Activate by confirming: Expected annual income $XXX [Confirm]"
 * inline — instead of the generic "Confirm your facts in the interview."
 *
 * API contract:
 *   GET /optimizer/checklist/{year}
 *   Each item with kind='confirm_ask' includes:
 *     gated_facts: [{fact_key, label, display_value, fact_id}]
 *   directive items have gated_facts as null or []
 */

use App\Models\OptimizationChecklistItem;
use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

// ── Helper ────────────────────────────────────────────────────────────────────

function makeChecklistItem(
    User $user,
    string $kind = 'confirm_ask',
    string $knobKey = 'k1',
    int $position = 1
): OptimizationChecklistItem {
    return OptimizationChecklistItem::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'knob' => $knobKey,
        'step_key' => "{$knobKey}_step_1",
        'kind' => $kind,
        'source_type' => 'scenario_choice',
        'benefit_line_params' => ['annual' => 120000],
        'position' => $position,
    ]);
}

// ── Item 3a: confirm_ask item → gated_facts in API response ─────────────────

it('Item3: confirm_ask checklist item → response includes gated_facts array', function () {
    makeChecklistItem($this->user, 'confirm_ask', 'k1', 1);

    $response = $this->getJson('/api/v1/optimizer/checklist/2026');

    $response->assertOk();

    $items = $response->json('items');
    $confirmAsk = collect($items)->firstWhere('kind', 'confirm_ask');

    expect($confirmAsk)->not->toBeNull()
        ->and($confirmAsk)->toHaveKey('gated_facts')
        ->and($confirmAsk['gated_facts'])->toBeArray();
});

// ── Item 3b: directive item → gated_facts null ────────────────────────────────

it('Item3: directive checklist item → gated_facts is null or absent', function () {
    // k6 has no KNOB_ANCHOR_FACTS → always directive
    makeChecklistItem($this->user, 'directive', 'k6', 1);

    // Confirm all k1 anchors so k6 becomes directive
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'interview_answer',
        label: 'Filing status',
    );

    $response = $this->getJson('/api/v1/optimizer/checklist/2026');

    $response->assertOk();

    $items = $response->json('items');
    $directive = collect($items)->firstWhere('kind', 'directive');

    // directive items have no gated_facts
    expect($directive)->not->toBeNull();
    expect($directive['gated_facts'] ?? null)->toBeNull();
});

// ── Item 3c: gated_fact entry has label and display_value when fact exists ────

it('Item3: gated_facts entry includes label and display_value when resolved snapshot exists', function () {
    makeChecklistItem($this->user, 'confirm_ask', 'k1', 1);

    // Seed a snapshot for one of the k1 anchors
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'profile_field',  // not confirmed
        label: 'Filing status',
    );

    $response = $this->getJson('/api/v1/optimizer/checklist/2026');

    $response->assertOk();

    $items = $response->json('items');
    $confirmAsk = collect($items)->firstWhere('kind', 'confirm_ask');

    expect($confirmAsk)->not->toBeNull();
    $gatedFacts = $confirmAsk['gated_facts'];
    expect($gatedFacts)->toBeArray()->not->toBeEmpty();

    // Each gated fact must have required keys
    $firstFact = $gatedFacts[0];
    expect($firstFact)->toHaveKeys(['fact_key', 'label', 'display_value', 'fact_id']);
    expect($firstFact['fact_key'])->toBeString();
    expect($firstFact['label'])->toBeString();
});

// ── Item 3d: confirmed facts are NOT in gated_facts (only unconfirmed) ───────

it('Item3: confirmed anchor facts are excluded from gated_facts', function () {
    // k1 anchors: profile.filing_status, w4.filing_status
    // Confirm profile.filing_status — only w4.filing_status should remain gated
    makeChecklistItem($this->user, 'confirm_ask', 'k1', 1);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'interview_answer',  // confirmed
        label: 'Filing status',
    );

    $response = $this->getJson('/api/v1/optimizer/checklist/2026');

    $response->assertOk();

    $items = $response->json('items');
    $confirmAsk = collect($items)->firstWhere('kind', 'confirm_ask');

    expect($confirmAsk)->not->toBeNull();
    $gatedFacts = $confirmAsk['gated_facts'];

    // profile.filing_status is now confirmed — should not be in gated_facts
    $confirmedInGated = collect($gatedFacts ?? [])
        ->where('fact_key', 'profile.filing_status')
        ->count();

    expect($confirmedInGated)->toBe(0);
});
