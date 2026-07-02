<?php

namespace App\Services;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\DocumentRequest;
use App\Models\InterviewSession;
use App\Models\OptimizationFinding;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Persisted one-question-at-a-time interview state machine (INT-01 / D5).
 *
 * RESPONSIBILITIES:
 *   - startOrResume(): idempotent session bootstrap (Pitfall 8 safe)
 *   - nextQuestion(): pops queue, applies skip-logic, prerequisite gating, batch-by-merchant
 *   - recordAnswer(): writes UserTaxFact + transcript; handles re-answer supersession
 *
 * CLAUDE USAGE (SAFE-01 / SAFE-03):
 *   Claude is called ONLY to phrase question text. The payload MUST NEVER contain:
 *   - estimated_value_cents (any monetary value from the finding)
 *   - dollar figures from finding->details
 *   - PII (fact_key namespaced strings are safe; no user name/email/address)
 *
 * INT-04 PREREQUISITE MAP (canonical list of gated probes):
 *   'ira.backdoor_roth_eligible' → requires 'ira.balance_range' to be answered
 *   Add entries to GATED_PROBES constant to gate additional probes.
 *
 * INT-07 CONFIDENCE BANDS:
 *   auto          → suggested-confirm (ai_confidence = 1.0)
 *   conditional   → multiple-choice (ai_confidence = 0.70)
 *   specialist    → full-module + pro routing (ai_confidence = 0.30)
 *
 * INT-06 BATCH-BY-MERCHANT:
 *   Each OptimizationFinding is keyed on finding_key (one per merchant pattern).
 *   nextQuestion() creates ONE AIQuestion per finding_key, carrying transaction_ids[]
 *   in options for retroactive application on confirm.
 */
class InterviewOrchestratorService
{
    /**
     * INT-04 prerequisite map.
     * key = probe fact_key (or finding_key) that is GATED
     * value = prerequisite fact_key that must be answered first
     *
     * @var array<string, string>
     */
    private const GATED_PROBES = [
        'ira.backdoor_roth_eligible' => 'ira.balance_range',
        // Add more gates here as interview content grows (wave 11b)
    ];

    /**
     * D20 — format version stamp.
     *
     * Bump this constant to invalidate pre-D20 sessions on next startOrResume().
     * The stale-queue self-heal rebuilds the queue with tier+eligibility ordering.
     *
     * Version history:
     *   0 (null) — pre-D18, legacy queue order
     *   1         — D18: escape hatch, pattern aggregation
     *   2         — D20: eligibility predicates + tier ordering + format_version stamp
     */
    public const FORMAT_VERSION = 2;

    /**
     * D20 — question tier definitions (1 = highest priority, 4 = lowest).
     *
     * Tier 1: identity / reconciliation (filing status, birth year, dependents)
     * Tier 2: big-dollar (retirement, HSA, 401k matching)
     * Tier 3: income classification (W-4 on file, withholding, pay frequency)
     * Tier 4: micro-probes (vehicle log, SaaS usage)
     *
     * These tiers annotate config question_templates and are evaluated in
     * buildInitialQueue() to order questions tier-ASC then estimated_impact-DESC.
     * Cap is applied AFTER sort (owner's complaint: "cap after sort").
     *
     * @var array<string, int>
     */
    private const FACT_TIER_MAP = [
        // Tier 1 — identity / reconciliation
        'profile.filing_status' => 1,
        'person.birth_year' => 1,
        'family.dependents_count' => 1,
        'family.qualifying_children_under_17' => 1,
        // Tier 2 — big-dollar
        'employer.has_401k' => 2,
        'employer.match_pct' => 2,
        'employer.match_threshold_pct' => 2,
        'employer.contribution_pct' => 2,
        'retirement.traditional_401k_ytd_cents' => 2,
        'retirement.roth_401k_ytd_cents' => 2,
        'retirement.statement_balance_cents' => 2,
        'health.hsa_eligible' => 2,
        'hsa.coverage_type' => 2,
        'hsa.ytd_contribution_cents' => 2,
        'ira.traditional_ytd_contribution_cents' => 2,
        'ira.roth_ytd_contribution_cents' => 2,
        'benefits.fsa_ytd_cents' => 2,
        // Tier 3 — income classification / withholding
        'pay.frequency' => 3,
        'pay.gross_per_period_cents' => 3,
        'pay.federal_withholding_per_period_cents' => 3,
        'income.annual_gross_cents' => 3,
        'w4.filing_status' => 3,
        'w4.dependents_claimed' => 3,
        'w4.extra_withholding_per_period_cents' => 3,
        'has_self_employment' => 3,
        'profile.estimated_magi_cents' => 3,
        'prior_year.federal_liability_cents' => 3,
        'prior_year.agi_cents' => 3,
        'finance.is_cash_constrained' => 3,
        // Tier 4 — micro-probes
        'vehicle.usage_log_status' => 4,
        'bonus.expected_month' => 4,
        'bonus.expected_amount_cents' => 4,
        'retirement.target_age' => 4,
        'spouse.annual_income_cents' => 4,
        'spouse.covered_by_retirement_plan' => 4,
    ];

    /**
     * INT-07 band → ai_confidence mapping.
     *
     * @var array<string, float>
     */
    private const BAND_CONFIDENCE = [
        'auto' => 1.0,         // suggested-confirm pre-fill
        'conditional' => 0.70, // standard multiple-choice
        'specialist' => 0.30,  // full-module + pro routing
    ];

    /**
     * Merged prerequisite gate map (§A.4.3): config prerequisites ∪ GATED_PROBES.
     * Built at construction; const entries win on collision. Consumed by
     * isPrerequisiteUnsatisfied() instead of the bare const.
     *
     * @var array<string, string>
     */
    private array $gateMap;

    /**
     * §A.5.5 confirm sentinel: when a suggested-confirm question is answered with
     * this exact value, recordAnswer() resolves the prefill pointer server-side and
     * records the resolved value (user-confirmed provenance) instead of the literal.
     */
    private const CONFIRM_SENTINEL = 'confirm';

    /**
     * D18 addendum 7 — the escape hatch. Every choice/multi-select question
     * carries a final "Something else? Let's talk about it" option. The client
     * submits `__other__: <free text>`; the orchestrator interprets the text
     * onto the choice set (wording-tier Claude, budget-counted) and records the
     * result through the same recordFact flow.
     */
    private const ESCAPE_VALUE = '__other__';

    private const ESCAPE_LABEL = "Something else? Let's talk about it";

    public function __construct(
        private readonly ScenarioFactResolverService $resolver = new ScenarioFactResolverService,
        private readonly FindingPatternQuestionService $patterns = new FindingPatternQuestionService,
    ) {
        // §A.4.3: merge config prerequisite pairs with the shipped const gate map.
        // array_merge order → GATED_PROBES (const) wins on key collision.
        $configPrereqs = (array) config('optimization-objectives.prerequisites', []);
        $this->gateMap = array_merge($configPrereqs, self::GATED_PROBES);
    }

    // ─── Session Lifecycle ────────────────────────────────────────────────────

