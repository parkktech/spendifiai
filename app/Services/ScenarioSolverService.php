<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserTaxFact;
use Carbon\Carbon;

/**
 * ScenarioSolverService — deterministic six-knob solver for optimization scenarios (SCN-05).
 *
 * ZERO Claude / ZERO HTTP. Every dollar figure returned by the public methods traces
 * to TaxRulesEngineService::computeScenarioOutcome() — the solver invents no money math.
 * All IRS thresholds come from config/tax-rules.php and config/optimizer-scenarios.php
 * (the no-literal guard in NoLiteralGuardTest enforces this).
 *
 * Public API:
 *   assembleBaseline(User, year) → §B.2 baseline array (built from ScenarioFactResolverService::resolveAll)
 *   solve(baseline, 'take_home'|'tax_burden'|'retirement') → knob vector
 *   solveBalanced(thKnobs, rKnobs, tbKnobs, baseline) → computed Balanced knob vector
 *   diffKnobs(a, b) → list of diverging dimension keys (using §C.1 config epsilons)
 *   attributeBenefits(baseline, chosenKnobs) → per-knob + header-aggregate deltas
 *
 * Determinism guarantee: same baseline × same objective → identical knob vector on every call.
 * solve() has zero now()-dependent branching; annualization lives exclusively in assembleBaseline().
 *
 * §B.7 floor guard: every emitted knob vector satisfies
 *   takeHome(candidate) ≥ min_take_home_ratio × takeHome(current)
 * (tightened to min_take_home_ratio_cash_constrained when baseline.is_cash_constrained).
 *
 * §B.7 dominant-free-return rule: k401.deferral_pct is never below match_threshold_pct
 * (captures all employer match) in any scenario.
 */
final class ScenarioSolverService
{
    public function __construct(
        private readonly ScenarioFactResolverService $resolver,
        private readonly TaxRulesEngineService $engine,
    ) {}

