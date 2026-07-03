<?php

namespace App\Services;

/**
 * SAFE-06 Hard-Block Refusal Detector.
 *
 * Detects abusive-scheme signals in user free text using a config-driven
 * keyword/phrase list. Returns a structured refuse-and-educate response or
 * null when the text is clear.
 *
 * Design requirements:
 *   - ZERO HTTP/Anthropic calls — pure phrase matching (D17 zero-Claude assertion).
 *   - No constructor dependencies on any Claude-calling service.
 *   - Detection is case-insensitive; phrases are multi-word n-grams (Pitfall 5).
 *   - Education copy is what/why only — sourced from config, never Claude.
 *   - Called BEFORE interpretEscapeAnswer() in InterviewOrchestratorService and
 *     BEFORE interpretUserResponse() in AIQuestionController::chat().
 */
class HardBlockRefusalService
{
    /**
     * Check user free text against the SAFE-06 hard-block phrase list.
     *
     * Returns a structured refusal array on match:
     *   {refused: true, category, education, best_effort_disclaimer, blocked_reason: 'hard_block_safe06'}
     *
     * Returns null when the text is clear (no abusive-scheme signal detected).
     *
     * @param  string  $userText  Raw user-supplied free text (escape hatch or chat message)
     * @return array{refused: true, category: string, education: string, best_effort_disclaimer: string, blocked_reason: string}|null
     */
    public function check(string $userText): ?array
    {
        $text = mb_strtolower($userText);

        foreach ((array) config('safe-refusal.clusters', []) as $cluster) {
            foreach ((array) ($cluster['phrases'] ?? []) as $phrase) {
                if (str_contains($text, (string) $phrase)) {
                    return [
                        'refused' => true,
                        'category' => (string) ($cluster['category'] ?? 'Abusive Tax Scheme'),
                        'education' => (string) ($cluster['education'] ?? ''),
                        'best_effort_disclaimer' => (string) config('safe-refusal.best_effort_disclaimer', ''),
                        'blocked_reason' => 'hard_block_safe06',
                    ];
                }
            }
        }

        return null;
    }
}
