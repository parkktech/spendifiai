<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Log;

/**
 * ObjectiveReadinessService — THE single readiness source (SCENARIOS-SPEC §A.8.1, M5).
 *
 * Computes two-tier per-objective readiness over the §A.2 fact-requirements map
 * (config/optimization-objectives.php) + ScenarioFactResolverService::resolveAll(),
 * and front-inserts gap questions into the interview session via enqueueGaps()
 * (§A.4.1, M6 — the ONLY gap-enqueue path).
 *
 * TWO TIERS (§A.7):
 *   - readiness gates on `known` (resolvable-for-math): blocking_missing counts
 *     only blocking facts with NO resolution (and not not_applicable).
 *   - `confirm_needed` = blocking facts resolved but confirmed=false (drives
 *     suggested-confirms; does NOT block readiness).
 *
 * INVARIANTS:
 *   - Iterates ONLY the three readiness objectives (take_home / tax_burden /
 *     retirement). The bonus_election scenario domain (is_scenario_domain) is
 *     skipped — it is consumed by 14-09 calendar watchers, never a readiness card.
 *   - Never returns money values (the §E projection is keys/labels/states only —
 *     the controller builds the response from this DTO's non-value fields).
 *   - No caching in v1 (readiness must reflect an answer given one second ago).
 */
final class ObjectiveReadinessService
{
    /** The three canonical readiness objectives (M4). Scenario domains excluded. */
    private const READINESS_OBJECTIVES = ['take_home', 'tax_burden', 'retirement'];

    public function __construct(
        private readonly ScenarioFactResolverService $resolver,
        private readonly InterviewOrchestratorService $orchestrator,
    ) {}

    /**
     * Per-objective readiness DTO (§A.8.1). Keyed by objective id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function readiness(User $user, int $taxYear): array
    {
        $resolved = $this->resolver->resolveAll($user, $taxYear);
        $objectives = (array) config('optimization-objectives.objectives', []);
        $templates = (array) config('optimization-objectives.question_templates', []);

        $out = [];
        foreach (self::READINESS_OBJECTIVES as $objectiveKey) {
            $spec = $objectives[$objectiveKey] ?? null;
            if ($spec === null || ! empty($spec['is_scenario_domain'])) {
                continue;
            }
            $out[$objectiveKey] = $this->computeObjective(
                $user, $taxYear, $spec, $resolved, $templates
            );
        }

        return $out;
    }

    /**
     * §A.4.1 — front-insert templated gap questions for one objective into the
     * user's interview session. The ONLY gap-enqueue path (M6).
     *
     * @return array{session: array<string, mixed>, enqueued: string[], queue_size: int, message: string}
     */
    public function enqueueGaps(User $user, int $taxYear, string $objective): array
    {
        // Shipped, idempotent session bootstrap (Pitfall 8 safe).
        $session = $this->orchestrator->startOrResume($user->id, $taxYear);

        $readiness = $this->readiness($user, $taxYear)[$objective] ?? null;

        $candidateKeys = [];
        if ($readiness !== null) {
            // Blocking gaps first, then known-but-unconfirmed suggested-confirms (§A.5).
            foreach ($readiness['blocking_missing'] as $entry) {
                $candidateKeys[] = $entry['fact_key'];
            }
            foreach ($readiness['confirm_needed'] as $entry) {
                $candidateKeys[] = $entry['fact_key'];
            }
        }

        $templates = (array) config('optimization-objectives.question_templates', []);
        $queue = $session->queue ?? [];
        $asked = $session->asked ?? [];
        $skipped = $session->skipped ?? [];

        // Fix 6: only exclude from asked[] keys that were actually ANSWERED (UserTaxFact
        // confirmed). Keys that were SERVED (in asked[]) but not yet answered can be
        // re-enqueued so the done-for-you loop continues after a queue drain.
        // Without this, served-but-unanswered gap keys are permanently excluded and the
        // "Review complete" screen fires while objectives remain locked.
        $answeredInAsked = array_filter($asked, function (string $askedKey) use ($user, $taxYear): bool {
            return UserTaxFact::currentFact($user->id, $askedKey, null, $taxYear) !== null
                || UserTaxFact::currentFact($user->id, $askedKey) !== null;
        });
        $excluded = array_merge($queue, array_values($answeredInAsked), $skipped);

        // Keys with a template (and a question — not label-only), not already in
        // queue/answered/skipped, not already answered via UserTaxFact.
        $keys = [];
        foreach ($candidateKeys as $key) {
            if (! isset($templates[$key])) {
                continue; // only templated gaps are enqueued (zero-Claude, answerable)
            }
            // Fix 7a: label-only template entries (no 'question' key, not dynamic) are
            // derived facts — they are never directly askable. Enqueueing them causes the
            // interview to serve a raw-key fallback question ("Please confirm: employer.
            // federal_withholding") which violates D18. Skip them here.
            if (empty($templates[$key]['question'] ?? null) && empty($templates[$key]['dynamic'] ?? false)) {
                continue;
            }
            if (in_array($key, $excluded, true) || in_array($key, $keys, true)) {
                continue;
            }
            if (UserTaxFact::currentFact($user->id, $key, null, $taxYear) !== null
                || UserTaxFact::currentFact($user->id, $key) !== null) {
                continue; // already answered (re-entrancy safe)
            }
            $keys[] = $key;
        }

        // FRONT-INSERT: gap keys go BEFORE pending finding keys. Battery questions
        // were already at the tail (buildInitialQueue), so they remain last.
        // initial_cap deliberately NOT applied (user explicitly requested these).
        if (! empty($keys)) {
            $session->update([
                'queue' => array_values(array_unique(array_merge($keys, $queue))),
            ]);
        }

        $fresh = $session->fresh();

        // D24: Log reason when enqueueGaps returns empty — kills silent no-ops.
        if (empty($keys)) {
            $readinessState = $this->readiness($user, $taxYear)[$objective] ?? null;
            $blockingCount = count($readinessState['blocking_missing'] ?? []);
            $candidateCount = count($candidateKeys);
            Log::info('ObjectiveReadinessService::enqueueGaps returned empty', [
                'user_id' => $user->id,
                'tax_year' => $taxYear,
                'objective' => $objective,
                'candidate_keys_count' => $candidateCount,
                'blocking_missing_count' => $blockingCount,
                'reason' => $candidateCount === 0
                    ? 'no blocking/confirm gaps found for objective'
                    : 'all gap candidates excluded (already in queue, answered, or skipped)',
                'candidate_keys' => $candidateKeys,
                'excluded_from_queue' => count($queue),
                'excluded_answered' => count($answeredInAsked),
                'excluded_skipped' => count($skipped),
            ]);
        }

        return [
            'session' => $fresh->only(['id', 'tax_year', 'status']),
            'enqueued' => $keys,
            'queue_size' => count($fresh->queue ?? []),
            'message' => count($keys) === 1
                ? '1 question added to your interview.'
                : count($keys).' questions added to your interview.',
        ];
    }

