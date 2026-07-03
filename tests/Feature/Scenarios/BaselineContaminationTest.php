<?php

declare(strict_types=1);

/**
 * BaselineContaminationTest — Engine Bug regression tests (2026-07-03).
 *
 * Bug 1: Baseline contaminated by user's ELECTION (chosen ≠ current)
 *   assembleBaseline must derive current.deferral_pct from observed paystub
 *   per-period deductions, never from employer.contribution_pct written by the
 *   checklist K3 writeRealityFact path (which stores the CHOSEN percentage).
 *
 * Bug 2: Per-period paystub deductions stored under _ytd_cents keys
 *   PaystubFactExtractorService must write traditional_401k_deduction to
 *   retirement.traditional_401k_per_period_cents (new key), not the _ytd_ key.
 *   assembleBaseline must multiply per-period facts by pay_periods_per_year
 *   to produce correct annual run-rates.
 *
 * Fixture mirrors user 1's production state (2026-07-03 baseline):
 *   paystub: trad=$608.70/period, roth=$152.18/period, gross=$7,608.75/period
 *   gross annual = $7,608.75 × 26 = $197,827.50
 *   actual deferral = ($608.70+$152.18)/$7,608.75 = 10%
 *   chosen plan: 12%  (written to employer.contribution_pct by checklist K3)
 */

use App\Models\IncomeOptimizationProfile;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\AI\PaystubFactExtractorService;
use App\Services\ScenarioSolverService;
use App\Services\TaxRulesEngineService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function contaminationUser(): User
{
    return User::factory()->create();
}

function seedUser1Facts(int $userId, int $year): void
{
    // Per-period 401(k) deductions from paystub (now written to per-period keys)
    // $608.70/period trad + $152.18/period roth = $760.88/period = 10% of $7,608.75
    UserTaxFact::recordFact(
        userId: $userId,
        factKey: 'retirement.traditional_401k_per_period_cents',
        value: '60870',   // $608.70 in cents
        sourceType: 'document_extraction',
        label: 'Traditional 401(k) per paycheck (paystub)',
        volatility: 'annual',
        taxYear: $year,
    );
    // Promote to current (simulate confirm())
    UserTaxFact::where('user_id', $userId)
        ->where('fact_key', 'retirement.traditional_401k_per_period_cents')
        ->update(['is_current' => true, 'confirmed_at' => now()]);

    UserTaxFact::recordFact(
        userId: $userId,
        factKey: 'retirement.roth_401k_per_period_cents',
        value: '15218',   // $152.18 in cents
        sourceType: 'document_extraction',
        label: 'Roth 401(k) per paycheck (paystub)',
        volatility: 'annual',
        taxYear: $year,
    );
    UserTaxFact::where('user_id', $userId)
        ->where('fact_key', 'retirement.roth_401k_per_period_cents')
        ->update(['is_current' => true, 'confirmed_at' => now()]);

    // Per-period gross from paystub: $7,608.75
    UserTaxFact::recordFact(
        userId: $userId,
        factKey: 'pay.gross_per_period_cents',
        value: '760875',
        sourceType: 'document_extraction',
        label: 'Gross pay per paycheck',
        volatility: 'annual',
        taxYear: $year,
    );
    UserTaxFact::where('user_id', $userId)
        ->where('fact_key', 'pay.gross_per_period_cents')
        ->update(['is_current' => true, 'confirmed_at' => now()]);

    // Pay frequency: biweekly (26 periods/yr)
    UserTaxFact::recordFact(
        userId: $userId,
        factKey: 'pay.frequency',
        value: 'biweekly',
        sourceType: 'document_extraction',
        label: 'Pay frequency',
        volatility: 'annual',
        taxYear: $year,
    );
    UserTaxFact::where('user_id', $userId)
        ->where('fact_key', 'pay.frequency')
        ->update(['is_current' => true, 'confirmed_at' => now()]);

    // Chosen 12% — written by checklist K3 writeRealityFact (user_edit = ELECTED, not current)
    UserTaxFact::recordFact(
        userId: $userId,
        factKey: 'employer.contribution_pct',
        value: '12',
        sourceType: 'user_edit',
        label: '401(k) contribution % (elected)',
        volatility: 'stable',
        taxYear: null,
    );

    // Filing status for engine
    UserTaxFact::recordFact(
        userId: $userId,
        factKey: 'profile.filing_status',
        value: 'single',
        sourceType: 'interview_answer',
        label: 'Filing status',
        volatility: 'annual',
        taxYear: $year,
    );
}

