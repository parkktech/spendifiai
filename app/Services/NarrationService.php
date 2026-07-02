<?php

namespace App\Services;

use App\Models\OptimizationFinding;
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

    // SAFE-01: banned assertive phrases that must NEVER appear in any system prompt
    // Reference: REQUIREMENTS.md SAFE-01
    protected const SYSTEM_PROMPT = <<<'SYS'
You are an educational financial assistant. Your role is to explain a pre-computed tax finding in plain English.

RULES (non-negotiable):
1. Use "may", "could", "consider", "might", "worth exploring" language — always educational, never assertive.
2. NEVER state dollar amounts. Use "a meaningful amount" or "a significant difference" instead.
3. NEVER use first-person from the IRS perspective ("you owe", "you qualify", "you are entitled").
4. NEVER make deduction or credit assertions. Say "may be worth reviewing" not "is deductible".
5. NEVER give filing-status advice or say "you should file jointly/separately".
6. Write EXACTLY 2 sentences, ~40 words total. Lead with the actionable insight first.
7. Do not repeat the finding_type verbatim — paraphrase into natural language.
8. End with a neutral call-to-action: "Consider discussing this with a tax professional."

OUTPUT: Return ONLY the 2-sentence description. No JSON. No markdown. No bullet points.
SYS;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-6');
    }

    /**
     * Generate a plain-English description for an OptimizationFinding.
     *
     * Sends ONLY: finding_type, severity, treatment, legal_basis.
     * Explicitly excludes: estimated_value_cents, net_cash_cost, tax_saved, cliff_bonus_value,
     * and any other monetary column (Pitfall 7 / T-11-03-03).
     *
     * Writes ONLY: finding.description
     *
     * @return string|null The generated description, or null on failure.
     */
    public function narrateFinding(OptimizationFinding $finding): ?string
    {
        // Build payload from NON-monetary fields only (T-11-03-03)
        // Prompt-injection safety: all user-derived content is json_encode()'d into
        // a structured object — never interpolated into the system prompt (T-11-03-02)
        $userPayload = json_encode([
            'finding_type' => $finding->finding_type,
            'severity' => $finding->severity,
            'treatment' => $finding->treatment,
            'legal_basis' => $finding->legal_basis,
            // estimated_value_cents is DELIBERATELY EXCLUDED (Pitfall 7)
            // band, docs_missing, transaction_ids are metadata, not user content
            'band' => $finding->band,
            'potential_range' => 'use a professional estimate range, not a specific dollar amount',
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->callClaude(self::SYSTEM_PROMPT, $userPayload);

        if ($response === null) {
            Log::warning('NarrationService: failed to narrate finding', [
                'finding_id' => $finding->id,
                'finding_key' => $finding->finding_key,
            ]);

            return null;
        }

        // Write ONLY description — never touch estimated_value_cents or other money fields
        $finding->update(['description' => $response]);

        return $response;
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
     * Make a single-turn Claude API call.
     *
     * Uses the same Http::post() pattern as TransactionCategorizerService
     * for consistency with the existing Anthropic integration.
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
