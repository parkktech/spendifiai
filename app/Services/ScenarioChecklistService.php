<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OptimizationChecklistItem;
use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ScenarioChecklistService — materializes and manages the D9 action checklist (§D.5).
 *
 * ZERO Claude / ZERO HTTP. Every benefit figure comes from ScenarioSolverService::attributeBenefits()
 * which in turn calls TaxRulesEngineService. This service only reads config templates and
 * builds item rows.
 *
 * Fact-gating (D9.2 / §A.7):
 *  - A step whose anchor facts are all CONFIRMED (interview_answer / user_edit / confirmed
 *    document_extraction) renders kind='directive'.
 *  - Any unconfirmed anchor → kind='confirm_ask'.
 *
 * Done-toggle reality facts (§D.5):
 *  - Checking off a K3 step → supersedes employer.contribution_pct via
 *    UserTaxFact::recordFact(source_type='user_edit').
 *  - Checking off a K2 step → writes retirement.elected_roth_share_pct.
 *
 * Re-choose scoped delete (§D.6):
 *  - materialize() deletes all prior rows WHERE user_id + tax_year + source_type='scenario_choice'
 *    before inserting the new set (never touches user data — only rows this feature owns).
 *
 * Group ordering (Δ3 — DOCUMENTS-FIRST-FUNNEL §5):
 *  - checklist groups are ordered by annual benefit DESC (big rocks first).
 *
 * Security:
 *  - All writes are user-scoped (T-14-08-02).
 *  - benefit_line_params carries integer cents on a user-scoped authed row only
 *    (OptimizationFinding.estimated_value_cents precedent — no encryption needed).
 */
final class ScenarioChecklistService
{
    /**
     * Anchor facts per knob that determine fact-gated kind.
     * A step is 'directive' only when ALL anchors for its knob are confirmed.
     */
    private const KNOB_ANCHOR_FACTS = [
        'k1' => ['profile.filing_status', 'w4.filing_status'],
        'k2' => ['employer.has_401k', 'retirement.roth_401k_ytd_cents'],
        'k3' => ['employer.has_401k', 'employer.contribution_pct'],
        'k4' => ['health.hsa_eligible', 'hsa.ytd_contribution_cents'],
        'k5' => ['ira.traditional_ytd_contribution_cents', 'ira.roth_ytd_contribution_cents'],
        'k6' => [],   // no anchor — always directive
    ];

    public function __construct(
        private readonly ScenarioSolverService $solver,
    ) {}