    /**
     * Find or create an active interview session for the user and tax year (INT-01).
     *
     * IDEMPOTENCY (Pitfall 8): uses firstOrCreate keyed on (user_id, tax_year, status=in_progress).
     * The partial unique index in the DB provides a hard uniqueness guarantee.
     *
     * RESUME (INT-05): if a paused session exists for this user+year, it is
     * resumed (set back to in_progress) rather than creating a new one.
     *
     * STALE-QUEUE SELF-HEAL: if the existing session's queue is empty (or all
     * items consumed) AND eligible findings now exist, rebuild the queue from
     * the current finding set. This repairs sessions created during a pipeline
     * outage (e.g., when conditional findings had not yet been seeded). The
     * session history (asked[], transcript) is preserved.
     */
    public function startOrResume(int $userId, int $taxYear): InterviewSession
    {
        // 1. Check for an existing in_progress / paused session (resume if found)
        $session = InterviewSession::forUser($userId)
            ->where('tax_year', $taxYear)
            ->whereIn('status', ['in_progress', 'paused'])
            ->first();

        if ($session !== null) {
            $session->activate(); // idempotent: in_progress stays in_progress

            // D20 format_version check: if the session was built with an older format,
            // force a queue rebuild with the new tier+eligibility ordering.
            // The session history (asked[], transcript) is preserved; only queue is rebuilt.
            $sessionVersion = (int) ($session->format_version ?? 0);
            if ($sessionVersion < self::FORMAT_VERSION) {
                $session->update(['queue' => [], 'format_version' => self::FORMAT_VERSION]);
                $session->refresh();
                Log::info('InterviewOrchestratorService: format_version upgraded — queue cleared for rebuild', [
                    'user_id' => $userId,
                    'session_id' => $session->id,
                    'old_version' => $sessionVersion,
                    'new_version' => self::FORMAT_VERSION,
                ]);
            }

            // Stale-queue self-heal: rebuild queue if it is empty and new eligible
            // findings are available (covers sessions created during pipeline outage).
            if (empty($session->queue)) {
                $newQueue = $this->buildInitialQueue($userId, $taxYear);
                // DEFECT-2 fix: the rebuild MUST exclude asked ∪ skipped ∪ answered
                // keys, otherwise a skipped finding-backed item is re-inserted at
                // position 1 on every load (the reported "skip just refreshes" loop).
                $asked = $session->asked ?? [];
                $skipped = $session->skipped ?? [];
                $consumed = array_merge($asked, $skipped);
                $filteredQueue = array_values(array_filter(
                    array_diff($newQueue, $consumed),
                    fn ($key) => ! $this->isAlreadyAnswered($key, $userId),
                ));

                if (! empty($filteredQueue)) {
                    $session->update(['queue' => $filteredQueue]);

                    Log::info('InterviewOrchestratorService: stale-queue self-healed', [
                        'user_id' => $userId,
                        'tax_year' => $taxYear,
                        'session_id' => $session->id,
                        'rebuilt_queue_size' => count($filteredQueue),
                    ]);
                }
            }

            return $session->fresh();
        }

        // 2. No active session — create one and seed the queue from all eligible findings
        $queue = $this->buildInitialQueue($userId, $taxYear);
        $initialCap = (int) config('tax-detection.interview.initial_cap', 10);

        $session = InterviewSession::create([
            'user_id' => $userId,
            'tax_year' => $taxYear,
            'status' => 'in_progress',
            'queue' => $queue,
            'asked' => [],
            'initial_cap' => $initialCap,
            'format_version' => self::FORMAT_VERSION, // D20: stamp current format
        ]);

        Log::info('InterviewOrchestratorService: session started', [
            'user_id' => $userId,
            'tax_year' => $taxYear,
            'session_id' => $session->id,
            'queue_size' => count($queue),
        ]);

        return $session;
    }

    /**
     * Build the initial question queue from eligible OptimizationFindings (INT-03 / D5).
     *
     * D20 ORDERING (owner decision — "cap after sort"):
     *   Questions are sorted tier-then-estimated_impact BEFORE the cap is applied.
     *   Tier 1 (identity/reconciliation) always surfaces first, then tier 2 (big-dollar),
     *   then tier 3 (withholding), then tier 4 (micro-probes). Within each tier, findings
     *   are ranked by estimated_impact (findings only; question-only keys sort to the end).
     *
     * D20 ELIGIBILITY PREDICATES:
     *   Templates with a `when` key are evaluated against known facts before queueing.
     *   - Evaluable and FALSE → never appears in queue
     *   - Evaluable and TRUE → queued normally
     *   - Unevaluable (fact unknown) → queued (may ask to get the fact)
     *
     * D18 QUESTION-QUALITY GATING (owner decision, binding, preserved):
     *   - Rule 3: pattern types collapse; untemplated findings are excluded.
     *   - Rule 4: only data-grounded question sources enter the queue.
     *
     * @return string[] ordered fact_key / finding_key strings
     */
    private function buildInitialQueue(int $userId, int $taxYear): array
    {
        $initialCap = (int) config('tax-detection.interview.initial_cap', 10);

        // 1. Auto-band findings (suggested-confirm)
        $autoFindings = OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->where('band', 'auto')
            ->where('status', 'open')
            ->get(['finding_key', 'finding_type', 'band', 'treatment', 'description', 'estimated_value_cents'])
            ->filter(fn (OptimizationFinding $f) => $this->hasDataGroundedQuestion($f))
            ->values()
            ->toArray();

        // 2. Conditional findings (non-battery)
        $conditionalRows = OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->where('band', 'conditional')
            ->where('finding_type', '!=', 'battery_question')
            ->where('status', 'open')
            ->get(['finding_key', 'finding_type', 'band', 'treatment', 'description', 'estimated_value_cents']);

        $conditionalFindings = [];
        $patternKeys = [];
        foreach ($conditionalRows as $f) {
            if ($this->patterns->isPatternType($f->finding_type)) {
                $patternKey = $this->patterns->patternKey($f->finding_type);
                if (! in_array($patternKey, $patternKeys, true)
                    && ! $this->isAlreadyAnswered($patternKey, $userId)) {
                    $patternKeys[] = $patternKey;
                }

                continue;
            }

            if ($this->questionTemplate($f->finding_key) !== null
                || $this->patterns->hasTemplate($f->finding_key)
                || $this->isQuestionPhrased($f->description)) {
                $conditionalFindings[] = $f->toArray();

                continue;
            }

            Log::info('InterviewOrchestratorService: finding excluded from interview (D18 — no data-grounded template)', [
                'user_id' => $userId,
                'finding_key' => $f->finding_key,
                'finding_type' => $f->finding_type,
            ]);
        }

        // 3. Battery questions
        $batteryFindings = OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->where('finding_type', 'battery_question')
            ->where('status', 'open')
            ->get(['finding_key', 'finding_type', 'band', 'treatment', 'description', 'estimated_value_cents'])
            ->filter(fn (OptimizationFinding $f) => $this->hasDataGroundedQuestion($f))
            ->values()
            ->toArray();

        // ── D20: Tier sort + eligibility filtering ────────────────────────────
        // Build merged list of {finding_key, estimated_value_cents, tier} entries.
        // Pattern keys and battery keys don't have an estimated_value so sort last.

        $allEntries = [];

        foreach ($autoFindings as $f) {
            $key = $f['finding_key'];
            if ($this->passesEligibilityPredicate($key, $userId)) {
                $allEntries[] = [
                    'key' => $key,
                    'tier' => $this->tierFor($key),
                    'impact' => (int) ($f['estimated_value_cents'] ?? 0),
                    'group' => 0, // auto first
                ];
            }
        }

        foreach ($patternKeys as $key) {
            if ($this->passesEligibilityPredicate($key, $userId)) {
                $allEntries[] = ['key' => $key, 'tier' => $this->tierFor($key), 'impact' => 0, 'group' => 1];
            }
        }

        foreach ($conditionalFindings as $f) {
            $key = $f['finding_key'];
            if ($this->passesEligibilityPredicate($key, $userId)) {
                $allEntries[] = [
                    'key' => $key,
                    'tier' => $this->tierFor($key),
                    'impact' => (int) ($f['estimated_value_cents'] ?? 0),
                    'group' => 1,
                ];
            }
        }

        foreach ($batteryFindings as $f) {
            $key = $f['finding_key'];
            if ($this->passesEligibilityPredicate($key, $userId)) {
                $allEntries[] = ['key' => $key, 'tier' => $this->tierFor($key), 'impact' => 0, 'group' => 2];
            }
        }

        // Sort: group ASC → tier ASC → impact DESC (D20: cap applied AFTER sort)
        usort($allEntries, function (array $a, array $b): int {
            if ($a['group'] !== $b['group']) {
                return $a['group'] <=> $b['group'];
            }
            if ($a['tier'] !== $b['tier']) {
                return $a['tier'] <=> $b['tier'];
            }

            return $b['impact'] <=> $a['impact']; // higher impact first
        });

        // Extract keys and deduplicate
        $sortedKeys = array_values(array_unique(array_column($allEntries, 'key')));

        // D20: cap AFTER sort (owner's complaint mechanism preserved)
        return array_slice($sortedKeys, 0, $initialCap);
    }

