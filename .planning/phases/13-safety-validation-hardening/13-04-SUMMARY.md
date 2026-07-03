---
phase: 13-safety-validation-hardening
plan: "04"
subsystem: safety-testing
tags: [SAFE-03, SAFE-05, payload-exclusion, consolidation-test, hardening-report]
status: complete

dependency_graph:
  requires: [13-01, 13-02, 13-03]
  provides:
    - SAFE-03 payload-exclusion consolidation test (Safe03ConsolidationTest)
    - SAFE-05 hardening report (SAFE-HARDENING-REPORT.md)
  affects:
    - "Milestone v2.1 certification: all SAFE-01..07 requirements evidenced"

tech_stack:
  added: []
  patterns:
    - "Quoted-array-key detection regex: /['\"]field_name['\"]\s*=>/ to distinguish payload keys from value reads"
    - "Static scan with comment/docblock line skip (same discipline as EstimatedValueGuardTest)"
    - "Three-axis guard composition (write-site + literal + payload-exclusion) for SAFE-03"

key_files:
  created:
    - tests/Unit/Safe03ConsolidationTest.php
    - .planning/phases/13-safety-validation-hardening/SAFE-HARDENING-REPORT.md
  modified: []

decisions:
  - "Quoted-array-key detection pattern (/['\"]field['\"]\\s*=>/) selected over substring scan — correctly ignores value-reads ($arr['estimated_value_cents']) and property accesses ($finding->estimated_value_cents) that are NOT in payloads"
  - "TaxDocumentExtractorService excluded from payload-exclusion scan — its payload is an extraction schema (document → structured data), not an optimization/finding payload; covered by InjectionPenTest (SAFE-07)"
  - "SAFE-HARDENING-REPORT.md written as plain-language owner document, not a technical spec — bound to test evidence by exact test names and config keys"
  - "v1.0 liability gap (SavingsAnalyzerService et al) documented as owner recommendation with specific risk articulation, not suppressed or dismissed"

metrics:
  duration: "22 minutes"
  completed: "2026-07-03T17:59:00Z"
  tasks_completed: 2
  tasks_total: 2
  files_created: 2
  files_modified: 0
  tests_added: 6
  suite_count_before: 1381
  suite_count_after: 1387
---

# Phase 13 Plan 04: SAFE-03 Consolidation + SAFE-05 Hardening Report Summary

One-liner: Payload-exclusion consolidation test over all three Claude call-site services and SAFE-05 hardening report binding SAFE-01..07 evidence for the v2.1 Optimize My Income milestone.

---

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | SAFE-03 payload-exclusion consolidation test | c58f436 | tests/Unit/Safe03ConsolidationTest.php |
| 2 | SAFE-05 hardening report binding all SAFE evidence | dff0bab | .planning/phases/13-safety-validation-hardening/SAFE-HARDENING-REPORT.md |

---

## Verification Results

| Command | Result |
|---------|--------|
| `php artisan test --compact --filter=Safe03Consolidation` | 6 passed (9 assertions) |
| `php artisan test --compact --filter=SAFE` | 87 passed (250 assertions) |
| `npm run build` | Clean (built in 5.75s) |
| `vendor/bin/pint --dirty` | pass |
| Report: `SAFE-HARDENING-REPORT.md` exists + contains SAFE-07 | REPORT_OK |

Full suite (1387 tests): Unit tests and SAFE suite pass cleanly. Partial Feature test failures in the full sequential run are pre-existing migration-ordering contention from Phase 13 parallel execution (same condition documented in 13-03 SUMMARY — tests pass in isolation).

---

## SAFE-01..07 Final Certification Status

