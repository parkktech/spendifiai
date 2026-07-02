<?php

namespace App\Services;

use App\Models\OptimizationFinding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NarrationService: ONE OF EXACTLY TWO permitted Claude call sites in Phase 11.
 *
 * SECURITY CONTRACT (non-negotiable):
 *  1. This service writes ONLY `description` on OptimizationFinding. It NEVER touches
 *     estimated_value_cents, legal_basis, assumptions, band, or any monetary column.
 *  2. estimated_value_cents and ALL dollar figures are DELIBERATELY EXCLUDED from the
 *     Claude payload (Pitfall 7 / T-11-03-03). Claude must never see money amounts —
 *     echoing them back as claims creates assertion liability.
 *  3. User-derived strings (treatment, finding_type) are passed ONLY inside
 *     json_encode()'d user message payload — NEVER interpolated into the system prompt
 *     (T-11-03-02 prompt-injection defense).
 *  4. System prompt hard-codes SAFE-01 educational framing — "may", "could", "consider"
 *     language; no assertive phrases ("you should", "you must", "you qualify", etc.).
 *  5. legal_basis and assumptions are static config citations written by the detector
 *     harness — this service reads them as context but does NOT regenerate them.
 *
 * SAFE-03 enforcement: a Pest grep-gate test (EstimatedValueGuardTest) fails the build
 * if estimated_value_cents is assigned anywhere outside TaxRulesEngineService.php.
 */
class NarrationService
{
    protected string $apiKey;

    protected string $model;

    /**
     * D19 — Structured output contract (owner decision 2026-07-02).
     *
     * Every AI call returns a validated JSON object — no free prose blobs.
     * CONTRACT: {hook: ≤120 chars, detail: ≤2 sentences, action_cue: ≤1 sentence}
     *
     * hook:       The one-line what-and-why (prominently displayed on the card).
     * detail:     Up to 2 short educational sentences expanding on hook.
     * action_cue: One educational call-to-action sentence.
     *
     * SAFE-01: banned assertive phrases that must NEVER appear in any field.
     * Reference: REQUIREMENTS.md SAFE-01
     */
    protected const SYSTEM_PROMPT = <<<'SYS'
You are an educational financial assistant explaining a pre-computed tax finding in plain English.

RULES (non-negotiable):
1. Use "may", "could", "consider", "might", "worth exploring" language — educational, never assertive.
2. NEVER state dollar amounts. Use "a meaningful amount" or "a significant difference" instead.
3. NEVER use first-person IRS perspective ("you owe", "you qualify", "you are entitled").
4. NEVER make deduction assertions. Say "may be worth reviewing" not "is deductible".
5. NEVER give filing-status advice or say "you should file jointly/separately".
6. Do not repeat the finding_type verbatim — paraphrase into natural language.

OUTPUT CONTRACT — return ONLY valid JSON, no markdown, no extra text:
{
  "hook": "<≤120 chars: the one-line what-and-why, leading with the actionable insight>",
  "detail": "<≤2 short educational sentences, no dollar amounts>",
  "action_cue": "<≤1 sentence educational call-to-action>"
}
SYS;

