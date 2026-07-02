<?php

/**
 * W2BenefitDetectorsTest — Task 2 RED (FLAG-20, FLAG-21, FLAG-24, FLAG-25)
 *
 * Tests for:
 *  - W2BenefitArbitrageDetector (FLAG-20): unelected-benefit gaps, ESPP/NQDC reframes
 *  - PublicSectorRetirementDetector (FLAG-21): 457(b) creditor-risk caveat order
 *  - ReimbursementRoutingRule (FLAG-25): W-2 expense reframe vs. survivor deduction education
 *  - IraToHsaQfdProbe (FLAG-24): 5-gate prerequisite + testing-period caveat
 *
 * ALL assertions follow INTEGRATION-MAP.md binding rules and 11-CONTEXT.md reframes.
 * These tests intentionally fail (RED) until the implementation classes are created.
 */

use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
});

// ── W2BenefitArbitrageDetector (FLAG-20) ──────────────────────────────────────

it('W2BenefitArbitrageDetector fires HSA gap when W-2 user has no HSA enrollment', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9500000', // $95,000
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_hsa_plan',
        value: 'true', // employer offers HSA
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.hsa_enrolled',
        value: 'false', // not enrolled
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\W2BenefitArbitrageDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'w2_benefit_hsa_gap')->first();
    expect($finding)->not->toBeNull();
    expect($finding->treatment)->toContain('HSA');
    expect($finding->treatment)->toContain('high-deductible');
});

it('W2BenefitArbitrageDetector ESPP finding bans free-money and guaranteed-return language', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9500000',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_espp',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.espp_enrolled',
        value: 'false',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\W2BenefitArbitrageDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'w2_benefit_espp_participation')->first();
    expect($finding)->not->toBeNull();
    expect($finding->treatment)->toContain('ESPP');

    // BINDING (FLAG-20, INTEGRATION-MAP): ban "free money" and "guaranteed return" language
    expect($finding->treatment)->not->toContain('free money');
    expect($finding->treatment)->not->toContain('guaranteed return');

    // BINDING: no disposition modeling
    expect($finding->treatment)->not->toContain('qualifying disposition');
    expect($finding->treatment)->not->toContain('disqualifying disposition');
    expect($finding->treatment)->not->toContain('holding period tax');
});

it('W2BenefitArbitrageDetector NQDC finding includes employer-credit-risk warning', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '15000000', // $150,000 — higher-income W-2
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_nqdc',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\W2BenefitArbitrageDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'w2_benefit_nqdc_education')->first();
    expect($finding)->not->toBeNull();

    // BINDING (FLAG-20): NQDC routes to education module with employer-credit-risk warning
    expect($finding->treatment)->toContain('credit risk');
    expect($finding->treatment)->not->toContain('you should defer'); // no directive
});

it('W2BenefitArbitrageDetector mega-backdoor finding uses if-your-plan-allows gate', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '12000000',
        'has_self_employment' => false,
        'traditional_401k_ytd' => '2450000', // maxing employee deferral
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.allows_after_tax_401k',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\W2BenefitArbitrageDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'w2_benefit_mega_backdoor')->first();
    expect($finding)->not->toBeNull();

    // BINDING (FLAG-20, INTEGRATION-MAP): mega-backdoor "if your plan allows" framing mandatory
    expect($finding->treatment)->toContain('if your plan allows');
    expect($finding->treatment)->not->toContain('you can do a mega backdoor');
    expect($finding->treatment)->not->toContain('definitely available');
});

it('W2BenefitArbitrageDetector does not fire for pure self-employed users', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '0',
        'self_employment_income' => '9500000',
        'has_self_employment' => true,
    ]);

    app(\App\Services\Detectors\W2BenefitArbitrageDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $findings = OptimizationFinding::where('user_id', $this->user->id)
        ->where('finding_key', 'like', 'w2_benefit_%')
        ->get();
    expect($findings)->toHaveCount(0);
});

