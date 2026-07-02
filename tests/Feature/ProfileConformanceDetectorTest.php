<?php

/**
 * ProfileConformanceDetectorTest — TDD RED for Plan 11-06 Task 3
 *
 * Tests for ProfileConformanceDetector (FLAG-28, D13 LOCKED):
 *
 *   Plane 1 — Filing status vs W-4/withholding evidence
 *     Direction A: profile says MFJ but snapshot says single → finding
 *     Direction B: profile says single but evidence suggests MFJ → finding
 *
 *   Plane 2 — IRA/HSA stated facts vs detected bank transfers/payroll deductions
 *     Direction A: profile says has_hsa=false but HSA deduction fact found → finding
 *     Direction B: profile says has_hsa=true but no HSA transfers/facts detected → finding (gentle)
 *     Direction A2: profile says Roth-only but trad_ira contribution detected → finding
 *
 *   Plane 3 — Checkbox facts vs transaction patterns (BOTH directions)
 *     Direction A: profile says has_rental_property=false but rent-income deposits found → finding
 *     Direction B: profile says has_student_loans=false but loan-servicer payments found → finding
 *     Direction C: profile says has_childcare=false but daycare merchants found → finding
 *
 *   Cross-cutting constraints:
 *     - Every mismatch: OptimizationFinding emitted (confirmed)
 *     - Educational question framing: "Your paystub/documents appear to show X while your profile says Y"
 *     - NEVER asserts the correct value
 *     - ProfileConformanceDetector NEVER writes to UserFinancialProfile directly
 *     - Banned assertive phrases absent
 *     - No HTTP calls
 */

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use App\Services\Detectors\ProfileConformanceDetector;
use App\Services\RedFlagDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
    $this->detector = app(ProfileConformanceDetector::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// Plane 1: Filing status conformance (FLAG-28 D13, extends FLAG-02)
// ──────────────────────────────────────────────────────────────────────────────

it('Plane 1 Dir A: emits when profile says MFJ but snapshot says single', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'married_filing_jointly',
    ]);
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
    ]);

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('conformance_filing_status');
});

it('Plane 1 Dir B: emits when W-4 withholding evidence differs from profile status', function () {
    // Profile says single; but withholding fact suggests MFJ withholding rate
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'single',
    ]);
    // Record a w4_filing_status fact (from paystub) that differs
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'w4.filing_status',
        value: 'married_filing_jointly',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single', // same as profile; but w4 fact differs
    ]);

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('conformance_filing_status');
});

it('Plane 1: stays silent when all filing status signals agree', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'single',
    ]);
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
    ]);

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->not->toContain('conformance_filing_status');
});

// ──────────────────────────────────────────────────────────────────────────────
// Plane 2: IRA/HSA conformance
// ──────────────────────────────────────────────────────────────────────────────

it('Plane 2 Dir A: emits when profile says no HSA but HSA deduction fact found', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_hsa' => false,
    ]);
    // Durable fact showing HSA deductions from paystub
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.hsa_deduction_ytd',
        value: '150000', // $1,500 HSA deduction — profile says no HSA
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('conformance_ira_hsa');
});

it('Plane 2 Dir A2: emits when profile says Roth-only but Traditional IRA contribution detected', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_ira' => true,
        'ira_type' => 'roth',
    ]);
    // Durable fact showing Traditional IRA contribution
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'ira.traditional_contribution_ytd',
        value: '350000', // $3,500 Traditional contribution
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('conformance_ira_hsa');
});

it('Plane 2: stays silent when HSA facts align with profile', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_hsa' => true,
    ]);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.hsa_deduction_ytd',
        value: '150000',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->not->toContain('conformance_ira_hsa');
});

// ──────────────────────────────────────────────────────────────────────────────
// Plane 3: Checkbox facts vs transaction patterns (BOTH directions)
// ──────────────────────────────────────────────────────────────────────────────

it('Plane 3 Dir A: emits when profile says no rental property but rent-income deposits detected', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_rental_property' => false,
    ]);
    // Durable fact showing rental income deposits detected in transactions
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'income.rental_income_detected',
        value: 'true',
        sourceType: 'detector',
        taxYear: $this->taxYear,
    );

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('conformance_checkbox');
});

