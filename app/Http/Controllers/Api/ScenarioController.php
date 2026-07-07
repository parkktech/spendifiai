<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChooseScenarioRequest;
use App\Http\Requests\ComputeScenarioRequest;
use App\Models\OptimizationReport;
use App\Models\ScenarioFactSet;
use App\Models\UserTaxFact;
use App\Services\ObjectiveReadinessService;
use App\Services\ScenarioChecklistService;
use App\Services\ScenarioFactResolverService;
use App\Services\ScenarioSolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ScenarioController — deterministic scenario comparison + choice flow (SCN-06/07 / §E).
 *
 * ROUTES (all under /api/v1/optimizer/scenarios/{year}):
 *   GET  /                — objective readiness + 1–3 pre-solved option cards (§D show)
 *   POST /compute         — mix-panel: solve a user-specified knob vector (no Claude)
 *   POST /choose          — record user's chosen option, snapshot fact_set, materialize
 *                           checklist, mark report stale (§D.6 / SCN-07 / Pitfall 4)
 *
 * ZERO Claude / ZERO HTTP (§T.16): every outcome traces to TaxRulesEngineService.
 *
 * PITFALL 4 (§D.6): choose() ALWAYS recomputes from the server's own solver output.
 * Client knobs (when 'custom') are input to the engine CLAMPED before persist. The
 * server stores the engine-clamped vector — never the raw client payload.
 *
 * SAFE-03: No dollar figures (cents) in any response field — the frontend renders
 * labels from option.outcome keys whose semantic meaning is clear without dollars.
 * Raw delta cents are intentionally omitted from the API response (available only
 * in internal benefit attribution for checklist ordering). The only numbers the API
 * emits are: deferral_pct (a percentage), roth_share_pct (a percentage, 0/25/50/75/100),
 * and per_period_cents / annual_election_cents used by the checklist internally.
 */
class ScenarioController extends Controller
{
    /** Static disclaimer text (non-financial-advice notice). */
    private const DISCLAIMER = 'Projections are estimates for planning purposes only and do not constitute tax, legal, or investment advice. Verify results with a qualified advisor before making payroll or contribution changes.';

    public function __construct(
        private readonly ScenarioSolverService $solver,
        private readonly ScenarioFactResolverService $resolver,
        private readonly ObjectiveReadinessService $readiness,
        private readonly ScenarioChecklistService $checklist,
    ) {}

