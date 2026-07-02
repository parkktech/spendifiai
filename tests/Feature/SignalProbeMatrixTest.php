<?php

/**
 * SignalProbeMatrixTest — TDD RED for Plan 11-08 Task 1
 *
 * Tests for:
 *  - SignalProbeMatrix (FLAG-17): prerequisite-gated probes, reframe compliance
 *  - TimeCriticalAlarmDetector (FLAG-16): 83(b), QOF, QSBS alarms at highest severity
 *
 * MANDATORY BINDING RULES (verified by these tests):
 *  - Entity probe leads with 60-month lock, never recommends (tops out at "commonly considered")
 *  - Income-drop trigger uses income signals ONLY, never asset values
 *  - QBI above-threshold returns professional-review sentinel, no implementation
 *  - ISO/AMT ships ONLY the safe one-liner
 *  - Alarms are highest severity with fact+professional framing
 *  - Nothing from the Blocked list appears in any treatment text
 */

use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\Detectors\SignalProbeMatrix;
use App\Services\Detectors\TimeCriticalAlarmDetector;
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
// SignalProbeMatrix — FLAG-17 (prerequisite gating)
// ──────────────────────────────────────────────────────────────────────────────

it('SignalProbeMatrix stays silent when no income signals present', function () {
    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('SignalProbeMatrix emits deferral_gap probe when payroll detected and no 401k deferral fact', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9000000', // $90,000
    ]);

    // No deferral fact recorded → gap detected
    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toContain('probe_deferral_gap');
    $finding = OptimizationFinding::where('finding_key', 'probe_deferral_gap')->first();
    expect($finding)->not->toBeNull();
});

it('SignalProbeMatrix does NOT emit deferral_gap when user already maxing 401k', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '9000000',
    ]);

    // Record max deferral fact (2450000 cents = $24,500)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'retirement.k401_contribution_ytd_cents',
        value: '2450000',
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->not->toContain('probe_deferral_gap');
});

it('SignalProbeMatrix emits se_income_probe when SE income detected', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'self_employment_income' => '6000000', // $60,000
        'has_self_employment' => true,
    ]);

    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toContain('probe_se_income');
});

it('SignalProbeMatrix entity probe leads with 60-month lock warning', function () {
    // Schedule C net > $50K triggers entity probe
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'self_employment_income' => '8000000', // $80,000
        'has_self_employment' => true,
    ]);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'business.schedule_c_net_cents',
        value: '6000000', // $60,000 net
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $detector = app(SignalProbeMatrix::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'probe_entity_analysis')->first();
    expect($finding)->not->toBeNull();

    // BINDING: 60-month lock warning MUST appear in treatment text
    expect($finding->treatment)->toContain('60-month');
    // BINDING: tops out at "commonly considered" — never a recommendation
    expect($finding->treatment)->toContain('commonly considered');
    // BINDING: must NOT contain assertive recommendation forms
    expect($finding->treatment)->not->toContain('you should elect');
    expect($finding->treatment)->not->toContain('you should form');
    expect($finding->treatment)->not->toContain('recommend that you');
});

it('SignalProbeMatrix income-drop Roth trigger uses income signals only, not asset values', function () {
    // Low current income
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '3000000', // $30,000 this year
    ]);

    // Prior year income much higher (income-drop signal)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'income.prior_year_income_cents',
        value: '9000000', // $90,000 prior year
        sourceType: 'interview_answer',
    );

    $detector = app(SignalProbeMatrix::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toContain('probe_income_drop_roth');

    $finding = OptimizationFinding::where('finding_key', 'probe_income_drop_roth')->first();
    // BINDING: treatment mentions income/bracket
    expect($finding->treatment)->toContain('income');
    // BINDING: CR-2 blocked — never "convert on portfolio drawdown" / asset values
    expect($finding->treatment)->not->toContain('drawdown');
    expect($finding->treatment)->not->toContain('portfolio decline');
    expect($finding->treatment)->not->toContain('asset value');
});

it('SignalProbeMatrix QBI above-threshold returns professional-review sentinel, no implementation', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'self_employment_income' => '30000000', // $300,000
        'has_self_employment' => true,
    ]);

    // Schedule C net well above QBI phaseout ($201,750 single 2026)
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'business.schedule_c_net_cents',
        value: '28000000', // $280,000 net
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $detector = app(SignalProbeMatrix::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'probe_qbi_high_income')->first();
    expect($finding)->not->toBeNull();
    // BINDING D11: sentinel text — no W-2 wage / UBIA implementation
    expect($finding->treatment)->toContain('professional');
    expect($finding->treatment)->not->toContain('W-2 wage test');
    expect($finding->treatment)->not->toContain('UBIA test');
    expect($finding->treatment)->not->toContain('compute');
});

it('SignalProbeMatrix ISO/AMT probe ships only the safe one-liner', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'equity.has_iso',
        value: 'true',
        sourceType: 'interview_answer',
    );

    $detector = app(SignalProbeMatrix::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'probe_iso_amt')->first();
    expect($finding)->not->toBeNull();
    // BINDING: only the safe one-liner; no AMT modeling / crossover calculation
    expect($finding->treatment)->toContain('AMT implications');
    expect($finding->treatment)->toContain('professional review before exercising');
    expect($finding->treatment)->not->toContain('crossover');
    expect($finding->treatment)->not->toContain('compute your AMT');
    expect($finding->treatment)->not->toContain('your AMT liability');
});

