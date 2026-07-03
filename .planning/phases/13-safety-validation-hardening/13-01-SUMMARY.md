---
phase: 13-safety-validation-hardening
plan: "01"
subsystem: safety-testing
tags: [SAFE-01, SAFE-05, SAFE-07, static-analysis, framing-audit, banned-phrase, pin-test]
status: complete

dependency_graph:
  requires: []
  provides:
    - SAFE-01 static gate (BannedPhraseSystemPromptsTest)
    - SAFE-07 framing-review pin (FramingReviewPinTest)
    - SAFE-05 framing audit worksheet (12-call-site ruling, in this SUMMARY)
  affects:
    - 13-04-PLAN.md (SAFE-05 hardening report binds this worksheet)

tech_stack:
  added: []
  patterns:
    - Static source-scan Pest tests (RecursiveIterator / file() pattern from EstimatedValueGuardTest)
    - Word-boundary regex phrase matching (\b...\b) to avoid false positives on substring forms
    - Narrowed negation-cue prefix skip (before-position scope, not whole-line skip)
    - Config-driven pin assertions via config() helper + str_contains file reads

key_files:
  created:
    - tests/Unit/BannedPhraseSystemPromptsTest.php
    - tests/Unit/FramingReviewPinTest.php
  modified: []

decisions:
  - "Word-boundary regex (\b...\b) selected over plain str_contains for phrase matching to avoid false positives: 'without guarantees' (OptimizationReportNarratorService line 192) matched 'guarantee' by substring but is a prohibition context, not assertive language"
  - "Negation-cue prefix approach selected (check substring before phrase position) over line-anchored allowlist because it is content-driven and resilient to line-number changes from future edits"
  - "v1.0 services (SavingsAnalyzerService, SavingsTargetPlannerService, AlternativeSuggestionService, SyncSummaryService) scoped out of BannedPhraseSystemPromptsTest enforcement per binding constraint 1; documented as SCOPED-OUT in the framing audit worksheet below"
  - "EmployerMatchGapDetector.php NOT pinned for 'if your plan allows' — phrase appears only in // Treatment: comment (line 81), not in emitted output; pinning comments is vacuous"
  - "ev_credit_30d pinned by effective_end/status (date-suppression), NOT by band — band stays conditional to feed the retroactive amended-return scanner"

metrics:
  duration: "18 minutes"
  completed: "2026-07-03T16:58:33Z"
  tasks_completed: 2
  tasks_total: 2
  files_created: 2
  files_modified: 0
  tests_added: 17
  suite_count_before: 1252
  suite_count_after: 1269
---

# Phase 13 Plan 01: SAFE-01/SAFE-07 Framing Audit Summary

One-liner: Static banned-phrase gate and framing-review pin test for v2.1 optimizer system prompts and liability-critical config phrases.

---

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | SAFE-01 banned-phrase static gate over v2.1 optimizer system prompts | 67ecb92 | tests/Unit/BannedPhraseSystemPromptsTest.php |
| 2 | SAFE-07 framing-review pin test + SAFE-01/SAFE-05 call-site worksheet | 21658cc | tests/Unit/FramingReviewPinTest.php |

---

## Verification Results

| Command | Result |
|---------|--------|
| `php artisan test --compact --filter=BannedPhraseSystem` | 2 passed (8 assertions) |
| `php artisan test --compact --filter=FramingReviewPin` | 15 passed (22 assertions) |
| `php artisan test --compact --filter=SAFE` | 41 passed (88 assertions) |
| `php artisan test --compact` (full suite) | 1269 passed, 1 risky (5619 assertions) |
| `vendor/bin/pint --dirty` | pass |

The "1 risky" is a pre-existing condition (test with no assertions) unrelated to this plan.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Word-boundary regex selected over str_contains for phrase matching**

- **Found during:** Task 1, first run
- **Issue:** Plain `str_contains` with `mb_strpos` matched "guarantee" as a substring of "without guarantees" on OptimizationReportNarratorService.php line 192. The line `4. Mention topical areas without guarantees.` is a prohibition instruction (telling Claude not to make guarantees), not assertive language. The naive scan returned a false positive RED.
- **Fix:** Changed phrase matching from `mb_strpos($lowLine, mb_strtolower($phrase))` to `preg_match_all('/\b' . preg_quote(...) . '\b/i', ...)` (word-boundary regex with `preg_match_all` for all occurrences). "guarantee" no longer matches "guarantees"; "without guarantees" is correctly excluded.
- **Files modified:** tests/Unit/BannedPhraseSystemPromptsTest.php
- **Not a scope change:** The plan permitted word-boundary matching as an implementation detail under the false-positive avoidance rules (research Pitfall 2).

