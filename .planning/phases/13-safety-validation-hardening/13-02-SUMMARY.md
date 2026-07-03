---
phase: 13-safety-validation-hardening
plan: "02"
subsystem: safety
tags: [safe-06, hard-block-refusal, irs-dirty-dozen, zero-claude, escape-hatch, chat-gate]
dependency_graph:
  requires: [13-01]
  provides: [SAFE-06]
  affects: [InterviewOrchestratorService, AIQuestionController, config/safe-refusal.php]
tech_stack:
  added:
    - "config/safe-refusal.php — 11 IRS Dirty Dozen keyword clusters with phrases[], category, education copy"
    - "HardBlockRefusalService — zero-Claude phrase detector (mb_strtolower + str_contains)"
    - "HardBlockRefusalException — carries refusal payload; render() returns HTTP 200 JSON"
  patterns:
    - "Gate-before-Claude: check() runs BEFORE any Anthropic call on hot paths"
    - "Refuse-and-educate: what/why only from config copy, never how-to from model"
    - "D17 zero-Claude assertion: Http::preventStrayRequests() + assertNothingSent() in tests"
key_files:
  created:
    - config/safe-refusal.php
    - app/Services/HardBlockRefusalService.php
    - app/Exceptions/HardBlockRefusalException.php
    - tests/Unit/HardBlockRefusalServiceTest.php
    - tests/Feature/HardBlockRefusalTest.php
  modified:
    - app/Services/InterviewOrchestratorService.php
    - app/Http/Controllers/Api/AIQuestionController.php
decisions:
  - "HardBlockRefusalService uses app() in InterviewOrchestratorService (same namespace — no import needed after pint); constructor-injected into AIQuestionController (additive param)"
  - "HardBlockRefusalException::render() returns HTTP 200 (not 4xx) — refusal is a deliberate policy response, not a client error"
  - "Education copy sourced from config only (RPT-06 lineage) — Claude never generates refusal text"
  - "Phrases are multi-word n-grams per Pitfall 5: 'abusive foreign trust' blocks; bare 'trust' does not"
metrics:
  duration: "~60 minutes (context continuation from prior session)"
  completed_date: "2026-07-03"
  tests_added: 79
  tests_total: 1381
status: complete
---

# Phase 13 Plan 02: SAFE-06 Hard-Block Refusal Enforcement Summary

**One-liner:** Config-driven zero-Claude IRS Dirty Dozen phrase detector wired as gate before both Claude call sites (escape-hatch answer path and AI question chat path), returning structured refuse-and-educate payload on match.

## What Was Built

### config/safe-refusal.php

11 IRS Dirty Dozen keyword clusters, each with:
- `category` — human-readable scheme label
- `phrases[]` — multi-word n-gram triggers (lowercase, ASCII+Unicode variants)
- `education` — what/why copy (never how-to; sourced from RPT-06 lineage)

Plus two top-level keys:
- `best_effort_disclaimer` — per-session safeguard disclosure
- `anti_waste_principle` — no output may present spending-to-create-deductions as net savings

**Clusters covered:**
1. 831(b) Micro-Captive Insurance
2. Syndicated / Façade Conservation Easements
3. Offshore Concealment / FBAR-FATCA
4. Malta Pension / Abusive Foreign Trust
5. Nonprofit-as-Personal-Shelter (Section 4958)
6. Corporation Sole / Pure Trust Packages
7. "Start a Ministry" Structures
8. Crypto Non-Reporting
9. Cash Structuring / Smurfing
10. PPLI / Offshore Crypto-IRA
11. Hess-Style Body-Modification Deduction Probes

### app/Services/HardBlockRefusalService.php

```php
public function check(string $userText): ?array
```

- `mb_strtolower` input once, `str_contains` against each cluster's phrases
- Returns `{refused: true, category, education, blocked_reason: 'hard_block_safe06'}` on first match
- Returns `null` when text is clear
- Zero HTTP/Anthropic calls — pure phrase matching (D17 zero-Claude constraint)
- No constructor dependencies on any Claude-calling service

### app/Exceptions/HardBlockRefusalException.php

- Carries refusal array; `render(Request $request): JsonResponse` returns HTTP 200 JSON
- Used by the escape-hatch path — thrown in `InterviewOrchestratorService::recordAnswer()` and handled by Laravel's exception handler without any controller change (additive)

### Wiring Points

**Escape-hatch (InterviewOrchestratorService::recordAnswer):**
```php
// SAFE-06: hard-block gate — refuse-and-educate BEFORE any Claude call.
$refusal = app(HardBlockRefusalService::class)->check($freeText);
if ($refusal !== null) {
    throw new HardBlockRefusalException($refusal);
}
$storedValue = $this->interpretEscapeAnswer($template, $freeText); // ← gate before this
```