    /**
     * Materialize checklist items for a chosen scenario option (§D.5 / §D.6).
     *
     * Steps:
     *  1. Scoped-delete prior scenario_choice rows for this user+year.
     *  2. Compute per-knob attributeBenefits from the baseline + chosenKnobs.
     *  3. For each knob in checklist_templates whose chosen value diverges from baseline,
     *     build one item (directive|confirm_ask based on confirmed facts).
     *  4. Build header aggregate item.
     *  5. Order groups by annual benefit DESC (Δ3).
     *  6. Persist all rows and return them.
     *
     * @param  array  $baseline  §B.2 baseline from ScenarioSolverService::assembleBaseline()
     * @param  array  $chosenKnobs  Clamped knob vector (server-recomputed, never client-trusted)
     * @return array<int, array> The materialized items (as arrays for the API response)
     */
    public function materialize(
        User $user,
        int $year,
        string $optionKey,
        array $chosenKnobs,
        array $baseline,
        int $factSetId,
    ): array {
        // ── Step 1: Scoped delete (never touches rows the user owns directly) ──
        OptimizationChecklistItem::where('user_id', $user->id)
            ->where('tax_year', $year)
            ->where('source_type', 'scenario_choice')
            ->delete();

        // ── Step 2: Attribute per-knob benefits ──────────────────────────────
        $attribution = $this->solver->attributeBenefits($baseline, $chosenKnobs, $year);
        $knobBenefits = $attribution['knobs'] ?? [];
        $headerAggregate = $attribution['header_aggregate'] ?? [];

        // ── Step 3: Build groups for diverging knobs ──────────────────────────
        $templates = (array) config('optimizer-scenarios.checklist_templates', []);
        $currentKnobs = $this->currentKnobsFromBaseline($baseline);

        // Build groups with their annual benefit for ordering (Δ3)
        $groups = [];
        foreach ($templates as $knobKey => $template) {
            // Check if this knob diverges
            if (! $this->knobDiverges($knobKey, $chosenKnobs, $currentKnobs)) {
                continue;
            }

            $benefitParams = $this->buildBenefitParams($knobKey, $knobBenefits, $chosenKnobs, $baseline, $year);
            $kind = $this->resolveKind($user, $knobKey, $year);
            $annualBenefit = $this->estimateAnnualBenefit($benefitParams);

            $groups[] = [
                'knob_key' => $knobKey,
                'template' => $template,
                'kind' => $kind,
                'benefit_params' => $benefitParams,
                'annual_benefit' => $annualBenefit,
            ];
        }

        // ── Step 4: Sort groups by annual benefit DESC (Δ3) ──────────────────
        usort($groups, fn ($a, $b) => $b['annual_benefit'] <=> $a['annual_benefit']);

        // D24: Log reason when materialize() returns empty — kills silent no-ops.
        if (empty($groups)) {
            $templateKeys = array_keys($templates);
            $divergingKeys = [];
            foreach ($templateKeys as $knobKey) {
                if ($this->knobDiverges($knobKey, $chosenKnobs, $currentKnobs)) {
                    $divergingKeys[] = $knobKey;
                }
            }
            Log::info('ScenarioChecklistService::materialize returned empty', [
                'user_id' => $user->id,
                'tax_year' => $year,
                'option_key' => $optionKey,
                'reason' => empty($divergingKeys) ? 'no knobs diverge from baseline' : 'all diverging knobs filtered',
                'template_count' => count($templateKeys),
                'diverging_knobs' => $divergingKeys,
                'chosen_knobs_keys' => array_keys($chosenKnobs),
            ]);
        }

        // ── Step 5: Persist items ─────────────────────────────────────────────
        $items = [];
        $position = 1;

        foreach ($groups as $group) {
            $knobKey = $group['knob_key'];
            $template = $group['template'];
            $kind = $group['kind'];
            $benefitParams = $group['benefit_params'];

            $stepKey = $knobKey.'_'.$kind;

            $item = OptimizationChecklistItem::create([
                'user_id' => $user->id,
                'tax_year' => $year,
                'source_type' => 'scenario_choice',
                'source_id' => null,
                'fact_set_id' => $factSetId,
                'knob' => $knobKey,
                'step_key' => $stepKey,
                'kind' => $kind,
                'benefit_line_params' => $benefitParams,
                'position' => $position++,
                'done_at' => null,
            ]);

            $items[] = $item->toArray();
        }

        // ── Step 6: Header aggregate row ─────────────────────────────────────
        if (! empty($groups)) {
            $headerItem = OptimizationChecklistItem::create([
                'user_id' => $user->id,
                'tax_year' => $year,
                'source_type' => 'scenario_choice',
                'source_id' => null,
                'fact_set_id' => $factSetId,
                'knob' => 'header',
                'step_key' => 'header_aggregate',
                'kind' => 'directive',
                'benefit_line_params' => [
                    'header_aggregate' => $headerAggregate,
                    'action_count' => count($groups),
                    'option_key' => $optionKey,
                ],
                'position' => 0,  // header goes first
                'done_at' => null,
            ]);
            $items[] = $headerItem->toArray();
        }

        return $items;
    }