### Out-of-Scope Observations

None deferred.

---

## Mutation Test Evidence (Anti-Vacuity)

### BannedPhraseSystemPromptsTest — Two mutation proofs

**Mutation A: Assertive phrase on non-comment, non-prohibition line**

- **Target file:** `app/Services/NarrationService.php`
- **Insertion (line 86):** `$mutationTestMarker = 'you qualify for this narration service';`
- **Result:** RED — `NarrationService.php:86 — you qualify` (1 failed, 1 passed)
- **Revert:** removed the line
- **After revert:** GREEN — 2 passed (8 assertions)

**Mutation B: Assertive-first with late negation cue (narrowed skip must NOT mask it)**

- **Target file:** `app/Services/NarrationService.php`
- **Insertion (line 86):** `$mutationTestMarker = 'You qualify — do not worry about it';`
- **"you qualify" at position 28 in variable value; prefix before phrase has no negation cue (the string begins "You qualify")**
- **Result:** RED — `NarrationService.php:86 — you qualify` (1 failed, 1 passed)
- **Revert:** removed the line
- **After revert:** GREEN — 2 passed (8 assertions)

The narrowed prefix skip correctly passes the line ONLY when a negation cue precedes the phrase. An assertive phrase that opens the value (even when a "do not" appears later) is correctly flagged.

### FramingReviewPinTest — Two mutation proofs

**Mutation C: Soften MFS ceiling phrase**

- **Target file:** `config/optimization-report.php`
- **Change:** `MFS may be worth modeling with your preparer.` → `MFS may be worth exploring with a professional.`
- **Result:** RED — `SAFE-07: "may be worth modeling with your preparer" is present in optimization-report.php` (1 failed, 14 passed)
- **Revert:** restored original phrase
- **After revert:** GREEN — 15 passed (22 assertions)

**Mutation D: Change EV credit date suppression**

- **Target file:** `config/tax-detection.php`
- **Change:** `ev_credit_30d.effective_end`: `'2025-09-30'` → `'2026-12-31'`
- **Result:** RED — `SAFE-07: ev_credit_30d is date-gated by effective_end=2025-09-30` (1 failed, 14 passed)
- **Revert:** restored `'2025-09-30'`
- **After revert:** GREEN — 15 passed (22 assertions)

All four mutation proofs confirm neither gate is vacuous.

---

## SAFE-01 Framing Audit Worksheet (SAFE-05 Input)

This 12-call-site ruling constitutes the SAFE-01 framing audit deliverable. 13-04's SAFE-HARDENING-REPORT will bind this worksheet.

### Ruling Key

| Symbol | Meaning |
|--------|---------|
| CERTIFIED | v2.1 optimizer surface — educational framing verified, covered by machine-enforced test |
| SCOPED-OUT | v1.0 user-facing service — assertive framing noted; not rewritten in this phase per binding constraint 1; owner recommendation documented |
| N/A | Admin-only — not user-facing; out of SAFE-01 scope |

### 12-Call-Site Audit Table