    /**
     * D20 — Evaluate a template's `when` eligibility predicate against known facts.
     *
     * Returns true when:
     *   - The template has no `when` field (unconditional — always eligible)
     *   - The predicate references an unknown fact (unevaluable → may ask)
     *   - The predicate evaluates to true
     *
     * Returns false when the predicate is evaluable AND false (e.g. Medicare
     * question for a user known to be age 40).
     *
     * Predicate grammar (simple fact comparisons only):
     *   "person.estimated_age >= 64"   — integer comparison
     *   "person.estimated_age >= 50"
     *   "person.estimated_age >= 65"
     *   "family.dependents_count > 0"
     *   "profile.filing_status = married_joint"
     *
     * Config templates encode `when` as a string predicate. Dynamic templates
     * (FindingPatternQuestionService) are always unconditional.
     */
    private function passesEligibilityPredicate(string $factKey, int $userId): bool
    {
        $template = $this->questionTemplate($factKey);
        if ($template === null) {
            return true; // dynamic templates are unconditional
        }

        $when = $template['when'] ?? null;
        if ($when === null || ! is_string($when)) {
            return true; // no predicate = always eligible
        }

        return $this->evaluateWhenPredicate($when, $userId);
    }

    /**
     * D20 — Evaluate a simple `when` predicate string against known facts.
     *
     * Supported operators: >=, >, <=, <, =, !=
     * Fact side always on the LEFT (e.g. "person.estimated_age >= 64").
     *
     * Unevaluable (fact unknown) → returns true (may ask).
     */
    private function evaluateWhenPredicate(string $when, int $userId): bool
    {
        // Parse: "fact_key operator value"
        if (! preg_match('/^([a-z_.]+)\s*(>=|<=|!=|>|<|=)\s*(.+)$/', trim($when), $m)) {
            return true; // unparseable → don't filter
        }

        [, $factKey, $op, $rhs] = $m;
        $rhs = trim($rhs);

        // Resolve the fact from UserTaxFact or derive (e.g. estimated_age from birth_year)
        $value = $this->resolvePredicateFact($factKey, $userId);

        if ($value === null) {
            return true; // unknown — may ask
        }

        // Numeric comparison
        if (is_numeric($value) && is_numeric($rhs)) {
            $lhs = (float) $value;
            $rhsF = (float) $rhs;

            return match ($op) {
                '>=' => $lhs >= $rhsF,
                '>' => $lhs > $rhsF,
                '<=' => $lhs <= $rhsF,
                '<' => $lhs < $rhsF,
                '=' => abs($lhs - $rhsF) < PHP_FLOAT_EPSILON,
                '!=' => abs($lhs - $rhsF) >= PHP_FLOAT_EPSILON,
                default => true,
            };
        }

        // String comparison
        return match ($op) {
            '=' => (string) $value === $rhs,
            '!=' => (string) $value !== $rhs,
            default => true,
        };
    }

    /**
     * D20 — Resolve a fact key for predicate evaluation.
     *
     * Supported built-in derivations:
     *   person.estimated_age → derived from person.birth_year + current year
     *
     * Falls back to UserTaxFact for stored values.
     *
     * @return float|string|null null when the fact is unknown
     */
    private function resolvePredicateFact(string $factKey, int $userId): float|string|null
    {
        // Derived: person.estimated_age from birth_year
        if ($factKey === 'person.estimated_age') {
            $birthYearFact = UserTaxFact::currentFact($userId, 'person.birth_year');
            if ($birthYearFact !== null && is_numeric($birthYearFact->value)) {
                return (float) (now()->year - (int) $birthYearFact->value);
            }

            // Try the user's financial profile (if birth_year is stored there)
            return null;
        }

        $fact = UserTaxFact::currentFact($userId, $factKey);
        if ($fact === null) {
            return null;
        }

        return is_numeric($fact->value) ? (float) $fact->value : (string) $fact->value;
    }

    /**
     * D20 — Get the tier number for a fact_key (1 = highest priority, 4 = lowest).
     *
     * Fact-keys not in the tier map default to tier 3 (income classification).
     * Finding-only keys (battery, pattern) that don't map to config templates default to 3.
     */
    private function tierFor(string $factKey): int
    {
        // Check FACT_TIER_MAP (config question_templates' fact keys)
        if (isset(self::FACT_TIER_MAP[$factKey])) {
            return self::FACT_TIER_MAP[$factKey];
        }

        // Config template tier override (future-proof: allow 'tier' key in template)
        $template = $this->questionTemplate($factKey);
        if ($template !== null && isset($template['tier'])) {
            return (int) $template['tier'];
        }

        // Battery questions and pattern keys default to tier 4 (micro-probes)
        if (str_starts_with($factKey, 'battery_') || str_starts_with($factKey, 'pattern.')) {
            return 4;
        }

        return 3; // default: income classification tier
    }

    /**
     * D18: does this finding carry enough concrete data to render a real,
     * show-the-data question? (config template | question-phrased narration |
     * treatment copy that the suggested-confirm / fallback path can surface).
     */
    private function hasDataGroundedQuestion(OptimizationFinding $finding): bool
    {
        if ($this->questionTemplate($finding->finding_key) !== null) {
            return true;
        }

        if ($this->isQuestionPhrased($finding->description)) {
            return true;
        }

        return trim((string) $finding->treatment) !== '';
    }

    /** A narration string usable AS the question (already phrased as one). */
    private function isQuestionPhrased(?string $text): bool
    {
        $text = trim((string) $text);

        return $text !== '' && str_ends_with($text, '?');
    }

    // ─── Question Popping ─────────────────────────────────────────────────────

