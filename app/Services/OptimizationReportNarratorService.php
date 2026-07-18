<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OptimizationReportNarratorService — 3rd and FINAL permitted Claude call site
 * in the Optimize My Income milestone.
 *
 * SECURITY CONTRACT (non-negotiable — mirror of NarrationService):
 *
 *  1. This service writes ONLY section narrator prose and executive_summary.
 *     It NEVER touches estimated_value_cents, net_cash_cost, tax_saved,
 *     cliff_bonus_value, or any monetary column on any model.
 *
 *  2. The Claude payload DELIBERATELY EXCLUDES estimated_value_cents and ALL
 *     dollar figures (T-12-03-01 / Pitfall 7). Claude must never see money
 *     amounts — echoing them back as claims creates assertion liability.
 *
 *  3. User-derived content (finding_type, severity, band, legal_basis) is
 *     passed ONLY inside json_encode()'d user-message payload — NEVER
 *     interpolated into the system prompt (T-12-03-03 prompt-injection defense).
 *
 *  4. System prompt hard-codes SAFE-01 educational framing — "may", "could",
 *     "consider" language; no assertive phrases ("you should", "you must",
 *     "you qualify", etc.).
 *
 *  5. This is call site #3 of exactly 3 permitted Claude calls in the v2.1
 *     milestone (NarrationService = #1, TaxDocumentExtractorService = #2).
 *
 * SAFE-03 enforcement: EstimatedValueGuardTest grep-gate fails the build if
 * estimated_value_cents is assigned anywhere outside TaxRulesEngineService.php.
 */
class OptimizationReportNarratorService
{
    protected string $apiKey;

    protected string $model;

    /**
     * D19 — Structured output contract for report section narrations.
     *
     * CONTRACT: {summary: ≤2 sentences, bullets: ≤5 items × ≤15 words each}
     *
     * SAFE-01: educational framing — identical policy as NarrationService.
     */
    protected const SYSTEM_PROMPT = <<<'SYS'
You are an educational financial assistant. Write a brief overview of the following
tax optimization findings for a user-facing report section.

RULES (non-negotiable):
1. Use "may", "could", "consider", "might", "worth exploring" language — educational, never assertive.
2. NEVER state dollar amounts. Use "potentially meaningful" or "worth reviewing" instead.
3. NEVER say "you qualify", "you owe", "you are entitled", "you should".
4. NEVER give filing-status advice ("you should file jointly/separately").
5. NEVER give securities-transaction advice (no buying, selling, or holding specific assets).
6. Do not repeat the finding_type verbatim — paraphrase into natural language.

OUTPUT CONTRACT — return ONLY valid JSON, no markdown, no extra text:
{
  "summary": "<≤2 sentences overview, educational framing>",
  "bullets": ["<≤15 words>", "<≤15 words>", "...up to 5 items"]
}
SYS;

    /** D19: retry prompt when response violates length caps. */
    protected const SYSTEM_PROMPT_SHORTER = <<<'SYS'
You are an educational financial assistant. Your previous response exceeded the length caps.

RULES: Educational, non-assertive, no dollar amounts.

Return ONLY valid JSON:
{
  "summary": "<≤2 sentences STRICTLY>",
  "bullets": ["<≤15 words STRICTLY>", "...up to 5 items"]
}
SYS;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        // D17: section narration runs on the narration (Haiku) tier, falling back
        // to the global model when the narration key is unset.
        $this->model = config('services.anthropic.model_narration', config('services.anthropic.model'))
            ?? 'claude-sonnet-4-6';
    }

    /**
     * D19 — Narrate a single report section (structured output contract).
     *
     * Sends ONLY: section_title, findings (each: finding_type, severity, band, legal_basis).
     *
     * DELIBERATELY EXCLUDES: estimated_value_cents, net_cash_cost, tax_saved,
     * cliff_bonus_value, and every other monetary column (T-12-03-01 / Pitfall 7).
     *
     * Returns {summary: string, bullets: string[]} or null on failure.
     * Caller stores in sections[].narrator_structured (additive; narrator_prose fallback kept).
     *
     * @param  array{
     *   section_title: string,
     *   findings: array<int, array{finding_type: string, severity: string, band: string|null, legal_basis: string|null}>
     * } $sectionContext
     * @return array{summary: string, bullets: string[]}|null Structured narration, or null on failure.
     */
    public function narrateSection(array $sectionContext): ?array
    {
        if (empty($sectionContext['findings'])) {
            return null;
        }

        // Build payload from NON-monetary fields only (T-12-03-01).
        // Prompt-injection safety: json_encode'd, never interpolated into system prompt (T-12-03-03).
        $userPayload = json_encode([
            'section_title' => $sectionContext['section_title'],
            'findings' => array_map(function (array $finding): array {
                // Strip all monetary fields — only structural metadata reaches Claude.
                // estimated_value_cents DELIBERATELY EXCLUDED (T-12-03-01).
                return [
                    'finding_type' => $finding['finding_type'] ?? 'unknown',
                    'severity' => $finding['severity'] ?? 'medium',
                    'band' => $finding['band'] ?? null,
                    'legal_basis' => $finding['legal_basis'] ?? null,
                    // monetary fields deliberately NOT included
                ];
            }, $sectionContext['findings']),
        ], JSON_UNESCAPED_UNICODE);

        // D19: first attempt.
        $structured = $this->callClaudeStructuredSection(self::SYSTEM_PROMPT, $userPayload);

        // D19: one retry with shorter-instruction prompt on violation.
        if ($structured === null) {
            $structured = $this->callClaudeStructuredSection(self::SYSTEM_PROMPT_SHORTER, $userPayload);
        }

        if ($structured === null) {
            Log::warning('OptimizationReportNarratorService: failed to narrate section', [
                'section_title' => $sectionContext['section_title'],
                'finding_count' => count($sectionContext['findings']),
            ]);
        }

        // Returns structured object only — never assigns any monetary field on any model.
        return $structured;
    }

    /**
     * Legacy prose accessor for backward compat — returns summary string from structured object,
     * or null if narration unavailable. Used by renderers that have not yet been updated (D19
     * rollout: renderers compose from fields when structured is present, fall back to prose clamp).
     *
     * @param  array{summary: string, bullets: string[]}|null  $structured
     */
    public function narrateSectionProse(?array $structured): ?string
    {
        return $structured['summary'] ?? null;
    }

    /**
     * D19 — Narrate an executive summary across all sections (structured output contract).
     *
     * Returns {summary: string, bullets: string[]} or null on failure.
     *
     * @param  array<int, array{title: string, finding_count: int}>  $sectionSummaries
     * @return array{summary: string, bullets: string[]}|null
     */
    public function narrateExecutiveSummary(array $sectionSummaries): ?array
    {
        if (empty($sectionSummaries)) {
            return null;
        }

        $userPayload = json_encode([
            'task' => 'executive_summary',
            'sections' => array_map(fn ($s): array => [
                'title' => $s['title'],
                'finding_count' => $s['finding_count'],
                // No monetary data
            ], $sectionSummaries),
        ], JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<'SYS'
You are an educational financial assistant. Write a brief executive summary for an optimization report.

RULES:
1. Educational, non-assertive language ("may", "could", "worth exploring").
2. NEVER state dollar amounts.
3. NEVER say "you qualify", "you owe", or make specific financial assertions.
4. Mention topical areas without guarantees.

OUTPUT CONTRACT — return ONLY valid JSON:
{
  "summary": "<≤2 sentences overview>",
  "bullets": ["<≤15 words each>", "...up to 5 items"]
}
SYS;

        // First attempt
        $structured = $this->callClaudeStructuredSection($systemPrompt, $userPayload);

        // One retry on violation
        if ($structured === null) {
            $structured = $this->callClaudeStructuredSection(self::SYSTEM_PROMPT_SHORTER, $userPayload);
        }

        return $structured;
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    /**
     * D19 — Call Claude and validate structured section narration.
     *
     * Expected JSON: {summary: string, bullets: string[]}
     * Validates: summary ≤ 2 sentences; bullets ≤ 5 items × ≤ 15 words each.
     *
     * @return array{summary: string, bullets: string[]}|null
     */
    protected function callClaudeStructuredSection(string $systemPrompt, string $userPayload): ?array
    {
        // D17 budget cap: skip gracefully at cap (log + null), NO HTTP request.
        if (! $this->checkAndIncrementBudget('narration')) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(150)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 400,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userPayload]],
            ]);

            if (! $response->successful()) {
                Log::error('OptimizationReportNarratorService: API error', ['status' => $response->status()]);

                return null;
            }

            $raw = $response->json('content.0.text');
            if (! is_string($raw)) {
                return null;
            }

            // Strip markdown fences
            $raw = (string) preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
            $raw = (string) preg_replace('/\s*```$/', '', $raw);

            $data = json_decode($raw, true);
            if (! is_array($data)) {
                Log::warning('OptimizationReportNarratorService: non-JSON response', ['raw' => substr($raw, 0, 200)]);

                return null;
            }

            return $this->validateStructuredSection($data);

        } catch (\Exception $e) {
            Log::error('OptimizationReportNarratorService: exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * D19 — Validate structured section narration field caps.
     *
     * summary ≤ 2 sentences; bullets ≤ 5 × ≤ 15 words each.
     *
     * @param  array<string, mixed>  $data
     * @return array{summary: string, bullets: string[]}|null
     */
    protected function validateStructuredSection(array $data): ?array
    {
        $summary = isset($data['summary']) && is_string($data['summary']) ? trim($data['summary']) : null;
        $bullets = isset($data['bullets']) && is_array($data['bullets']) ? $data['bullets'] : null;

        if ($summary === null || $summary === '' || $bullets === null) {
            return null;
        }

        // summary ≤ 2 sentences
        if ($this->sentenceCount($summary) > 2) {
            Log::warning('OptimizationReportNarratorService: summary exceeds 2 sentences');

            return null;
        }

        // bullets ≤ 5 items, each ≤ 15 words
        if (count($bullets) > 5) {
            $bullets = array_slice($bullets, 0, 5);
        }

        $validBullets = [];
        foreach ($bullets as $bullet) {
            if (! is_string($bullet)) {
                continue;
            }
            $bullet = trim($bullet);
            $words = explode(' ', $bullet);
            if (count($words) > 15) {
                $bullet = implode(' ', array_slice($words, 0, 15));
            }
            $validBullets[] = $bullet;
        }

        return ['summary' => $summary, 'bullets' => $validBullets];
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
     * D17 per-purpose daily budget guard + call counter (Cache/Redis-backed).
     *
     * Mirrors NarrationService: reads `claude_calls_{purpose}_{date}`, skips at the
     * configured cap (null => uncapped), otherwise increments the day-counter for
     * the Admin ai-usage surface.
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
     * Narrate the chosen_plan report section (SCN-08 / §D.7 / T-14-08-03).
     *
     * SAFE-03 enforcement (absolute):
     *   - Payload carries ZERO monetary figures — no *_cents keys, no raw dollar amounts.
     *   - Claude must never see money values: echoing them back as assertions creates liability.
     *   - Option outcomes are described by their tier tags (positive_large, neutral, etc.)
     *     and by their semantic direction, NOT by dollar amounts.
     *
     * This is NOT a new Claude call site — it uses the same callClaudeStructuredSection()
     * helper already sanctioned by the SAFE-03 contract. Call budget is shared with
     * narrateSection() / narrateExecutiveSummary() under the 'narration' budget key.
     *
     * Returns {summary: string, bullets: string[]} or null on failure (graceful degradation).
     *
     * @param  array{
     *   option_key: string,
     *   option_label: string,
     *   checklist_item_count: int,
     *   readiness: array<string, array{ready: bool}>,
     *   outcomes: array<string, string>,   // take_home|federal_tax|retirement → tier string (no cents)
     * }  $chosenPlanContext
     * @return array{summary: string, bullets: string[]}|null
     */
    public function narrateScenarioComparison(array $chosenPlanContext): ?array
    {
        if (empty($chosenPlanContext['option_key'])) {
            return null;
        }

        // SAFE-03: build the payload from NON-monetary fields only.
        // 'outcomes' carries tier-tags (positive_large, neutral, etc.) — never raw cents.
        $userPayload = json_encode([
            'task' => 'chosen_plan_summary',
            'option_key' => $chosenPlanContext['option_key'],
            'option_label' => $chosenPlanContext['option_label'] ?? $chosenPlanContext['option_key'],
            'checklist_item_count' => (int) ($chosenPlanContext['checklist_item_count'] ?? 0),
            'readiness' => array_map(fn ($r): array => [
                'ready' => $r['ready'] ?? false,
            ], $chosenPlanContext['readiness'] ?? []),
            'outcomes' => $chosenPlanContext['outcomes'] ?? [],  // tier tags only — no _cents
            // NO monetary fields. EstimatedValueGuardTest grep-gate enforces this.
        ], JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<'SYS'
You are an educational financial assistant. Write a brief overview of the optimization plan a user
has chosen for their tax optimization report.

RULES (non-negotiable):
1. Educational, non-assertive: "may", "could", "worth exploring", "consider" — never "you should", "you qualify".
2. NEVER state dollar amounts. Outcomes like "positive_large" mean "could meaningfully improve" — paraphrase accordingly.
3. NEVER give securities advice, filing-status commands, or guaranteed outcomes.
4. Mention the plan type and that next steps are in the checklist.

"positive_large" = "potentially meaningful improvement"
"positive_medium" = "moderate potential improvement"
"positive_small" = "modest potential improvement"
"neutral" = "no material change projected"
"negative_*" = "trade-off compared to current approach"

OUTPUT CONTRACT — return ONLY valid JSON, no markdown, no extra text:
{
  "summary": "<≤2 sentences describing the chosen approach, educational framing>",
  "bullets": ["<≤15 words each, describing the plan focus and checklist next steps>", "...up to 4 items"]
}
SYS;

        $structured = $this->callClaudeStructuredSection($systemPrompt, $userPayload);

        if ($structured === null) {
            $structured = $this->callClaudeStructuredSection(self::SYSTEM_PROMPT_SHORTER, $userPayload);
        }

        if ($structured === null) {
            Log::warning('OptimizationReportNarratorService: failed to narrate chosen_plan', [
                'option_key' => $chosenPlanContext['option_key'],
            ]);
        }

        return $structured;
    }
}
