<?php

/**
 * RetirementOpportunitySweepTest — Fix 2 (2026-07-02, corrected 2026-07-02)
 *
 * Verifies the four retirement-opportunity findings from confirmed durable facts,
 * using the REAL fact keys from PaystubFactExtractorService (not invented aliases).
 *
 * User-1 confirmed-fact state (production baseline, 2026-07-02):
 *   employer.after_tax_401k_available = 'yes'      (bool_field convention, NOT 'true')
 *   retirement.roth_401k_ytd_cents    = 15218       (paystub extraction)
 *   retirement.traditional_401k_ytd_cents = 60870   (paystub extraction)
 *   w4.step3_annual_credits_cents     = 320000      (W-4 Step 3 extract)
 *
 * DRIFT GATE: one test asserts that the keys used here exist in the live
 * PaystubFactExtractorService field maps so key drift fails loudly.
 *
 * ALL findings must:
 *  - Use hedged educational copy ("may / could / consider / worth exploring").
 *  - Carry correct finding_type and band.
 *  - SAFE-03: only RET-C (match_pace_gap) sets estimated_value_cents (via engine).
 *  - No hardcoded dollar figures in treatment text (amounts from config/engine only).
 */

use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\AI\PaystubFactExtractorService;
use App\Services\RedFlagDetectorService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
    $this->detector = app(\App\Services\Detectors\RetirementOpportunitySweep::class);
});

// Helper: create a W-2 profile for the detector to pass the first gate.
function makeW2Profile(int $userId, int $taxYear, array $overrides = []): IncomeOptimizationProfile
{
    return IncomeOptimizationProfile::factory()->create(array_merge([
        'user_id' => $userId,
        'tax_year' => $taxYear,
        'w2_wages' => '9000000',   // $90,000 in cents
        'traditional_401k_ytd' => '0',
        'roth_401k_ytd' => '0',
    ], $overrides));
}

function recordConfirmedFact(int $userId, string $factKey, string $value, int $taxYear): UserTaxFact
{
    return UserTaxFact::recordFact(
        userId: $userId,
        factKey: $factKey,
        value: $value,
        sourceType: 'interview_answer',
        taxYear: $taxYear,
    );
}

// ── DRIFT GATE: assert real extraction keys exist in PaystubFactExtractorService ─

it('DRIFT-GATE: employer.after_tax_401k_available key is present in PaystubFactExtractorService::BENEFITS_FACT_MAP', function () {
    // This test fails loudly if the extractor renames the fact key,
    // preventing RetirementOpportunitySweep from drifting silently.
    // Uses reflection because BENEFITS_FACT_MAP is a protected constant.
    $ref = new \ReflectionClass(PaystubFactExtractorService::class);
    $benefits = $ref->getConstant('BENEFITS_FACT_MAP');
    $benefitsFactKeys = array_column($benefits, 'fact_key');

    expect($benefitsFactKeys)->toContain('employer.after_tax_401k_available');
});

it('DRIFT-GATE: retirement YTD and W-4 Step 3 keys are present in PaystubFactExtractorService::PAYSTUB_FACT_MAP', function () {
    // retirement.{roth,traditional}_401k_ytd_cents and w4.step3_annual_credits_cents are
    // paystub facts (not benefits guide facts). This asserts they live in PAYSTUB_FACT_MAP.
    $ref = new \ReflectionClass(PaystubFactExtractorService::class);
    $paystubFields = $ref->getConstant('PAYSTUB_FACT_MAP');
    $paystubFactKeys = array_column($paystubFields, 'fact_key');

    expect($paystubFactKeys)->toContain('retirement.roth_401k_ytd_cents');
    expect($paystubFactKeys)->toContain('retirement.traditional_401k_ytd_cents');
    expect($paystubFactKeys)->toContain('w4.step3_annual_credits_cents');
});

// ── RET-A: After-tax 401k opportunity ────────────────────────────────────────

it('RET-A: fires retirement_after_tax_401k_opportunity when after-tax available (yes) and not maxed', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    // CORRECT KEY+VALUE: employer.after_tax_401k_available = 'yes' (bool_field convention)
    recordConfirmedFact($this->user->id, 'employer.after_tax_401k_available', 'yes', $this->taxYear);
    // Low YTD via facts — below employee deferral max
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '500000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    expect($finding)->not->toBeNull();
    expect($finding->finding_type)->toBe('retirement');
    expect($finding->band)->toBe('conditional');
});