    /**
     * Pop the next question from the queue (INT-01 / D5).
     *
     * Algorithm (Pattern 5):
     * 1. Activate session if paused
     * 2. Pop first key from session->queue
     * 3. Check UserTaxFact::currentFact() — if confirmed, skip
     * 4. Check prerequisite gating (INT-04)
     * 5. Create AIQuestion(Optimization), record in asked[], return
     *
     * Returns null if queue is empty (session moves to completed).
     */
    public function nextQuestion(InterviewSession $session): ?AIQuestion
    {
        // Activate paused sessions on next-question call
        $session->activate();

        $queue = $session->queue ?? [];

        while (! empty($queue)) {
            $factKey = array_shift($queue);

            // Update session queue immediately (removed the popped key)
            $session->update(['queue' => $queue]);

            // INT-04: prerequisite gating — skip if prerequisite not yet answered
            if ($this->isPrerequisiteUnsatisfied($factKey, $session->user_id)) {
                Log::info('InterviewOrchestratorService: prerequisite not met, skipping', [
                    'fact_key' => $factKey,
                    'user_id' => $session->user_id,
                    'prerequisite' => $this->gateMap[$factKey] ?? null,
                ]);

                continue;
            }

            // CTX-04 / INT-03: skip if already answered (UserTaxFact proxy)
            if ($this->isAlreadyAnswered($factKey, $session->user_id)) {
                Log::info('InterviewOrchestratorService: fact already answered, skipping', [
                    'fact_key' => $factKey,
                    'user_id' => $session->user_id,
                ]);

                continue;
            }

            // Pop succeeded — create the AIQuestion and record it
            $question = $this->createOptimizationQuestion($session, $factKey);

            if ($question !== null) {
                $session->markAsked($factKey);

                return $question;
            }
        }

        // Queue exhausted — complete the session
        if ($session->status !== 'completed') {
            $session->complete();
        }

        return null;
    }

    /**
     * Check INT-04 prerequisite gating.
     * Returns true if the probe should be SKIPPED because its prerequisite is unmet.
     */
    private function isPrerequisiteUnsatisfied(string $factKey, int $userId): bool
    {
        // §A.4.3: use the merged gate map (config prerequisites ∪ GATED_PROBES).
        $prerequisite = $this->gateMap[$factKey] ?? null;
        if ($prerequisite === null) {
            return false; // no gate → allow
        }

        // Check if the prerequisite fact is confirmed in UserTaxFact
        $fact = UserTaxFact::currentFact($userId, $prerequisite);

        return $fact === null; // gated if prerequisite not confirmed
    }

    /**
     * CTX-04 / INT-03: check if a fact key is already answered in UserTaxFact.
     * Returns true if the fact has a confirmed current value (skip).
     */
    private function isAlreadyAnswered(string $factKey, int $userId): bool
    {
        // Check UserTaxFact directly (durable store, covers interview_answer + profile_field)
        $fact = UserTaxFact::currentFact($userId, $factKey);
        if ($fact !== null) {
            return true;
        }

        // Also check using the finding prefix if the factKey has no dot separator
        // (finding_keys like 'vehicle_mileage_deduction' map to 'finding.vehicle_mileage_deduction')
        if (! str_contains($factKey, '.')) {
            $findingFact = UserTaxFact::currentFact($userId, "finding.{$factKey}");
            if ($findingFact !== null) {
                return true;
            }
        }

        return false;
    }

    // ─── Question Creation ────────────────────────────────────────────────────

    /**
     * Create the AIQuestion(Optimization) for a given fact_key, using the associated
     * OptimizationFinding for metadata (INT-06 batch, INT-07 band).
     *
     * Claude is called ONLY to phrase the question text.
     * Payload excludes estimated_value_cents and all dollar figures (SAFE-01/SAFE-03).
     */
    private function createOptimizationQuestion(InterviewSession $session, string $factKey): ?AIQuestion
    {
        $userId = $session->user_id;
        $taxYear = $session->tax_year;

        // Look up the associated OptimizationFinding
        $finding = OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->where('finding_key', $factKey)
            ->where('status', 'open')
            ->first();

        if ($finding === null) {
            // No finding associated — skip this key (may be a pure interview probe)
            // For pure interview probes, create a generic question
            $finding = null;
        }

        // Check idempotency — don't create duplicate questions for the same fact_key
        $existing = AIQuestion::where('user_id', $userId)
            ->where('question_type', QuestionType::Optimization->value)
            ->where('ai_best_guess', $factKey)
            ->where('status', QuestionStatus::Pending->value)
            ->first();

        if ($existing !== null) {
            return $existing; // already exists from SurfaceHighPriorityRedFlags
        }

        // §A.3 / D17 — TEMPLATE-FIRST BRANCH (zero Claude, safe by construction).
        // If the fact key has a deterministic template — config-defined or a D18
        // aggregated pattern template (dynamic, data-grounded) — build the
        // AIQuestion from it and skip wordQuestion() entirely.
        $template = $this->resolveTemplate($session, $factKey);
        if ($template !== null) {
            return $this->createTemplateQuestion($session, $factKey, $template);
        }

        // Template-mapped keys without a resolvable template right now (all items
        // answered / no matching findings or transactions) are skipped — never
        // verbalized generically (D18).
        if ($this->patterns->hasTemplate($factKey)) {
            return null;
        }

        // ── Finding-driven questions: Claude wording path ──
        // INT-07: determine confidence from band
        $band = $finding?->band ?? 'auto';
        $confidence = self::BAND_CONFIDENCE[$band] ?? 0.70;

        // Build options JSON (INT-06 batch metadata + INT-07 suggested treatment)
        $options = [
            'fact_key' => str_contains($factKey, '.') ? $factKey : "finding.{$factKey}",
            'finding_id' => $finding?->id,
            'band' => $band,
            'suggested_treatment' => $finding?->treatment,
            'transaction_ids' => $finding?->transaction_ids ?? [],
        ];

        // D17 template-first: a question-phrased narration IS the question —
        // zero Claude. Otherwise call Claude ONLY to phrase it (SAFE-01/SAFE-03).
        $questionText = $this->isQuestionPhrased($finding?->description)
            ? trim($finding->description)
            : $this->wordQuestion($factKey, $finding, $band);

        // D18 rule 4/5: no data-grounded copy available → skip the key entirely.
        // The finding stays in the findings list; the interview NEVER renders a
        // contentless "does this apply to you" question or a raw internal key.
        if ($questionText === null) {
            Log::info('InterviewOrchestratorService: question skipped (D18 — no data-grounded copy)', [
                'user_id' => $userId,
                'fact_key' => $factKey,
            ]);

            return null;
        }

        // Create the AIQuestion (INT-06: one per finding_key = one per merchant pattern)
        return AIQuestion::create([
            'user_id' => $userId,
            'transaction_id' => null,
            'question' => $questionText,
            'question_type' => QuestionType::Optimization->value,
            'options' => $options,
            'ai_confidence' => $confidence,
            'ai_best_guess' => $factKey,
            'status' => QuestionStatus::Pending->value,
        ]);
    }

