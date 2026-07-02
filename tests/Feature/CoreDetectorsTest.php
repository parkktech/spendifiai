<?php

/**
 * CoreDetectorsTest — TDD RED for Plan 11-06 Task 1 & Task 2
 *
 * Tests for:
 *  - FilingStatusDetector (FLAG-02): mismatch → finding; same → silent; never assertive
 *  - WithholdingGapDetector (FLAG-03): gap > floor → finding; under → silent; no HTTP
 *  - EmployerMatchGapDetector (FLAG-04): unclaimed match → finding; at threshold → silent; framing
 *  - DeductionProbeDetector (FLAG-05): 5 probes gated on prerequisites
 *  - ComminglingMonitor (FLAG-14): personal spend in business account; locked wording
 *  - AuditRiskScorer (FLAG-15): protective framing; no numeric probability; no HTTP
 */

use App\Enums\AccountPurpose;
use App\Enums\ExpenseType;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\TaxProfileEntity;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use App\Services\Detectors\AuditRiskScorer;
use App\Services\Detectors\ComminglingMonitor;
use App\Services\Detectors\DeductionProbeDetector;
use App\Services\Detectors\EmployerMatchGapDetector;
use App\Services\Detectors\FilingStatusDetector;
use App\Services\Detectors\WithholdingGapDetector;
use App\Services\RedFlagDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// FilingStatusDetector — FLAG-02
// ──────────────────────────────────────────────────────────────────────────────

it('FilingStatusDetector stays silent when no profile exists', function () {
    $detector = app(FilingStatusDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('FilingStatusDetector stays silent when statuses match', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'single',
    ]);
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
    ]);

    $detector = app(FilingStatusDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('FilingStatusDetector emits finding on status mismatch', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'married_filing_jointly',
    ]);
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
    ]);

    $detector = app(FilingStatusDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('filing_status_mismatch');
});

it('FilingStatusDetector finding never asserts a correct status', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'married_filing_jointly',
    ]);
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
    ]);

    $detector = app(FilingStatusDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'filing_status_mismatch')
        ->first();

    expect($finding)->not->toBeNull();
    // Banned assertive phrases (FLAG-02, locked out-of-scope)
    expect($finding->treatment)->not->toContain('you should file as');
    expect($finding->treatment)->not->toContain('you are required to file as');
    expect($finding->treatment)->not->toContain('your filing status is');
    expect($finding->treatment)->not->toContain('you must file as');
});