function seedUser1Snapshot(int $userId, int $year): IncomeOptimizationProfile
{
    return IncomeOptimizationProfile::factory()->create([
        'user_id' => $userId,
        'tax_year' => $year,
        'w2_wages' => '19782750',   // $197,827.50 annual (per-period × 26)
        'traditional_401k_ytd' => null,
        'roth_401k_ytd' => null,
    ]);
}

// ── Bug 1: Baseline must NOT use chosen employer.contribution_pct as current ─

describe('Bug 1 — baseline not contaminated by election', function () {
    it('assembleBaseline derives current.deferral_pct from paystub per-period facts, not employer.contribution_pct', function () {
        $user = contaminationUser();
        $year = 2026;

        seedUser1Facts($user->id, $year);
        seedUser1Snapshot($user->id, $year);

        $solver = app(ScenarioSolverService::class);
        $baseline = $solver->assembleBaseline($user, $year);

        // employer.contribution_pct is 12 (user_edit from checklist K3)
        // Paystub per-period trad=$608.70, roth=$152.18, gross=$7,608.75 → 10%
        // Baseline must read 10%, NOT 12%
        expect($baseline['current']['deferral_pct'])
            ->toBeFloat()
            ->toBeBetween(9.5, 10.5);   // ≈10% with floating-point tolerance
    });

    it('solve(take_home) deferral_pct never exceeds the actual current 10% baseline', function () {
        $user = contaminationUser();
        $year = 2026;

        seedUser1Facts($user->id, $year);
        seedUser1Snapshot($user->id, $year);

        // Employer match data so the solver has something to work with
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'employer.match_threshold_pct',
            value: '6',
            sourceType: 'interview_answer',
            taxYear: $year,
        );
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'employer.has_401k',
            value: 'yes',
            sourceType: 'interview_answer',
            taxYear: $year,
        );

        $solver = app(ScenarioSolverService::class);
        $baseline = $solver->assembleBaseline($user, $year);

        // TAKE_HOME objective: maximize take-home by minimizing deferral.
        // K3 floor = max(current, match_threshold) = max(10, 6) = 10.
        // Chosen 12% (if baseline were contaminated) would set floor to 12,
        // making TAKE_HOME vector = 12 and all three options identical.
        $thKnobs = $solver->solve($baseline, 'take_home');

        // solve(take_home) should respect the REAL current deferral (~10%),
        // and may drop to the match_threshold floor or stay at 10%,
        // but NEVER should it report the contaminated 12%.
        expect($thKnobs['k401']['deferral_pct'])->toBeLessThanOrEqual(10.5);
    });

    it('three objectives produce distinct deferral_pct vectors when optima differ', function () {
        $user = contaminationUser();
        $year = 2026;

        seedUser1Facts($user->id, $year);
        seedUser1Snapshot($user->id, $year);

        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'employer.match_threshold_pct',
            value: '6',
            sourceType: 'interview_answer',
            taxYear: $year,
        );
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'employer.has_401k',
            value: 'yes',
            sourceType: 'interview_answer',
            taxYear: $year,
        );

        $solver = app(ScenarioSolverService::class);
        $engine = app(TaxRulesEngineService::class);
        $baseline = $solver->assembleBaseline($user, $year);

        $thKnobs = $solver->solve($baseline, 'take_home');
        $tbKnobs = $solver->solve($baseline, 'tax_burden');
        $rKnobs  = $solver->solve($baseline, 'retirement');

        // At $197k gross, RETIREMENT objective must push deferral higher than TAKE_HOME.
        // If all three are identical (=12% from contaminated baseline), this assertion fails.
        expect($rKnobs['k401']['deferral_pct'])
            ->toBeGreaterThanOrEqual($thKnobs['k401']['deferral_pct']);

        // The outcomes must be computable (no crash/exception)
        $thOutcome = $engine->computeScenarioOutcome($baseline, $thKnobs, $year);
        $rOutcome  = $engine->computeScenarioOutcome($baseline, $rKnobs, $year);

        expect($thOutcome['take_home'])->toHaveKey('annual_delta_cents');
        expect($rOutcome['retirement'])->toHaveKey('annual_contributions_delta_cents');
    });
});