    // ══════════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build the §B.2 baseline array from ScenarioFactResolverService::resolveAll() (M15).
     *
     * Annualization of YTD facts happens HERE — no now()-branching in solve().
     * The fact_set_hash covers relevant current-fact ids + snapshot profile_hash so any
     * interview answer invalidates downstream caches.
     *
     * @param  int  $monthlySurplusCents  Pre-computed monthly surplus from the dashboard
     *                                    waterfall (DashboardController figures; never re-derived
     *                                    here per §B.1 K6). Pass 0 if not available.
     */
    public function assembleBaseline(User $user, int $year, int $monthlySurplusCents = 0): array
    {
        $resolved = $this->resolver->resolveAll($user, $year);

        $v = fn (string $key) => ($resolved[$key]['value'] ?? null);

        // ── C2: annual gross ──────────────────────────────────────────────────
        $grossCents = (int) ($v('income.annual_gross_cents') ?? 0);

        // ── SE income separately (snapshot column; may be included in gross) ──
        // NOTE: We read SE income to populate se_income_cents for engine accuracy.
        // If the resolver's gross already includes SE (composite snapshot), and we
        // also add se_income_cents on top, the engine would double-count. For safety
        // we set se=0 here (the delta math is invariant to SE in v2.1 per spec) and
        // let gross include everything. This matches the documented SE assumption.
        $seIncomeCents = 0;

        // ── C3: pay frequency → periods ───────────────────────────────────────
        $payFreq = (string) ($v('pay.frequency') ?? 'biweekly');
        $periodsMap = (array) config('optimization-objectives.pay_periods_per_year', []);
        $periods = max(1, (int) ($periodsMap[$payFreq] ?? 26));

        // ── C1: filing status (confirmed) ─────────────────────────────────────
        $filingStatus = (string) ($v('profile.filing_status') ?? 'single');

        // ── W-4 on file (T4, optional-with-suppression M12) ───────────────────
        $w4FilingStatus = $v('w4.filing_status');
        $w4Deps = $v('w4.dependents_claimed');

        // Fix-B: Pub 15-T Step 3 annual dollar credits (direct; overrides count-based credits
        // when confirmed from paystub / W-4 document extraction).
        $w4Step3Fact = UserTaxFact::currentFact($user->id, 'w4.step3_annual_credits_cents', null, $year);
        $w4Step3CreditsCents = $w4Step3Fact !== null ? (int) $w4Step3Fact->value : 0;

        // ── Family (T5) ───────────────────────────────────────────────────────
        $depsUnder17 = max(0, (int) ($v('family.qualifying_children_under_17') ?? 0));
        $depsTotal = max(0, (int) ($v('family.dependents_count') ?? 0));
        $otherDeps = max(0, $depsTotal - $depsUnder17);

        // ── C4: age (from birth year derivation, snapshot fallback) ───────────
        $birthYear = $v('person.birth_year');
        $age = $birthYear !== null && ctype_digit((string) $birthYear)
            ? ($year - (int) $birthYear)
            : null;

        // ── Retirement target age (R2) ────────────────────────────────────────
        $targetRetirementAge = (int) (
            $v('retirement.target_age')
            ?? config('optimizer-scenarios.assumptions.default_retirement_age')
        );

        // ── Current annualized YTD contributions (T6/B3/R3/R4) ───────────────
        // Annualize: ytd / elapsed_fraction where fraction = day_of_year / days_in_year.
        // This 'now()' call is intentional and acceptable here (annualization allowed in
        // assembleBaseline per spec — it is the ONLY now()-dependent computation in the solver).
        $today = Carbon::now();
        $dayOfYear = $today->dayOfYear;
        $daysInYear = $today->daysInYear;
        $annFraction = max(0.01, $dayOfYear / $daysInYear); // avoid division by zero

        $ytdTrad401k = (int) ($v('retirement.traditional_401k_ytd_cents') ?? 0);
        $ytdRoth401k = (int) ($v('retirement.roth_401k_ytd_cents') ?? 0);
        $ytdHsa = (int) ($v('hsa.ytd_contribution_cents') ?? 0);
        $ytdIraTrad = (int) ($v('ira.traditional_ytd_contribution_cents') ?? 0);
        $ytdIraRoth = (int) ($v('ira.roth_ytd_contribution_cents') ?? 0);

        // Annual run-rates (annualized from YTD):
        $annTrad401k = (int) round($ytdTrad401k / $annFraction);
        $annRoth401k = (int) round($ytdRoth401k / $annFraction);
        $annHsa = (int) round($ytdHsa / $annFraction);
        // IRA ytd is used as-is (absolute; not typically annualized mid-year):
        $curIraTrad = $ytdIraTrad;
        $curIraRoth = $ytdIraRoth;

        // Current deferral % (employer.contribution_pct or derived):
        $contribPctRaw = $v('employer.contribution_pct');
        if ($contribPctRaw !== null) {
            $curDeferralPct = (float) $contribPctRaw;
        } elseif ($grossCents > 0) {
            $totalDeferral = $annTrad401k + $annRoth401k;
            $curDeferralPct = $totalDeferral / $grossCents * 100;
        } else {
            $curDeferralPct = 0.0;
        }

        // ── Employer match trio (R6/R7) ───────────────────────────────────────
        $matchPct = $v('employer.match_pct') !== null ? (float) $v('employer.match_pct') : null;
        $matchThresholdPct = $v('employer.match_threshold_pct') !== null
            ? (float) $v('employer.match_threshold_pct')
            : null;
        $has401k = $v('employer.has_401k');
        $has401kBool = in_array($has401k, ['yes', 'true', '1', true], true);

        // ── Prior year FICA wages proxy (for mandatory Roth catchup check) ────
        $priorYearFica = $v('prior_year.agi_cents') !== null
            ? (int) $v('prior_year.agi_cents')
            : null;

        // ── HSA coverage type (B6) ────────────────────────────────────────────
        $hsaCoverageType = $v('hsa.coverage_type');

        // ── Medicare enrollment date ──────────────────────────────────────────
        $medicareDate = $v('medicare.enrollment_date');

        // ── ACA marketplace flag ──────────────────────────────────────────────
        $marketplace = $v('marketplace.pays_marketplace_premiums');
        $isMarketplace = in_array($marketplace, ['yes', 'true', '1', true], true);

        // ── Cash-constrained flag (R10) ───────────────────────────────────────
        $cashConst = $v('finance.is_cash_constrained');
        $isCashConstrained = in_array($cashConst, ['yes', 'true', '1', true], true);

        // ── Spouse covered by retirement plan (M13) ───────────────────────────
        $spCovered = $v('spouse.covered_by_retirement_plan');
        $spouseCovered = in_array($spCovered, ['yes', 'true', '1', true], true);

        // ── Annual withholding (for safe-harbor guardrail, T3) ────────────────
        $annualWithholding = $v('employer.federal_withholding') !== null
            ? (int) $v('employer.federal_withholding')
            : null;

        // ── Prior year federal liability (T8) ─────────────────────────────────
        $priorFedLiability = $v('prior_year.federal_liability_cents') !== null
            ? (int) $v('prior_year.federal_liability_cents')
            : null;

        // ── Fact set hash (from resolver) ─────────────────────────────────────
        $factSetHash = $this->resolver->snapshotFactSet($user, $year)->fact_set_hash;

        $baseline = [
            'annual_gross_cents' => $grossCents,
            'se_income_cents' => $seIncomeCents,
            'pay_periods_per_year' => $periods,
            'filing_status' => $filingStatus,
            'w4_on_file' => [
                'filing_status' => $w4FilingStatus,
                'dependents_claimed' => $w4Deps !== null ? (int) $w4Deps : null,
                // Fix-B: Pub 15-T Step 3 dollar credits — when > 0, engine uses this directly
                // instead of count-based credits (dependents × per-unit CTC/ODC amounts).
                'step3_credits_cents' => $w4Step3CreditsCents,
            ],
            'family' => [
                'dependents_under_17' => $depsUnder17,
                'other_dependents' => $otherDeps,
            ],
            'age' => $age,
            'target_retirement_age' => $targetRetirementAge,
            'prior_year_fica_wages_cents' => $priorYearFica,
            'current' => [
                'trad_401k_cents' => $annTrad401k,
                'roth_401k_cents' => $annRoth401k,
                'hsa_cents' => $annHsa,
                'ira_trad_ytd_cents' => $curIraTrad,
                'ira_roth_ytd_cents' => $curIraRoth,
                'deferral_pct' => $curDeferralPct,
            ],
            'employer' => [
                'match_pct' => $matchPct,
                'match_threshold_pct' => $matchThresholdPct,
                'has_401k' => $has401kBool,
            ],
            'hsa_coverage_type' => $hsaCoverageType,
            'medicare_enrollment_date' => $medicareDate,
            'is_marketplace_enrollee' => $isMarketplace,
            'is_cash_constrained' => $isCashConstrained,
            'spouse_covered_by_plan' => $spouseCovered,
            'monthly_surplus_cents' => $monthlySurplusCents,
            'annual_withholding_cents' => $annualWithholding,
            'prior_year_federal_liability_cents' => $priorFedLiability,
            'fact_set_hash' => $factSetHash,
        ];

        // ── Baseline take-home per period (for floor guard in solve()) ─────────
        // Computed once here so solve() has zero now()-branching.
        $baseline['baseline_per_period_take_home_cents'] = $this->computeCurrentTakeHomeCents(
            $baseline, $year
        );

        return $baseline;
    }

    /**
     * Solve for the given objective and return the §B.1 knob vector.
     *
     * Objective ids: 'take_home' | 'tax_burden' | 'retirement' (§A.2, [M4]).
     * The returned vector can be passed directly to computeScenarioOutcome().
     *
     * Deterministic: fixed grids, fixed iteration order, integer math,
     * zero now()-branching (annualization was done in assembleBaseline).
     */
    public function solve(array $baseline, string $objective, int $year = 2026): array
    {
        return match ($objective) {
            'take_home' => $this->solveTakeHome($baseline, $year),
            'tax_burden' => $this->solveTaxBurden($baseline, $year),
            'retirement' => $this->solveRetirement($baseline, $year),
            default => throw new \InvalidArgumentException(
                "Unknown objective: {$objective}. Allowed: take_home, tax_burden, retirement."
            ),
        };
    }