    /**
     * Toggle done state on a checklist item.
     *
     * When done=true on a fact-writing step:
     *  - K3: supersedes employer.contribution_pct via recordFact(source_type='user_edit')
     *  - K2: writes retirement.elected_roth_share_pct via recordFact(source_type='user_edit')
     *
     * @param  bool  $done  True to mark done, false to unmark.
     */
    public function toggleDone(User $user, OptimizationChecklistItem $item, bool $done): OptimizationChecklistItem
    {
        $item->done_at = $done ? Carbon::now() : null;
        $item->save();

        if ($done) {
            $this->writeRealityFact($user, $item);
        }

        return $item;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Derive the "current" knob values from the baseline for divergence detection.
     */
    private function currentKnobsFromBaseline(array $baseline): array
    {
        $current = $baseline['current'] ?? [];
        $employer = $baseline['employer'] ?? [];

        // Compute actual current Roth share from annualized 401k breakdown in the baseline.
        // (Earlier versions hardcoded this to 0, causing K2 checklist items to never generate
        // when the user currently has Roth contributions and the scenario recommends 0% Roth.)
        $tradCents = (int) ($current['trad_401k_cents'] ?? 0);
        $rothCents = (int) ($current['roth_401k_cents'] ?? 0);
        $totalCents = $tradCents + $rothCents;
        $currentRothShare = $totalCents > 0
            ? (float) round($rothCents / $totalCents * 100)
            : 0.0;

        return [
            'k401' => [
                'deferral_pct' => (float) ($current['deferral_pct'] ?? 0.0),
                'roth_share_pct' => $currentRothShare,
            ],
            'hsa' => [
                'annual_election_cents' => (int) ($current['hsa_cents'] ?? 0),
            ],
            'ira' => [
                'traditional_cents' => 0,  // additional on top of YTD
                'roth_cents' => 0,
            ],
            'transfer' => [
                'per_period_cents' => 0,
            ],
            'w4' => [
                'filing_status' => $baseline['w4_on_file']['filing_status'] ?? null,
                'dependents_under_17' => $baseline['family']['dependents_under_17'] ?? 0,
                'other_dependents' => $baseline['family']['other_dependents'] ?? 0,
            ],
        ];
    }

    /**
     * Determine whether a knob's chosen value diverges from the baseline current value.
     */
    private function knobDiverges(string $knobKey, array $chosenKnobs, array $currentKnobs): bool
    {
        $epsilons = (array) config('optimizer-scenarios.divergence', []);

        switch ($knobKey) {
            case 'k1':
                // W-4 diverges when filing status or dependents differ
                $chosenStatus = $chosenKnobs['w4']['filing_status'] ?? null;
                $curStatus = $currentKnobs['w4']['filing_status'] ?? null;
                $chosenDeps = (int) ($chosenKnobs['w4']['dependents_under_17'] ?? 0);
                $curDeps = (int) ($currentKnobs['w4']['dependents_under_17'] ?? 0);

                return $chosenStatus !== $curStatus || $chosenDeps !== $curDeps;

            case 'k2':
                // Roth share diverges
                $chosen = (float) ($chosenKnobs['k401']['roth_share_pct'] ?? 0);
                $current = (float) ($currentKnobs['k401']['roth_share_pct'] ?? 0);
                $epsilon = (float) ($epsilons['k401.roth_share_pct'] ?? 25.0);

                return abs($chosen - $current) >= $epsilon;

            case 'k3':
                // Deferral pct diverges
                $chosen = (float) ($chosenKnobs['k401']['deferral_pct'] ?? 0);
                $current = (float) ($currentKnobs['k401']['deferral_pct'] ?? 0);
                $epsilon = (float) ($epsilons['k401.deferral_pct'] ?? 1.0);

                return abs($chosen - $current) >= $epsilon;

            case 'k4':
                // HSA election diverges
                $chosen = (int) ($chosenKnobs['hsa']['annual_election_cents'] ?? 0);
                $current = (int) ($currentKnobs['hsa']['annual_election_cents'] ?? 0);
                $epsilon = (int) ($epsilons['hsa.annual_election_cents'] ?? 25_000);

                return abs($chosen - $current) >= $epsilon;

            case 'k5':
                // IRA additional contribution diverges
                $chosenTrad = (int) ($chosenKnobs['ira']['traditional_cents'] ?? 0);
                $chosenRoth = (int) ($chosenKnobs['ira']['roth_cents'] ?? 0);
                $curTrad = (int) ($currentKnobs['ira']['traditional_cents'] ?? 0);
                $curRoth = (int) ($currentKnobs['ira']['roth_cents'] ?? 0);
                $epsilon = (int) ($epsilons['ira.traditional_cents'] ?? 50_000);

                return abs($chosenTrad - $curTrad) >= $epsilon || abs($chosenRoth - $curRoth) >= $epsilon;

            case 'k6':
                // Transfer diverges
                $chosen = (int) ($chosenKnobs['transfer']['per_period_cents'] ?? 0);
                $current = (int) ($currentKnobs['transfer']['per_period_cents'] ?? 0);
                $epsilon = (int) ($epsilons['transfer.per_period_cents'] ?? 2_500);

                return abs($chosen - $current) >= $epsilon;

            default:
                return false;
        }
    }

    /**
     * Build the benefit_line_params for a knob from the attribution output.
     */
    private function buildBenefitParams(string $knobKey, array $knobBenefits, array $chosenKnobs, array $baseline, int $year): array
    {
        $params = [];
        $periods = (int) ($baseline['pay_periods_per_year'] ?? 26);
        $age = $baseline['age'] ?? null;

        switch ($knobKey) {
            case 'k1':
                $dim = $knobBenefits['w4'] ?? [];
                $params['per_paycheck'] = (int) ($dim['take_home']['per_paycheck_delta_cents'] ?? 0);
                $params['annual'] = (int) ($dim['take_home']['annual_delta_cents'] ?? 0);
                break;

            case 'k2':
                $dim = $knobBenefits['k401_roth'] ?? [];
                $params['delta_tax'] = (int) ($dim['federal_tax']['annual_delta_cents'] ?? 0);
                $params['roth_pct'] = (int) ($chosenKnobs['k401']['roth_share_pct'] ?? 0);
                $params['trad_pct'] = 100 - (int) ($chosenKnobs['k401']['roth_share_pct'] ?? 0);
                // Exact-instruction fields: "from" current Roth share
                $currTrad = (int) ($baseline['current']['trad_401k_cents'] ?? 0);
                $currRoth = (int) ($baseline['current']['roth_401k_cents'] ?? 0);
                $currTotal = $currTrad + $currRoth;
                $params['from_roth_pct'] = $currTotal > 0
                    ? (int) round($currRoth / $currTotal * 100)
                    : 0;
                // Illustration: long-horizon FV from retirement dim
                $illustration = $dim['retirement']['illustration'] ?? null;
                if ($illustration !== null) {
                    $params['fv_low'] = (int) ($illustration['low_cents'] ?? 0);
                    $params['fv_high'] = (int) ($illustration['high_cents'] ?? 0);
                    $params['age'] = $age ?? config('optimizer-scenarios.assumptions.default_retirement_age', 65);
                }
                break;

            case 'k3':
                $dim = $knobBenefits['k401_deferral'] ?? [];
                $params['pct'] = (float) ($chosenKnobs['k401']['deferral_pct'] ?? 0);
                $params['from_pct'] = (float) ($baseline['current']['deferral_pct'] ?? 0);
                $params['roth_share_pct'] = (int) ($chosenKnobs['k401']['roth_share_pct'] ?? $baseline['current']['roth_share_pct'] ?? 0);
                $params['match'] = (int) ($dim['retirement']['employer_match_delta_cents'] ?? 0);
                $params['delta_paycheck'] = (int) ($dim['take_home']['per_paycheck_delta_cents'] ?? 0);
                $params['delta_annual'] = (int) ($dim['take_home']['annual_delta_cents'] ?? 0);
                break;

            case 'k4':
                $dim = $knobBenefits['hsa'] ?? [];
                $params['amount'] = (int) ($chosenKnobs['hsa']['annual_election_cents'] ?? 0);
                $params['delta_paycheck'] = (int) ($dim['take_home']['per_paycheck_delta_cents'] ?? 0);
                break;

            case 'k5':
                $dim = $knobBenefits['ira'] ?? [];
                $params['trad'] = (int) ($chosenKnobs['ira']['traditional_cents'] ?? 0);
                $params['roth'] = (int) ($chosenKnobs['ira']['roth_cents'] ?? 0);
                $params['delta_deduction'] = abs((int) ($dim['federal_tax']['annual_delta_cents'] ?? 0));
                $periods = (int) ($baseline['pay_periods_per_year'] ?? 26);
                $totalIra = $params['trad'] + $params['roth'];
                $params['n'] = $periods;
                $params['amount'] = $periods > 0 ? (int) round($totalIra / $periods) : 0;
                break;

            case 'k6':
                $dim = $knobBenefits['transfer'] ?? [];
                $params['amount'] = (int) ($chosenKnobs['transfer']['per_period_cents'] ?? 0);
                $periodLabel = $this->periodLabel($baseline['pay_periods_per_year'] ?? 26);
                $params['period_label'] = $periodLabel;
                $params['annual'] = (int) ($params['amount'] * ($baseline['pay_periods_per_year'] ?? 26));
                break;
        }

        return $params;
    }

    /**
     * Resolve the kind (directive|confirm_ask) for a knob based on confirmed facts.
     * D9.2: a step is directive only when ALL anchor facts are confirmed.
     */
    private function resolveKind(User $user, string $knobKey, int $year): string
    {
        $anchors = self::KNOB_ANCHOR_FACTS[$knobKey] ?? [];

        if (empty($anchors)) {
            return 'directive';
        }

        foreach ($anchors as $factKey) {
            $fact = UserTaxFact::currentFact($user->id, $factKey, null, $year)
                ?? UserTaxFact::currentFact($user->id, $factKey);

            if ($fact === null) {
                return 'confirm_ask';
            }

            // Check provenance: only user_edit / interview_answer / confirmed document_extraction = confirmed
            $confirmedSources = ['user_edit', 'interview_answer'];
            if (in_array($fact->source_type, $confirmedSources, true)) {
                continue;
            }
            if ($fact->source_type === 'document_extraction' && $fact->confirmed_at !== null) {
                continue;
            }

            return 'confirm_ask';
        }

        return 'directive';
    }

    /**
     * Estimate the annual benefit for a knob group (used for group ordering — Δ3).
     *
     * Reads from benefit_params using the most relevant annual figure per knob.
     */
    private function estimateAnnualBenefit(array $params): int
    {
        // Priority: delta_annual > delta_tax > match > amount*periods > 0
        if (isset($params['delta_annual']) && $params['delta_annual'] !== 0) {
            return abs($params['delta_annual']);
        }
        if (isset($params['delta_tax']) && $params['delta_tax'] !== 0) {
            return abs($params['delta_tax']);
        }
        if (isset($params['match']) && $params['match'] !== 0) {
            return abs($params['match']);
        }
        if (isset($params['annual']) && $params['annual'] !== 0) {
            return abs($params['annual']);
        }
        if (isset($params['amount']) && $params['amount'] !== 0) {
            return abs($params['amount']);
        }

        return 0;
    }

    /**
     * Write reality facts when a fact-writing checklist step is marked done (§D.5).
     *
     * K3 done → supersedes employer.contribution_pct
     * K2 done → writes retirement.elected_roth_share_pct
     */
    private function writeRealityFact(User $user, OptimizationChecklistItem $item): void
    {
        $params = $item->benefit_line_params ?? [];

        switch ($item->knob) {
            case 'k3':
                // K3 done → supersedes employer.contribution_pct (§D.5).
                // Volatility: stable; taxYear: null — this is an employer characteristic,
                // not an annual figure. Matches the existing EmployerMatchGapDetector key.
                $pct = $params['pct'] ?? null;
                if ($pct !== null) {
                    UserTaxFact::recordFact(
                        userId: $user->id,
                        factKey: 'employer.contribution_pct',
                        value: (string) (float) $pct,
                        sourceType: 'user_edit',
                        label: '401(k) contribution % (elected)',
                        volatility: 'stable',
                        taxYear: null,   // employer attribute — not year-scoped
                    );
                }
                break;

            case 'k2':
                // K2 done → writes retirement.elected_roth_share_pct (§A.1.2 / §D.5).
                // Volatility: stable; taxYear: null (§A.1.2 table — no tax_year? column).
                $rothPct = $params['roth_pct'] ?? null;
                if ($rothPct !== null) {
                    UserTaxFact::recordFact(
                        userId: $user->id,
                        factKey: 'retirement.elected_roth_share_pct',
                        value: (string) (int) $rothPct,
                        sourceType: 'user_edit',
                        label: 'Elected Roth share % (elected)',
                        volatility: 'stable',
                        taxYear: null,   // §A.1.2: tax_year? = NO
                    );
                }
                break;
        }
    }

    /**
     * Human-readable period label for K6 directive copy.
     */
    private function periodLabel(int $periods): string
    {
        return match ($periods) {
            52 => 'week',
            26 => '2 weeks',
            24 => 'half-month',
            12 => 'month',
            default => 'pay period',
        };
    }
}