    // ─── Per-objective computation (§A.8.1) ───────────────────────────────────

    /**
     * @param  array<string, mixed>  $spec  the objective spec from config
     * @param  array<string, array<string, mixed>|null>  $resolved  resolveAll() output
     * @param  array<string, mixed>  $templates
     * @return array<string, mixed>
     */
    private function computeObjective(
        User $user,
        int $taxYear,
        array $spec,
        array $resolved,
        array $templates,
    ): array {
        $blocking = [];          // §E "blocking" projection (state per effectively-blocking fact)
        $blockingMissing = [];
        $confirmNeeded = [];
        $optionalMissing = [];

        $totalBlocking = 0;
        $resolvedBlocking = 0;
        $totalOptional = 0;
        $resolvedOptional = 0;

        foreach ((array) ($spec['facts'] ?? []) as $factConfig) {
            $canonicalKey = $factConfig['canonical_key'] ?? null;
            if ($canonicalKey === null) {
                continue;
            }

            $res = $resolved[$canonicalKey] ?? null;
            $blockingType = $factConfig['blocking'] ?? false; // true | false | 'conditional'
            $label = $templates[$canonicalKey]['label'] ?? $canonicalKey;

            // Prerequisite answered 'no' → dependent flips to not_applicable (§A.4.3).
            $notApplicable = isset($factConfig['prerequisite'])
                && $this->conditionKeyIsNo($user, $taxYear, $resolved, $factConfig['prerequisite']);

            // A conditional fact only participates when its condition holds.
            $conditionHolds = null;
            if ($blockingType === 'conditional') {
                $conditionHolds = $this->evaluateCondition(
                    $user, $taxYear, $resolved, (string) ($factConfig['condition'] ?? '')
                );
            }

            // Optional facts may also carry a gating condition (e.g. spouse income
            // only when married_joint). When it does not hold, the fact is not surfaced.
            $optionalConditionHolds = true;
            if ($blockingType === false && isset($factConfig['condition'])) {
                $optionalConditionHolds = $this->evaluateCondition(
                    $user, $taxYear, $resolved, (string) $factConfig['condition']
                );
            }

            $isEffectivelyBlocking = ($blockingType === true)
                || ($blockingType === 'conditional' && $conditionHolds === true);

            if ($isEffectivelyBlocking) {
                $totalBlocking++;
                $state = $notApplicable
                    ? 'not_applicable'
                    : ($res === null ? 'missing' : (($res['confirmed'] ?? false) ? 'confirmed' : 'known'));

                $blocking[] = [
                    'fact_key' => $canonicalKey,
                    'label' => $label,
                    'state' => $state,
                    'source' => $res['source'] ?? null,
                ];

                if ($notApplicable || $res !== null) {
                    $resolvedBlocking++; // not_applicable counts as resolved
                }

                if (! $notApplicable && $res === null) {
                    $blockingMissing[] = ['fact_key' => $canonicalKey, 'label' => $label];
                } elseif (! $notApplicable && $res !== null && ! ($res['confirmed'] ?? false)) {
                    $confirmNeeded[] = ['fact_key' => $canonicalKey, 'label' => $label];
                }
            } elseif ($blockingType === false && $optionalConditionHolds && ! $notApplicable) {
                $totalOptional++;
                if ($res !== null) {
                    $resolvedOptional++;
                } else {
                    $optionalMissing[] = [
                        'fact_key' => $canonicalKey,
                        'label' => $label,
                        'default_note' => $this->defaultNote($factConfig),
                    ];
                }
            }
            // conditional && condition does not hold → not in play (skipped entirely).
        }

        // questions_to_unlock = distinct templates covering blocking_missing
        // (multi-part asks count each part). Only directly-askable keys count.
        // Fix 4 (choices-repair): check for 'question' key, not just template presence.
        // Label-only entries (e.g. employer.federal_withholding — a derived key) provide
        // human-readable names in blocking_missing without inflating the question count.
        $questionsToUnlock = 0;
        foreach ($blockingMissing as $entry) {
            if (isset($templates[$entry['fact_key']]['question'])) {
                $questionsToUnlock++;
            }
        }

        $denominator = (2 * $totalBlocking) + $totalOptional;
        $completeness = $denominator > 0
            ? (int) round(100 * ((2 * $resolvedBlocking) + $resolvedOptional) / $denominator)
            : 100;

        return [
            'label' => $spec['label'] ?? '',
            'ready' => count($blockingMissing) === 0,
            'completeness_pct' => $completeness,
            'questions_to_unlock' => $questionsToUnlock,
            'blocking' => $blocking,
            'blocking_missing' => $blockingMissing,
            'confirm_needed' => $confirmNeeded,
            'optional_missing' => $optionalMissing,
        ];
    }