it('FilingStatusDetector makes no HTTP calls', function () {
    $detector = app(FilingStatusDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect(true)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// WithholdingGapDetector — FLAG-03
// ──────────────────────────────────────────────────────────────────────────────

it('WithholdingGapDetector stays silent without income snapshot', function () {
    $detector = app(WithholdingGapDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('WithholdingGapDetector stays silent without withholding fact', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '7500000',
        'filing_status' => 'single',
    ]);

    $detector = app(WithholdingGapDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('WithholdingGapDetector emits when gap exceeds floor', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '10000000', // $100,000
        'filing_status' => 'single',
    ]);
    // Withholding of $10 — far below estimated tax, creating large gap
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.federal_withholding',
        value: '1000',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $detector = app(WithholdingGapDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('withholding_gap');
});

it('WithholdingGapDetector stays silent when gap is within floor', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '7500000', // $75,000
        'filing_status' => 'single',
    ]);
    // Single filer on $75k: tax ≈ $12,600 → write withholding very close to that
    // 12,600 - 12,599 = $1 gap (well under $500 floor)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.federal_withholding',
        value: '1260000', // $12,600 — near estimated tax
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $detector = app(WithholdingGapDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('WithholdingGapDetector makes no HTTP calls', function () {
    $detector = app(WithholdingGapDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect(true)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// EmployerMatchGapDetector — FLAG-04
// ──────────────────────────────────────────────────────────────────────────────

it('EmployerMatchGapDetector stays silent without match fact', function () {
    $detector = app(EmployerMatchGapDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('EmployerMatchGapDetector stays silent without contribution fact', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.match_pct',
        value: '50',
        sourceType: 'interview_answer',
    );
    // No employer.contribution_pct fact

    $detector = app(EmployerMatchGapDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('EmployerMatchGapDetector emits when contribution is below match threshold', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.match_pct',
        value: '50',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.match_threshold_pct',
        value: '6',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.contribution_pct',
        value: '3', // below 6% threshold
        sourceType: 'interview_answer',
    );

    $detector = app(EmployerMatchGapDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('employer_match_gap');
});

it('EmployerMatchGapDetector stays silent when user meets match threshold', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.match_pct',
        value: '50',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.match_threshold_pct',
        value: '6',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.contribution_pct',
        value: '6', // exactly at threshold
        sourceType: 'interview_answer',
    );

    $detector = app(EmployerMatchGapDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('EmployerMatchGapDetector treatment uses "if your plan allows" framing', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.match_pct',
        value: '100',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.match_threshold_pct',
        value: '5',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.contribution_pct',
        value: '2',
        sourceType: 'interview_answer',
    );

    $detector = app(EmployerMatchGapDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'employer_match_gap')
        ->first();

    expect($finding)->not->toBeNull();
    expect(strtolower($finding->treatment))->toContain('if your plan allows');
});

it('EmployerMatchGapDetector makes no HTTP calls', function () {
    $detector = app(EmployerMatchGapDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect(true)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// DeductionProbeDetector — FLAG-05 (5 probes gated on prerequisites)
// ──────────────────────────────────────────────────────────────────────────────

it('DeductionProbeDetector stays silent when no prerequisites are met', function () {
    $detector = app(DeductionProbeDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('DeductionProbeDetector home-office probe fires when has_home_office is true', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_home_office' => true,
    ]);

    $detector = app(DeductionProbeDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('deduction_home_office');
});

it('DeductionProbeDetector home-office probe stays silent when has_home_office is false', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_home_office' => false,
    ]);

    $detector = app(DeductionProbeDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->not->toContain('deduction_home_office');
});

it('DeductionProbeDetector vehicle probe fires when a vehicle entity exists', function () {
    TaxProfileEntity::factory()->create([
        'user_id' => $this->user->id,
        'entity_type' => 'vehicle',
        'label' => 'My Work Truck',
    ]);

    $detector = app(DeductionProbeDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('deduction_vehicle');
});

it('DeductionProbeDetector vehicle probe stays silent without a vehicle entity', function () {
    $detector = app(DeductionProbeDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->not->toContain('deduction_vehicle');
});

it('DeductionProbeDetector pet probe fires when pet_business_use fact is true', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'probe.pet_business_use',
        value: 'true',
        sourceType: 'interview_answer',
    );

    $detector = app(DeductionProbeDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('deduction_pet');
});

it('DeductionProbeDetector makes no HTTP calls', function () {
    $detector = app(DeductionProbeDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect(true)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// ComminglingMonitor — FLAG-14 (locked wording)
// ──────────────────────────────────────────────────────────────────────────────

it('ComminglingMonitor stays silent when no business accounts exist', function () {
    $detector = app(ComminglingMonitor::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('ComminglingMonitor emits when personal spend exists in business account', function () {
    $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);
    $account = BankAccount::factory()->create([
        'user_id' => $this->user->id,
        'bank_connection_id' => $connection->id,
        'purpose' => AccountPurpose::Business,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $account->id,
        'account_purpose' => AccountPurpose::Business,
        'expense_type' => ExpenseType::Personal,
        'transaction_date' => $this->taxYear . '-06-15',
        'amount' => 250.00,
    ]);

    $detector = app(ComminglingMonitor::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('commingling_detected');
});

it('ComminglingMonitor stays silent when all business-account transactions are business-type', function () {
    $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);
    $account = BankAccount::factory()->create([
        'user_id' => $this->user->id,
        'bank_connection_id' => $connection->id,
        'purpose' => AccountPurpose::Business,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $account->id,
        'account_purpose' => AccountPurpose::Business,
        'expense_type' => ExpenseType::Business,
        'transaction_date' => $this->taxYear . '-06-15',
        'amount' => 250.00,
    ]);

    $detector = app(ComminglingMonitor::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('ComminglingMonitor finding uses locked wording (warn-and-educate only)', function () {
    $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);
    $account = BankAccount::factory()->create([
        'user_id' => $this->user->id,
        'bank_connection_id' => $connection->id,
        'purpose' => AccountPurpose::Business,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $account->id,
        'account_purpose' => AccountPurpose::Business,
        'expense_type' => ExpenseType::Personal,
        'transaction_date' => $this->taxYear . '-06-15',
        'amount' => 250.00,
    ]);

    $detector = app(ComminglingMonitor::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'commingling_detected')
        ->first();

    expect($finding)->not->toBeNull();
    // Locked wording from INTEGRATION-MAP FLAG-14 / D10
    expect($finding->treatment)->toContain('separate account');
    // Must NOT assert business qualification
    expect($finding->treatment)->not->toContain('you qualify as a business');
    expect($finding->treatment)->not->toContain('you are a business');
});

it('ComminglingMonitor makes no HTTP calls', function () {
    $detector = app(ComminglingMonitor::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect(true)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// AuditRiskScorer — FLAG-15 (protective framing; no numeric probability)
// ──────────────────────────────────────────────────────────────────────────────

it('AuditRiskScorer stays silent with no risk factors', function () {
    // Minimal snapshot with no outliers — score < threshold
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '7500000',        // $75,000
        'bank_deposit_total' => '7500000', // matches income — no mismatch
        'charitable_contributions' => '50000', // $500 — tiny, well under threshold
        'self_employment_income' => null,
        'filing_status' => 'single',
    ]);

    $detector = app(AuditRiskScorer::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('AuditRiskScorer emits when multiple risk factors detected', function () {
    // Set up 2 risk factors:
    // 1. Outsized charitable (>20% of income)
    // 2. Deposits far exceed reported income (>130%)
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',           // $50,000
        'bank_deposit_total' => '8000000', // $80,000 — 160% of income (>130% threshold)
        'charitable_contributions' => '1500000', // $15,000 — 30% of income (>20% threshold)
        'self_employment_income' => null,
        'filing_status' => 'single',
    ]);

    $detector = app(AuditRiskScorer::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('audit_risk_score');
});

it('AuditRiskScorer finding uses locked protective framing', function () {
    // Two risk factors to ensure emission (score >= threshold=2)
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '5000000',
        'bank_deposit_total' => '8000000',
        'charitable_contributions' => '1500000',
        'self_employment_income' => null,
        'filing_status' => 'single',
    ]);

    $detector = app(AuditRiskScorer::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'audit_risk_score')
        ->first();

    expect($finding)->not->toBeNull();
    // Locked framing: "commonly receive additional IRS scrutiny — here is the documentation"
    expect(strtolower($finding->treatment))->toContain('scrutiny');
    // Must NOT contain numeric audit probability
    expect($finding->treatment)->not->toMatch('/\d+%/');
    // Must NOT be accusatory
    expect($finding->treatment)->not->toContain('you cheated');
    expect($finding->treatment)->not->toContain('you committed');
    expect($finding->treatment)->not->toContain('you falsified');
    expect($finding->treatment)->not->toContain('audit probability');
    expect($finding->treatment)->not->toContain('chance of audit');
});

it('AuditRiskScorer makes no HTTP calls', function () {
    $detector = app(AuditRiskScorer::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect(true)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// SAFE-03: No detector sets estimated_value_cents
// ──────────────────────────────────────────────────────────────────────────────

it('No detector assigns estimated_value_cents directly (SAFE-03 confirmed via EstimatedValueGuardTest)', function () {
    // This test confirms that SAFE-03 grep-gate covers the new Detectors/ namespace.
    // The actual file scan is in EstimatedValueGuardTest — this test verifies
    // no finding created by detectors in this test file has estimated_value_cents set.
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'married_filing_jointly',
        'has_home_office' => true,
    ]);
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
        'w2_wages' => '10000000',
        'charitable_contributions' => '1500000',
    ]);

    $service = app(RedFlagDetectorService::class);
    $service->detectAll($this->user->id, $this->taxYear);

    $findings = OptimizationFinding::where('user_id', $this->user->id)
        ->where('tax_year', $this->taxYear)
        ->get();

    foreach ($findings as $finding) {
        expect($finding->estimated_value_cents)->toBeNull();
    }
});