    /**
     * §A.3 / §A.5.5 — build an AIQuestion from a config question_template (zero Claude).
     *
     * The options JSON carries `answer_type`, `choices`, `objective_tags`, and (for
     * known-but-unconfirmed values) a `prefill_source` POINTER only — NEVER a dollar
     * value (AIQuestion.options is unencrypted JSON; SAFE-03 / T-14-05-01).
     */
    private function createTemplateQuestion(InterviewSession $session, string $factKey, array $template): AIQuestion
    {
        $userId = $session->user_id;
        $taxYear = $session->tax_year;

        // §A.5.5: is there a known-but-unconfirmed value? → suggested-confirm (band=auto).
        // Dynamic (D18 pattern) templates have no static fact to pre-fill — skip.
        $isSuggestedConfirm = false;
        $resolved = null;
        if (empty($template['dynamic'])) {
            $resolved = $this->resolver->resolve($session->user, $taxYear, $factKey);
            $isSuggestedConfirm = $resolved !== null && ! ($resolved['confirmed'] ?? false);
        }

        $band = $isSuggestedConfirm ? 'auto' : 'conditional';
        $confidence = self::BAND_CONFIDENCE[$band] ?? 0.70;

        // D18 addendum 7: append the escape hatch to every choice-style question.
        $choices = $template['choices'] ?? null;
        if (is_array($choices)
            && in_array($template['answer_type'] ?? 'string', ['choice', 'multi_select'], true)) {
            $choices = array_values($choices);
            $choices[] = ['value' => self::ESCAPE_VALUE, 'label' => self::ESCAPE_LABEL];
        }

        $options = [
            'fact_key' => $factKey,
            'template' => true,
            'band' => $band,
            'answer_type' => $template['answer_type'] ?? 'string',
            'choices' => $choices,
            'none_value' => $template['none_value'] ?? null,
            // D18: education lives in the collapsible context, not the body.
            'context' => $template['context'] ?? null,
            'objective_tags' => $this->objectiveTagsFor($factKey),
            'doc_affordance' => $template['doc_affordance'] ?? null,
            // D20.3: label for the "get from document" choice (rendered as third option)
            'doc_source_label' => $template['doc_source_label'] ?? null,
            'transaction_ids' => [],
        ];

        if ($isSuggestedConfirm) {
            // Store the POINTER only (e.g. 'snapshot:12:filing_status'), never the value.
            $options['prefill_source'] = $resolved['source_ref'] ?? null;
        }

        return AIQuestion::create([
            'user_id' => $userId,
            'transaction_id' => null,
            'question' => $template['question'] ?? "Please confirm: {$factKey}",
            'question_type' => QuestionType::Optimization->value,
            'options' => $options,
            'ai_confidence' => $confidence,
            'ai_best_guess' => $factKey,
            'status' => QuestionStatus::Pending->value,
        ]);
    }

    /**
     * Look up the deterministic template for a fact key (§A.3). Templates are keyed
     * by dotted canonical keys — fetch the whole map and index by literal string
     * (config() dot-notation would mis-traverse).
     *
     * @return array<string, mixed>|null
     */
    private function questionTemplate(string $factKey): ?array
    {
        $templates = (array) config('optimization-objectives.question_templates', []);

        return isset($templates[$factKey]) ? (array) $templates[$factKey] : null;
    }

    /**
     * D18 — full template resolution: static config templates first, then the
     * aggregated pattern templates (dynamic, built from the user's live data).
     *
     * @return array<string, mixed>|null
     */
    private function resolveTemplate(InterviewSession $session, string $factKey): ?array
    {
        $template = $this->questionTemplate($factKey);
        if ($template !== null) {
            return $template;
        }

        if ($this->patterns->hasTemplate($factKey)) {
            return $this->patterns->templateFor($session->user, (int) $session->tax_year, $factKey);
        }

        return null;
    }