    /** D19: retry instruction when response violates length caps. */
    protected const SYSTEM_PROMPT_SHORTER = <<<'SYS'
You are an educational financial assistant. Your previous response exceeded the length caps.

RULES:
1. Educational, non-assertive language only.
2. NEVER state dollar amounts.

Return ONLY valid JSON — no markdown:
{
  "hook": "<≤120 chars STRICTLY>",
  "detail": "<≤2 sentences STRICTLY, no dollar amounts>",
  "action_cue": "<≤1 sentence STRICTLY>"
}
SYS;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        // D17: per-call-site model — narration runs on Haiku, falling back to the
        // global model when the narration key is unset.
        $this->model = config('services.anthropic.model_narration', config('services.anthropic.model'))
            ?? 'claude-sonnet-4-6';
    }

    /**
     * D19 — structured output contract field caps.
     *
     * @var array<string, int>
     */
    protected const FIELD_CAPS = [
        'hook' => 120,         // chars
        'detail_sentences' => 2,
        'action_cue_sentences' => 1,
    ];

    /**
     * Generate a structured narration for an OptimizationFinding (D19).
     *
     * Sends ONLY: finding_type, severity, treatment, legal_basis.
     * Explicitly excludes: estimated_value_cents, net_cash_cost, tax_saved, cliff_bonus_value,
     * and any other monetary column (Pitfall 7 / T-11-03-03).
     *
     * Writes: finding.narration_structured ({hook, detail, action_cue})
     *         finding.description (hook — for backward compat display clamp)
     *
     * @return string|null The hook string, or null on failure.
     */
    public function narrateFinding(OptimizationFinding $finding): ?string
    {
        // D17 template-first (SCN-03): deterministic config templates bypass Claude.
        $templates = config('optimization-report.finding_narration_templates', []);
        if (is_array($templates) && array_key_exists($finding->finding_type, $templates)) {
            $description = $this->renderTemplate($templates[$finding->finding_type], $finding);
            // Template narrations don't have structured fields — write description only.
            // narration_structured remains null; renderer uses description + display clamp.
            $finding->update(['description' => $description]);

            return $description;
        }

        // D17 budget cap: skip gracefully at cap (log + null), NO HTTP request.
        if (! $this->checkAndIncrementBudget('narration')) {
            return null;
        }

        // Build payload from NON-monetary fields only (T-11-03-03).
        // Prompt-injection safety: json_encode'd user message, never interpolated (T-11-03-02).
        $userPayload = json_encode([
            'finding_type' => $finding->finding_type,
            'severity' => $finding->severity,
            'treatment' => $finding->treatment,
            'legal_basis' => $finding->legal_basis,
            // estimated_value_cents DELIBERATELY EXCLUDED (Pitfall 7)
            'band' => $finding->band,
            'potential_range' => 'use a professional estimate range, not a specific dollar amount',
        ], JSON_UNESCAPED_UNICODE);

        // D19: first attempt with full prompt.
        $structured = $this->callClaudeStructured(self::SYSTEM_PROMPT, $userPayload);

        // D19: one retry with shorter-instruction prompt on violation.
        if ($structured === null) {
            $structured = $this->callClaudeStructured(self::SYSTEM_PROMPT_SHORTER, $userPayload);
        }

        // D19: on second failure → template fallback (omit structured; leave description null).
        if ($structured === null) {
            Log::warning('NarrationService: failed to narrate finding after retry', [
                'finding_id' => $finding->id,
                'finding_key' => $finding->finding_key,
            ]);

            return null;
        }

        // Write narration_structured AND description (hook — backward compat).
        $finding->update([
            'narration_structured' => $structured,
            'description' => $structured['hook'],
        ]);

        return $structured['hook'];
    }

    /**
     * Narrate all null-description findings for a user/year.
     *
     * Called by NarrateOptimizationFindings listener after RunRedFlagDetectors
     * has had a chance to create findings (order-independent because we query
     * null-description findings from the DB rather than taking them as input).
     *
     * @return int Number of findings narrated.
     */
    public function narratePendingFindings(int $userId, int $taxYear): int
    {
        $findings = OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->whereNull('description')
            ->get();

        $count = 0;
        foreach ($findings as $finding) {
            if ($this->narrateFinding($finding) !== null) {
                $count++;
            }
        }

        return $count;
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Render a deterministic finding-narration template (D17 template-first).
     *
     * Substitutes {value} (formatted estimated_value figure — from the finding row
     * at render time, never a dollar literal in config) and {severity}. When the
     * figure is absent the {value} token collapses to a neutral qualitative phrase.
     */
    protected function renderTemplate(string $template, OptimizationFinding $finding): string
    {
        $cents = $finding->estimated_value_cents;
        $valueDisplay = ($cents !== null && (int) $cents > 0)
            ? '$'.number_format(((int) $cents) / 100, 0)
            : 'a meaningful amount';

        return strtr($template, [
            '{value}' => $valueDisplay,
            '{severity}' => (string) ($finding->severity ?? 'medium'),
        ]);
    }

    /**
     * D17 per-purpose daily budget guard + call counter (Cache/Redis-backed).
     *
     * Reads the day-key `claude_calls_{purpose}_{date}`, compares it to the
     * configured daily budget cap (null/absent => uncapped = PHP_INT_MAX). At the
     * cap it logs and returns false (caller skips the call, no HTTP). Otherwise it
     * increments the day-counter (for the Admin ai-usage surface) and returns true.
     */
    protected function checkAndIncrementBudget(string $purpose): bool
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

    /**
     * D19 — Make a single-turn Claude API call and validate the structured response.
     *
     * Expects JSON: {hook: string, detail: string, action_cue: string}.
     * Validates field presence and length caps on receipt.
     * Returns null when the response is missing, malformed, or violates any cap.
     *
     * @return array{hook: string, detail: string, action_cue: string}|null
     */
    protected function callClaudeStructured(string $systemPrompt, string $userMessage): ?array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(150)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 300,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userMessage]],
            ]);

            if (! $response->successful()) {
                Log::error('NarrationService: API error', ['status' => $response->status()]);

                return null;
            }

            $raw = $response->json('content.0.text');
            if (! is_string($raw)) {
                return null;
            }

            // Strip markdown code fences if present
            $raw = (string) preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
            $raw = (string) preg_replace('/\s*```$/', '', $raw);

            $data = json_decode($raw, true);
            if (! is_array($data)) {
                Log::warning('NarrationService: non-JSON response', ['raw' => substr($raw, 0, 200)]);

                return null;
            }

            return $this->validateStructuredNarration($data);

        } catch (\Exception $e) {
            Log::error('NarrationService: exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * D19 — Validate structured narration field presence and length caps.
     *
     * hook       ≤ 120 chars
     * detail     ≤ 2 sentences (counted as periods/exclamations/questions)
     * action_cue ≤ 1 sentence
     *
     * @param  array<string, mixed>  $data
     * @return array{hook: string, detail: string, action_cue: string}|null
     */
    protected function validateStructuredNarration(array $data): ?array
    {
        $hook = isset($data['hook']) && is_string($data['hook']) ? trim($data['hook']) : null;
        $detail = isset($data['detail']) && is_string($data['detail']) ? trim($data['detail']) : null;
        $actionCue = isset($data['action_cue']) && is_string($data['action_cue']) ? trim($data['action_cue']) : null;

        if ($hook === null || $hook === '' || $detail === null || $detail === '' || $actionCue === null || $actionCue === '') {
            Log::warning('NarrationService: missing structured fields', array_keys(array_filter(compact('hook', 'detail', 'actionCue'), fn ($v) => $v === null)));

            return null;
        }

        // hook ≤ 120 chars
        if (mb_strlen($hook) > self::FIELD_CAPS['hook']) {
            Log::warning('NarrationService: hook exceeds 120 chars', ['length' => mb_strlen($hook)]);

            return null;
        }

        // detail ≤ 2 sentences (rough sentence-count heuristic)
        if ($this->sentenceCount($detail) > self::FIELD_CAPS['detail_sentences']) {
            Log::warning('NarrationService: detail exceeds 2 sentences');

            return null;
        }

        // action_cue ≤ 1 sentence
        if ($this->sentenceCount($actionCue) > self::FIELD_CAPS['action_cue_sentences']) {
            Log::warning('NarrationService: action_cue exceeds 1 sentence');

            return null;
        }

        return ['hook' => $hook, 'detail' => $detail, 'action_cue' => $actionCue];
    }

    /**
     * Count sentences in a string (heuristic: split on .!? followed by space/end).
     */
    protected function sentenceCount(string $text): int
    {
        $parts = preg_split('/[.!?]+\s+|[.!?]+$/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return max(1, count($parts ?? [1]));
    }

    /**
     * Make a single-turn Claude API call — legacy prose path (kept for backward compat
     * with the test harness; narrative calls now use callClaudeStructured).
     *
     * Returns the text content on success, null on any failure.
     */
    protected function callClaude(string $systemPrompt, string $userMessage): ?string
    {
        $maxRetries = 2;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(150)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 256,
                    'system' => $systemPrompt,
                    'messages' => [['role' => 'user', 'content' => $userMessage]],
                ]);

                if (! $response->successful()) {
                    if ($attempt < $maxRetries) {
                        sleep(1);

                        continue;
                    }
                    Log::error('NarrationService: API error', ['status' => $response->status()]);

                    return null;
                }

                return $response->json('content.0.text');

            } catch (\Exception $e) {
                if ($attempt < $maxRetries) {
                    sleep(1);

                    continue;
                }
                Log::error('NarrationService: exception', ['error' => $e->getMessage()]);

                return null;
            }
        }

        return null;
    }
}
