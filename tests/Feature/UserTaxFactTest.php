<?php

use App\Models\IncomeOptimizationProfile;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Guard: no HTTP calls touch facts ────────────────────────────────────────
beforeEach(function () {
    Http::preventStrayRequests();
});

// ─────────────────────────────────────────────────────────────────────────────
// STORE-01: Append-only — no UPDATE of value column
// ─────────────────────────────────────────────────────────────────────────────

it('append_only_no_update: superseding creates a new row; old row is preserved with is_current=false', function () {
    $user = User::factory()->create();

    $first = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.pretax_balance_range',
        value: '100000',
        sourceType: 'interview_answer',
    );

    expect($first->is_current)->toBeTrue();
    expect($first->superseded_by_id)->toBeNull();

    $second = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.pretax_balance_range',
        value: '120000',
        sourceType: 'interview_answer',
    );

    $first->refresh();

    // Old row: is_current=false, superseded_by_id points to new row
    expect($first->is_current)->toBeFalse();
    expect($first->superseded_by_id)->toBe($second->id);

    // Old row's encrypted value is unchanged (append-only — we NEVER UPDATE the value column;
    // we only set is_current=false and superseded_by_id on the old row)
    expect((int) $first->value)->toBe(100000);

    // New row: is_current=true
    expect($second->is_current)->toBeTrue();
    expect((int) $second->value)->toBe(120000);

    // Exactly two rows total (append-only — no rows deleted)
    expect(UserTaxFact::where('user_id', $user->id)->count())->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// STORE-01: Concurrency — partial unique index enforces one is_current row
// ─────────────────────────────────────────────────────────────────────────────

it('partial_unique_concurrency: two sequential recordFact calls yield exactly one is_current row', function () {
    $user = User::factory()->create();

    // Call recordFact twice for the same key tuple — simulates two sequential writes
    // (true concurrent tests are environment-dependent; we test that the model logic
    //  handles the supersession correctly and the index is never violated)
    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'method.mileage',
        value: '15000',
        sourceType: 'interview_answer',
    );

    UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'method.mileage',
        value: '18000',
        sourceType: 'interview_answer',
    );

    // Exactly one is_current=true row
    $currentCount = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'method.mileage')
        ->where('is_current', true)
        ->count();
    expect($currentCount)->toBe(1);

    // The current row has the latest value
    $current = UserTaxFact::currentFact($user->id, 'method.mileage');
    expect($current)->not->toBeNull();
    expect((int) $current->value)->toBe(18000);

    // Two total rows (append-only)
    $totalCount = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'method.mileage')
        ->count();
    expect($totalCount)->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// STORE-01: Confirm gate — confirmed document fact feeds answerableFields
// ─────────────────────────────────────────────────────────────────────────────

it('confirmed_fact_answerable: a confirmed document_extraction fact makes the key answerable', function () {
    $user = User::factory()->create();
    $profile = IncomeOptimizationProfile::create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'data_sources' => [],
    ]);

    // Write a document_extraction proposal (is_current=false initially)
    $proposal = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.match_pct',
        value: '50',
        sourceType: 'document_extraction',
        metadata: ['field_confidence' => ['match_pct' => 0.92]],
    );

    // Before confirmation: proposal is NOT answerable
    expect($profile->answerableFields(new UserTaxFact)['employer.match_pct'] ?? false)->toBeFalse();

    // Confirm the proposal
    UserTaxFact::confirmProposal($proposal->id);

    // After confirmation: fact IS answerable
    expect($profile->answerableFields(new UserTaxFact)['employer.match_pct'])->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// STORE-01: Confirm gate — unconfirmed proposal excluded from skip-logic
// ─────────────────────────────────────────────────────────────────────────────

it('proposal_not_answerable: unconfirmed document_extraction proposal stays false and does not supersede user_edit', function () {
    $user = User::factory()->create();
    $profile = IncomeOptimizationProfile::create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'data_sources' => [],
    ]);

    // User has already provided an interview_answer for this key
    $userFact = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.match_pct',
        value: '50',
        sourceType: 'interview_answer',
    );
    expect($userFact->is_current)->toBeTrue();

    // A document_extraction proposal arrives but is NOT confirmed
    $proposal = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.match_pct',
        value: '60',  // different value from document
        sourceType: 'document_extraction',
    );

    // Proposal must NOT be current (confirm gate: never overwrite user_edit/interview_answer)
    expect($proposal->is_current)->toBeFalse();
    expect($proposal->confirmed_at)->toBeNull();

    // The original interview_answer must still be the current fact
    $userFact->refresh();
    expect($userFact->is_current)->toBeTrue();

    // The proposal does NOT appear in currentFactKeys (excluded from skip-logic)
    $keys = UserTaxFact::currentFactKeys($user->id);
    expect(in_array('employer.match_pct', $keys))->toBeTrue(); // still answerable via user fact
    // The proposal must not be counted as a separate answerable entry (it stays is_current=false)
    // Verify only ONE current row for this key (the user_edit, not the proposal)
    $currentCount = UserTaxFact::where('user_id', $user->id)
        ->where('fact_key', 'employer.match_pct')
        ->where('is_current', true)
        ->count();
    expect($currentCount)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// STORE-01: Provenance round-trip