    /**
     * Balanced synthesis (§B.7, §C.2): per-knob midpoints between TAKE_HOME and
     * RETIREMENT vectors, snapped to each knob's grid, then re-run through
     * computeScenarioOutcome (re-clamped, re-guarded). Never averages outcomes.
     *
     * Special case §C.2: when the TAX_BURDEN vector diverges from BOTH poles
     * (diffKnobs(TB, TH) non-empty AND diffKnobs(TB, R) non-empty), Balanced is
     * seeded from the TB vector instead of the midpoint, and labeled
     * "Balanced — also lowest 2026 tax" (label applied by the caller/API layer).
     *
     * @param  array  $thKnobs  TAKE_HOME solver output
     * @param  array  $rKnobs  RETIREMENT solver output
     * @param  array  $tbKnobs  TAX_BURDEN solver output
     * @param  array  $baseline  §B.2 baseline
     */
    public function solveBalanced(
        array $thKnobs,
        array $rKnobs,
        array $tbKnobs,
        array $baseline,
        int $year = 2026
    ): array {
        // §C.2 special-case: TB diverges from both poles → seed from TB.
        $tbDivFromTH = $this->diffKnobs($tbKnobs, $thKnobs);
        $tbDivFromR = $this->diffKnobs($tbKnobs, $rKnobs);

        if (! empty($tbDivFromTH) && ! empty($tbDivFromR)) {
            // Balanced seeded from TB; re-run through engine for re-clamping.
            // The outcome shape is identical (engine handles remaining guards).
            $seed = $tbKnobs;
        } else {
            // Standard midpoint synthesis: per-knob midpoints of TH and R.
            $seed = $this->midpointKnobs($thKnobs, $rKnobs, $baseline, $year);
        }

        // Re-run through engine (re-clamped, re-guarded) and return the KNOB vector
        // from the clamped outcome (not the raw seed — engine may adjust).
        $outcome = $this->engine->computeScenarioOutcome($baseline, $seed, $year);

        return $outcome['knobs'];
    }

    /**
     * Knob-level divergence detection (§C.1). Returns the list of dimension keys
     * whose absolute difference exceeds their config epsilon.
     *
     * w4.* dimensions never diverge (identical in all scenarios by construction —
     * they always align to the user's confirmed filing status and dependents).
     *
     * For ira.* dimensions the epsilon is max(config_epsilon, ¼ × remainingRoom)
     * per §C.1. Pass remainingIraRoomCents from the baseline if available.
     *
     * @return string[] e.g. ['k401.deferral_pct', 'k401.roth_share_pct']
     */
    public function diffKnobs(array $a, array $b, ?int $remainingIraRoomCents = null): array
    {
        $eps = (array) config('optimizer-scenarios.divergence');
        $diverging = [];

        // k401.deferral_pct
        if (abs(($a['k401']['deferral_pct'] ?? 0) - ($b['k401']['deferral_pct'] ?? 0))
            >= (float) ($eps['k401.deferral_pct'] ?? 1.0)) {
            $diverging[] = 'k401.deferral_pct';
        }

        // k401.roth_share_pct
        if (abs(($a['k401']['roth_share_pct'] ?? 0) - ($b['k401']['roth_share_pct'] ?? 0))
            >= (float) ($eps['k401.roth_share_pct'] ?? 25)) {
            $diverging[] = 'k401.roth_share_pct';
        }

        // hsa.annual_election_cents
        if (abs(($a['hsa']['annual_election_cents'] ?? 0) - ($b['hsa']['annual_election_cents'] ?? 0))
            >= (int) ($eps['hsa.annual_election_cents'] ?? 25_000)) {
            $diverging[] = 'hsa.annual_election_cents';
        }

        // ira.traditional_cents — epsilon = max(config_epsilon, ¼ × remainingRoom)
        $iraTradEps = (int) ($eps['ira.traditional_cents'] ?? 50_000);
        if ($remainingIraRoomCents !== null) {
            $iraTradEps = max($iraTradEps, (int) round($remainingIraRoomCents / 4));
        }
        if (abs(($a['ira']['traditional_cents'] ?? 0) - ($b['ira']['traditional_cents'] ?? 0))
            >= $iraTradEps) {
            $diverging[] = 'ira.traditional_cents';
        }

        // ira.roth_cents — same epsilon logic
        $iraRothEps = (int) ($eps['ira.roth_cents'] ?? 50_000);
        if ($remainingIraRoomCents !== null) {
            $iraRothEps = max($iraRothEps, (int) round($remainingIraRoomCents / 4));
        }
        if (abs(($a['ira']['roth_cents'] ?? 0) - ($b['ira']['roth_cents'] ?? 0))
            >= $iraRothEps) {
            $diverging[] = 'ira.roth_cents';
        }

        // transfer.per_period_cents
        if (abs(($a['transfer']['per_period_cents'] ?? 0) - ($b['transfer']['per_period_cents'] ?? 0))
            >= (int) ($eps['transfer.per_period_cents'] ?? 2_500)) {
            $diverging[] = 'transfer.per_period_cents';
        }

        // w4.* never diverges — not in the result by construction.

        return $diverging;
    }