it('RET-A: treatment includes mandatory if-your-plan-allows framing and mega-backdoor hedging', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.after_tax_401k_available', 'yes', $this->taxYear);
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '500000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    $lower = strtolower($finding->treatment);
    expect($lower)
        ->toContain('if your plan allows')
        ->toContain('mega backdoor')
        ->not->toContain('guaranteed');
});

it('RET-A: does NOT fire when employer.after_tax_401k_available is no', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.after_tax_401k_available', 'no', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    expect($finding)->toBeNull();
});

it('RET-A: does NOT fire when wrong value true (bool_field must be yes not true)', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    // 'true' is the wrong value — bool_field convention uses 'yes'/'no'
    recordConfirmedFact($this->user->id, 'employer.after_tax_401k_available', 'true', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    expect($finding)->toBeNull();
});

it('RET-A: does NOT fire when after-tax allowed but already at employee deferral max', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.after_tax_401k_available', 'yes', $this->taxYear);
    // $24,500 = employee deferral limit for 2026 — already maxed
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '2450000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    // Already at max — mega backdoor is handled by W2BenefitArbitrageDetector
    expect($finding)->toBeNull();
});

it('RET-A: does NOT fire for pure self-employed profile (no W-2 wages)', function () {
    makeW2Profile($this->user->id, $this->taxYear, ['w2_wages' => '0']);
    recordConfirmedFact($this->user->id, 'employer.after_tax_401k_available', 'yes', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    expect($finding)->toBeNull();
});

// ── RET-B: Contribution mix review ───────────────────────────────────────────

it('RET-B: fires retirement_contribution_mix_review when both Roth and Traditional YTD facts present', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    // Use UserTaxFact directly — the authoritative source
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '600000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'retirement.roth_401k_ytd_cents', '400000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->first();
    expect($finding)->not->toBeNull();
    expect($finding->finding_type)->toBe('retirement');
    expect($finding->band)->toBe('conditional');
});

it('RET-B: treatment mentions marginal rate comparison', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '600000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'retirement.roth_401k_ytd_cents', '400000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->first();
    expect(strtolower($finding->treatment))
        ->toContain('marginal')
        ->toContain('roth')
        ->toContain('traditional');
});

it('RET-B: does NOT fire when only Traditional YTD fact present (no Roth)', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '800000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->first();
    expect($finding)->toBeNull();
});

it('RET-B: does NOT fire when only Roth YTD fact present (no Traditional)', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    recordConfirmedFact($this->user->id, 'retirement.roth_401k_ytd_cents', '900000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->first();
    expect($finding)->toBeNull();
});

// ── RET-C: Match pace gap ─────────────────────────────────────────────────────

it('RET-C: fires retirement_match_pace_gap when YTD pace below match threshold', function () {
    // Annual gross $90,000; match 50% up to 6% threshold; user contributing ~2% pace → gap
    makeW2Profile($this->user->id, $this->taxYear, ['w2_wages' => '9000000']);
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '180000', $this->taxYear);

    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    expect($finding)->not->toBeNull();
    expect($finding->finding_type)->toBe('retirement');
    expect($finding->band)->toBe('auto');
});

it('RET-C: estimated_value_cents is set from engine (SAFE-03 — engine-only dollar amount)', function () {
    makeW2Profile($this->user->id, $this->taxYear, ['w2_wages' => '9000000']);
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '180000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    expect($finding->estimated_value_cents)->toBeGreaterThan(0);
});

it('RET-C: treatment text contains no hardcoded dollar amounts (SAFE-03)', function () {
    makeW2Profile($this->user->id, $this->taxYear, ['w2_wages' => '9000000']);
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '180000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    expect($finding->treatment)->not->toMatch('/\$[\d,]+/');
});

it('RET-C: does NOT fire when no employer.match_pct fact present', function () {
    makeW2Profile($this->user->id, $this->taxYear, ['w2_wages' => '9000000']);
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '180000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    expect($finding)->toBeNull();
});

it('RET-C: does NOT fire when YTD pace already meets match threshold', function () {
    // $9k YTD → annualized well above 6% of $90k
    makeW2Profile($this->user->id, $this->taxYear, ['w2_wages' => '9000000']);
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '900000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    expect($finding)->toBeNull();
});