// ─────────────────────────────────────────────────────────────────────────────

it('provenance_round_trip: source_type, source_id, asserted_at, and confirmed_at persist through encrypted cast', function () {
    $user = User::factory()->create();

    $assertedAt = now()->subHours(2);

    $fact = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.roth_ytd_cents',
        value: '750000',  // $7,500 Roth contribution
        sourceType: 'interview_answer',
        sourceId: 'session_123',
        label: 'Roth IRA YTD contribution',
        volatility: 'annual',
        taxYear: 2025,
    );

    $found = UserTaxFact::find($fact->id);

    // Provenance fields persisted correctly
    expect($found->source_type)->toBe('interview_answer');
    expect($found->source_id)->toBe('session_123');
    expect($found->label)->toBe('Roth IRA YTD contribution');
    expect($found->volatility)->toBe('annual');
    expect($found->tax_year)->toBe(2025);
    expect($found->asserted_at)->not->toBeNull();
    expect($found->confirmed_at)->toBeNull();  // interview_answer needs no confirmation

    // Value round-trips through encryption
    expect((int) $found->value)->toBe(750000);

    // Value hidden from toArray()
    $arr = $found->toArray();
    expect($arr)->not->toHaveKey('value');
});

// ─────────────────────────────────────────────────────────────────────────────
// STORE-01: Carry-forward — stable fact past reconfirm_months is flagged
// ─────────────────────────────────────────────────────────────────────────────

it('carry_forward: a stable fact past reconfirm_months threshold is flagged for reconfirmation', function () {
    // Set config inline so this test never hard-depends on 11-01's config file
    config(['tax-rules.facts.reconfirm_months' => 12]);

    $user = User::factory()->create();

    // Fact asserted 14 months ago (past the 12-month threshold)
    $fact = UserTaxFact::create([
        'user_id' => $user->id,
        'fact_key' => 'employer.match_pct',
        'value' => '50',
        'volatility' => 'stable',
        'source_type' => 'interview_answer',
        'asserted_at' => now()->subMonths(14),
        'is_current' => true,
    ]);

    expect($fact->isDueForReconfirmation())->toBeTrue();

    // Fact asserted 6 months ago (within the 12-month threshold)
    $recentFact = UserTaxFact::create([
        'user_id' => $user->id,
        'fact_key' => 'method.mileage',
        'value' => '15000',
        'volatility' => 'stable',
        'source_type' => 'interview_answer',
        'asserted_at' => now()->subMonths(6),
        'is_current' => true,
    ]);

    expect($recentFact->isDueForReconfirmation())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// STORE-01: Multi-account retirement — Roth + Traditional coexist as separate facts
// ─────────────────────────────────────────────────────────────────────────────

it('retirement_multi_account: roth_ytd_cents and traditional_ytd_cents coexist as separate current facts', function () {
    $user = User::factory()->create();

    // Record Roth IRA contributions
    $roth = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.roth_ytd_cents',
        value: '400000',  // $4,000 to Roth
        sourceType: 'interview_answer',
        volatility: 'annual',
        taxYear: 2025,
    );

    // Record Traditional IRA contributions (DIFFERENT fact_key — separate current row)
    $traditional = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'ira.traditional_ytd_cents',
        value: '350000',  // $3,500 to Traditional
        sourceType: 'interview_answer',
        volatility: 'annual',
        taxYear: 2025,
    );

    // Both facts must be current simultaneously (shared limit check is caller's responsibility)
    expect($roth->is_current)->toBeTrue();
    expect($traditional->is_current)->toBeTrue();

    // Combined = $4,000 + $3,500 = $7,500 (the shared 2025 IRA limit)
    $combined = (int) $roth->value + (int) $traditional->value;
    expect($combined)->toBe(750000);  // 750,000 cents = $7,500.00

    // Each can be retrieved independently
    $rothCurrent = UserTaxFact::currentFact($user->id, 'ira.roth_ytd_cents', null, 2025);
    $tradCurrent = UserTaxFact::currentFact($user->id, 'ira.traditional_ytd_cents', null, 2025);

    expect($rothCurrent)->not->toBeNull();
    expect($tradCurrent)->not->toBeNull();
    expect((int) $rothCurrent->value)->toBe(400000);
    expect((int) $tradCurrent->value)->toBe(350000);

    // Legacy ira_type column on UserFinancialProfile is UNTOUCHED
    // (multi-account facts live in UserTaxFact; the snapshot ira_type is a single legacy string)
    $profileModel = UserFinancialProfile::where('user_id', $user->id)->first();
    expect($profileModel)->toBeNull(); // no profile created in this test — correct behaviour
});
