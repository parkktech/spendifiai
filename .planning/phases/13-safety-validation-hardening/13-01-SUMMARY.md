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

---

## Checklist before/after + TODO

**Commit:** 8618ec2 on branch `feature/v2.1-optimize-my-income`
**Files changed:** 4
**Tests:** 1381 passed, 0 failures, 1 risky (up from ~1269 baseline)
**Build:** `npm run build` — 0 TypeScript errors; `vendor/bin/pint --dirty` — pass

### Disposition: Change 1 — Impact banner BEFORE → AFTER

**Status: IMPLEMENTED**

`HeaderAggregateBanner` in `OptimizationChecklistView.tsx` redesigned from delta-only to BEFORE → AFTER per-tile layout:

- **Bring home tile:** Shows `{baseline_per_period_take_home_cents} → {chosen_per_period_take_home_cents}/check` with annual delta as the primary large number. Graceful fallback to delta-only if absolute values absent (legacy materialized rows).
- **Est. tax tile:** Shows `{baseline_federal_tax_annual_cents} → {chosen_federal_tax_annual_cents}` with savings delta secondary. Negative delta = savings shown with minus sign.
- **Retirement tile:** Shows `~$Xk–$Yk → ~$Ak–$Bk` range at target age. Falls back to annual contribution delta if FV range not computed (horizon = 0 or no age facts).

D9.7 illustration rule enforced: when FV range is present, an assumptions line always displays: `"Illustration only — not a guarantee. X%–Y% annual growth assumed over N years. Actual results vary."` This is never a plain number — always a range with its growth-rate context.

**Backend changes:**
- `TaxRulesEngineService::computeScenarioOutcome` (additive): returns `baseline_absolute` with `federal_tax_annual_cents`, `per_period_take_home_cents`, `annual_contributions_cents`, `employer_match_cents` — the current-state values before any knob changes.
- `ScenarioSolverService::attributeBenefits` (additive): populates `header_aggregate.baseline_per_period_take_home_cents`, `chosen_per_period_take_home_cents`, `baseline_federal_tax_annual_cents`, `chosen_federal_tax_annual_cents`, `baseline_retirement_fv`, `chosen_retirement_fv`, `retirement_target_age`, `retirement_horizon_years`. Uses `engine->futureValueRangeCents()` for both trajectories.
- TypeScript `ChecklistBenefitParams.header_aggregate` extended with all new optional fields.

**SAFE-03 compliance:** All dollar values come from `TaxRulesEngineService` (zero Claude). The new absolute values are derived from the same engine path as the existing deltas — `computeScenarioOutcome` with the current knob vector, not a separate AI call.

**Text mock of the new Bring Home tile:**

```
┌─────────────────────────────────┐
│ BRING HOME                      │
│ $2,412 → $2,581/check           │
│ +$4.4k/yr          (sw-success) │
│ per paycheck                    │
└─────────────────────────────────┘
```

### Disposition: Change 2 — Checklist item typography + TODO prefix

**Status: IMPLEMENTED**

In `ChecklistCard`, the instruction paragraph was:
```tsx
<p className="text-[12px] text-sw-text-secondary leading-relaxed mt-1.5">
  {instruction}
</p>
```

Now:
```tsx
<p className="text-[13px] font-semibold text-sw-text leading-tight mt-1">
  <span className="text-sw-accent">TODO: </span>{instruction}
</p>
```

- Size matches the title (`text-[13px] font-semibold text-sw-text`)
- "TODO: " in `text-sw-accent` (blue) for visual weight and actionability
- `leading-tight` matches the title's `leading-tight` (was `leading-relaxed`)

### Disposition: Coordinator Change 3 — Per-item benefit line attribution fix

**Status: IMPLEMENTED** (acknowledged — not from user; no user authority)

Root cause: `ScenarioSolverService::attributeBenefits` stored per-dimension outcomes in a flat structure (`take_home_annual_delta_cents`, etc.) but `buildBenefitParams` tried to read them as nested (`$dim['take_home']['per_paycheck_delta_cents']`). Result: all per-knob attribution fields evaluated to `(int)(null ?? 0) = 0`, making every benefit line "—" instead of the item's own attributed figure.

Fix: `attributeBenefits` now includes BOTH the flat keys (backward compat) AND the nested `take_home`, `federal_tax`, `retirement` sub-arrays in each dimension entry. `buildBenefitParams` now correctly reads:
- k1: `per_paycheck` from `$dim['take_home']['per_paycheck_delta_cents']` ✓
- k2: `delta_tax` from `$dim['federal_tax']['annual_delta_cents']`; `fv_low/fv_high` from `$dim['retirement']['illustration']` ✓
- k3: `match` from `$dim['retirement']['employer_match_delta_cents']`; `delta_paycheck/delta_annual` from `$dim['take_home']` ✓
- k4: `delta_paycheck` from `$dim['take_home']['per_paycheck_delta_cents']` ✓
- k5: `delta_deduction` from `abs($dim['federal_tax']['annual_delta_cents'])` ✓
- k6: `amount` from `chosenKnobs` (unchanged — was already correct) ✓

Two items with different knobs now produce different benefit lines; no item repeats the banner aggregate total.