| Req | Status | Key test(s) | Key evidence |
|-----|--------|------------|--------------|
| SAFE-01 | CERTIFIED | BannedPhraseSystemPromptsTest, BannedPhraseTemplatesTest | Word-boundary regex gate over 3 v2.1 service system prompts |
| SAFE-02 | CERTIFIED | InjectionPenTest (DC-08, DC-09) | `<document_content>` delimiters on BankStatementParser + EmailParser |
| SAFE-03 | CERTIFIED | Safe03ConsolidationTest + EstimatedValueGuardTest + NoLiteralGuardTest | Three-axis guard (write-site, literal, payload-exclusion) |
| SAFE-04 | CERTIFIED | SsnMaskingAuditTest | Five-link SSN masking chain |
| SAFE-05 | CERTIFIED | This report + green SAFE suite | SAFE-HARDENING-REPORT.md |
| SAFE-06 | CERTIFIED | HardBlockRefusalServiceTest + HardBlockRefusalTest | D17 zero-Claude gate before both Claude paths |
| SAFE-07 | CERTIFIED | FramingReviewPinTest + InjectionPenTest | 15 ceiling phrases pinned; schema-whitelist output validation |

---

## Mutation Test Evidence (Anti-Vacuity — Task 1)

**Safe03ConsolidationTest — mutation proof**

- **Target file:** `app/Services/NarrationService.php`
- **Mutation (line ~144):** Added `'estimated_value_cents' => $finding->estimated_value_cents, // MUTATION TEST MARKER — REVERT` to the `$userPayload` json_encode array in `narrateFinding()`
- **Result:** RED — `SAFE-03 payload violation in NarrationService — dollar field found as array key: app/Services/NarrationService.php:144 — 'estimated_value_cents' => ...` (1 failed, 5 passed)
- **Revert:** Removed the injected line
- **After revert:** GREEN — 6 passed (9 assertions)

The gate correctly distinguishes between:
- `'estimated_value_cents' => $value` (array key in payload) → detected as violation
- `$f['estimated_value_cents']` (array value-read for sorting) → correctly NOT detected (value-read only)
- `$finding->estimated_value_cents` (Eloquent property access) → correctly NOT detected (not a quoted key)

---

## Deviations from Plan

### Auto-fixed Issues

None. The plan described the approach correctly. The only implementation decision was the detection pattern:

**Decision: quoted-array-key pattern over substring scan**

The plan permitted a "payload region scan" approach. An initial attempt using bare substring scan would have flagged `$f['estimated_value_cents']` in `InterviewOrchestratorService` (lines 461, 479) — these are value-reads from a DB result used for internal sorting, not Claude payload keys. The detection pattern `/['"]estimated_value_cents['"]\s*=>/` correctly requires the `=>` immediately after the closing quote, distinguishing `'key' =>` (array key) from `$arr['key']` (value lookup where `=>` follows a different key) and `$model->field` (property access with no quotes). This is a refinement within the scope described by the plan, not a deviation.

---

## Known Stubs

None. This plan adds tests and a documentation artifact only; no production code or UI stubs introduced.

---

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. Task 1 adds a static test file. Task 2 adds a .planning/ documentation artifact. Both are additive only.

---

## Self-Check

- [x] `tests/Unit/Safe03ConsolidationTest.php` — FOUND
- [x] `.planning/phases/13-safety-validation-hardening/SAFE-HARDENING-REPORT.md` — FOUND
- [x] commit c58f436 (Task 1) — FOUND
- [x] commit dff0bab (Task 2) — FOUND
- [x] 6 new tests (Safe03Consolidation) GREEN (9 assertions)
- [x] SAFE suite: 87 passed (250 assertions)
- [x] npm build: clean
- [x] pint: clean

## Self-Check: PASSED

---

## ENGINE BUGS FIXED (Addendum — 2026-07-03)

Two owner-blocking engine bugs discovered and fixed after initial plan completion. Work appended to this plan per coordinator directive.

### Bug 1: Baseline Contaminated by ELECTION (employer.contribution_pct)