| # | Service File | Call Purpose | SAFE-01 Framing Status | Test Coverage | Ruling | Notes |
|---|-------------|-------------|------------------------|---------------|--------|-------|
| 1 | `NarrationService.php` | Finding narration (v2.1) | Educational system prompt — "may", "could", "consider"; NEVER assertions documented in prompt; no dollar amounts in payload | BannedPhraseSystemPromptsTest (Task 1) + NarrationServiceTest (existing) | **CERTIFIED** | System prompt hard-codes SAFE-01 rules; test gate enforces no drift |
| 2 | `OptimizationReportNarratorService.php` | Report section narration (v2.1) | Educational system prompt — identical policy to NarrationService; NEVER assertions listed | BannedPhraseSystemPromptsTest (Task 1) + existing tests | **CERTIFIED** | Same SAFE-01 system prompt structure; gate enforced |
| 3 | `InterviewOrchestratorService.php` | Question wording + escape-hatch interpretation (v2.1) | "educational, non-assertive" in system prompt; escape-hatch uses json_encode injection defense | BannedPhraseSystemPromptsTest (Task 1); SAFE-06 refusal gap covered by 13-02 | **CERTIFIED** | Framing compliant; SAFE-06 user-input refusal pre-filter is 13-02 scope |
| 4 | `TaxDocumentExtractorService.php` | Document extraction (v2.0/v2.1) | Extraction-only; not optimization-facing text; structured JSON schema output constraint | Existing TaxDocumentExtractorServiceTest; injection pen-test is 13-03 scope | **CERTIFIED** | SAFE-01 not applicable (extraction prompt, not optimization prompt); SAFE-02/SAFE-07 injection scope is 13-03 |
| 5 | `SavingsAnalyzerService.php` | Savings recommendations (v1.0) | Assertive framing: "Be honest and direct about where money is being wasted"; Claude computes monthly_savings/annual_savings as dollar figures | Not covered by BannedPhraseSystemPromptsTest (scoped out) | **SCOPED-OUT** | v1.0 feature; assertive language + Claude-computed dollars outside TaxRulesEngineService boundary; owner recommendation: schedule a v1.0 framing audit separately |
| 6 | `SavingsTargetPlannerService.php` | Savings action plan (v1.0) | Assertive framing: "personal finance advisor building a CONCRETE action plan" | Not covered | **SCOPED-OUT** | v1.0 feature; same owner recommendation as #5 |
| 7 | `AlternativeSuggestionService.php` | Cheaper alternatives (v1.0) | Assertive framing: "personal finance advisor" | Not covered | **SCOPED-OUT** | v1.0 feature; same owner recommendation as #5 |
| 8 | `EmailParserService.php` | Order email parsing (v1.0) | Extraction-only; no optimization output; raw email text interpolation (injection risk) | Not covered here; SAFE-02/SAFE-07 injection is 13-03 scope | **SCOPED-OUT** | Extraction-only purpose limits SAFE-01 risk; injection defense is 13-03 |
| 9 | `BankStatementParserService.php` | Bank statement parsing (v1.0/v2.0) | Extraction-only; binary PDF path + text fallback (injection risk on text path) | Not covered here; 13-03 scope | **SCOPED-OUT** | Same as #8 — extraction purpose, injection is 13-03 |
| 10 | `TransactionCategorizerService.php` | Transaction categorization + chat (v1.0) | Extraction/categorization only; not optimization-facing; chat path moderate risk | Not in SAFE-01 enforcement scope | **SCOPED-OUT** | Categorization purpose; not an optimization recommendation surface |
| 11 | `SyncSummaryService.php` | Weekly email digest (admin-scheduled, user-facing email) | Assertive framing risk: "friendly personal finance assistant" | Not covered | **SCOPED-OUT** | User-facing email; assertive framing risk noted; owner recommendation: review and align with SAFE-01 vocabulary in a v1.0 hardening pass |
| 12 | `CancellationLinkFinderService.php` | Admin-only link finder | Admin input only; not user-facing | Out of scope | **N/A** | Admin-only tool; SAFE-01 user-facing scope does not apply |

### v1.0 Scoped-Out Owner Recommendation

Services 5, 6, 7, 11 use assertive system-prompt language ("personal finance advisor", "Be honest and direct"). Services 5 and 6 additionally have Claude compute dollar savings amounts, which is outside the TaxRulesEngineService boundary enforced by SAFE-03 (though SAFE-03 is scoped to OptimizationFinding, not v1.0 SavingsRecommendation). These are noted as a documented liability boundary gap for the owner to schedule in a future v1.0 hardening pass. They were deliberately not rewritten in this phase per binding constraint 1 (v1.0 prompts untouched).

---

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. This plan adds static test files only.

---

## Known Stubs

None. This plan adds tests only; no production code or config stubs introduced.

---

## Self-Check

- [x] `tests/Unit/BannedPhraseSystemPromptsTest.php` — FOUND
- [x] `tests/Unit/FramingReviewPinTest.php` — FOUND
- [x] commit 67ecb92 (Task 1) — FOUND
- [x] commit 21658cc (Task 2) — FOUND
- [x] 17 new tests (BannedPhraseSystem: 2, FramingReviewPin: 15) GREEN

## Self-Check: PASSED