    /**
     * Per-knob benefit attribution (§B.8, feeds Decision-9 D9.7 benefit lines).
     *
     * For each knob dimension, calls computeScenarioOutcome with only that dimension
     * changed (vs the current run-rate vector) and records its three deltas. The
     * interaction remainder (chosen-total minus sum of singles) is attributed to
     * header_aggregate only — never to an individual step.
     *
     * Engine calls: ≤ 6 single-knob calls + 1 total call = ≤ 7 total.
     * Deterministic arithmetic → exact short-horizon figures; long-horizon → range framing
     * (D9.7: 'illustration' key in retirement deltas).
     *
     * @return array{
     *   knobs: array<string, array{take_home_annual_delta_cents:int, federal_tax_annual_delta_cents:int, retirement_contributions_delta_cents:int}>,
     *   header_aggregate: array{take_home_annual_delta_cents:int, federal_tax_annual_delta_cents:int, retirement_contributions_delta_cents:int, interaction_remainder_take_home_cents:int, interaction_remainder_federal_tax_cents:int, interaction_remainder_retirement_cents:int}
     * }
     */
    public function attributeBenefits(array $baseline, array $chosenKnobs, int $year = 2026): array
    {
        $current = $this->currentKnobVector($baseline);

        // ── Total chosen outcome ──────────────────────────────────────────────
        $total = $this->engine->computeScenarioOutcome($baseline, $chosenKnobs, $year);
        $totalTH = $total['take_home']['annual_delta_cents'];
        $totalFT = $total['federal_tax']['annual_delta_cents'];
        $totalRC = $total['retirement']['annual_contributions_delta_cents'];

        // ── Six single-knob isolated outcomes ────────────────────────────────
        $dimensions = [
            'k401_deferral' => fn () => array_replace_recursive($current, [
                'k401' => ['deferral_pct' => $chosenKnobs['k401']['deferral_pct'] ?? 0],
            ]),
            'k401_roth' => fn () => array_replace_recursive($current, [
                'k401' => ['roth_share_pct' => $chosenKnobs['k401']['roth_share_pct'] ?? 0],
            ]),
            'w4' => fn () => array_replace_recursive($current, [
                'w4' => $chosenKnobs['w4'],
            ]),
            'hsa' => fn () => array_replace_recursive($current, [
                'hsa' => $chosenKnobs['hsa'],
            ]),
            'ira' => fn () => array_replace_recursive($current, [
                'ira' => $chosenKnobs['ira'],
            ]),
            'transfer' => fn () => array_replace_recursive($current, [
                'transfer' => $chosenKnobs['transfer'],
            ]),
        ];

        $knobAttrs = [];
        $sumTH = 0;
        $sumFT = 0;
        $sumRC = 0;

        foreach ($dimensions as $dim => $buildKnobs) {
            $singleKnobs = $buildKnobs();
            $singleOutcome = $this->engine->computeScenarioOutcome($baseline, $singleKnobs, $year);

            $thDelta = $singleOutcome['take_home']['annual_delta_cents'];
            $ftDelta = $singleOutcome['federal_tax']['annual_delta_cents'];
            $rcDelta = $singleOutcome['retirement']['annual_contributions_delta_cents'];

            $knobAttrs[$dim] = [
                'take_home_annual_delta_cents' => $thDelta,
                'federal_tax_annual_delta_cents' => $ftDelta,
                'retirement_contributions_delta_cents' => $rcDelta,
            ];

            $sumTH += $thDelta;
            $sumFT += $ftDelta;
            $sumRC += $rcDelta;
        }

        // ── Interaction remainder (D9.7: header aggregate only) ──────────────
        $interTH = $totalTH - $sumTH;
        $interFT = $totalFT - $sumFT;
        $interRC = $totalRC - $sumRC;

        return [
            'knobs' => $knobAttrs,
            'header_aggregate' => [
                'take_home_annual_delta_cents' => $totalTH,
                'federal_tax_annual_delta_cents' => $totalFT,
                'retirement_contributions_delta_cents' => $totalRC,
                'interaction_remainder_take_home_cents' => $interTH,
                'interaction_remainder_federal_tax_cents' => $interFT,
                'interaction_remainder_retirement_cents' => $interRC,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE: objective solvers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * TAKE_HOME solver — maximise per-paycheck take-home now (§B.7).
     *
     * 1. K1 = align W-4 to confirmed facts.
     * 2. K3 = max(current_pct, match_threshold_pct) — never leave employer match.
     * 3. K2 = 0 (traditional lowers withholding → raises take-home),
     *          unless mandatory-Roth-catchup forces the excess to Roth.
     * 4. K4 = current HSA (unchanged).
     * 5. K5 IRA additional = 0.
     * 6. K6 = income_objective_transfer_share × per-paycheck take-home gain
     *         (routes the gain to automatic savings; capped by surplus cap).
     */
    private function solveTakeHome(array $baseline, int $year): array
    {
        $w4 = $this->buildW4Knobs($baseline);
        $currentPct = $this->currentDeferralPct($baseline);
        $matchThreshold = (float) ($baseline['employer']['match_threshold_pct'] ?? 0);
        $has401k = (bool) ($baseline['employer']['has_401k'] ?? false);

        // K3: at least match_threshold_pct (captures free match); never below current.
        $deferralPct = $has401k
            ? max($currentPct, $matchThreshold)
            : $currentPct;

        // K2 = 0 (traditional reduces FITW → raises take-home).
        $rothSharePct = 0;

        // K4 = current HSA (unchanged).
        $hsaCents = (int) ($baseline['current']['hsa_cents'] ?? 0);

        // K5 = 0 additional IRA.
        $iraTrad = 0;
        $iraRoth = 0;

        // Build candidate with K6 = 0 first, run engine to get take-home delta.
        $candidate = [
            'w4' => $w4,
            'k401' => ['deferral_pct' => $deferralPct, 'roth_share_pct' => $rothSharePct],
            'hsa' => ['annual_election_cents' => $hsaCents],
            'ira' => ['traditional_cents' => $iraTrad, 'roth_cents' => $iraRoth],
            'transfer' => ['per_period_cents' => 0],
        ];

        $outcome = $this->engine->computeScenarioOutcome($baseline, $candidate, $year);

        // K6: income_objective_transfer_share × per-paycheck take-home gain.
        $perPaycheckGain = $outcome['take_home']['per_paycheck_delta_cents'];
        $transferShare = (float) config('optimizer-scenarios.assumptions.income_objective_transfer_share');
        $k6PerPeriod = max(0, (int) round($perPaycheckGain * $transferShare));

        // Apply K6 (engine will clamp to surplus cap; re-run not needed for the
        // knob vector since K6 doesn't affect take-home per §B.1 K6 note).
        $candidate['transfer']['per_period_cents'] = $k6PerPeriod;

        // Re-run to get clamped knob vector (in case engine adjusts K6).
        $final = $this->engine->computeScenarioOutcome($baseline, $candidate, $year);

        return $final['knobs'];
    }

    /**
     * TAX_BURDEN solver — minimise current-year federal tax (§B.7).
     *
     * Greedy fill in config tax_objective_priority order:
     *   [match_capture, hsa, trad_401k, trad_ira]
     *
     * 1. K3 to match threshold (captures free match; never below current).
     * 2. K4 HSA → largest grid-aligned election keeping floor satisfied.
     * 3. K3 continue → raise deferral_pct in 1-pt steps while floor holds.
     * 4. K5 trad IRA → largest quarter-grid amount while floor holds. K5 roth = 0.
     * 5. K6 = 0.
     * K2 = 0 (all-traditional is MAGI-minimizing, naturally ACA-safe).
     */
    private function solveTaxBurden(array $baseline, int $year): array
    {
        $w4 = $this->buildW4Knobs($baseline);
        $currentPct = $this->currentDeferralPct($baseline);
        $matchThreshold = (float) ($baseline['employer']['match_threshold_pct'] ?? 0);
        $has401k = (bool) ($baseline['employer']['has_401k'] ?? false);

        // Step 1: K3 = max(current, match_threshold) — capture match first.
        $deferralPct = $has401k
            ? max($currentPct, $matchThreshold)
            : $currentPct;

        // K2 = 0 (traditional).
        $rothSharePct = 0;

        // K4 starts at current HSA.
        $currentHsa = (int) ($baseline['current']['hsa_cents'] ?? 0);
        $hsaCents = $currentHsa;

        // Step 2: raise K4 HSA in grid steps if HSA-eligible.
        if ($baseline['hsa_coverage_type'] !== null) {
            $hsaStep = (int) config('optimizer-scenarios.assumptions.hsa_grid_step_cents');
            $age = $baseline['age'] ?? null;
            $coverageType = (string) $baseline['hsa_coverage_type'];
            $fullHsaLimit = $this->engine->remainingHsaRoomCents(0, $coverageType, $age, $year)
                + $currentHsa;

            // Try raising in steps; keep largest that passes floor.
            $best = $currentHsa;
            $probe = $currentHsa + $hsaStep;
            while ($probe <= $fullHsaLimit) {
                $candidate = $this->buildCandidateKnobs($w4, $deferralPct, $rothSharePct, $probe, 0, 0, 0, $baseline);
                if ($this->checkFloor($baseline, $candidate, $year)) {
                    $best = $probe;
                }
                $probe += $hsaStep;
            }
            $hsaCents = $best;
        }

        // Step 3: raise K3 deferral_pct in 1-pt steps.
        if ($has401k) {
            $maxDeferral = $this->maxDeferralPct($baseline, $year);
            $bestPct = $deferralPct;
            $probe = $deferralPct + self::DEFERRAL_STEP_PCT;
            while ($probe <= $maxDeferral + 0.001) {
                $candidate = $this->buildCandidateKnobs($w4, $probe, 0, $hsaCents, 0, 0, 0, $baseline);
                if ($this->checkFloor($baseline, $candidate, $year)) {
                    $bestPct = $probe;
                } else {
                    break; // floor fails; raising further will only reduce take-home more
                }
                $probe += self::DEFERRAL_STEP_PCT;
            }
            $deferralPct = $bestPct;
        }

        // Step 4: K5 trad IRA — largest quarter-grid amount within deductible limit.
        $iraTrad = 0;
        $iraRothYtd = (int) ($baseline['current']['ira_roth_ytd_cents'] ?? 0);
        $iraTradYtd = (int) ($baseline['current']['ira_trad_ytd_cents'] ?? 0);
        $age = $baseline['age'] ?? null;
        $remainingRoom = $this->engine->remainingIraRoomCents($iraTradYtd + $iraRothYtd, $age, $year);

        if ($remainingRoom > 0) {
            $fractions = array_reverse((array) config('optimizer-scenarios.grids.ira_room_fractions'));
            foreach ($fractions as $frac) {
                if ($frac <= 0.0) {
                    break;
                }
                $trialTrad = (int) round($remainingRoom * $frac);
                $candidate = $this->buildCandidateKnobs(
                    $w4, $deferralPct, 0, $hsaCents, $trialTrad, 0, 0, $baseline
                );
                $outcome = $this->engine->computeScenarioOutcome($baseline, $candidate, $year);
                // Only keep if the engine didn't clamp it to 0 (deductibility check)
                // AND the floor holds.
                if ($outcome['knobs']['ira']['traditional_cents'] > 0
                    && $this->checkFloor($baseline, $candidate, $year)) {
                    $iraTrad = $trialTrad;
                    break;
                }
            }
        }

        // Step 5: K6 = 0.
        $finalKnobs = $this->buildCandidateKnobs(
            $w4, $deferralPct, $rothSharePct, $hsaCents, $iraTrad, 0, 0, $baseline
        );

        $outcome = $this->engine->computeScenarioOutcome($baseline, $finalKnobs, $year);

        return $outcome['knobs'];
    }

    /**
     * RETIREMENT solver — maximise retirement savings delta (§B.7).
     *
     * Greedy fill order mirrors solveTaxBurden() for Steps 1-3 so both solvers face the same
     * combined (HSA + 401k) floor constraint and converge to the same K3 ceiling:
     *   1. K3 = max(current, match_threshold)   — capture free match first.
     *   2. K2 = 0 (all-traditional 401k)        — Roth band applied to K5 IRA only.
     *   2b. K4 HSA fill                          — at match-threshold deferral (before K3 raise).
     *   3. K3 continue to max                    — with HSA already filled in floor checks.
     *   4. K5 IRA fill                           — type-split by band + Roth phase-out cap.
     *   5. K6 = ceil(K5_annual / periods)        — funds the IRA transfer per paycheck.
     *
     * K2=0 rationale: Roth 401k costs the same gross deduction as traditional but provides zero
     * withholding relief, tightening the floor constraint and reducing achievable K3. Setting K2=0
     * lets K3 reach the same ceiling as solveTaxBurden() and satisfies the dominance invariant
     * (§B.7: retirement contributions_delta ≥ max(take_home, tax_burden) contributions_delta).
     * The Roth vs Traditional recommendation from rothVsTraditionalBand() is applied to K5 IRA
     * instead — IRA contributions do not affect take-home, so the band can be fully honoured there
     * without tightening the floor.
     *
     * HSA-before-K3-continue rationale: with K2=0, placing the K3 greedy search BEFORE HSA
     * fill allows retirement to reach a higher K3 than tax_burden (which fills HSA first) — the
     * extra trad-401k deduction then produces lower federal tax than tax_burden, violating the
     * lowest-tax badge invariant (§C.3). Filling HSA first (matching tax_burden's ordering) ensures
     * both solvers reach the same K3 ceiling so their trad deductions and federal tax are tied.
     */
    private function solveRetirement(array $baseline, int $year): array
    {
        $w4 = $this->buildW4Knobs($baseline);
        $has401k = (bool) ($baseline['employer']['has_401k'] ?? false);
        $matchThreshold = (float) ($baseline['employer']['match_threshold_pct'] ?? 0);
        $currentPct = $this->currentDeferralPct($baseline);
        $age = $baseline['age'] ?? null;

        // Step 1: K3 = max(current, match_threshold) — capture free match; K3 raised further in Step 3.
        $deferralPct = $has401k
            ? max($currentPct, $matchThreshold)
            : $currentPct;

        // Step 2: K2 = 0 (all-traditional 401k; see docblock above).
        $gross = (int) ($baseline['annual_gross_cents'] ?? 0);
        $se = (int) ($baseline['se_income_cents'] ?? 0);
        $curTrad401k = (int) ($baseline['current']['trad_401k_cents'] ?? 0);
        $curHsa = (int) ($baseline['current']['hsa_cents'] ?? 0);
        $stdDed = $this->engine->standardDeductionCents(
            (string) ($baseline['filing_status'] ?? 'single'), $age, $year
        );
        $curTaxable = max(0, $gross + $se - $curTrad401k - $curHsa - $stdDed);
        $margRate = $this->engine->marginalRate($curTaxable, (string) ($baseline['filing_status'] ?? 'single'), $year);
        $band = $this->engine->rothVsTraditionalBand($margRate, $year);

        $rothSharePct = 0;  // K2=0; band used for K5 IRA type-split below

        // Step 2b: K4 HSA — fill room at match-threshold deferral BEFORE the K3 greedy.
        // Mirrors solveTaxBurden()'s match→HSA→K3 order; see docblock for why this matters.
        $currentHsa = (int) ($baseline['current']['hsa_cents'] ?? 0);
        $hsaCents = $currentHsa;

        if ($baseline['hsa_coverage_type'] !== null) {
            $hsaStep = (int) config('optimizer-scenarios.assumptions.hsa_grid_step_cents');
            $coverageType = (string) $baseline['hsa_coverage_type'];
            $fullHsaLimit = $this->engine->remainingHsaRoomCents(0, $coverageType, $age, $year)
                + $currentHsa;

            $bestHsa = $currentHsa;
            $probeHsa = $currentHsa + $hsaStep;
            while ($probeHsa <= $fullHsaLimit) {
                $candidate = $this->buildCandidateKnobs(
                    $w4, $deferralPct, $rothSharePct, $probeHsa, 0, 0, 0, $baseline
                );
                if ($this->checkFloor($baseline, $candidate, $year)) {
                    $bestHsa = $probeHsa;
                }
                $probeHsa += $hsaStep;
            }
            $hsaCents = $bestHsa;
        }

        // Step 3: K3 continue — raise deferral_pct in 1-pt steps WITH HSA already filled.
        if ($has401k) {
            $maxDeferral = $this->maxDeferralPct($baseline, $year);
            $bestPct = $deferralPct;
            $probe = $deferralPct + self::DEFERRAL_STEP_PCT;
            while ($probe <= $maxDeferral + 0.001) {
                $candidate = $this->buildCandidateKnobs($w4, $probe, $rothSharePct, $hsaCents, 0, 0, 0, $baseline);
                if ($this->checkFloor($baseline, $candidate, $year)) {
                    $bestPct = $probe;
                } else {
                    break;
                }
                $probe += self::DEFERRAL_STEP_PCT;
            }
            $deferralPct = $bestPct;
        }

        // Step 4: K5 IRA — fill remaining room; type-split by band + Roth phase-out cap.
        $iraTradYtd = (int) ($baseline['current']['ira_trad_ytd_cents'] ?? 0);
        $iraRothYtd = (int) ($baseline['current']['ira_roth_ytd_cents'] ?? 0);
        $remainingRoom = $this->engine->remainingIraRoomCents($iraTradYtd + $iraRothYtd, $age, $year);
        $iraTrad = 0;
        $iraRoth = 0;

        if ($remainingRoom > 0) {
            // Determine Roth cap from phase-out.
            $projMagi = $this->engine->projectedMagiCents($baseline, [
                'w4' => $w4,
                'k401' => ['deferral_pct' => $deferralPct, 'roth_share_pct' => $rothSharePct],
                'hsa' => ['annual_election_cents' => $hsaCents],
                'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],
                'transfer' => ['per_period_cents' => 0],
            ], $year);
            $filingStatus = (string) ($baseline['filing_status'] ?? 'single');
            $rothElig = $this->engine->rothIraEligibility($projMagi, $filingStatus, $year);
            $rothAllowed = max(0, $rothElig['limit_cents'] - $iraRothYtd);

            $fractions = array_reverse((array) config('optimizer-scenarios.grids.ira_room_fractions'));

            foreach ($fractions as $frac) {
                if ($frac <= 0.0) {
                    break;
                }
                $totalIra = $this->snapToIraQuarterGrid((int) round($remainingRoom * $frac), $remainingRoom);

                // Type-split: if band == 'roth' and Roth is allowed, use Roth.
                // Otherwise trad (deductibility-capped by engine).
                if ($band === 'roth' && $rothAllowed > 0) {
                    $trialRoth = min($totalIra, $rothAllowed);
                    $trialTrad = max(0, $totalIra - $trialRoth);
                } elseif ($band === 'split' && $rothAllowed > 0) {
                    $trialRoth = min((int) round($totalIra / 2), $rothAllowed);
                    $trialTrad = $totalIra - $trialRoth;
                } else {
                    $trialRoth = 0;
                    $trialTrad = $totalIra;
                }

                $candidate = $this->buildCandidateKnobs(
                    $w4, $deferralPct, $rothSharePct, $hsaCents, $trialTrad, $trialRoth, 0, $baseline
                );

                if ($this->checkFloor($baseline, $candidate, $year)) {
                    $iraTrad = $trialTrad;
                    $iraRoth = $trialRoth;
                    break;
                }
            }
        }

        // Step 5: K6 — sized to fund IRA amounts per period (the execution mechanism for IRA).
        $periods = max(1, (int) ($baseline['pay_periods_per_year'] ?? 26));
        $k6PerPeriod = $remainingRoom > 0
            ? (int) ceil(($iraTrad + $iraRoth) / $periods)
            : 0;

        $finalKnobs = $this->buildCandidateKnobs(
            $w4, $deferralPct, $rothSharePct, $hsaCents, $iraTrad, $iraRoth, $k6PerPeriod, $baseline
        );

        $outcome = $this->engine->computeScenarioOutcome($baseline, $finalKnobs, $year);

        return $outcome['knobs'];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE: helpers for Balanced synthesis
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Per-knob midpoints of two knob vectors, snapped to each knob's grid.
     * Used as the seed for the standard Balanced path (§B.7).
     */
    private function midpointKnobs(array $th, array $r, array $baseline, int $year): array
    {
        // k401.deferral_pct: round midpoint to nearest 0.1 (1-pt grid at integer steps).
        $midDeferral = ($th['k401']['deferral_pct'] + $r['k401']['deferral_pct']) / 2;
        $midDeferral = round($midDeferral, 1);  // Snap to 0.1-pt steps (1-pt integer grid minimum).

        // k401.roth_share_pct: snap midpoint to the roth grid {0,25,50,75,100}.
        $midRoth = ($th['k401']['roth_share_pct'] + $r['k401']['roth_share_pct']) / 2;
        $midRothPct = $this->snapToRothGrid($midRoth);

        // hsa: snap to nearest $250 grid step.
        $midHsa = (int) round((($th['hsa']['annual_election_cents'] ?? 0)
            + ($r['hsa']['annual_election_cents'] ?? 0)) / 2);
        $hsaStep = (int) config('optimizer-scenarios.assumptions.hsa_grid_step_cents');
        if ($hsaStep > 0) {
            $midHsa = (int) round($midHsa / $hsaStep) * $hsaStep;
        }

        // ira: snap each to the quarter-grid of remaining IRA room.
        $iraTradYtd = (int) ($baseline['current']['ira_trad_ytd_cents'] ?? 0);
        $iraRothYtd = (int) ($baseline['current']['ira_roth_ytd_cents'] ?? 0);
        $age = $baseline['age'] ?? null;
        $remainingRoom = $this->engine->remainingIraRoomCents($iraTradYtd + $iraRothYtd, $age, $year);

        $midIraTrad = (int) round((($th['ira']['traditional_cents'] ?? 0)
            + ($r['ira']['traditional_cents'] ?? 0)) / 2);
        $midIraRoth = (int) round((($th['ira']['roth_cents'] ?? 0)
            + ($r['ira']['roth_cents'] ?? 0)) / 2);

        $midIraTrad = $this->snapToIraQuarterGrid($midIraTrad, $remainingRoom);
        $midIraRoth = $this->snapToIraQuarterGrid($midIraRoth, $remainingRoom);
        // Ensure combined doesn't exceed remaining room.
        if ($midIraTrad + $midIraRoth > $remainingRoom) {
            $midIraRoth = max(0, $remainingRoom - $midIraTrad);
        }

        // transfer: no specific grid, midpoint rounded to nearest cent.
        $midTransfer = (int) round((($th['transfer']['per_period_cents'] ?? 0)
            + ($r['transfer']['per_period_cents'] ?? 0)) / 2);

        // W-4 is identical in all scenarios.
        $w4 = $th['w4'] ?? $r['w4'];

        return [
            'w4' => $w4,
            'k401' => ['deferral_pct' => $midDeferral, 'roth_share_pct' => $midRothPct],
            'hsa' => ['annual_election_cents' => $midHsa],
            'ira' => ['traditional_cents' => $midIraTrad, 'roth_cents' => $midIraRoth],
            'transfer' => ['per_period_cents' => $midTransfer],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE: floor guard and supporting helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Check whether a candidate knob vector passes the take-home floor guard.
     *
     * Floor = min_take_home_ratio × baseline_per_period_take_home_cents
     * (min_take_home_ratio_cash_constrained when baseline.is_cash_constrained).
     *
     * §B.7 note: the floor check only uses the DELTA returned by the engine plus the
     * pre-computed baseline take-home. No new dollar math is invented here.
     */
    private function checkFloor(array $baseline, array $knobs, int $year): bool
    {
        $outcome = $this->engine->computeScenarioOutcome($baseline, $knobs, $year);
        $delta = $outcome['take_home']['per_paycheck_delta_cents'];

        $baselineTH = (int) ($baseline['baseline_per_period_take_home_cents'] ?? 0);
        $candidateTH = $baselineTH + $delta;
        $floor = (int) floor($baselineTH * $this->minTakeHomeRatio($baseline));

        return $candidateTH >= $floor;
    }

    /** Returns the applicable min-take-home ratio (§B.7). */
    private function minTakeHomeRatio(array $baseline): float
    {
        return (bool) ($baseline['is_cash_constrained'] ?? false)
            ? (float) config('optimizer-scenarios.assumptions.min_take_home_ratio_cash_constrained')
            : (float) config('optimizer-scenarios.assumptions.min_take_home_ratio');
    }

    /**
     * Maximum deferral percentage that does not exceed the full-year 401(k) limit.
     * ceiling for K3 greedy search.
     */
    private function maxDeferralPct(array $baseline, int $year): float
    {
        $gross = (int) ($baseline['annual_gross_cents'] ?? 0);
        if ($gross <= 0) {
            return 0.0;
        }
        $age = $baseline['age'] ?? null;
        $fullLimit = $this->engine->remaining401kRoomCents(0, $age, $year);

        return $fullLimit / $gross * 100;
    }

    /**
     * Build a fully-shaped knob vector from scalar parts.
     * Always produces the complete §B.1 structure.
     */
    private function buildCandidateKnobs(
        array $w4,
        float $deferralPct,
        float $rothSharePct,
        int $hsaCents,
        int $iraTrad,
        int $iraRoth,
        int $transferPerPeriod,
        array $baseline,
    ): array {
        return [
            'w4' => $w4,
            'k401' => ['deferral_pct' => $deferralPct, 'roth_share_pct' => $rothSharePct],
            'hsa' => ['annual_election_cents' => $hsaCents],
            'ira' => ['traditional_cents' => $iraTrad, 'roth_cents' => $iraRoth],
            'transfer' => ['per_period_cents' => $transferPerPeriod],
        ];
    }

    /**
     * Build the W-4 knob sub-array aligned to the user's confirmed filing status
     * and family dependents (K1 rule: operationalise confirmed facts, §B.1).
     * w4.* is identical in all scenarios — solvers never search it.
     */
    private function buildW4Knobs(array $baseline): array
    {
        $confirmedStatus = (string) ($baseline['filing_status'] ?? 'single');

        return [
            'filing_status' => $this->confirmedStatusToW4Status($confirmedStatus),
            'dependents_under_17' => max(0, (int) ($baseline['family']['dependents_under_17'] ?? 0)),
            'other_dependents' => max(0, (int) ($baseline['family']['other_dependents'] ?? 0)),
        ];
    }

    /**
     * Map confirmed annual-tax filing status to the W-4 Step 1(c) box.
     * 'single' / 'married_separate' → 'single_or_mfs' (Pub 15-T column convention, [M11]).
     */
    private function confirmedStatusToW4Status(string $status): string
    {
        return match ($status) {
            'married_joint' => 'married_joint',
            'head_of_household' => 'head_of_household',
            default => 'single_or_mfs',  // 'single' or 'married_separate'
        };
    }

    /** Current deferral percentage from the baseline (annualized run-rate or 0). */
    private function currentDeferralPct(array $baseline): float
    {
        return (float) ($baseline['current']['deferral_pct'] ?? 0.0);
    }

    /**
     * "Current" knob vector: baseline run-rates expressed as a §B.1 structure.
     * This is the zero-delta reference for attributeBenefits() single-knob isolation.
     */
    private function currentKnobVector(array $baseline): array
    {
        $w4 = $this->buildW4Knobs($baseline);
        $curPct = $this->currentDeferralPct($baseline);
        $curRothShare = $this->currentRothSharePct($baseline);
        $curHsa = (int) ($baseline['current']['hsa_cents'] ?? 0);

        return [
            'w4' => $w4,
            'k401' => ['deferral_pct' => $curPct, 'roth_share_pct' => $curRothShare],
            'hsa' => ['annual_election_cents' => $curHsa],
            'ira' => ['traditional_cents' => 0, 'roth_cents' => 0],  // no additional IRA
            'transfer' => ['per_period_cents' => 0],
        ];
    }

    /**
     * Current Roth share of 401(k) deferral as a percentage (0–100).
     * Snapped to the roth grid for a consistent baseline reference.
     */
    private function currentRothSharePct(array $baseline): float
    {
        $trad = (float) ($baseline['current']['trad_401k_cents'] ?? 0);
        $roth = (float) ($baseline['current']['roth_401k_cents'] ?? 0);
        $total = $trad + $roth;
        if ($total <= 0) {
            return 0.0;
        }

        return (float) $this->snapToRothGrid($roth / $total * 100);
    }

    /** Snap a value to the nearest point on the roth_share_pct grid {0,25,50,75,100}. */
    private function snapToRothGrid(float $value): int
    {
        $grid = (array) config('optimizer-scenarios.grids.roth_share_pct');
        $best = (int) $grid[0];
        $bestDist = PHP_INT_MAX;

        foreach ($grid as $point) {
            $dist = abs($value - (float) $point);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = (int) $point;
            }
        }

        return $best;
    }

    /**
     * Snap a value to the nearest point on the IRA quarter-grid:
     *   {0, ¼×room, ½×room, ¾×room, room}
     * Returns the snapped value, clamped to [0, room].
     */
    private function snapToIraQuarterGrid(int $value, int $remainingRoom): int
    {
        if ($remainingRoom <= 0) {
            return 0;
        }

        $fractions = (array) config('optimizer-scenarios.grids.ira_room_fractions');
        $best = 0;
        $bestDist = PHP_INT_MAX;

        foreach ($fractions as $frac) {
            $point = (int) round($remainingRoom * (float) $frac);
            $dist = abs($value - $point);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $point;
            }
        }

        return max(0, min($best, $remainingRoom));
    }

    /**
     * Compute the current baseline per-period take-home in cents.
     * This mirrors the engine's Step 4 internal computation so the floor guard
     * is consistent with the engine's own understanding of the baseline state.
     * Called once in assembleBaseline(); never in solve() (no now() in solvers).
     *
     * Note: calls engine helpers (SCN-01, SCN-02) — not new dollar math.
     */
    private function computeCurrentTakeHomeCents(array $baseline, int $year): int
    {
        $gross = (int) ($baseline['annual_gross_cents'] ?? 0);
        $periods = max(1, (int) ($baseline['pay_periods_per_year'] ?? 26));

        $curTrad401k = (int) ($baseline['current']['trad_401k_cents'] ?? 0);
        $curRoth401k = (int) ($baseline['current']['roth_401k_cents'] ?? 0);
        $curHsa = (int) ($baseline['current']['hsa_cents'] ?? 0);

        $periodGross = (int) round($gross / $periods);

        // Withholding: prefer observed W-4-on-file, then annual_withholding, then estimate.
        $w4OnFile = $baseline['w4_on_file'] ?? [];
        $w4Status = (string) ($w4OnFile['filing_status'] ?? '');
        $w4DepsAll = max(0, (int) ($w4OnFile['dependents_claimed'] ?? 0));
        // Fix-B: Step 3 dollar credits override count-based credits when available.
        $step3Credits = max(0, (int) ($w4OnFile['step3_credits_cents'] ?? 0));

        if ($w4Status !== '') {
            $curWH = $this->engine->estimatePeriodWithholdingCents(
                $periodGross,
                (int) round(($curTrad401k + $curHsa) / $periods),
                $w4Status,
                $w4DepsAll,
                0,
                $periods,
                $year,
                $step3Credits,
            );
        } elseif (($baseline['annual_withholding_cents'] ?? null) !== null) {
            $curWH = (int) round((int) $baseline['annual_withholding_cents'] / $periods);
        } else {
            // No W-4 evidence: estimate from confirmed filing status.
            $curWH = $this->engine->estimatePeriodWithholdingCents(
                $periodGross,
                (int) round(($curTrad401k + $curHsa) / $periods),
                $this->confirmedStatusToW4Status((string) ($baseline['filing_status'] ?? 'single')),
                0, 0,
                $periods,
                $year,
            );
        }

        $curFica = (int) round(
            $this->engine->employeeFicaCents(max(0, $gross - $curHsa), $year)['total_cents']
            / $periods
        );

        return $periodGross
            - (int) round(($curTrad401k + $curRoth401k + $curHsa) / $periods)
            - $curWH
            - $curFica;
    }

    // Deferral step size for greedy fill (1 percentage point — structural constant).
    private const DEFERRAL_STEP_PCT = 1.0;
}