// ── PublicSectorRetirementDetector (FLAG-21) ──────────────────────────────────

it('PublicSectorRetirementDetector 457b finding always leads with creditor-risk caveat', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '8000000',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.employer_type',
        value: 'nonprofit',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_457b',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\PublicSectorRetirementDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'ps_457b_education')->first();
    expect($finding)->not->toBeNull();

    // BINDING (FLAG-21, INTEGRATION-MAP): non-governmental creditor-risk caveat MANDATORY
    // and BEFORE any 457(b) content — test ordering by checking caveat appears before "defer"
    $treatment = $finding->treatment;
    $creditorPos = mb_strpos($treatment, 'creditor');
    $deferPos = mb_strpos($treatment, '457');
    expect($creditorPos)->not->toBeFalse(); // caveat present
    expect($creditorPos)->toBeLessThan($deferPos); // caveat precedes 457(b) content
});

it('PublicSectorRetirementDetector non-governmental 457b notes plan assets are not segregated', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '8000000',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.employer_type',
        value: 'hospital',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_457b',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\PublicSectorRetirementDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'ps_457b_education')->first();
    expect($finding)->not->toBeNull();
    // Non-governmental 457(b): funds remain employer assets subject to creditor claims
    expect($finding->treatment)->toContain('creditor');
});

it('PublicSectorRetirementDetector governmental 457b omits creditor-risk caveat', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '8000000',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.employer_type',
        value: 'government', // governmental — no creditor risk on vested amounts
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_457b',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\PublicSectorRetirementDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'ps_457b_education')->first();
    expect($finding)->not->toBeNull();
    // Governmental: separate trust, different rules — no creditor-risk caveat needed
    expect($finding->treatment)->not->toContain('employer creditor');
    expect($finding->treatment)->toContain('457(b)');
});

it('PublicSectorRetirementDetector asks 3-year catch-up question', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '7500000',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.employer_type',
        value: 'school',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_457b',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\PublicSectorRetirementDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'ps_457b_education')->first();
    expect($finding)->not->toBeNull();
    // BINDING (FLAG-21): 3-year catch-up signal / question must be present
    expect($finding->treatment)->toContain('3 year');
});

it('PublicSectorRetirementDetector does not fire for private-sector employers', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9000000',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.employer_type',
        value: 'private',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\PublicSectorRetirementDetector::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'ps_457b_education')->first();
    expect($finding)->toBeNull();
});

// ── ReimbursementRoutingRule (FLAG-25) ────────────────────────────────────────

it('ReimbursementRoutingRule reframes W-2 employee work expenses as accountable-plan ask', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9500000',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_w2_work_expenses',
        value: 'true', // W-2 user has detected work expenses
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\ReimbursementRoutingRule::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'reimbursement_routing_w2')->first();
    expect($finding)->not->toBeNull();

    // BINDING (FLAG-25, M5): "consider asking your employer" framing
    expect($finding->treatment)->toContain('accountable');
    expect($finding->treatment)->toContain('employer');

    // BINDING (FLAG-25): must NOT say "deduct this" / "deductible for you"
    expect($finding->treatment)->not->toContain('deduct this');
    expect($finding->treatment)->not->toContain('deductible for you');
});

it('ReimbursementRoutingRule survivor category routes as deduction education not reimbursement ask', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9500000',
        'has_self_employment' => false,
    ]);

    // Reservist = surviving above-the-line category (Schedule 1, line 12)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employment.is_reservist',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer.has_w2_work_expenses',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\ReimbursementRoutingRule::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'reimbursement_survivor_reservist')->first();
    expect($finding)->not->toBeNull();
    // Survivor: deduction education (above-the-line, unreimbursed travel)
    expect($finding->treatment)->toContain('deduction');
    expect($finding->treatment)->toContain('reservist');
});

