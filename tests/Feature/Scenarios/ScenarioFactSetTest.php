<?php

use App\Models\IncomeOptimizationProfile;
use App\Models\ScenarioFactSet;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\ScenarioFactResolverService;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ScenarioFactSet + snapshotFactSet/isStale (SCENARIOS-SPEC §A.8.2)
|--------------------------------------------------------------------------
| HMAC hash stability, isStale-on-supersession, encrypted + $hidden
| resolved_facts, and GDPR cascade.
*/

beforeEach(function () {
    $this->resolver = app(ScenarioFactResolverService::class);
    $this->user = User::factory()->create();
    $this->year = 2026;

    // A snapshot so at least one fact resolves into the set.
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->year,
        'filing_status' => 'single',
        'w2_wages' => '9000000',
    ]);
});

it('freezes a fact set with an HMAC hash and encrypted resolved_facts', function () {
    $set = $this->resolver->snapshotFactSet($this->user, $this->year);

    expect($set)->toBeInstanceOf(ScenarioFactSet::class)
        ->and($set->user_id)->toBe($this->user->id)
        ->and($set->tax_year)->toBe($this->year)
        ->and($set->fact_set_hash)->toHaveLength(64)     // sha256 hex
        ->and($set->resolvedFactsArray())->not->toBeEmpty();
});

it('produces a stable hash for identical underlying facts', function () {
    $a = $this->resolver->snapshotFactSet($this->user, $this->year);
    $b = $this->resolver->snapshotFactSet($this->user, $this->year);

    expect($a->fact_set_hash)->toBe($b->fact_set_hash)
        ->and($a->id)->not->toBe($b->id); // two distinct rows, same hash
});

it('keys the hash on app.key (HMAC, not bare sha256)', function () {
    $set = $this->resolver->snapshotFactSet($this->user, $this->year);

    // The stored hash must not equal a bare sha256 of the canonical payload —
    // proving it is HMAC-keyed. Recompute the canonical form the resolver uses.
    $resolved = array_filter(
        $this->resolver->resolveAll($this->user, $this->year),
        fn ($r) => $r !== null
    );
    ksort($resolved);
    $canonical = [];
    foreach ($resolved as $k => $f) {
        $canonical[$k] = [$f['source_ref'], $f['value']];
    }
    $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($set->fact_set_hash)->not->toBe(hash('sha256', $json))
        ->and($set->fact_set_hash)->toBe(hash_hmac('sha256', $json, (string) config('app.key')));
});

it('is not stale immediately after snapshot', function () {
    $set = $this->resolver->snapshotFactSet($this->user, $this->year);

    expect($this->resolver->isStale($set))->toBeFalse();
});

it('flips isStale true when a fact is superseded', function () {
    $set = $this->resolver->snapshotFactSet($this->user, $this->year);

    // Supersede filing status via a confirmed fact (beats the snapshot, fact-first).
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'profile.filing_status',
        value: 'married_joint',
        sourceType: 'interview_answer',
    );

    expect($this->resolver->isStale($set))->toBeTrue();
});

it('stores resolved_facts encrypted at rest', function () {
    $set = $this->resolver->snapshotFactSet($this->user, $this->year);

    $raw = DB::table('scenario_fact_sets')->where('id', $set->id)->value('resolved_facts');

    // Ciphertext must not contain the plaintext fact key or a raw money value.
    expect($raw)->not->toContain('profile.filing_status')
        ->and($raw)->not->toContain('9000000')
        ->and($set->resolvedFactsArray())->toHaveKey('profile.filing_status');
});

it('hides resolved_facts from array/JSON serialization', function () {
    $set = $this->resolver->snapshotFactSet($this->user, $this->year);

    expect($set->toArray())->not->toHaveKey('resolved_facts')
        ->and($set->toJson())->not->toContain('resolved_facts');
});

it('cascades on user delete (GDPR)', function () {
    $set = $this->resolver->snapshotFactSet($this->user, $this->year);
    $id = $set->id;

    $this->user->delete();

    expect(ScenarioFactSet::find($id))->toBeNull();
});
