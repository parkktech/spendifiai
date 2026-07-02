<?php

namespace App\Services;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AIQuestion;
use App\Models\InterviewSession;
use App\Models\OptimizationFinding;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * INT-07 band → ai_confidence mapping.
     *
     * @var array<string, float>
     */
    private const BAND_CONFIDENCE = [
        'auto' => 1.0,         // suggested-confirm pre-fill
        'conditional' => 0.70, // standard multiple-choice
        'specialist' => 0.30,  // full-module + pro routing
    ];

    // ─── Session Lifecycle ────────────────────────────────────────────────────

    /**
     * Find or create an active interview session for the user and tax year (INT-01).
     *
     * IDEMPOTENCY (Pitfall 8): uses firstOrCreate keyed on (user_id, tax_year, status=in_progress).
     * The partial unique index in the DB provides a hard uniqueness guarantee.
     *
     * RESUME (INT-05): if a paused session exists for this user+year, it is
     * resumed (set back to in_progress) rather than creating a new one.
     */
    public function startOrResume(int $userId, int $taxYear): InterviewSession
    {
        // 1. Check for an existing in_progress session (resume if found)
        $session = InterviewSession::forUser($userId)
            ->where('tax_year', $taxYear)
            ->whereIn('status', ['in_progress', 'paused'])
            ->first();

        if ($session !== null) {
            $session->activate(); // idempotent: in_progress stays in_progress

            return $session->fresh();
        }

        // 2. No active session — create one and seed the queue from high-band findings
        $queue = $this->buildInitialQueue($userId, $taxYear);
        $initialCap = (int) config('tax-detection.interview.initial_cap', 10);

        $session = InterviewSession::create([
            'user_id' => $userId,
            'tax_year' => $taxYear,
            'status' => 'in_progress',
            'queue' => $queue,
            'asked' => [],
            'initial_cap' => $initialCap,
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
     * Build the initial question queue from high-band OptimizationFindings (INT-03 / D5).
     *
     * Queue order:
     *   1. High-band (auto) findings — highest confidence, suggested-confirm mode.
     *   2. Annual battery questions (finding_type='battery_question') — life-event
     *      check-ins surfaced by LifeEventTriggerDetector. These are always
     *      band='conditional' and are appended AFTER auto findings. They belong in
     *      the interview queue, not the high-priority feed (SurfaceHighPriorityRedFlags
     *      listener handles band='auto' only — battery questions are excluded from the
     *      feed intentionally).
     *
     * Gated probes (INT-04) are added AFTER their prerequisites in the queue.
     *
     * @return string[] ordered fact_key / finding_key strings
     */
    private function buildInitialQueue(int $userId, int $taxYear): array
    {
        $initialCap = (int) config('tax-detection.interview.initial_cap', 10);

        // 1. High-band (auto) findings — ordered first
        $autoFindings = OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->where('band', 'auto')
            ->where('status', 'open')
            ->take($initialCap)
            ->pluck('finding_key')
            ->toArray();

        // 2. Annual battery questions — appended after auto findings regardless of band.
        //    Battery questions are annual life-event check-ins (marriage, birth, job change,
        //    inheritance, Medicare). They are lower priority than auto-band red flags.
        $batteryFindings = OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->where('finding_type', 'battery_question')
            ->where('status', 'open')
            ->pluck('finding_key')
            ->toArray();

        // Deduplicate (battery key already in auto queue is not re-added)
        $queue = array_values(array_unique(array_merge($autoFindings, $batteryFindings)));

        return $queue;
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
                    'prerequisite' => self::GATED_PROBES[$factKey] ?? null,
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
        $prerequisite = self::GATED_PROBES[$factKey] ?? null;
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

        // Call Claude ONLY to phrase the question (SAFE-01 / SAFE-03)
        $questionText = $this->wordQuestion($factKey, $finding, $band);

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
    ): string {
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

        try {
            $anthropicKey = config('services.anthropic.key');
            $model = config('services.anthropic.model', 'claude-sonnet-4-6');

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

        // Fallback: use the finding description or a generic question
        return $finding?->description
            ?? "We noticed a potential tax optimization related to: {$factKey}. Would you like to review it?";
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
        // Write (or supersede) the durable fact — append-only with concurrency safety
        $fact = UserTaxFact::recordFact(
            userId: $session->user_id,
            factKey: $factKey,
            value: $value,
            sourceType: 'interview_answer',
            label: $questionText ?? "Answer to: {$factKey}",
            volatility: 'stable',
            taxYear: null,
            sourceId: $questionId ? (string) $questionId : null,
        );

        // Append to session transcript
        $session->appendTranscript([
            'fact_key' => $factKey,
            'question' => $questionText ?? "Interview question: {$factKey}",
            'answer' => $value,
            'answered_at' => now()->toIso8601String(),
            'question_id' => $questionId,
        ]);

        // Mark as asked in the session (remove from queue if still there)
        $session->markAsked($factKey);
        $session->dequeueKey($factKey);

        return $fact;
    }
}