it('SignalProbeMatrix solo-401k probe includes employee gate', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'self_employment_income' => '5000000',
        'has_self_employment' => true,
    ]);
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'business.schedule_c_net_cents',
        value: '3000000', // $30,000 net
        sourceType: 'interview_answer',
        taxYear: $this->taxYear,
    );

    $detector = app(SignalProbeMatrix::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'probe_solo_401k')->first();
    expect($finding)->not->toBeNull();
    // BINDING: employee gate must appear in treatment
    expect($finding->treatment)->toContain('employees');
});

it('SignalProbeMatrix 529 probe surfaces federal-parts only', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'family.has_children',
        value: 'true',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'family.has_529_account',
        value: 'false',
        sourceType: 'interview_answer',
    );

    $detector = app(SignalProbeMatrix::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'probe_529_education')->first();
    expect($finding)->not->toBeNull();
    // BINDING: 529 content is federal-parts-only; state deductions deferred to STATE-01
    expect($finding->treatment)->not->toContain('state deduction');
    expect($finding->treatment)->not->toContain('state tax');
    expect($finding->treatment)->toContain('federal');
});

it('SignalProbeMatrix findings never contain blocked content', function () {
    IncomeOptimizationProfile::factory()->create([
        'user_id' => $this->user->id,
        'tax_year' => $this->taxYear,
        'w2_wages' => '15000000',
    ]);

    $detector = app(SignalProbeMatrix::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $findings = OptimizationFinding::where('user_id', $this->user->id)->get();
    foreach ($findings as $finding) {
        expect($finding->treatment)->not->toContain('filing status optimizer');
        expect($finding->treatment)->not->toContain('harvest your losses');
        expect($finding->treatment)->not->toContain('sell and rebuy');
        expect($finding->treatment)->not->toContain('asset location');
        expect($finding->treatment)->not->toContain('direct indexing');
        expect($finding->treatment)->not->toContain('AMT crossover');
        expect($finding->treatment)->not->toContain('compute your AMT');
    }
});

// ──────────────────────────────────────────────────────────────────────────────
// TimeCriticalAlarmDetector — FLAG-16
// ──────────────────────────────────────────────────────────────────────────────

it('TimeCriticalAlarmDetector stays silent when no startup equity signals present', function () {
    $detector = app(TimeCriticalAlarmDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);
    expect($result)->toBeEmpty();
});

it('TimeCriticalAlarmDetector emits 83b alarm at critical severity when restricted stock grant within 30 days', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'equity.restricted_stock_grant_date',
        value: now()->subDays(10)->toDateString(),
        sourceType: 'interview_answer',
    );

    $detector = app(TimeCriticalAlarmDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toContain('alarm_83b_election');
    $finding = OptimizationFinding::where('finding_key', 'alarm_83b_election')->first();
    expect($finding)->not->toBeNull();
    // BINDING: highest severity
    expect($finding->severity)->toBe('critical');
    // BINDING: urgency stated as fact ("30-day deadline")
    expect($finding->treatment)->toContain('30-day');
    // BINDING: professional framing
    expect($finding->treatment)->toContain('professional');
});

it('TimeCriticalAlarmDetector does NOT emit 83b alarm when grant is older than 30 days', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'equity.restricted_stock_grant_date',
        value: now()->subDays(45)->toDateString(),
        sourceType: 'interview_answer',
    );

    $detector = app(TimeCriticalAlarmDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->not->toContain('alarm_83b_election');
});

it('TimeCriticalAlarmDetector emits QOF end-2026 alarm when pre-2027 QOF investment detected', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'investment.has_qof',
        value: 'true',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'investment.qof_invested_before_2027',
        value: 'true',
        sourceType: 'interview_answer',
    );

    $detector = app(TimeCriticalAlarmDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toContain('alarm_qof_recognition');
    $finding = OptimizationFinding::where('finding_key', 'alarm_qof_recognition')->first();
    expect($finding)->not->toBeNull();
    expect($finding->severity)->toBe('critical');
    // BINDING: urgency stated as fact + professional framing
    expect($finding->treatment)->toContain('2026');
    expect($finding->treatment)->toContain('professional');
});

it('TimeCriticalAlarmDetector emits QSBS eligibility alarm at C-corp formation with §1244 note', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'business.entity_type',
        value: 'c_corp',
        sourceType: 'interview_answer',
    );
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'business.formation_date',
        value: now()->subDays(30)->toDateString(),
        sourceType: 'interview_answer',
    );

    $detector = app(TimeCriticalAlarmDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toContain('alarm_qsbs_eligibility');
    $finding = OptimizationFinding::where('finding_key', 'alarm_qsbs_eligibility')->first();
    expect($finding)->not->toBeNull();
    expect($finding->severity)->toBe('critical');
    // BINDING: paired with §1244 note
    expect($finding->treatment)->toContain('1244');
    // BINDING: professional framing
    expect($finding->treatment)->toContain('professional');
});

it('TimeCriticalAlarmDetector treatment never contains banned phrases', function () {
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'equity.restricted_stock_grant_date',
        value: now()->subDays(5)->toDateString(),
        sourceType: 'interview_answer',
    );

    $detector = app(TimeCriticalAlarmDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $findings = OptimizationFinding::where('user_id', $this->user->id)->get();
    foreach ($findings as $finding) {
        expect($finding->treatment)->not->toContain('you qualify');
        expect($finding->treatment)->not->toContain('you will save');
        expect($finding->treatment)->not->toContain('harvest your losses');
    }
});