    /**
     * All objective ids whose fact map contains this canonical key (§A.3 objective tags).
     *
     * @return string[]
     */
    private function objectiveTagsFor(string $factKey): array
    {
        $tags = [];
        foreach ((array) config('optimization-objectives.objectives', []) as $objectiveId => $objective) {
            if (! empty($objective['is_scenario_domain'])) {
                continue;
            }
            foreach ((array) ($objective['facts'] ?? []) as $spec) {
                if (($spec['canonical_key'] ?? null) === $factKey) {
                    $tags[] = $objectiveId;
                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * Call Claude ONLY for question wording (SAFE-01 / SAFE-03).
     *
     * PAYLOAD CONTRACT (must never contain money):
     *   - finding_type, severity, treatment, legal_basis, band: safe metadata
     *   - finding_key: non-PII identifier
     *   - potential_range: always "use a professional estimate range, not a specific dollar amount"
     *   - estimated_value_cents: ALWAYS EXCLUDED
     *   - details (raw gap figures): ALWAYS EXCLUDED
     */
    private function wordQuestion(
        string $factKey,
        ?OptimizationFinding $finding,
        string $band
    ): ?string {
        // Use Claude to generate a natural-language question (wording only)
        // If finding description already exists (from NarrationService), use it as context
        $safePayload = json_encode([
            'fact_key' => $factKey,
            'finding_type' => $finding?->finding_type ?? 'tax_optimization',
            'severity' => $finding?->severity ?? 'medium',
            'treatment' => $finding?->treatment,
            'legal_basis' => $finding?->legal_basis,
            'band' => $band,
            'existing_description' => $finding?->description,
            'potential_range' => 'use a professional estimate range, not a specific dollar amount',
            // estimated_value_cents deliberately excluded (SAFE-03)
            // details (raw dollar figures) deliberately excluded (SAFE-01)
        ]);

        $systemPrompt = <<<'SYS'
You are an educational financial assistant helping a user review potential tax optimizations.
Given a pre-computed tax finding, generate ONE clear, conversational question to ask the user.

Rules:
- Use "may", "could", or "consider" language — never make direct tax assertions
- One legal test per question — keep it simple and actionable
- Ask in plain English, no jargon
- Leading is fine ("It looks like you may..."), assuming is not
- Maximum 2 sentences
- Never state specific dollar amounts — use qualitative ranges if needed
- Never advise; always frame as "would you like to review / confirm / tell us more"
- Return ONLY the question text, no preamble
SYS;

        // D17 budget cap (purpose 'wording'): at cap, skip the Claude wording call
        // gracefully and fall through to the deterministic fallback below (no HTTP).
        if (! $this->checkAndIncrementBudget('wording')) {
            return $this->findingFallbackQuestion($finding);
        }

        try {
            $anthropicKey = config('services.anthropic.key');
            // D17: question wording runs on the wording (Haiku) tier, falling back
            // to the global model when the wording key is unset.
            $model = config('services.anthropic.model_wording', config('services.anthropic.model'))
                ?? 'claude-sonnet-4-6';

            $response = Http::withHeaders([
                'x-api-key' => $anthropicKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 200,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $safePayload],
                ],
            ]);

            if ($response->successful()) {
                $text = $response->json('content.0.text');
                if ($text) {
                    return trim($text);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('InterviewOrchestratorService: Claude wording call failed', [
                'fact_key' => $factKey,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: a real answerable question — never raw finding narration.
        return $this->findingFallbackQuestion($finding);
    }

    /**
     * DEFECT 1 / D18 fix — deterministic fallback for a finding-driven item when
     * Claude wording is unavailable (budget cap / error).
     *
     * D18 rules 1+2 (owner decision, binding): the fallback must SHOW THE DATA
     * and NEVER leak internal keys. In order:
     *   1. question-phrased narration → used verbatim (it IS the question);
     *   2. treatment copy (concrete, merchant-named detector output) → surfaced
     *      with a real confirm ask appended;
     *   3. nothing data-grounded → NULL. The caller skips the key entirely —
     *      a contentless question is worse than no question (D18 rule 4).
     *
     * The pre-D18 output — "We noticed something related to {finding_key}..." —
     * is the owner-reported live defect and is permanently dead.
     */
    private function findingFallbackQuestion(?OptimizationFinding $finding): ?string
    {
        if ($this->isQuestionPhrased($finding?->description)) {
            return trim($finding->description);
        }

        $treatment = trim((string) $finding?->treatment);
        if ($treatment !== '' && str_contains($treatment, ' ')) {
            // Real prose (not a bare enum token like 'standard_mileage'):
            // lead with the data, ask the unknown. D18 addendum 6 succinctness —
            // the FIRST sentence only; longer education never rides the body.
            $first = preg_split('/(?<=[.!?])\s+/', $treatment)[0] ?? $treatment;

            return rtrim($first, '.').'. Does this reflect your situation?';
        }

        return null;
    }

    /**
     * D20.2 — Detect whether a free-text escape answer contains a QUESTION.
     *
     * Heuristic: ends in '?', OR contains known question-opener words.
     * Returns true when the user is asking for information, not providing it.
     */
    public function isQuestion(string $text): bool
    {
        $text = trim($text);
        if (str_ends_with($text, '?')) {
            return true;
        }

        // Common question openers (case-insensitive, word-boundary anchored)
        return (bool) preg_match(
            '/\b(what|what\'s|how|why|when|where|who|is|are|can|could|should|do|does|don\'t|doesn\'t|isn\'t|aren\'t|will|would)\b/i',
            $text
        );
    }

    /**
     * D20.2 — Answer a user's question from the escape hatch briefly (1-2 educational sentences).
     *
     * Called when the user's escape-hatch free text is a question ("what's Medicare?").
     * Returns {educational_answer, interpreted_value} or null at budget cap.
     *
     * - educational_answer: 1-2 sentences (Haiku, D17-counted, educational framing)
     * - interpreted_value: the best-guess answer to the ORIGINAL question after explanation
     *
     * The caller presents: answer text + "Got it — recording: {interpreted_value}. ✓ / ✗"
     * The user must confirm or correct before recordAnswer() is called.
     *
     * @param  array<string, mixed>  $template  the question template
     * @return array{educational_answer: string, interpreted_value: string}|null
     */
    public function answerHatchQuestion(array $template, string $userQuestion): ?array
    {
        if (! $this->checkAndIncrementBudget('wording')) {
            return null;
        }

        $choices = (array) ($template['choices'] ?? []);
        $choiceList = $choices
            ? implode(', ', array_column($choices, 'label'))
            : '(free-text answer)';

        $system = <<<'SYS'
You are an educational financial assistant. A user typed a question instead of an answer.
Your job: (1) answer their question briefly in 1-2 educational sentences, then (2) state what
their answer to the original question LIKELY is based on their question.

RULES:
- Educational, non-assertive: "may", "could", "generally" language
- Never state specific dollar amounts
- Never give advice: "worth discussing with a professional"
- Brevity: 1-2 sentences max for the educational answer

Return ONLY valid JSON (no markdown):
{"educational_answer": "<1-2 educational sentences>", "interpreted_value": "<the user's likely answer>"}
SYS;

        $userPrompt = json_encode([
            'original_question' => $template['question'] ?? '',
            'available_choices' => $choiceList,
            'user_question' => $userQuestion,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $model = config('services.anthropic.model_wording', config('services.anthropic.model'))
                ?? 'claude-sonnet-4-6';

            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 300,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $userPrompt]],
            ]);

            if ($response->successful()) {
                $raw = $response->json('content.0.text');
                $raw = (string) preg_replace('/^```(?:json)?\s*/i', '', trim((string) $raw));
                $raw = (string) preg_replace('/\s*```$/', '', $raw);
                $data = json_decode($raw, true);

                if (is_array($data)
                    && isset($data['educational_answer'], $data['interpreted_value'])
                    && is_string($data['educational_answer'])
                    && is_string($data['interpreted_value'])) {
                    return [
                        'educational_answer' => trim($data['educational_answer']),
                        'interpreted_value' => trim($data['interpreted_value']),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('InterviewOrchestratorService: hatch-question answer failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * D20.3 — Check if an answer is the doc-source choice ("__doc_source__").
     * When selected, files a DocumentRequest and returns doc_pending status.
     *
     * The question must not advance; it resolves when the document is extracted.
     * The AIQuestion remains Pending (not Answered) with options.doc_pending = true.
     *
     * Returns true if the answer is the doc-source sentinel (handled here);
     * false if it should be processed normally.
     *
     * @param  array<string, mixed>  $template
     */
    public const DOC_SOURCE_VALUE = '__doc_source__';

    public function handleDocSourceAnswer(
        InterviewSession $session,
        AIQuestion $question,
        array $template
    ): bool {
        $docAffordance = $template['doc_affordance'] ?? null;
        if ($docAffordance === null) {
            return false;
        }

        // File a self-initiated DocumentRequest (no firm/accountant — self-service)
        // D20.3: accounting_firm_id and accountant_id are nullable since migration
        // 2026_07_02_220000_make_document_request_fks_nullable.php
        DocumentRequest::firstOrCreate(
            [
                'client_id' => $session->user_id,
                'category' => $docAffordance,
                'tax_year' => $session->tax_year,
                'accounting_firm_id' => null,
                'accountant_id' => null,
            ],
            [
                'description' => 'Requested from interview: '.($template['label'] ?? $docAffordance),
                'status' => \App\Enums\DocumentRequestStatus::Pending->value,
            ]
        );

        // Mark question as doc_pending (not re-asked; question stays Pending)
        $opts = (array) ($question->options ?? []);
        $opts['doc_pending'] = true;
        $opts['doc_category'] = $docAffordance;
        $question->update(['options' => $opts]);

        // Dequeue the fact_key (will re-appear once doc is extracted)
        $factKey = $opts['fact_key'] ?? null;
        if ($factKey) {
            $session->dequeueKey($factKey);
            $session->markAsked($factKey);
        }

        Log::info('InterviewOrchestratorService: doc-source answer filed', [
            'session_id' => $session->id,
            'fact_key' => $factKey,
            'doc_affordance' => $docAffordance,
        ]);

        return true;
    }

    /**
     * D18 addendum 7 — interpret an escape-hatch free-text answer onto the
     * question's choice set.
     *
     * This IS an allowed Claude call per D17 (genuinely bespoke user input):
     * wording tier (Haiku via model_wording), counted against the 'wording'
     * daily budget. At the cap — or on any failure — it degrades gracefully to
     * the neutral value 'other' (the raw text is preserved in the encrypted
     * session transcript; nothing is asserted from an uninterpreted answer).
     *
     * @param  array<string, mixed>  $template
     */
    private function interpretEscapeAnswer(array $template, string $freeText): string
    {
        if (! $this->checkAndIncrementBudget('wording')) {
            return 'other';
        }

        $isMulti = ($template['answer_type'] ?? 'string') === 'multi_select';
        $choices = (array) ($template['choices'] ?? []);
        $choiceValues = array_column($choices, 'value');
        $noneValue = (string) ($template['none_value'] ?? 'none');

        $choiceList = implode("\n", array_map(
            fn (array $c) => '- '.$c['value'].': '.$c['label'],
            $choices
        ));

        $system = <<<'SYS'
You map a user's free-text answer onto a fixed choice set for a financial questionnaire.
Return ONLY valid JSON (no markdown): {"value": "<choice value>"}

Rules:
- Pick the single best-matching choice value from the list.
- For multi-select questions you may return several values joined by commas.
- If nothing clearly matches, return {"value": "other"}.
- Never invent values that are not in the list (except "other").
SYS;

        $userPrompt = 'Question: '.($template['question'] ?? '')."\n"
            .'Answer type: '.($isMulti ? 'multi_select' : 'choice')."\n"
            ."Choices:\n{$choiceList}\n\n"
            ."User's answer: {$freeText}";

        try {
            $model = config('services.anthropic.model_wording', config('services.anthropic.model'))
                ?? 'claude-sonnet-4-6';

            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 100,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

            if ($response->successful()) {
                $decoded = json_decode((string) $response->json('content.0.text'), true);
                $candidate = trim((string) ($decoded['value'] ?? ''));

                if ($candidate !== '' && $candidate !== 'other') {
                    $parts = $isMulti
                        ? array_map('trim', explode(',', $candidate))
                        : [$candidate];

                    $valid = array_filter(
                        $parts,
                        fn (string $p) => in_array($p, $choiceValues, true) && $p !== self::ESCAPE_VALUE
                    );

                    if ($valid !== [] && count($valid) === count($parts)) {
                        // 'none' is exclusive on multi-select.
                        if ($isMulti && in_array($noneValue, $valid, true) && count($valid) > 1) {
                            return 'other';
                        }

                        return implode(',', array_values($valid));
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('InterviewOrchestratorService: escape-hatch interpretation failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return 'other';
    }

    /**
     * D17 per-purpose daily budget guard + call counter (Cache/Redis-backed).
     *
     * Reads `claude_calls_{purpose}_{date}`, skips at the configured cap
     * (null => uncapped), otherwise increments the day-counter for the Admin
     * ai-usage surface. Mirrors NarrationService.
     */
    private function checkAndIncrementBudget(string $purpose): bool
    {
        $date = now()->toDateString();
        $key = "claude_calls_{$purpose}_{$date}";
        $cap = config("services.anthropic.daily_budget_{$purpose}");
        $cap = ($cap === null) ? PHP_INT_MAX : (int) $cap;

        if ((int) Cache::get($key, 0) >= $cap) {
            Log::info("Claude daily budget cap hit: {$purpose}", ['date' => $date, 'cap' => $cap]);

            return false;
        }

        Cache::increment($key);

        return true;
    }

    // ─── Answer Recording ─────────────────────────────────────────────────────

    /**
     * Record a user's answer to an optimization question (INT-05 / FEED-03).
     *
     * 1. Writes a UserTaxFact (append-only, superseding any prior answer)
     * 2. Appends to the InterviewSession transcript
     * 3. Moves the fact_key from queue to asked (if not already)
     *
     * SAFE-03: NEVER writes estimated_value_cents or any dollar figure.
     * The recorded value is the user's raw answer string only.
     *
     * @param  InterviewSession  $session  the active session
     * @param  string  $factKey  the fact key being answered
     * @param  string  $value  the user's answer (raw string — never a dollar amount)
     * @param  string|null  $questionText  the question that was asked (for transcript)
     * @param  int|null  $questionId  the AIQuestion.id (for transcript provenance)
     */
    public function recordAnswer(
        InterviewSession $session,
        string $factKey,
        string $value,
        ?string $questionText = null,
        ?int $questionId = null
    ): UserTaxFact {
        // §A.5.3 additive: when a template exists for this key (config or D18
        // pattern), apply typed conversion and pull volatility/taxYear/label from
        // the template. Non-template keys keep the EXACT current hardcoded
        // behavior (DRIFT-02 additive guard).
        $template = $this->resolveTemplate($session, $factKey);

        $storedValue = $value;
        $volatility = 'stable';
        $taxYear = null;
        $label = $questionText ?? "Answer to: {$factKey}";

        if ($template !== null) {
            $isChoiceStyle = in_array($template['answer_type'] ?? 'string', ['choice', 'multi_select'], true);

            if ($isChoiceStyle && str_starts_with(ltrim($value), self::ESCAPE_VALUE)) {
                // D18 addendum 7 — escape hatch: interpret the free text onto
                // the choice set (wording-tier Claude; graceful 'other' at cap).
                $freeText = trim((string) preg_replace('/^\s*__other__\s*:?\s*/', '', $value));
                if ($freeText === '') {
                    throw ValidationException::withMessages([
                        'answer' => 'Please tell us a little more so we can record your answer.',
                    ]);
                }
                $storedValue = $this->interpretEscapeAnswer($template, $freeText);
            } elseif (empty($template['dynamic']) && strtolower(trim($value)) === self::CONFIRM_SENTINEL) {
                // §A.5.5: resolve the prefill pointer server-side at answer time and
                // record the resolved value (user-confirmed provenance). The value is
                // never taken from client-supplied options.
                $resolved = $this->resolver->resolve($session->user, $session->tax_year, $factKey);
                if ($resolved === null || ($resolved['value'] ?? null) === null) {
                    throw ValidationException::withMessages([
                        'answer' => 'There is nothing to confirm for this question yet.',
                    ]);
                }
                $storedValue = (string) $resolved['value'];
            } else {
                $storedValue = $this->convertTypedAnswer($template, $value);
            }

            $volatility = $template['volatility'] ?? 'stable';
            $taxYear = ! empty($template['tax_year_scoped']) ? (int) $session->tax_year : null;
            $label = $template['label'] ?? $label;
        }

        // D18 rule 3 fan-out: ONE aggregated multi-select answer writes the
        // per-item fact for EVERY choice (yes for selected, no for the rest).
        // An uninterpretable escape answer ('other') asserts nothing per-item.
        if ($template !== null && ! empty($template['fan_out']) && $storedValue !== 'other') {
            $this->fanOutPatternAnswer($session, $template, $storedValue, $questionId);
        }

        // Write (or supersede) the durable fact — append-only with concurrency safety
        $fact = UserTaxFact::recordFact(
            userId: $session->user_id,
            factKey: $factKey,
            value: $storedValue,
            sourceType: 'interview_answer',
            label: $label,
            volatility: $volatility,
            taxYear: $taxYear,
            sourceId: $questionId ? (string) $questionId : null,
        );

        // Append to session transcript (raw answer preserved; encrypted assertions).
        $session->appendTranscript([
            'fact_key' => $factKey,
            'question' => $questionText ?? "Interview question: {$factKey}",
            'answer' => $value,
            'answered_at' => now()->toIso8601String(),
            'question_id' => $questionId,
        ]);

        // D18 confirmation shape: also record the canonical fact key (e.g. the
        // battery's life_event.* key, tax-year scoped) so detector suppression
        // and 14-09 Action Center items read the same durable fact.
        if ($template !== null && ! empty($template['also_record'])) {
            $also = (array) $template['also_record'];
            if (! empty($also['fact_key'])) {
                UserTaxFact::recordFact(
                    userId: $session->user_id,
                    factKey: (string) $also['fact_key'],
                    value: $storedValue,
                    sourceType: 'interview_answer',
                    label: (string) ($also['label'] ?? $label),
                    volatility: (string) ($template['volatility'] ?? 'stable'),
                    taxYear: ! empty($also['tax_year_scoped']) ? (int) $session->tax_year : null,
                    sourceId: $questionId ? (string) $questionId : null,
                );
            }
        }

        // Mark as asked in the session (remove from queue if still there)
        $session->markAsked($factKey);
        $session->dequeueKey($factKey);

        // D18 rule 5: follow-ups fan from the CHOICE as their own questions —
        // never crammed into one. Runs LAST (after dequeue) so the queue update
        // is never clobbered by the stale in-memory model.
        if ($template !== null && ! empty($template['follow_ups'])) {
            $this->enqueueFollowUps($session, (array) $template['follow_ups'], $storedValue);
        }

        return $fact;
    }

    /**
     * §A.5.3 typed answer conversion driven by the template `answer_type`.
     * 422s (ValidationException) on a type mismatch — the orchestrator is the typed
     * validation boundary; AnswerOptimizationQuestionRequest stays string|max:500.
     *
     * @param  array<string, mixed>  $template
     */
    private function convertTypedAnswer(array $template, string $value): string
    {
        $type = $template['answer_type'] ?? 'string';
        $trimmed = trim($value);

        switch ($type) {
            case 'money_dollars':
                if (! is_numeric($trimmed) || (float) $trimmed < 0) {
                    throw ValidationException::withMessages([
                        'answer' => 'Please enter a dollar amount (e.g. 1500 or 1500.00).',
                    ]);
                }

                // integer-cents-as-string (mirrors assembler dollarsToCents)
                return (string) ((int) round((float) $trimmed * 100));

            case 'integer':
            case 'year':
                if (! ctype_digit($trimmed)) {
                    throw ValidationException::withMessages([
                        'answer' => 'Please enter a whole number.',
                    ]);
                }
                $n = (int) $trimmed;
                if (isset($template['min']) && $n < (int) $template['min']) {
                    throw ValidationException::withMessages([
                        'answer' => 'That value is below the allowed minimum.',
                    ]);
                }
                if (isset($template['max']) && $n > (int) $template['max']) {
                    throw ValidationException::withMessages([
                        'answer' => 'That value is above the allowed maximum.',
                    ]);
                }

                return $trimmed;

            case 'choice':
                $choices = array_column((array) ($template['choices'] ?? []), 'value');
                if (! in_array($trimmed, $choices, true)) {
                    throw ValidationException::withMessages([
                        'answer' => 'Please choose one of the provided options.',
                    ]);
                }

                return $trimmed;

            case 'multi_select':
                // D18 rule 3 — comma-joined choice values; 'none' is exclusive.
                $values = array_values(array_filter(
                    array_map('trim', explode(',', $trimmed)),
                    fn (string $v) => $v !== ''
                ));
                if ($values === []) {
                    throw ValidationException::withMessages([
                        'answer' => 'Please select at least one option.',
                    ]);
                }

                $choices = array_column((array) ($template['choices'] ?? []), 'value');
                $none = (string) ($template['none_value'] ?? 'none');

                if (in_array($none, $values, true)) {
                    if (count($values) > 1) {
                        throw ValidationException::withMessages([
                            'answer' => 'Please choose either specific items or "None of these", not both.',
                        ]);
                    }

                    return $none;
                }

                foreach ($values as $v) {
                    if (! in_array($v, $choices, true)) {
                        throw ValidationException::withMessages([
                            'answer' => 'Please choose only from the provided options.',
                        ]);
                    }
                }

                return implode(',', array_values(array_unique($values)));

            default:
                return $trimmed;
        }
    }

    /**
     * D18 rule 3 — fan the aggregated multi-select answer out to per-item facts.
     *
     * For every non-none choice in the template, records
     * `{fact_prefix}{choice_value}` = 'yes' (selected) or 'no' (not selected)
     * as an interview_answer fact. Labels stay humanized (the choice label,
     * never the key) so the facts surface cleanly in Profile & Settings.
     *
     * @param  array<string, mixed>  $template
     */
    private function fanOutPatternAnswer(
        InterviewSession $session,
        array $template,
        string $storedValue,
        ?int $questionId
    ): void {
        $fanOut = (array) $template['fan_out'];
        $prefix = (string) ($fanOut['fact_prefix'] ?? 'finding.');
        $selectedValue = (string) ($fanOut['selected_value'] ?? 'yes');
        $unselectedValue = (string) ($fanOut['unselected_value'] ?? 'no');
        $labelPrefix = (string) ($fanOut['label_prefix'] ?? '');
        $none = (string) ($template['none_value'] ?? 'none');

        $selected = $storedValue === $none ? [] : explode(',', $storedValue);

        foreach ((array) ($template['choices'] ?? []) as $choice) {
            $choiceValue = (string) ($choice['value'] ?? '');
            if ($choiceValue === '' || $choiceValue === $none) {
                continue;
            }

            UserTaxFact::recordFact(
                userId: $session->user_id,
                factKey: $prefix.$choiceValue,
                value: in_array($choiceValue, $selected, true) ? $selectedValue : $unselectedValue,
                sourceType: 'interview_answer',
                label: $labelPrefix.(string) ($choice['label'] ?? $choiceValue),
                volatility: (string) ($template['volatility'] ?? 'stable'),
                sourceId: $questionId ? (string) $questionId : null,
            );
        }
    }

    /**
     * D18 — front-insert follow-up question keys triggered by a choice answer.
     *
     * A follow-up fires only when: the answered value is in its trigger list,
     * a real template exists for it (config question_templates), and it is not
     * already answered, asked, skipped, or queued. Value-density preserved —
     * personal-flavored answers fan nothing.
     *
     * @param  array<string, string[]>  $followUps  followUpKey => trigger values
     */
    private function enqueueFollowUps(InterviewSession $session, array $followUps, string $answeredValue): void
    {
        $fresh = $session->fresh();
        $queue = $fresh->queue ?? [];
        $consumed = array_merge($queue, $fresh->asked ?? [], $fresh->skipped ?? []);

        $toInsert = [];
        foreach ($followUps as $followKey => $triggerValues) {
            if (! in_array($answeredValue, (array) $triggerValues, true)) {
                continue;
            }
            if ($this->questionTemplate($followKey) === null) {
                continue; // only real, templated asks fan (D18 rule 4)
            }
            if (in_array($followKey, $consumed, true)
                || $this->isAlreadyAnswered($followKey, $session->user_id)) {
                continue;
            }
            $toInsert[] = $followKey;
        }

        if ($toInsert !== []) {
            $session->update(['queue' => array_values(array_unique(array_merge($toInsert, $queue)))]);

            Log::info('InterviewOrchestratorService: follow-up questions fanned from answer (D18)', [
                'user_id' => $session->user_id,
                'session_id' => $session->id,
                'follow_ups' => $toInsert,
            ]);
        }
    }

    /**
     * DEFECT 2 — persist a SKIP for a finding-backed or template queue item.
     *
     * Skipping records the key in the session's durable `skipped[]` set and removes it
     * from the queue, but does NOT write a fact or complete the session. The stale-queue
     * self-heal (startOrResume) excludes skipped keys, so a skipped item never returns to
     * position 1 on reload. 'Back' navigation (frontend-local history) can still revisit
     * it without duplicating the queue entry.
     */
    public function skip(InterviewSession $session, string $factKey): void
    {
        $session->activate();
        $session->markSkipped($factKey);
        $session->dequeueKey($factKey);

        Log::info('InterviewOrchestratorService: question skipped', [
            'user_id' => $session->user_id,
            'session_id' => $session->id,
            'fact_key' => $factKey,
        ]);
    }
}
