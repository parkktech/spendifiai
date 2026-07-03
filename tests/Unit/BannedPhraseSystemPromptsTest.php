<?php

/**
 * BannedPhraseSystemPromptsTest — SAFE-01 static gate over v2.1 optimizer system prompts.
 *
 * SCOPE (binding constraint 1):
 *   Enforced against the THREE v2.1 optimizer Claude call sites only:
 *     - NarrationService.php
 *     - OptimizationReportNarratorService.php
 *     - InterviewOrchestratorService.php
 *
 *   v1.0 services (SavingsAnalyzerService, SavingsTargetPlannerService,
 *   AlternativeSuggestionService, SyncSummaryService) are NOT enforced here.
 *   They are documented in the 13-01-SUMMARY framing audit worksheet as
 *   SCOPED-OUT with an owner recommendation (assertive-language bleed + Claude-
 *   computed dollar amounts outside the TaxRulesEngineService boundary).
 *
 * BANNED-PHRASE LIST SOURCE:
 *   bannedPhraseList() from BannedPhraseTemplatesTest.php — single source of truth.
 *   This test NEVER redefines the phrases; it calls the function from the
 *   canonical source. A companion assertion guards this dependency link.
 *
 * MATCHING STRATEGY — word-boundary regex:
 *   Each banned phrase is matched using \b…\b word boundaries to avoid false
 *   positives where the phrase appears as a substring of a longer word.
 *   Example: "guarantee" must NOT match "guarantees" or "guaranteed" (the latter
 *   is its own banned phrase). "without guarantees" is a prohibition (no assertive
 *   claim) and is correctly excluded by the word-boundary miss on "guarantee".
 *   preg_match_all is used to catch all occurrences on a line, not just the first.
 *
 * NEGATION-CUE SKIP RULE (narrowed — "before-position" scope):
 *   A banned-phrase match is suppressed ONLY when:
 *     (a) the line is a pure comment (ltrim starts with // or # or *), OR
 *     (b) a negation-cue word/phrase — "never", "do not", "don't", "prohibited" —
 *         appears in the byte-substring of the (lower-cased) line BEFORE the
 *         matched phrase's start position.
 *
 *   RATIONALE: The v2.1 system prompts legitimately QUOTE banned phrases inside
 *   prohibition instructions ("NEVER say 'you qualify'"). A naive str_contains
 *   scan would flag the very files that correctly implement the ban.
 *
 *   CRITICAL BOUNDARY: The negation cue must appear BEFORE the phrase's position,
 *   not just anywhere on the line. A line like:
 *       "You must act — do not wait"
 *   opens with an assertive phrase ("you must") before any negation cue and MUST
 *   fail this gate. Searching only the prefix eliminates this false-negative.
 *
 *   BLANKET-SKIP PROHIBITION: Never suppress merely because a negation cue appears
 *   somewhere on the line (the "whole-line skip" anti-pattern). Only the bounded
 *   prefix check is permitted.
 *
 * TEST-THE-TEST EVIDENCE (mutation results in 13-01-SUMMARY.md):
 *   This test was verified RED-provable by:
 *   1. Temporarily inserting an assertive phrase on a non-comment, non-prohibition
 *      line of NarrationService.php → confirmed RED.
 *   2. Reverting → confirmed GREEN.
 *   3. Temporarily inserting a line where the assertive phrase precedes the
 *      negation cue ("You qualify — do not worry") → confirmed RED (the
 *      narrowed prefix skip does NOT mask an assertive-first line).
 *
 * REFERENCES:
 *   REQUIREMENTS.md SAFE-01; 13-RESEARCH.md Call Site Inventory #1/#2/#3;
 *   13-01-PLAN.md Task 1; research Pitfall 2 (false positives); Pitfall 6
 *   (the test IS the artifact).
 */

// ---------------------------------------------------------------------------
// Companion guard — assert the single-source-of-truth list is still reachable.
// Pest loads all Unit test files during discovery, so bannedPhraseList() is
// available when this file runs. The assertions below make the dependency
// explicit and fail loudly if BannedPhraseTemplatesTest.php is ever deleted.
// ---------------------------------------------------------------------------