it('ReimbursementRoutingRule does not fire for self-employed users', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '0',
        'self_employment_income' => '9500000',
        'has_self_employment' => true,
    ]);

    app(\App\Services\Detectors\ReimbursementRoutingRule::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'reimbursement_routing_w2')->first();
    expect($finding)->toBeNull();
});

// ── IraToHsaQfdProbe (FLAG-24) ────────────────────────────────────────────────

it('IraToHsaQfdProbe fires when all five prerequisite gates pass', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '8500000',
        'has_self_employment' => false,
    ]);

    // Gate 1: HSA eligibility
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'health.hsa_eligible',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    // Gate 2: IRA balance
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.has_ira_balance',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    // Gate 3: cash constrained
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'finance.is_cash_constrained',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    // Gate 4: medical expenses or funding intent
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'health.has_medical_expenses',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    // Gate 5: QFD not previously used
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'health.qfd_previously_used',
        value: 'false',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\IraToHsaQfdProbe::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'ira_to_hsa_qfd')->first();
    expect($finding)->not->toBeNull();

    // BINDING (FLAG-24, EXP M7): testing-period caveat is MANDATORY in the wording
    expect($finding->treatment)->toContain('does not create an extra deduction');
    expect($finding->treatment)->toContain('testing-period');
});

it('IraToHsaQfdProbe does not fire when HSA eligibility gate is missing', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '8500000',
        'has_self_employment' => false,
    ]);

    // Gate 1 MISSING (no hsa_eligible fact)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.has_ira_balance',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'finance.is_cash_constrained',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'health.has_medical_expenses',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'health.qfd_previously_used',
        value: 'false',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\IraToHsaQfdProbe::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    expect(OptimizationFinding::where('finding_key', 'ira_to_hsa_qfd')->first())->toBeNull();
});

it('IraToHsaQfdProbe does not fire when QFD was previously used (gate 5)', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '8500000',
        'has_self_employment' => false,
    ]);

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'health.hsa_eligible',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.has_ira_balance',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'finance.is_cash_constrained',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'health.has_medical_expenses',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );
    // Gate 5 FAILED: QFD already used
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'health.qfd_previously_used',
        value: 'true',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    app(\App\Services\Detectors\IraToHsaQfdProbe::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    expect(OptimizationFinding::where('finding_key', 'ira_to_hsa_qfd')->first())->toBeNull();
});

it('IraToHsaQfdProbe treatment always includes testing-period caveat', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '8500000',
        'has_self_employment' => false,
    ]);

    // All 5 gates pass
    UserTaxFact::recordFact(userId: $this->user->id, factKey: 'health.hsa_eligible', value: 'true', sourceType: 'interview_answer', taxYear: $this->taxYear);
    UserTaxFact::recordFact(userId: $this->user->id, factKey: 'retirement.has_ira_balance', value: 'true', sourceType: 'interview_answer', taxYear: $this->taxYear);
    UserTaxFact::recordFact(userId: $this->user->id, factKey: 'finance.is_cash_constrained', value: 'true', sourceType: 'interview_answer', taxYear: $this->taxYear);
    UserTaxFact::recordFact(userId: $this->user->id, factKey: 'health.has_medical_expenses', value: 'true', sourceType: 'interview_answer', taxYear: $this->taxYear);
    UserTaxFact::recordFact(userId: $this->user->id, factKey: 'health.qfd_previously_used', value: 'false', sourceType: 'interview_answer', taxYear: $this->taxYear);

    app(\App\Services\Detectors\IraToHsaQfdProbe::class)
        ->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'ira_to_hsa_qfd')->first();
    expect($finding)->not->toBeNull();

    // BINDING (FLAG-24): both halves of the mandatory wording from EXP M7 verbatim
    expect($finding->treatment)->toContain('does not create an extra deduction');
    expect($finding->treatment)->toContain('testing-period');

    // SAFE-01: no assertive directive
    expect($finding->treatment)->not->toContain('you should transfer');
    expect($finding->treatment)->not->toContain('do the transfer');
});
