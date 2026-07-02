<?php

/**
 * AuditRiskScorerTest — dedicated TDD coverage for AuditRiskScorer (FLAG-15)
 *
 * Tests the 9-factor deterministic score computation, protective framing,
 * and the absence of numeric probability language.
 */

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\Detectors\AuditRiskScorer;
use App\Services\RedFlagDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
    $this->detector = app(AuditRiskScorer::class);
});

// ── Score threshold gating ─────────────────────────────────────────────────────

it('stays silent when score is below threshold (< 2 factors)', function () {
    // Only 1 factor — charitable outlier but no deposit mismatch
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '5000000', // matches income — no mismatch
        'charitable_contributions' => '1500000', // 30% of income — outlier factor
        'self_employment_income' => null,
    ]);

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('emits when score reaches threshold (>= 2 factors)', function () {
    // Factor 1: outsized charitable (30% of income)
    // Factor 2: deposit mismatch (deposits 160% of income)
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '8000000',
        'charitable_contributions' => '1500000',
        'self_employment_income' => null,
    ]);

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('audit_risk_score');
});

it('emits when 100% vehicle use claim is one of the factors', function () {
    // Factor 1: 100% vehicle use claim
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'vehicle.business_use_pct',
        value: '100',
        sourceType: 'interview_answer',
    );
    // Factor 2: deposit mismatch
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '9000000',
        'charitable_contributions' => '0',
        'self_employment_income' => null,
    ]);

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('audit_risk_score');
});

it('emits when SE income present without estimated payments', function () {
    // Factor 1: SE income without estimated payments
    // Factor 2: deposit mismatch
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '0',
        'self_employment_income' => '5000000',
        'bank_deposit_total' => '9000000',
        'charitable_contributions' => '0',
    ]);
    // No tax.estimated_payments_ytd fact → triggers factor 1

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('audit_risk_score');
});

// ── Framing constraints (FLAG-15 locked) ──────────────────────────────────────

it('finding contains "scrutiny" (protective framing)', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '8000000',
        'charitable_contributions' => '1500000',
        'self_employment_income' => null,
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'audit_risk_score')
        ->first();

    expect($finding)->not->toBeNull();
    expect(strtolower($finding->treatment))->toContain('scrutiny');
});

it('finding does not contain a numeric audit probability', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '8000000',
        'charitable_contributions' => '1500000',
        'self_employment_income' => null,
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'audit_risk_score')
        ->first();

    expect($finding)->not->toBeNull();
    // No numeric percentage in the treatment text
    expect($finding->treatment)->not->toMatch('/\d+%/');
    expect($finding->treatment)->not->toContain('audit probability');
    expect($finding->treatment)->not->toContain('chance of audit');
    expect($finding->treatment)->not->toContain('likelihood of audit');
});

it('finding does not use accusatory language', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '8000000',
        'charitable_contributions' => '1500000',
        'self_employment_income' => null,
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'audit_risk_score')
        ->first();

    expect($finding)->not->toBeNull();
    $treatment = strtolower($finding->treatment);
    expect($treatment)->not->toContain('you cheated');
    expect($treatment)->not->toContain('you committed');
    expect($treatment)->not->toContain('you falsified');
    expect($treatment)->not->toContain('fraud');
    expect($treatment)->not->toContain('illegal');
});

it('finding severity is derived from band (not null)', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '8000000',
        'charitable_contributions' => '1500000',
        'self_employment_income' => null,
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'audit_risk_score')
        ->first();

    expect($finding)->not->toBeNull();
    expect($finding->severity)->toBeIn(['high', 'medium']);
    expect($finding->band)->toBe('conditional'); // audit risk is conditional band
});

it('does not assign estimated_value_cents (SAFE-03)', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '8000000',
        'charitable_contributions' => '1500000',
        'self_employment_income' => null,
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'audit_risk_score')
        ->first();

    expect($finding)->not->toBeNull();
    expect($finding->estimated_value_cents)->toBeNull();
});

it('makes no HTTP calls', function () {
    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect(true)->toBeTrue();
});
