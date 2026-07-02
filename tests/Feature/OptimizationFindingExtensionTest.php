<?php

use App\Models\OptimizationFinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

// ── Column Existence (FLAG-13) ────────────────────────────────────────────────

it('has transaction_ids jsonb column', function () {
    expect(Schema::hasColumn('optimization_findings', 'transaction_ids'))->toBeTrue();
});

it('has treatment text column', function () {
    expect(Schema::hasColumn('optimization_findings', 'treatment'))->toBeTrue();
});

it('has legal_basis text column', function () {
    expect(Schema::hasColumn('optimization_findings', 'legal_basis'))->toBeTrue();
});

it('has assumptions jsonb column', function () {
    expect(Schema::hasColumn('optimization_findings', 'assumptions'))->toBeTrue();
});

it('has band string column', function () {
    expect(Schema::hasColumn('optimization_findings', 'band'))->toBeTrue();
});

it('has user_assertions encrypted text column', function () {
    expect(Schema::hasColumn('optimization_findings', 'user_assertions'))->toBeTrue();
});

it('has docs_captured jsonb column', function () {
    expect(Schema::hasColumn('optimization_findings', 'docs_captured'))->toBeTrue();
});

it('has docs_missing jsonb column', function () {
    expect(Schema::hasColumn('optimization_findings', 'docs_missing'))->toBeTrue();
});

it('has estimated_value_cents biginteger column', function () {
    expect(Schema::hasColumn('optimization_findings', 'estimated_value_cents'))->toBeTrue();
});

it('has pro_export_ready boolean column', function () {
    expect(Schema::hasColumn('optimization_findings', 'pro_export_ready'))->toBeTrue();
});

it('has deadline date column', function () {
    expect(Schema::hasColumn('optimization_findings', 'deadline'))->toBeTrue();
});

it('has lead_time_days integer column', function () {
    expect(Schema::hasColumn('optimization_findings', 'lead_time_days'))->toBeTrue();
});

it('has net_cash_cost biginteger column', function () {
    expect(Schema::hasColumn('optimization_findings', 'net_cash_cost'))->toBeTrue();
});

it('has tax_saved biginteger column', function () {
    expect(Schema::hasColumn('optimization_findings', 'tax_saved'))->toBeTrue();
});

it('has cliff_bonus_value biginteger column', function () {
    expect(Schema::hasColumn('optimization_findings', 'cliff_bonus_value'))->toBeTrue();
});

it('has reversible boolean column', function () {
    expect(Schema::hasColumn('optimization_findings', 'reversible'))->toBeTrue();
});

// ── Model Casts (FLAG-13) ─────────────────────────────────────────────────────

it('casts transaction_ids as array', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'transaction_ids' => [1, 2, 3],
    ]);

    $fresh = $finding->fresh();
    expect($fresh->transaction_ids)->toBeArray()
        ->and($fresh->transaction_ids)->toBe([1, 2, 3]);
});

it('casts assumptions as array', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding_2',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'assumptions' => ['irc_section' => '§179', 'authority' => 'IRS'],
    ]);

    $fresh = $finding->fresh();
    expect($fresh->assumptions)->toBeArray()
        ->and($fresh->assumptions['irc_section'])->toBe('§179');
});

it('casts docs_captured as array', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding_3',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'docs_captured' => ['mileage_log' => true],
    ]);

    $fresh = $finding->fresh();
    expect($fresh->docs_captured)->toBeArray();
});

it('casts docs_missing as array', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding_4',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'docs_missing' => ['rx_letter'],
    ]);

    $fresh = $finding->fresh();
    expect($fresh->docs_missing)->toBeArray();
});

it('encrypts user_assertions', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding_5',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'user_assertions' => 'user confirmed this applies',
    ]);

    // Raw value in DB should be encrypted (not plaintext)
    $raw = \Illuminate\Support\Facades\DB::table('optimization_findings')
        ->where('id', $finding->id)
        ->value('user_assertions');

    expect($raw)->not->toBe('user confirmed this applies') // encrypted in DB
        ->and($finding->fresh()->user_assertions)->toBe('user confirmed this applies'); // decrypted via model
});

it('hides user_assertions from toArray', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding_6',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'user_assertions' => 'sensitive assertion',
    ]);

    $arr = $finding->toArray();
    expect($arr)->not->toHaveKey('user_assertions');
});

it('casts pro_export_ready as boolean', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding_7',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'pro_export_ready' => true,
    ]);

    expect($finding->fresh()->pro_export_ready)->toBeBool()->toBeTrue();
});

it('casts reversible as boolean when set', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding_8',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'reversible' => false,
    ]);

    expect($finding->fresh()->reversible)->toBeBool()->toBeFalse();
});

it('casts deadline as date', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_finding_9',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
        'deadline' => '2026-04-15',
    ]);

    expect($finding->fresh()->deadline)->toBeInstanceOf(\Carbon\Carbon::class);
});

// ── Existing functionality preserved ─────────────────────────────────────────

it('preserves existing details array cast', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'test_legacy',
        'finding_type' => 'income_gap',
        'severity' => 'high',
        'status' => 'open',
        'details' => ['gap_cents' => 500_00, 'gap_pct' => 0.05],
    ]);

    expect($finding->fresh()->details)->toBeArray()
        ->and($finding->fresh()->details['gap_cents'])->toBe(500_00);
});

it('preserves scopeForUser scope', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'scope_test',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
    ]);

    expect(OptimizationFinding::forUser($user->id)->count())->toBe(1)
        ->and(OptimizationFinding::forUser($other->id)->count())->toBe(0);
});

it('description remains null by default', function () {
    $user = User::factory()->create();
    $finding = OptimizationFinding::create([
        'user_id' => $user->id,
        'tax_year' => 2026,
        'finding_key' => 'null_desc_test',
        'finding_type' => 'test',
        'severity' => 'medium',
        'status' => 'open',
    ]);

    expect($finding->fresh()->description)->toBeNull();
});
