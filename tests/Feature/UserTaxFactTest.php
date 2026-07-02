<?php

use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Guard: no HTTP calls touch facts ────────────────────────────────────────
beforeEach(function () {
    Http::preventStrayRequests();
});

// ─── RED phase stub: class must exist and recordFact must work ────────────────

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
    // Old row's encrypted value is unchanged (append-only — we never UPDATE the value column)
    expect((int) $first->value)->toBe(100000);

    // New row: is_current=true
    expect($second->is_current)->toBeTrue();
    expect((int) $second->value)->toBe(120000);

    // Exactly two rows total
    expect(UserTaxFact::where('user_id', $user->id)->count())->toBe(2);
});