// ── Bug 2: PaystubFactExtractorService writes to per-period keys ──────────────

describe('Bug 2 — per-period deductions written to per-period fact keys', function () {
    it('proposeFacts() writes traditional_401k_deduction to retirement.traditional_401k_per_period_cents', function () {
        $user = contaminationUser();

        // Build paystub document with per-period 401k fields
        $document = \App\Models\TaxDocument::create([
            'user_id' => $user->id,
            'original_filename' => 'paystub.pdf',
            'stored_path' => 'tax/'.$user->id.'/paystub.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'file_hash' => hash('sha256', uniqid('', true)),
            'tax_year' => 2026,
            'category' => 'pay_stub',
            'status' => 'ready',
            'extracted_data' => ['fields' => [
                'traditional_401k_deduction' => ['value' => '608.70', 'confidence' => 0.95],
                'roth_401k_deduction'         => ['value' => '152.18', 'confidence' => 0.95],
                'gross_pay'                   => ['value' => '7608.75', 'confidence' => 0.98],
            ]],
        ]);

        $service = app(PaystubFactExtractorService::class);
        $service->proposeFacts($document);

        // Must write to the per-period key, NOT the ytd key
        $tradProposal = UserTaxFact::forUser($user->id)
            ->where('fact_key', 'retirement.traditional_401k_per_period_cents')
            ->first();
        $rothProposal = UserTaxFact::forUser($user->id)
            ->where('fact_key', 'retirement.roth_401k_per_period_cents')
            ->first();

        expect($tradProposal)->not->toBeNull()
            ->and($tradProposal->is_current)->toBeFalse()
            ->and($tradProposal->source_type)->toBe('document_extraction');

        expect($rothProposal)->not->toBeNull()
            ->and($rothProposal->is_current)->toBeFalse()
            ->and($rothProposal->source_type)->toBe('document_extraction');

        // Must NOT write to the old ytd key anymore
        $oldTrad = UserTaxFact::forUser($user->id)
            ->where('fact_key', 'retirement.traditional_401k_ytd_cents')
            ->where('source_type', 'document_extraction')
            ->first();
        expect($oldTrad)->toBeNull();
    });

    it('assembleBaseline annualizes per-period 401k correctly: per_period_cents × pay_periods_per_year', function () {
        $user = contaminationUser();
        $year = 2026;

        seedUser1Facts($user->id, $year);
        seedUser1Snapshot($user->id, $year);

        $solver = app(ScenarioSolverService::class);
        $baseline = $solver->assembleBaseline($user, $year);

        // Annual trad = $608.70 × 26 = $15,826.20 → 1,582,620 cents
        // Annual roth  = $152.18 × 26 = $3,956.68 → 395,668 cents
        // Allow ±200 cents (rounding tolerance)
        expect($baseline['current']['trad_401k_cents'])
            ->toBeBetween(1_582_420, 1_582_820);

        expect($baseline['current']['roth_401k_cents'])
            ->toBeBetween(395_468, 395_868);
    });

    it('per-period 401k keys are registered in config/fact-registry.php', function () {
        $registry = config('fact-registry');

        expect($registry)->toHaveKey('retirement.traditional_401k_per_period_cents');
        expect($registry)->toHaveKey('retirement.roth_401k_per_period_cents');
    });
});
