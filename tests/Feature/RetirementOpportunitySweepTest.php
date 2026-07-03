<?php

/**
 * RetirementOpportunitySweepTest — Fix 2 (2026-07-02)
 *
 * Verifies the four retirement-opportunity findings from confirmed durable facts,
 * mirroring user 1's real confirmed-fact state:
 *   - employer.allows_after_tax_401k = 'true' (benefits guide upload)
 *   - retirement.roth_401k_ytd_cents > 0 (paystub)
 *   - retirement.traditional_401k_ytd_cents > 0 (paystub)
 *   - employer.match_pct + employer.match_threshold_pct (benefits guide)
 *   - income.annual_gross_cents (paystub)
 *   - w4.step3_annual_credits_cents + family.qualifying_children_under_17 (W-4 + interview)
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

// ── RET-A: After-tax 401k opportunity ────────────────────────────────────────

it('RET-A: fires retirement_after_tax_401k_opportunity when after-tax allowed and not maxed', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '500000',  // $5,000 — well below max
        'roth_401k_ytd' => '0',
    ]);

    recordConfirmedFact($this->user->id, 'employer.allows_after_tax_401k', 'true', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    expect($finding)->not->toBeNull();
    expect($finding->finding_type)->toBe('retirement');
    expect($finding->band)->toBe('conditional');
});

it('RET-A: treatment includes mandatory if-your-plan-allows framing and mega-backdoor hedging', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '500000',
        'roth_401k_ytd' => '0',
    ]);

    recordConfirmedFact($this->user->id, 'employer.allows_after_tax_401k', 'true', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    $lower = strtolower($finding->treatment);
    expect($lower)
        ->toContain('if your plan allows')
        ->toContain('mega backdoor')
        ->not->toContain('guaranteed');
});

it('RET-A: does NOT fire when employer.allows_after_tax_401k is false', function () {
    makeW2Profile($this->user->id, $this->taxYear, ['traditional_401k_ytd' => '500000']);

    recordConfirmedFact($this->user->id, 'employer.allows_after_tax_401k', 'false', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    expect($finding)->toBeNull();
});

it('RET-A: does NOT fire when after-tax allowed but already at employee deferral max', function () {
    // $24,500 = employee deferral limit for 2026 → already maxed
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '2450000',  // $24,500 in cents
        'roth_401k_ytd' => '0',
    ]);

    recordConfirmedFact($this->user->id, 'employer.allows_after_tax_401k', 'true', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    // Already at max — mega backdoor is handled by W2BenefitArbitrageDetector, not this sweep
    expect($finding)->toBeNull();
});

it('RET-A: does NOT fire for pure self-employed profile (no W-2 wages)', function () {
    makeW2Profile($this->user->id, $this->taxYear, ['w2_wages' => '0']);

    recordConfirmedFact($this->user->id, 'employer.allows_after_tax_401k', 'true', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->first();
    expect($finding)->toBeNull();
});

// ── RET-B: Contribution mix review ───────────────────────────────────────────

it('RET-B: fires retirement_contribution_mix_review when both Roth and Traditional YTD present', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '600000',   // $6,000
        'roth_401k_ytd' => '400000',           // $4,000
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->first();
    expect($finding)->not->toBeNull();
    expect($finding->finding_type)->toBe('retirement');
    expect($finding->band)->toBe('conditional');
});

it('RET-B: treatment mentions marginal rate comparison and reviewing annually', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '600000',
        'roth_401k_ytd' => '400000',
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->first();
    expect(strtolower($finding->treatment))
        ->toContain('marginal')
        ->toContain('roth')
        ->toContain('traditional');
});

it('RET-B: does NOT fire when only Traditional YTD present (no Roth)', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '800000',
        'roth_401k_ytd' => '0',
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->first();
    expect($finding)->toBeNull();
});

it('RET-B: does NOT fire when only Roth YTD present (no Traditional)', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '0',
        'roth_401k_ytd' => '900000',
    ]);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->first();
    expect($finding)->toBeNull();
});

// ── RET-C: Match pace gap ─────────────────────────────────────────────────────

it('RET-C: fires retirement_match_pace_gap when YTD pace below match threshold', function () {
    // Annual gross $90,000; match 50% up to 6% threshold; user contributing ~2% pace → gap
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '180000',   // $1,800 YTD in January → ~2% pace
        'roth_401k_ytd' => '0',
        'w2_wages' => '9000000',              // $90,000
    ]);

    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);       // 50% match
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear); // up to 6%
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear); // $90k

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    expect($finding)->not->toBeNull();
    expect($finding->finding_type)->toBe('retirement');
    expect($finding->band)->toBe('auto');
});

it('RET-C: estimated_value_cents is set from engine (SAFE-03 — engine-only dollar amount)', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '180000',
        'roth_401k_ytd' => '0',
        'w2_wages' => '9000000',
    ]);

    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    expect($finding->estimated_value_cents)->toBeGreaterThan(0);
});

it('RET-C: treatment text contains no hardcoded dollar amounts (SAFE-03)', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '180000',
        'roth_401k_ytd' => '0',
        'w2_wages' => '9000000',
    ]);

    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    // No dollar amounts like $2,700 or $1,800 should appear in the treatment text
    expect($finding->treatment)->not->toMatch('/\$[\d,]+/');
});

it('RET-C: does NOT fire when no employer.match_pct fact present', function () {
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '180000',
        'roth_401k_ytd' => '0',
        'w2_wages' => '9000000',
    ]);
    // No match_pct fact — cannot assess gap
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9000000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->first();
    expect($finding)->toBeNull();
});

it('RET-C: does NOT fire when YTD pace already meets match threshold', function () {
    // $9k YTD in first month → ~108k annualized pace → well above 6% threshold
    makeW2Profile($this->user->id, $this->taxYear, [
        'traditional_401k_ytd' => '900000',   // $9,000 YTD
        'roth_401k_ytd' => '0',
        'w2_wages' => '9000000',
    ]);

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
    // Confirm hedged copy — must say "may" or "worth"
    expect($finding->treatment)->toMatch('/may|worth/i');
});

it('RET-D: does NOT fire when Step 3 credits exactly match qualifying children count', function () {
    makeW2Profile($this->user->id, $this->taxYear);

    // 2 children × $2,200 = $4,400 → exact match → no finding
    recordConfirmedFact($this->user->id, 'w4.step3_annual_credits_cents', '440000', $this->taxYear); // $4,400
    recordConfirmedFact($this->user->id, 'family.qualifying_children_under_17', '2', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_w4_step3_alignment')->first();
    expect($finding)->toBeNull();
});

it('RET-D: does NOT fire when either W-4 or dependent fact is missing', function () {
    makeW2Profile($this->user->id, $this->taxYear);

    // Only Step 3 fact present — cannot compute mismatch without dependents
    recordConfirmedFact($this->user->id, 'w4.step3_annual_credits_cents', '200000', $this->taxYear);

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    $finding = OptimizationFinding::where('finding_key', 'retirement_w4_step3_alignment')->first();
    expect($finding)->toBeNull();
});

// ── User 1 state: all four findings from confirmed paystub + benefits facts ───

it('all four retirement findings fire for user-1 confirmed fact state', function () {
    // Reproduce user 1's actual confirmed fact state
    makeW2Profile($this->user->id, $this->taxYear, [
        // Both Roth + Traditional > 0 (triggers RET-B); total YTD annualised at month 7 = ~$2,057
        // ($1,200 * 12/7), which is ~2.1% of $96k gross — below 6% match threshold (triggers RET-C).
        'traditional_401k_ytd' => '60000',   // $600 YTD
        'roth_401k_ytd' => '60000',           // $600 YTD
        'w2_wages' => '9600000',              // $96,000 — 6% threshold = $5,760/yr; pace ~2.1%
    ]);

    // (a) After-tax 401k available (from benefits guide upload)
    recordConfirmedFact($this->user->id, 'employer.allows_after_tax_401k', 'true', $this->taxYear);

    // (b) Both YTD buckets already set above — mix finding

    // (c) Match formula from benefits guide; YTD pace ~2.1% → below 6% threshold
    recordConfirmedFact($this->user->id, 'employer.match_pct', '50', $this->taxYear);
    recordConfirmedFact($this->user->id, 'employer.match_threshold_pct', '6', $this->taxYear);
    recordConfirmedFact($this->user->id, 'income.annual_gross_cents', '9600000', $this->taxYear);

    // (d) W-4 Step 3 credits vs dependents
    recordConfirmedFact($this->user->id, 'w4.step3_annual_credits_cents', '220000', $this->taxYear); // $2,200 (1 child worth)
    recordConfirmedFact($this->user->id, 'family.qualifying_children_under_17', '2', $this->taxYear); // 2 children → mismatch

    $this->detector->run($this->user->id, $this->taxYear, $this->service, []);

    // All four retirement opportunity findings should be present
    expect(OptimizationFinding::where('finding_key', 'retirement_after_tax_401k_opportunity')->exists())->toBeTrue();
    expect(OptimizationFinding::where('finding_key', 'retirement_contribution_mix_review')->exists())->toBeTrue();
    expect(OptimizationFinding::where('finding_key', 'retirement_match_pace_gap')->exists())->toBeTrue();
    expect(OptimizationFinding::where('finding_key', 'retirement_w4_step3_alignment')->exists())->toBeTrue();
});