test('SAFE-01 dependency: BannedPhraseTemplatesTest.php exists and bannedPhraseList() is callable', function () {
    $file = base_path('tests/Unit/BannedPhraseTemplatesTest.php');

    expect(file_exists($file))->toBeTrue(
        'BannedPhraseTemplatesTest.php must exist — it is the single source of the banned-phrase list ' .
        'used by BannedPhraseSystemPromptsTest. Deleting it breaks SAFE-01 enforcement.'
    );

    expect(function_exists('bannedPhraseList'))->toBeTrue(
        'bannedPhraseList() must be callable. Pest loads BannedPhraseTemplatesTest.php during discovery, ' .
        'making the function globally available. If this fails, the discovery path has changed.'
    );

    $list = bannedPhraseList();
    expect($list)->toBeArray()->not->toBeEmpty(
        'bannedPhraseList() must return a non-empty array of banned phrases.'
    );
});

// ---------------------------------------------------------------------------
// SAFE-01 main gate: scan v2.1 optimizer system prompts for banned phrases.
// ---------------------------------------------------------------------------

test('SAFE-01: no banned assertive phrase in v2.1 optimizer service system prompts', function () {
    // ── Enforced scope: exactly the three v2.1 optimizer Claude call sites ──
    // (v1.0 services are deliberately excluded — see file header and framing
    // audit worksheet in 13-01-SUMMARY.md)
    $enforcedFiles = [
        base_path('app/Services/NarrationService.php'),
        base_path('app/Services/OptimizationReportNarratorService.php'),
        base_path('app/Services/InterviewOrchestratorService.php'),
    ];

    // ── Negation cues that indicate a prohibition context ──────────────────
    // A cue MUST appear in the PREFIX (before the phrase's start position) to
    // suppress a match. Using only high-signal, unambiguous phrases to avoid
    // false positives (e.g., "not" is excluded because it appears inside words
    // like "notation", "notice", "network"; "do not" and "don't" are the
    // explicit phrase forms).
    $negationCues = ['never', 'do not', "don't", 'prohibited'];

    $banned     = bannedPhraseList();
    $violations = [];

    foreach ($enforcedFiles as $path) {
        // All three enforced files must exist; missing file = mis-configured gate.
        expect(file_exists($path))->toBeTrue(
            "SAFE-01 enforced service file is missing: {$path}"
        );

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $lineIndex => $line) {
            // ── Comment-line skip ────────────────────────────────────────────
            // Pure comment lines (docblock lines, single-line PHP comments, and
            // shell-style hash comments) legitimately quote banned phrases to
            // document what is prohibited — skip them.
            $trimmed = ltrim($line);
            if (
                str_starts_with($trimmed, '//') ||
                str_starts_with($trimmed, '#') ||
                str_starts_with($trimmed, '*')
            ) {
                continue;
            }

            $lowLine = mb_strtolower($line);

            foreach ($banned as $phrase) {
                // ── Word-boundary regex matching ─────────────────────────────
                // Use \b…\b to avoid false positives where the phrase appears as
                // a substring of a longer word.  "guarantee" must not match
                // "guarantees" (a separate word form); "without guarantees" in a
                // prohibition rule is correctly excluded by the word-boundary miss.
                // preg_match_all captures all occurrences on the line, not just the first.
                $pattern = '/\b' . preg_quote(mb_strtolower($phrase), '/') . '\b/i';
                $matchCount = preg_match_all($pattern, $lowLine, $matches, PREG_OFFSET_CAPTURE);

                if ($matchCount === 0 || $matchCount === false) {
                    continue;
                }

                foreach ($matches[0] as [$matchedText, $phrasePos]) {
                    // ── Narrowed negation-cue skip ───────────────────────────
                    // Extract the substring BEFORE the matched phrase (byte offset).
                    // If any negation cue appears in that prefix, the phrase is in a
                    // prohibition context ("NEVER say 'you qualify'") and is skipped.
                    // If the negation cue appears AFTER the phrase, it does NOT exempt
                    // ("You must act — do not wait" → prefix is empty → no cue → FAIL).
                    $prefix = mb_substr($lowLine, 0, $phrasePos);
                    $isProhibitionContext = false;
                    foreach ($negationCues as $cue) {
                        if (str_contains($prefix, $cue)) {
                            $isProhibitionContext = true;
                            break;
                        }
                    }

                    if ($isProhibitionContext) {
                        continue;
                    }

                    $violations[] = basename($path) . ':' . ($lineIndex + 1) . ' — ' . $phrase;
                }
            }
        }
    }

    expect($violations)->toBeEmpty(
        'SAFE-01 violation — banned assertive phrase found outside a prohibition context in a ' .
        'v2.1 optimizer system prompt:' . PHP_EOL . implode(PHP_EOL, $violations)
    );
});