// ── RET-D: W-4 Step 3 alignment ──────────────────────────────────────────────

it('RET-D: fires retirement_w4_step3_alignment when Step 3 credits do not match qualifying children', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    // 2 qualifying children at $2,200 each = $4,400 expected; user claimed $2,000 → mismatch
    recordConfirmedFact($this->user->id, 'w4.step3_annual_credits_cents', '200000', $this->taxYear); // $2,000
    recordConfirmedFact($this->user->id, 'family.qualifying_children_under_17', '2', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_w4_step3_alignment')->first();
    expect($finding)->not->toBeNull();
    expect($finding->finding_type)->toBe('withholding');
    expect($finding->band)->toBe('conditional');
});

it('RET-D: treatment contains educational withholding-alignment language', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    recordConfirmedFact($this->user->id, 'w4.step3_annual_credits_cents', '200000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'family.qualifying_children_under_17', '2', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_w4_step3_alignment')->first();
    expect(strtolower($finding->treatment))
        ->toContain('w-4')
        ->toContain('step 3');
    expect($finding->treatment)->toMatch('/may|worth/i');
});

it('RET-D: does NOT fire when Step 3 credits exactly match qualifying children count', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    // 2 children × $2,200 (2026 CTC) = $4,400 = 440000 cents → exact match → no finding
    recordConfirmedFact($this->user->id, 'w4.step3_annual_credits_cents', '440000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'family.qualifying_children_under_17', '2', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_w4_step3_alignment')->first();
    expect($finding)->toBeNull();
});

it('RET-D: does NOT fire when either W-4 or dependent fact is missing', function () {
    makeW2Profile($this->user->id, $this->taxYear);
    // Only Step 3 fact — cannot compute mismatch without dependents
    recordConfirmedFact($this->user->id, 'w4.step3_annual_credits_cents', '200000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_w4_step3_alignment')->first();
    expect($finding)->toBeNull();
});

// ── User 1 production state: all four findings from real confirmed facts ───────

it('all four retirement findings fire for user-1 production confirmed fact state', function () {
    // Reproduce user 1's actual confirmed fact state (2026-07-02 baseline):
    //   employer.after_tax_401k_available = 'yes' (benefits guide upload)
    //   retirement.roth_401k_ytd_cents = 15218 (paystub)
    //   retirement.traditional_401k_ytd_cents = 60870 (paystub)
    //   w4.step3_annual_credits_cents = 320000 (W-4; $3,200 but expect $4,400 for 2 kids)
    makeW2Profile($this->user->id, $this->taxYear, [
        // Both > 0 for RET-B; total YTD~$760 annualized ~$1,300 → well below 6% threshold for RET-C
        'traditional_401k_ytd' => '60870',   // mirroring confirmed fact value
        'roth_401k_ytd' => '15218',           // mirroring confirmed fact value
        'w2_wages' => '9600000',              // $96,000 estimate
    ]);

    // (a) After-tax 401k available (from benefits guide)
    recordConfirmedFact($this->user->id, 'employer.after_tax_401k_available', 'yes', $this->taxYear);

    // (b+c) YTD facts (both roth and traditional > 0; also used for RET-C pace)
    recordConfirmedFact($this->user->id, 'retirement.traditional_401k_ytd_cents', '60870', $this->taxYear);
    recordConfirmedFact($this->user->id, 'retirement.roth_401k_ytd_cents', '15218', $this->taxYear);

    // (c) Match formula — YTD pace ~0.8% → well below 6% threshold
    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9600000', $this->taxYear);

    // (d) W-4 Step 3 credits vs dependents ($3,200 claimed; 2 children × $2,200 = $4,400 expected)
    recordConfirmedFact($this->user->id, 'w4.step3_annual_credits_cents', '320000', $this->taxYear);
    recordConfirmedFact($this->user->id, 'family.qualifying_children_under_17', '2', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    // All four retirement opportunity findings should be present
    expect(OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->exists())->toBeTrue();
    expect(OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->exists())->toBeTrue();
    expect(OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->exists())->toBeTrue();
    expect(OptimizationFinding::where('finding_key', 'retirement_w4_step3_alignment')->exists())->toBeTrue();
});
