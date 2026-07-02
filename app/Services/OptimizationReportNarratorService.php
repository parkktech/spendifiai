<?php

namespace App\Services;

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

    // SAFE-01: educational framing — identical policy as NarrationService
    // Reports narrate sections (2–4 sentences each), not individual findings.
    protected const SYSTEM_PROMPT = <<<'SYS'
You are an educational financial assistant. Write a brief overview of the following
tax optimization findings for a user-facing report section.

RULES (non-negotiable):
1. Use "may", "could", "consider", "might", "worth exploring" language — always educational, never assertive.
2. NEVER state dollar amounts. Use "potentially meaningful" or "worth reviewing" instead.
3. NEVER say "you qualify", "you owe", "you are entitled", "you should".
4. NEVER give filing-status advice ("you should file jointly/separately").
5. NEVER give securities-transaction advice (do not mention buying, selling, or holding specific assets).
6. Write 2–4 sentences of plain English that a non-expert can understand.
7. Do not repeat the finding_type verbatim — paraphrase into natural language.
8. End with: "Consider discussing these items with a tax professional."

OUTPUT: Return ONLY the 2–4 sentence overview. No JSON. No markdown. No bullet points.
SYS;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Narrate a single report section.
     *
     * Sends ONLY: section_title, findings (each: finding_type, severity,
     * band, legal_basis).
     *
     * DELIBERATELY EXCLUDES: estimated_value_cents, net_cash_cost, tax_saved,
     * cliff_bonus_value, and every other monetary column (T-12-03-01 / Pitfall 7).
     *
     * @param  array{
     *   section_title: string,
     *   findings: array<int, array{finding_type: string, severity: string, band: string|null, legal_basis: string|null}>
     * } $sectionContext
     * @return string|null Generated section prose, or null on failure.
     */
    public function narrateSection(array $sectionContext): ?string
    {
        if (empty($sectionContext['findings'])) {
            return null;
        }

        // Build payload from NON-monetary fields only (T-12-03-01)
        // Prompt-injection safety: all user-derived content is json_encode()'d
        // into a structured object — never interpolated into system prompt (T-12-03-03).
        $userPayload = json_encode([
            'section_title' => $sectionContext['section_title'],
            'findings' => array_map(function (array $finding): array {
                // Strip all monetary fields — only structural metadata reaches Claude
                // estimated_value_cents is DELIBERATELY EXCLUDED (T-12-03-01)
                return [
                    'finding_type' => $finding['finding_type'] ?? 'unknown',
                    'severity' => $finding['severity'] ?? 'medium',
                    'band' => $finding['band'] ?? null,
                    'legal_basis' => $finding['legal_basis'] ?? null,
                    // monetary fields (estimated_value_cents, net_cash_cost, tax_saved,
                    // cliff_bonus_value) are deliberately NOT included here
                ];
            }, $sectionContext['findings']),
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->callClaude(self::SYSTEM_PROMPT, $userPayload);

        if ($response === null) {
            Log::warning('OptimizationReportNarratorService: failed to narrate section', [
                'section_title' => $sectionContext['section_title'],
                'finding_count' => count($sectionContext['findings']),
            ]);
        }

        // Returns prose only — never assigns any monetary field on any model
        return $response;
    }

    /**
     * Narrate an executive summary across all sections.
     *
     * @param  array<int, array{title: string, finding_count: int}> $sectionSummaries
     */
    public function narrateExecutiveSummary(array $sectionSummaries): ?string
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
You are an educational financial assistant. Write a brief executive summary (2–3 sentences) for an optimization report.

RULES (non-negotiable):
1. Use educational, non-assertive language ("may", "could", "worth exploring").
2. NEVER state dollar amounts.
3. NEVER say "you qualify", "you owe", or make any specific financial assertion.
4. Mention the topical areas covered without making guarantees.
5. End with: "A tax professional can help you evaluate these areas for your specific situation."

OUTPUT: Return ONLY the 2–3 sentence summary. No JSON. No markdown.
SYS;

        return $this->callClaude($systemPrompt, $userPayload);
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    /**
     * Make a single-turn Claude API call.
     *
     * Uses the same Http::post() pattern as NarrationService for consistency.
     * Returns text content on success, null on any failure.
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
                ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
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
                    Log::error('OptimizationReportNarratorService: API error', [
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                return $response->json('content.0.text');

            } catch (\Exception $e) {
                if ($attempt < $maxRetries) {
                    sleep(1);

                    continue;
                }
                Log::error('OptimizationReportNarratorService: exception', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }
}