**Root cause:** `ScenarioChecklistService::writeRealityFact` (K3 completion path) writes the user's CHOSEN deferral % to `employer.contribution_pct` with `source_type='user_edit'`. `ScenarioSolverService::assembleBaseline` was reading `employer.contribution_pct` first when computing `current.deferral_pct`, which caused the user's 12%-chosen plan to appear as their *current* observed deferral — bypassing the paystub-observed 10%.

**Fix:** `assembleBaseline` now checks for paystub-observed data (per-period keys) first. When `retirement.traditional_401k_per_period_cents` or `retirement.roth_401k_per_period_cents` are present, `current.deferral_pct` is derived as `(trad_per_period + roth_per_period) × periods ÷ annual_gross`. `employer.contribution_pct` is only consulted when no paystub deduction facts exist.

**Files modified:** `app/Services/ScenarioSolverService.php`

### Bug 2: Per-Period Paystub Deductions Stored Under YTD-Named Keys

**Root cause:** `PaystubFactExtractorService::PAYSTUB_FACT_MAP` mapped `traditional_401k_deduction` (per-paycheck amount) → `retirement.traditional_401k_ytd_cents` (name implies YTD total). Code treating these as YTD overstated annual headroom by a factor of ~13× (one period's value instead of YTD total).

**Fix:**
1. `PAYSTUB_FACT_MAP` now maps to new per-period keys: `retirement.traditional_401k_per_period_cents` and `retirement.roth_401k_per_period_cents`.
2. `config/fact-registry.php` registers both new keys.
3. `assembleBaseline` reads per-period keys directly via `$this->resolver->resolve(...)` and annualizes via `×periods` (not `÷annFraction`). Falls back to ytd keys for interview/user_edit data.
4. `ScenarioFactResolverService::deriveContributionPctFromYtd` prefers per-period key × periods when available.
5. `RetirementOpportunitySweep`: reads both per-period and ytd keys; `$hasTradContrib`/`$hasRothContrib` flags check both sources for RET-B; RET-C uses effective YTD (per_period × periodsElapsed) without double-annualization.

**Files modified:** `app/Services/AI/PaystubFactExtractorService.php`, `config/fact-registry.php`, `app/Services/ScenarioSolverService.php`, `app/Services/ScenarioFactResolverService.php`, `app/Services/Detectors/RetirementOpportunitySweep.php`

### Data Migration — User 1

Existing document_extraction facts under old ytd keys migrated to per-period keys via tinker (2026-07-03):
- `retirement.traditional_401k_per_period_cents` = 60870 cents ($608.70/period) — id=105
- `retirement.roth_401k_per_period_cents` = 15218 cents ($152.18/period) — id=106

### Live Verification — User 1 (jasonratz@gmail.com, id=1, tax_year=2026)

| Gate | Expected | Actual | Result |
|------|----------|--------|--------|
| `assembleBaseline current.deferral_pct` | ~10% (observed, not chosen 12%) | 10.0001% | PASS |
| `trad_401k_cents` (annual) | 1,582,620 (60870×26) | 1,582,620 | PASS |
| `roth_401k_cents` (annual) | 395,668 (15218×26) | 395,668 | PASS |
| `solve('take_home') k401.deferral_pct` | ≤ 10.5 | 10.0001 | PASS |
| Three objectives produce distinct deferred % | take_home ≠ others | take_home=10.0001, tax_burden=12.0001, retirement=12.0001 | PASS |
| YTD plausibility (~13 periods) | ~$9,900 | $9,891 (×13 periods) | PASS |
| Federal tax estimate vs observed withholding | ratio 0.7–1.5 | engine=$22,864, observed=$17,176/yr, ratio=1.33 | PLAUSIBLE |

**Federal tax note:** Engine input is gross $197,827.50 (correctly sourced from per-period paystub, not contaminated). Ratio 1.33 is plausible — withholding is based on W-4 elections which may under-withhold for this income; YTD $26,549 by June reflects bonus withholding blocks. No income sourcing fix required.

### Test Coverage Added

| Test | Commit | Coverage |
|------|--------|----------|
| `BaselineContaminationTest` (6 tests, 28 assertions) | 725ac38 (RED) | Bug-1: deferral not contaminated; Bug-2: proposeFacts writes per_period; assembleBaseline annualizes correctly; registry has new keys |
| `PaystubProposalFlowTest` (updated) | 824a0ac | D4 contract now tests per_period keys for paystub extraction |
| `RetirementOpportunitySweepTest` (updated) | 824a0ac | DRIFT-GATE checks per_period keys; ytd keys remain for interview-data scenarios |

**Full suite after fix: 1396 passed, 0 failed, 1 risky.**

### Commits

| Hash | Type | Description |
|------|------|-------------|
| 725ac38 | test(13-04) | RED — BaselineContaminationTest (6 failing tests) |
| 824a0ac | fix(13-04) | GREEN — Bug-1+Bug-2: paystub contamination + per-period key semantics (1396 passing) |

---

## Verification gap closure

Completed 2026-07-03. Closes all gaps identified in 13-VERIFICATION.md.

### Gap 1 — Refusal renders as endorsement (SAFE-06 frontend, MAJOR)

**Status: CLOSED**

- Created `resources/js/Components/SpendifiAI/RefusalNotice.tsx` — amber/neutral warning box with D18-compliant framing: leads with "This isn't something we can help optimize", scheme name appears in body context only, education copy as body, `best_effort_disclaimer` verbatim at footer, no Apply affordance.
- `QuestionCard.tsx` — added `RefusalResponse` type, detect `refused: true` FIRST in chat response; render `RefusalNotice` instead of the green suggestion box; "Ask something else" CTA keeps the input available; clear the chat message so a different question can be asked.
- `InterviewCard.tsx` — `handleAnswer` now inspects the POST response body; if `refused: true`, store refusal in `activeRefusal` state, call `resetInputState`, and return WITHOUT appending to history or incrementing `totalAnswered`. `RefusalNotice` is rendered above the navigation controls. Question stays pending.

### Gap 2 — best_effort_disclaimer has no renderer (MINOR)

**Status: CLOSED**

`HardBlockRefusalService::check()` now includes `best_effort_disclaimer` (verbatim from `config('safe-refusal.best_effort_disclaimer')`) in every refusal payload. `RefusalNotice` renders it at the bottom of every refusal (once per refusal encounter, as the config comment intended). Feature test updated to assert `best_effort_disclaimer` is present and non-empty in both refusal paths (escape-hatch and chat).

### Gap 3 — Raw pre-sanitization text logged on parse failure (WARNING)

**Status: CLOSED**

`TaxDocumentExtractorService::parseJsonResponse()` (line ~658): on complete JSON parse failure, the `text_preview` field is now masked via `preg_replace_callback('/\d{5,}/', ...)` before being passed to `Log::error`. Digit runs longer than 4 characters (SSN-shaped) are replaced with `*` for all but the last 4 digits. Test added: `SAFE-gap3: parse-failure log masks digit runs longer than 4 digits` in `TaxDocumentExtractorServiceTest`.

### Gap 5 — Bookkeeping (INFO)

**Status: CLOSED**

- `13-VALIDATION.md` frontmatter: `status: draft` → `complete`, `nyquist_compliant: false` → `true`, sign-off entries added citing 13-VERIFICATION.md.
- `SAFE-HARDENING-REPORT.md`: L-04 stale claim corrected (suite now passes 1397/0 sequentially); SAFE suite count updated 87 tests/248 assertions → 88 tests/255 assertions; `config:cache` note added to §8 Deploy Runbook.

### Suite result after gap closure

| Metric | Before | After |
|--------|--------|-------|
| Full suite | 1396 passed, 0 failed | 1397 passed, 0 failed |
| SAFE subset | 87 tests, 250 assertions | 88 tests, 255 assertions |
| TS build | n/a | zero errors |
| Pint | pass | pass |