**Chat (AIQuestionController::chat):**
```php
// SAFE-06 gate — intercepts before any Claude call.
$refusal = $this->refusalGate->check($request->validated('message'));
if ($refusal !== null) {
    return response()->json($refusal, 200);
}
$suggestion = $this->categorizer->interpretUserResponse(...); // ← gate before this
```

## TDD Commits

| Phase | Commit | Description |
|-------|--------|-------------|
| RED (unit) | f70e7ea | 75 unit tests — abusive corpus (30 entries), legitimate corpus (33 entries), D17 zero-call, config shape |
| GREEN (unit) | 34a15ad | HardBlockRefusalService + config/safe-refusal.php + HardBlockRefusalException |
| RED (feature) | ed3c307 | 4 feature tests — escape-hatch abusive/legitimate, chat abusive/legitimate |
| GREEN (feature) | febaf0b | Wire gate before both Claude call sites |

## Verification Results

```
php artisan test --filter=HardBlockRefusal      → 79 passed (371 assertions)
php artisan test --filter=NoClaudeScenario      → 12 passed (D17 zero-call: still green)
php artisan test --filter=ObjectiveEnqueue      → (subset of above)
php artisan test --compact                      → 1381 passed, 1 risky, 0 failed
vendor/bin/pint --dirty                         → pass (clean)
```

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed 4 abusive-corpus phrase mismatches in unit tests**
- **Found during:** Task 1 RED debugging
- **Issues:**
  - FBAR cluster: had `'not file fbar'` but test used `"don't file fbar"` — added `"don't file fbar"`, `'dont file fbar'`
  - FATCA cluster: had `'fatca conceal'` (wrong word order) — added `'conceal foreign account'`, `'conceal a foreign account'`
  - Nonprofit cluster: missing phrase for `"use a nonprofit as a shelter"` — added `'nonprofit as a shelter'`
- **Fix:** Added missing phrases to config/safe-refusal.php
- **Commit:** 34a15ad (included in GREEN)

**2. [Rule 1 - Bug] Fixed false positive in legitimate corpus**
- **Found during:** Task 1 RED debugging
- **Issue:** `'below the reporting threshold'` matched legitimate "freelance income below reporting threshold for 1099s"
- **Fix:** Removed over-broad phrases; kept `'below $10,000 to avoid'` and `'under 10000 to avoid'`
- **Commit:** 34a15ad (included in GREEN)

**3. [Rule 1 - Bug] Fixed Pest toContain/toHaveKey API misuse**
- **Found during:** Task 1 RED phase (39 tests failing initially)
- **Issue:** `expect($str)->toContain($val, $message)` — Pest treats second arg as expected value
- **Fix:** Changed to two-step lowercase compare: `expect(mb_strtolower($category))->toContain(mb_strtolower($expected))`

**4. [Rule 1 - Bug] Pint removed redundant use import**
- **Found during:** GREEN pint run
- **Issue:** `use App\Services\HardBlockRefusalService;` in `InterviewOrchestratorService` was removed by pint (`no_unused_imports` fixer) because same-namespace references don't require explicit imports; `app(HardBlockRefusalService::class)` resolves to `App\Services\HardBlockRefusalService` automatically
- **Fix:** Let pint remove the import — behavior unchanged

## D17 Zero-Claude Gate Compliance

All abusive-text tests assert:
- `Http::preventStrayRequests()` active
- `Http::assertNothingSent()` passes
- Response has `refused: true`, `blocked_reason: 'hard_block_safe06'`, `category`, `education`

No Anthropic HTTP request is made on the detection path. The gate is pure phrase matching.

## Known Stubs

None. All cluster education text is sourced from config (RPT-06 lineage). No placeholder text.

## Threat Flags

None. No new network endpoints, auth paths, or file access patterns introduced. All changes are additive pre-filter gates on existing authenticated routes.

## Self-Check: PASSED

- config/safe-refusal.php: exists ✓
- app/Services/HardBlockRefusalService.php: exists ✓
- app/Exceptions/HardBlockRefusalException.php: exists ✓
- tests/Unit/HardBlockRefusalServiceTest.php: exists ✓
- tests/Feature/HardBlockRefusalTest.php: exists ✓
- Commit f70e7ea: exists ✓
- Commit 34a15ad: exists ✓
- Commit ed3c307: exists ✓
- Commit febaf0b: exists ✓
- Full suite: 1381 passed, 0 failed ✓