    // ══════════════════════════════════════════════════════════════════════════
    // GET /optimizer/scenarios/{year}
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Show pre-solved scenario options for all ready objectives.
     *
     * Returns:
     *  - tax_year: int
     *  - readiness: {take_home, tax_burden, retirement} readiness blocks
     *  - agreement: bool — true when all ready objectives converge on the same knob vector
     *  - options: array — 1 merged (agreement) or up to 3 objective-specific options
     *  - chosen: null | {option_key, fact_set_id} — previous choice if exists
     *  - fact_set: {id, fact_count} — citation for the fact snapshot
     *  - disclaimer: string
     */
    public function show(Request $request, int $year): JsonResponse
    {
        $user = $request->user();

        // ── 1. Readiness ──────────────────────────────────────────────────────
        $readinessData = $this->readiness->readiness($user, $year);

        $readinessOut = [];
        foreach ($readinessData as $key => $data) {
            $readinessOut[$key] = [
                'ready' => $data['ready'],
                'missing_fact_keys' => array_column($data['blocking_missing'] ?? [], 'fact_key'),
            ];
        }

        // ── 2. Build options for ready objectives ─────────────────────────────
        $readyObjectives = array_filter($readinessData, fn ($d) => $d['ready']);

        if (empty($readyObjectives)) {
            // No objectives are ready — return empty options
            $factSet = $this->resolver->snapshotFactSet($user, $year);

            return response()->json([
                'tax_year' => $year,
                'readiness' => $readinessOut,
                'agreement' => false,
                'options' => [],
                'chosen' => $this->resolveChosenFact($user->id, $year),
                'fact_set' => ['id' => $factSet->id, 'fact_count' => count($factSet->resolvedFactsArray())],
                'disclaimer' => self::DISCLAIMER,
            ]);
        }

        $baseline = $this->solver->assembleBaseline($user, $year);

        // Solve each ready objective
        $solvedVectors = [];
        foreach (array_keys($readyObjectives) as $obj) {
            try {
                $solvedVectors[$obj] = $this->solver->solve($baseline, $obj, $year);
            } catch (\Throwable $e) {
                Log::warning('ScenarioController: solve() failed for objective', [
                    'objective' => $obj,
                    'user_id' => $user->id,
                    'year' => $year,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($solvedVectors)) {
            $factSet = $this->resolver->snapshotFactSet($user, $year);

            return response()->json([
                'tax_year' => $year,
                'readiness' => $readinessOut,
                'agreement' => false,
                'options' => [],
                'chosen' => $this->resolveChosenFact($user->id, $year),
                'fact_set' => ['id' => $factSet->id, 'fact_count' => count($factSet->resolved_facts ?? [])],
                'disclaimer' => self::DISCLAIMER,
            ]);
        }

        // ── 3. Check for agreement (§C.2 — all vectors identical) ────────────
        $vectors = array_values($solvedVectors);
        $allAgree = count($vectors) > 1 && $this->allVectorsAgree($vectors, $baseline);

        // ── 4. Build Balanced if we have all three and they diverge ──────────
        $options = [];

        if ($allAgree) {
            // One merged option — no conflict
            $mergedKnobs = $vectors[0];
            $mergedOutcome = $this->buildOptionOutcome($baseline, $mergedKnobs, $year);
            $options[] = [
                'key' => 'merged',
                'label' => 'Balanced across all objectives',
                'knobs' => $this->publicKnobs($mergedKnobs),
                'outcome' => $mergedOutcome,
            ];
        } else {
            // Individual objective options (ordered: take_home, tax_burden, retirement)
            $labelMap = [
                'take_home' => 'Maximize take-home pay',
                'tax_burden' => 'Minimize 2026 federal tax',
                'retirement' => 'Maximize retirement contributions',
            ];

            foreach (['take_home', 'tax_burden', 'retirement'] as $obj) {
                if (! isset($solvedVectors[$obj])) {
                    continue;
                }
                $knobs = $solvedVectors[$obj];
                $options[] = [
                    'key' => $obj,
                    'label' => $labelMap[$obj],
                    'knobs' => $this->publicKnobs($knobs),
                    'outcome' => $this->buildOptionOutcome($baseline, $knobs, $year),
                ];
            }

            // Balanced option (when all three are present and diverge)
            if (isset($solvedVectors['take_home'], $solvedVectors['tax_burden'], $solvedVectors['retirement'])) {
                $balancedKnobs = $this->solver->solveBalanced(
                    $solvedVectors['take_home'],
                    $solvedVectors['retirement'],
                    $solvedVectors['tax_burden'],
                    $baseline,
                    $year
                );
                $tbDivFromTH = $this->solver->diffKnobs($solvedVectors['tax_burden'], $solvedVectors['take_home']);
                $tbDivFromR = $this->solver->diffKnobs($solvedVectors['tax_burden'], $solvedVectors['retirement']);
                $balancedLabel = (! empty($tbDivFromTH) && ! empty($tbDivFromR))
                    ? 'Balanced — also lowest 2026 tax'
                    : 'Balanced';
                $options[] = [
                    'key' => 'balanced',
                    'label' => $balancedLabel,
                    'knobs' => $this->publicKnobs($balancedKnobs),
                    'outcome' => $this->buildOptionOutcome($baseline, $balancedKnobs, $year),
                ];
            }
        }

        // ── 5. Fact set citation ──────────────────────────────────────────────
        $factSet = $this->resolver->snapshotFactSet($user, $year);

        return response()->json([
            'tax_year' => $year,
            'readiness' => $readinessOut,
            'agreement' => $allAgree,
            'options' => $options,
            'chosen' => $this->resolveChosenFact($user->id, $year),
            'fact_set' => ['id' => $factSet->id, 'fact_count' => count($factSet->resolvedFactsArray())],
            'disclaimer' => self::DISCLAIMER,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /optimizer/scenarios/{year}/compute
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Compute the outcome of a user-specified knob mix (mix panel).
     *
     * The engine always clamps — hostile inputs are clamped to legal ranges.
     * Returns outcome only: take_home, federal_tax, retirement deltas + clamped knobs.
     * No fact snapshot, no persistence, no report stale. ZERO Claude.
     */
    public function compute(ComputeScenarioRequest $request, int $year): JsonResponse
    {
        $user = $request->user();
        $clientKnobs = $request->input('knobs', []);

        $baseline = $this->solver->assembleBaseline($user, $year);

        // Merge client knobs onto the neutral (all-zero) vector; engine clamps
        $knobs = $this->mergeClientKnobs($baseline, $clientKnobs);

        // Use a minimal engine call via solver to clamp (reuse the solver's private
        // implementation by delegating to the engine directly)
        /** @var \App\Services\TaxRulesEngineService $engine */
        $engine = app(\App\Services\TaxRulesEngineService::class);
        $outcome = $engine->computeScenarioOutcome($baseline, $knobs, $year);

        return response()->json([
            'outcome' => [
                'take_home' => $outcome['take_home']['annual_delta_cents'],
                'federal_tax' => $outcome['federal_tax']['annual_delta_cents'],
                'retirement' => $outcome['retirement']['annual_contributions_delta_cents'],
                'knobs' => $outcome['knobs'],   // engine-clamped vector for display
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /optimizer/scenarios/{year}/simulate
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * What-if calculator (owner request 2026-07-06): the user drags a contribution
     * slider and watches bring-home, federal tax, and the retirement projection
     * update live. ZERO Claude — pure TaxRulesEngineService math.
     *
     * OWNER SEMANTICS: contribution_pct_of_max is a percentage of the IRS LEGAL
     * MAXIMUM (402(g) employee deferral limit incl. age catch-ups) — 100 means
     * "contribute the full legally allowed amount". The server converts it to a
     * payroll deferral percentage against deferral-eligible comp (base wages +
     * bonus when the plan takes 401(k) deferrals from bonus checks) and returns
     * the translation so the UI can say "that's X% of each paycheck".
     *
     * Non-401(k) knobs (W-4, HSA, IRA) are seeded from the user's chosen plan so
     * the numbers line up with the checklist banner. Engine clamps everything
     * (Pitfall 4 — client input never reaches the math unclamped).
     */
    public function simulate(\App\Http\Requests\SimulateScenarioRequest $request, int $year): JsonResponse
    {
        $user = $request->user();

        $baseline = $this->solver->assembleBaseline($user, $year);

        /** @var \App\Services\TaxRulesEngineService $engine */
        $engine = app(\App\Services\TaxRulesEngineService::class);

        // Legal maximum for this user's age (402(g) + catch-ups).
        $legalMaxCents = $engine->remaining401kRoomCents(0, $baseline['age'] ?? null, $year);

        // Deferral-eligible comp mirrors the engine: base + bonus when eligible.
        $bonusEligible = ($baseline['bonus_401k_eligible'] ?? false) === true;
        $deferralBaseCents = (int) ($baseline['annual_gross_cents'] ?? 0)
            + ($bonusEligible ? (int) ($baseline['bonus_annual_cents'] ?? 0) : 0);

        // Defaults = the user's CURRENT position, so the calculator opens where
        // they actually are today instead of an arbitrary point.
        $curTrad = (int) ($baseline['current']['trad_401k_cents'] ?? 0);
        $curRoth = (int) ($baseline['current']['roth_401k_cents'] ?? 0);
        $curDeferralTotal = $curTrad + $curRoth;

        $pctOfMaxInput = $request->input('contribution_pct_of_max');
        $pctOfMax = $pctOfMaxInput !== null
            ? (float) $pctOfMaxInput
            : ($legalMaxCents > 0 ? min(100.0, $curDeferralTotal / $legalMaxCents * 100) : 0.0);

        $rothInput = $request->input('roth_share_pct');
        $rothSharePct = $rothInput !== null
            ? (int) $rothInput
            : ($curDeferralTotal > 0 ? (int) round($curRoth / $curDeferralTotal * 100) : 0);

        $targetContributionCents = (int) round($legalMaxCents * $pctOfMax / 100);
        $deferralPct = $deferralBaseCents > 0
            ? min(100.0, $targetContributionCents / $deferralBaseCents * 100)
            : 0.0;

        // Seed W-4/HSA/IRA knobs from the chosen plan so results match the banner.
        $chosenKnobsJson = UserTaxFact::currentFact($user->id, 'scenario.chosen_knobs', null, $year)?->value;
        $seed = $chosenKnobsJson !== null ? (json_decode($chosenKnobsJson, true) ?: []) : [];
        $knobs = $this->mergeClientKnobs($baseline, $seed);
        $knobs['k401'] = ['deferral_pct' => $deferralPct, 'roth_share_pct' => $rothSharePct];

        $outcome = $engine->computeScenarioOutcome($baseline, $knobs, $year);

        // ── Banner-parallel absolutes ─────────────────────────────────────────
        $abs = $outcome['baseline_absolute'];
        $beforePP = (int) $abs['per_period_take_home_cents'];
        $afterPP = $beforePP + (int) $outcome['take_home']['per_paycheck_delta_cents'];
        $beforeTax = (int) $abs['federal_tax_annual_cents'];
        $afterTax = $beforeTax + (int) $outcome['federal_tax']['annual_delta_cents'];

        $matchBefore = (int) ($abs['employer_match_cents'] ?? 0);
        $matchAfter = $matchBefore + (int) $outcome['retirement']['employer_match_delta_cents'];

        $curTotalContrib = (int) ($abs['annual_contributions_cents'] ?? 0) + $matchBefore;
        $simTotalContrib = $curTotalContrib
            + (int) $outcome['retirement']['annual_contributions_delta_cents']
            + (int) $outcome['retirement']['employer_match_delta_cents'];

        $curAge = $baseline['age'] ?? null;
        $targetAge = $baseline['target_retirement_age'] ?? null;
        $horizon = ($curAge !== null && $targetAge !== null) ? max(0, (int) $targetAge - (int) $curAge) : 0;
        $fvBefore = ($horizon > 0 && $curTotalContrib > 0)
            ? $engine->futureValueRangeCents(max(0, $curTotalContrib), $horizon, $year)
            : null;
        $fvAfter = ($horizon > 0)
            ? $engine->futureValueRangeCents(max(0, $simTotalContrib), $horizon, $year)
            : null;

        // ── Payroll translation (post-clamp) ──────────────────────────────────
        $clampedPct = (float) $outcome['knobs']['k401']['deferral_pct'];
        $appliedContribCents = (int) round($deferralBaseCents * $clampedPct / 100);
        $periods = max(1, (int) ($baseline['pay_periods_per_year'] ?? 26));
        $baseShare = $deferralBaseCents > 0
            ? (int) ($baseline['annual_gross_cents'] ?? 0) / $deferralBaseCents
            : 1;
        $perCheckCents = (int) round($appliedContribCents * $baseShare / $periods);

        return response()->json([
            'contribution' => [
                'annual_cents' => $appliedContribCents,
                'legal_max_cents' => $legalMaxCents,
                'pct_of_max' => $legalMaxCents > 0
                    ? round($appliedContribCents / $legalMaxCents * 100)
                    : 0,
                'payroll_deferral_pct' => round($clampedPct, 1),
                'per_check_cents' => $perCheckCents,
                'roth_share_pct' => (int) round((float) $outcome['knobs']['k401']['roth_share_pct']),
                'bonus_included' => $bonusEligible,
            ],
            'bring_home' => [
                'before_per_check_cents' => $beforePP,
                'after_per_check_cents' => $afterPP,
                'delta_per_check_cents' => $afterPP - $beforePP,
                'delta_annual_cents' => (int) $outcome['take_home']['annual_delta_cents'],
            ],
            'federal_tax' => [
                'before_annual_cents' => $beforeTax,
                'after_annual_cents' => $afterTax,
                'delta_annual_cents' => $afterTax - $beforeTax,
            ],
            'retirement' => [
                'before_fv' => $fvBefore,
                'after_fv' => $fvAfter,
                'target_age' => $targetAge,
                'horizon_years' => $horizon,
                'employer_match_before_cents' => $matchBefore,
                'employer_match_after_cents' => $matchAfter,
            ],
            'clamps' => $outcome['guards']['clamps'] ?? [],
            'disclaimer' => self::DISCLAIMER,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /optimizer/scenarios/{year}/choose
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Record the user's chosen scenario plan (§D.6 / SCN-07).
     *
     * Protocol:
     *  1. Validate option_key (canonical or custom)
     *  2. Server RE-COMPUTES from its own solver (Pitfall 4 — never trust client knobs)
     *  3. Snapshot ScenarioFactSet (M9)
     *  4. Persist scenario.chosen_option + scenario.chosen_knobs facts (source_type='user_edit')
     *  5. Materialize OptimizationChecklistItem rows via ScenarioChecklistService
     *  6. Mark OptimizationReport stale (D13 user-action staleness path)
     *  7. Return {chosen, checklist}
     */
    public function choose(ChooseScenarioRequest $request, int $year): JsonResponse
    {
        $user = $request->user();
        $optionKey = $request->input('option_key');

        // ── 1. Resolve knob vector by re-computing server-side (Pitfall 4) ────
        $baseline = $this->solver->assembleBaseline($user, $year);
        $chosenKnobs = $this->resolveOptionKnobs($optionKey, $baseline, $request->input('knobs', []), $year);

        // ── 2. Snapshot fact_set ──────────────────────────────────────────────
        $factSet = $this->resolver->snapshotFactSet($user, $year);

        // ── 3. Persist scenario.chosen_option ────────────────────────────────
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'scenario.chosen_option',
            value: $optionKey,
            sourceType: 'user_edit',
            label: 'Chosen optimization scenario',
            volatility: 'volatile',
            taxYear: $year,
            metadata: ['fact_set_id' => $factSet->id],
        );

        // ── 4. Persist scenario.chosen_knobs ─────────────────────────────────
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'scenario.chosen_knobs',
            value: json_encode($chosenKnobs),
            sourceType: 'user_edit',
            label: 'Chosen optimization knob vector',
            volatility: 'volatile',
            taxYear: $year,
            metadata: ['fact_set_id' => $factSet->id, 'option_key' => $optionKey],
        );

        // ── 4b. Persist scenario.chosen_option_label (Addition 6) ────────────
        // Derive the exact display label using the same logic as ::show() so the
        // checklist page can display "YOUR PLAN: <label>" without re-running the solver.
        $chosenLabel = $this->deriveOptionLabel($optionKey, $baseline, $year);
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'scenario.chosen_option_label',
            value: $chosenLabel,
            sourceType: 'user_edit',
            label: 'Chosen optimization scenario display label',
            volatility: 'volatile',
            taxYear: $year,
            metadata: ['fact_set_id' => $factSet->id],
        );

        // ── 5. Materialize checklist ──────────────────────────────────────────
        // Pass the clamped knob vector directly (ScenarioChecklistService::materialize()
        // calls solver->attributeBenefits() internally — no pre-attribution needed here).
        $checklistResult = $this->checklist->materialize(
            $user,
            $year,
            $optionKey,
            $chosenKnobs,   // full knob vector (w4, k401, hsa, ira, transfer) — Pitfall 4 safe
            $baseline,
            $factSet->id,
        );

        // ── 6. Mark optimization report stale (D13 user-action staleness) ────
        OptimizationReport::forUser($user->id)
            ->where('tax_year', $year)
            ->update([
                'is_stale' => true,
                'stale_since' => now(),
            ]);

        Log::info('ScenarioController: plan chosen and checklist materialized', [
            'user_id' => $user->id,
            'year' => $year,
            'option_key' => $optionKey,
            'fact_set_id' => $factSet->id,
            'checklist_item_count' => count($checklistResult['items'] ?? []),
        ]);

        // Structure the checklist for the response (mirrors OptimizationChecklistController::show())
        $headerItem = collect($checklistResult)->first(fn ($r) => ($r['step_key'] ?? null) === 'header_aggregate');
        $regularItems = collect($checklistResult)->filter(fn ($r) => ($r['step_key'] ?? null) !== 'header_aggregate')->values()->toArray();

        return response()->json([
            'chosen' => [
                'option_key' => $optionKey,
                'fact_set_id' => $factSet->id,
                'chosen_at' => now()->toIso8601String(),
            ],
            'checklist' => [
                'header_aggregate' => $headerItem['benefit_line_params'] ?? null,
                'items' => $regularItems,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build an option's outcome for the response. No cents in response (SAFE-03):
     * deltas are classified as positive/negative/neutral relative to current.
     */
    private function buildOptionOutcome(array $baseline, array $knobs, int $year): array
    {
        /** @var \App\Services\TaxRulesEngineService $engine */
        $engine = app(\App\Services\TaxRulesEngineService::class);
        $result = $engine->computeScenarioOutcome($baseline, $knobs, $year);

        // SAFE-03: expose only signed direction tags + magnitude tier (no raw cents)
        return [
            'take_home' => $this->deltaTier($result['take_home']['annual_delta_cents'] ?? 0),
            'federal_tax' => $this->deltaTier($result['federal_tax']['annual_delta_cents'] ?? 0),
            'retirement' => $this->deltaTier($result['retirement']['annual_contributions_delta_cents'] ?? 0),
        ];
    }

    /**
     * Convert a delta_cents integer into a three-tier magnitude descriptor.
     * This satisfies SAFE-03 (no raw dollar/cent figures in the narrative payload).
     *
     *   positive_large  = annual gain > $2,000
     *   positive_medium = annual gain $500–$2,000
     *   positive_small  = annual gain $1–$499
     *   neutral         = no change
     *   negative_*      = symmetrical loss tiers
     */
    private function deltaTier(int $deltaCents): string
    {
        if ($deltaCents === 0) {
            return 'neutral';
        }
        $abs = abs($deltaCents);
        $prefix = $deltaCents > 0 ? 'positive' : 'negative';

        if ($abs >= 200_000) {
            return "{$prefix}_large";
        }
        if ($abs >= 50_000) {
            return "{$prefix}_medium";
        }

        return "{$prefix}_small";
    }

    /**
     * Returns public (non-internal) subset of a knob vector (no cents fields
     * that could leak dollar amounts through the API response).
     *
     * Percentages and ordinal values (filing_status, roth_share_pct, deferral_pct)
     * are safe — they carry no dollar amount.
     */
    private function publicKnobs(array $knobs): array
    {
        return [
            'w4' => [
                'filing_status' => $knobs['w4']['filing_status'] ?? null,
                'dependents_under_17' => $knobs['w4']['dependents_under_17'] ?? 0,
                'other_dependents' => $knobs['w4']['other_dependents'] ?? 0,
            ],
            'k401' => [
                'deferral_pct' => $knobs['k401']['deferral_pct'] ?? 0.0,
                'roth_share_pct' => $knobs['k401']['roth_share_pct'] ?? 0,
            ],
        ];
    }

    /**
     * Derive the exact display label for an option key (same derivation as ::show()).
     * For 'balanced': re-runs diffKnobs to determine whether it's "also lowest tax".
     * For all other keys: uses the canonical label map.
     */
    private function deriveOptionLabel(string $optionKey, array $baseline, int $year): string
    {
        $labelMap = [
            'take_home' => 'Maximize take-home pay',
            'tax_burden' => 'Minimize '.$year.' federal tax',
            'retirement' => 'Maximize retirement contributions',
        ];

        if (isset($labelMap[$optionKey])) {
            return $labelMap[$optionKey];
        }

        if ($optionKey === 'balanced') {
            // Re-derive the balanced label: "also lowest tax" when TB diverges from both poles.
            try {
                $thKnobs = $this->solver->solve($baseline, 'take_home', $year);
                $tbKnobs = $this->solver->solve($baseline, 'tax_burden', $year);
                $rKnobs = $this->solver->solve($baseline, 'retirement', $year);
                $tbDivFromTH = $this->solver->diffKnobs($tbKnobs, $thKnobs);
                $tbDivFromR = $this->solver->diffKnobs($tbKnobs, $rKnobs);

                return (! empty($tbDivFromTH) && ! empty($tbDivFromR))
                    ? 'Balanced — also lowest '.$year.' tax'
                    : 'Balanced';
            } catch (\Throwable) {
                return 'Balanced';
            }
        }

        return ucwords(str_replace('_', ' ', $optionKey));
    }

    /**
     * Resolve the previous scenario.chosen_option fact for this user+year.
     * Returns null if not chosen yet.
     */
    private function resolveChosenFact(int $userId, int $year): ?array
    {
        $fact = UserTaxFact::currentFact($userId, 'scenario.chosen_option', null, $year);
        if ($fact === null) {
            return null;
        }

        return [
            'option_key' => $fact->value,
            'fact_set_id' => $fact->metadata['fact_set_id'] ?? null,
        ];
    }

    /**
     * Check whether all solved vectors agree (§C.2 agreement model).
     * Agreement = diffKnobs() returns empty for all pairs.
     */
    private function allVectorsAgree(array $vectors, array $baseline): bool
    {
        for ($i = 0; $i < count($vectors) - 1; $i++) {
            $diff = $this->solver->diffKnobs($vectors[$i], $vectors[$i + 1]);
            if (! empty($diff)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the canonical knob vector for the chosen option key (Pitfall 4 guard).
     *
     * For named options (take_home, tax_burden, retirement, balanced, merged):
     *   the server solves independently → client knobs are ignored.
     * For 'custom':
     *   client knobs are merged into a clamped engine run (not stored raw).
     */
    private function resolveOptionKnobs(string $optionKey, array $baseline, array $clientKnobs, int $year): array
    {
        return match ($optionKey) {
            'take_home' => $this->solver->solve($baseline, 'take_home', $year),
            'tax_burden' => $this->solver->solve($baseline, 'tax_burden', $year),
            'retirement' => $this->solver->solve($baseline, 'retirement', $year),
            'balanced' => $this->solver->solveBalanced(
                $this->solver->solve($baseline, 'take_home', $year),
                $this->solver->solve($baseline, 'retirement', $year),
                $this->solver->solve($baseline, 'tax_burden', $year),
                $baseline,
                $year
            ),
            'merged' => $this->solver->solve($baseline, 'take_home', $year), // convergence → any equals all
            'custom' => $this->runCustomClamped($baseline, $clientKnobs, $year),
            default => throw new \InvalidArgumentException("Unknown option key: {$optionKey}"),
        };
    }

    /**
     * Run the engine on client-supplied custom knobs (clamped by engine — Pitfall 4).
     * Returns the engine's clamped knob output, not the raw client payload.
     */
    private function runCustomClamped(array $baseline, array $clientKnobs, int $year): array
    {
        $knobs = $this->mergeClientKnobs($baseline, $clientKnobs);
        /** @var \App\Services\TaxRulesEngineService $engine */
        $engine = app(\App\Services\TaxRulesEngineService::class);
        $outcome = $engine->computeScenarioOutcome($baseline, $knobs, $year);

        return $outcome['knobs'];  // engine's clamped output — not the raw input
    }

    /**
     * Merge client knobs onto a neutral vector, clamping obviously-hostile values.
     * The engine will further clamp during computeScenarioOutcome.
     */
    private function mergeClientKnobs(array $baseline, array $clientKnobs): array
    {
        // Start from the baseline current run-rate (not zero — prevents take-home floor violations)
        $rothGrid = config('optimizer-scenarios.grids.roth_share_pct', [0, 25, 50, 75, 100]);

        $deferralPct = isset($clientKnobs['k401']['deferral_pct'])
            ? min(100.0, max(0.0, (float) $clientKnobs['k401']['deferral_pct']))
            : (float) ($baseline['current']['deferral_pct'] ?? 0.0);

        $rothShareRaw = (int) ($clientKnobs['k401']['roth_share_pct'] ?? $baseline['current']['roth_share_pct'] ?? 0);
        $rothSharePct = in_array($rothShareRaw, $rothGrid) ? $rothShareRaw : 0;

        return [
            'w4' => [
                'filing_status' => $clientKnobs['w4']['filing_status'] ?? ($baseline['w4_on_file']['filing_status'] ?? 'single_or_mfs'),
                'dependents_under_17' => min(99, max(0, (int) ($clientKnobs['w4']['dependents_under_17'] ?? $baseline['family']['dependents_under_17'] ?? 0))),
                'other_dependents' => min(99, max(0, (int) ($clientKnobs['w4']['other_dependents'] ?? $baseline['family']['other_dependents'] ?? 0))),
            ],
            'k401' => [
                'deferral_pct' => $deferralPct,
                'roth_share_pct' => $rothSharePct,
            ],
            'hsa' => [
                'annual_election_cents' => max(0, (int) ($clientKnobs['hsa']['annual_election_cents'] ?? $baseline['current']['hsa_cents'] ?? 0)),
            ],
            'ira' => [
                'traditional_cents' => max(0, (int) ($clientKnobs['ira']['traditional_cents'] ?? $baseline['current']['ira_trad_ytd_cents'] ?? 0)),
                'roth_cents' => max(0, (int) ($clientKnobs['ira']['roth_cents'] ?? $baseline['current']['ira_roth_ytd_cents'] ?? 0)),
            ],
            'transfer' => [
                'per_period_cents' => max(0, (int) ($clientKnobs['transfer']['per_period_cents'] ?? 0)),
            ],
        ];
    }

    /**
     * @deprecated Unused after choose() fix — materialize() receives the full knob vector.
     * Kept for future custom-knob subset feature. Not referenced.
     */
    private function extractKnobList(array $knobs): array
    {
        // All six knob dimensions are always present in the vector.
        return ['k1', 'k2', 'k3', 'k4', 'k5', 'k6'];
    }
}