it('Plane 3 Dir B: emits when profile says no student loans but loan-servicer payments found', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_student_loans' => false,
    ]);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'payment.student_loan_detected',
        value: 'true',
        sourceType: 'detector',
        taxYear: $this->taxYear,
    );

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('conformance_checkbox');
});

it('Plane 3 Dir C: emits when profile says no childcare but daycare payments detected', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_childcare_expenses' => false,
    ]);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'payment.childcare_detected',
        value: 'true',
        sourceType: 'detector',
        taxYear: $this->taxYear,
    );

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toContain('conformance_checkbox');
});

it('Plane 3: stays silent when profile and transaction patterns agree', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_rental_property' => false,
        'has_student_loans' => false,
        'has_childcare_expenses' => false,
    ]);
    // No detection facts

    $result = $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->not->toContain('conformance_checkbox');
});

// ──────────────────────────────────────────────────────────────────────────────
// Cross-cutting: Educational framing (D13, LOCKED)
// ──────────────────────────────────────────────────────────────────────────────

it('Conformance findings use D13 framing — appears to show / profile says', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'married_filing_jointly',
    ]);
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'conformance_filing_status')
        ->first();

    expect($finding)->not->toBeNull();
    // Must use D13 framing pattern
    $treatment = strtolower($finding->treatment);
    expect($treatment)->toContain('profile');
    // Must NOT assert the correct value
    expect($treatment)->not->toContain('you should file as');
    expect($treatment)->not->toContain('you must file as');
    expect($treatment)->not->toContain('your correct filing status is');
});

it('Conformance findings do not contain banned assertive phrases', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'has_hsa' => false,
    ]);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.hsa_deduction_ytd',
        value: '150000',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'conformance_ira_hsa')
        ->first();

    expect($finding)->not->toBeNull();
    // Banned phrases check (from FLAG-02 / D13 locked constraint)
    $treatment = $finding->treatment;
    expect($treatment)->not->toContain('you are required to');
    expect($treatment)->not->toContain('you must update');
    expect($treatment)->not->toContain('your profile is wrong');
    expect($treatment)->not->toContain('you have an HSA');
});

// ──────────────────────────────────────────────────────────────────────────────
// Critical constraint: NO auto-write to UserFinancialProfile (D13 / D3 gate)
// ──────────────────────────────────────────────────────────────────────────────

it('ProfileConformanceDetector never writes to UserFinancialProfile directly', function () {
    $profile = UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'married_filing_jointly',
        'has_hsa' => false,
        'has_rental_property' => false,
        'has_student_loans' => false,
    ]);

    // Capture the state of the profile before detection
    $before = $profile->fresh()->toArray();

    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.hsa_deduction_ytd',
        value: '150000',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'income.rental_income_detected',
        value: 'true',
        sourceType: 'detector',
        taxYear: $this->taxYear,
    );

    // Run the detector
    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    // Profile MUST NOT have changed
    $after = $profile->fresh()->toArray();

    // Compare the changeable fields — all must be identical
    $fieldsToCheck = [
        'tax_filing_status', 'has_hsa', 'has_rental_property',
        'has_student_loans', 'has_childcare_expenses', 'ira_type', 'has_ira',
    ];

    foreach ($fieldsToCheck as $field) {
        expect($after[$field])->toBe($before[$field], "Field {$field} was modified by conformance detector");
    }
});

it('ProfileConformanceDetector makes no HTTP calls', function () {
    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect(true)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// AuditRiskScorerTest coverage (moved here since it tests Flag-15)
// ──────────────────────────────────────────────────────────────────────────────

it('All conformance findings have severity derived from band (not null)', function () {
    UserFinancialProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_filing_status' => 'married_filing_jointly',
        'has_hsa' => false,
    ]);
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'filing_status' => 'single',
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $findings = OptimizationFinding::where('user_id', $this->user->id)
        ->where('tax_year', $this->taxYear)
        ->get();

    foreach ($findings as $finding) {
        expect($finding->severity)->not->toBeNull();
        expect($finding->severity)->toBeIn(['high', 'medium', 'suppressed', 'blocked']);
    }
});