    // ─── Condition evaluation ─────────────────────────────────────────────────

    /**
     * Evaluate a config condition string against resolved facts.
     * Supports ' OR ' disjunction and `key OP value` / bare-key-truthy clauses.
     *
     * @param  array<string, array<string, mixed>|null>  $resolved
     */
    private function evaluateCondition(User $user, int $taxYear, array $resolved, string $condition): bool
    {
        $condition = trim($condition);
        if ($condition === '') {
            return false;
        }

        foreach (preg_split('/\s+OR\s+/', $condition) as $clause) {
            if ($this->evaluateClause($user, $taxYear, $resolved, trim($clause))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $resolved
     */
    private function evaluateClause(User $user, int $taxYear, array $resolved, string $clause): bool
    {
        if ($clause === '') {
            return false;
        }

        // Operators, longest first so '>=' / '<=' win over '>' / '<' / '='.
        foreach (['>=', '<=', '=', '>', '<'] as $op) {
            $pos = strpos($clause, $op);
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($clause, 0, $pos));
            $expected = trim(substr($clause, $pos + strlen($op)));
            $actual = $this->resolvedValue($user, $taxYear, $resolved, $key);

            return $this->compare($actual, $op, $expected);
        }

        // Bare key → truthy check (present and not a falsy 'no'/'0'/'').
        $actual = $this->resolvedValue($user, $taxYear, $resolved, $clause);

        return $actual !== null
            && ! in_array(strtolower((string) $actual), ['no', '0', '', 'false'], true);
    }

    private function compare(?string $actual, string $op, string $expected): bool
    {
        if ($actual === null) {
            return false;
        }

        if ($op === '=') {
            return strtolower($actual) === strtolower($expected);
        }

        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        $a = (float) $actual;
        $b = (float) $expected;

        return match ($op) {
            '>' => $a > $b,
            '<' => $a < $b,
            '>=' => $a >= $b,
            '<=' => $a <= $b,
            default => false,
        };
    }

    /**
     * A prerequisite fact is "no" when its resolved value is exactly 'no' (§A.4.3).
     *
     * @param  array<string, array<string, mixed>|null>  $resolved
     */
    private function conditionKeyIsNo(User $user, int $taxYear, array $resolved, string $key): bool
    {
        $value = $this->resolvedValue($user, $taxYear, $resolved, $key);

        return $value !== null && strtolower((string) $value) === 'no';
    }

    /**
     * Read a value for a condition key: prefer the already-resolved map, fall back
     * to a direct resolver call (handles condition keys that are not objective facts).
     *
     * @param  array<string, array<string, mixed>|null>  $resolved
     */
    private function resolvedValue(User $user, int $taxYear, array $resolved, string $key): ?string
    {
        if (array_key_exists($key, $resolved)) {
            return $resolved[$key]['value'] ?? null;
        }

        $direct = $this->resolver->resolve($user, $taxYear, $key);

        return $direct['value'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $factConfig
     */
    private function defaultNote(array $factConfig): ?string
    {
        if (isset($factConfig['default'])) {
            return 'default: '.$factConfig['default'];
        }
        if (isset($factConfig['suppresses'])) {
            return 'optional; suppresses '.$factConfig['suppresses'].' when provided';
        }

        return 'optional — a documented default is applied conservatively';
    }
}
