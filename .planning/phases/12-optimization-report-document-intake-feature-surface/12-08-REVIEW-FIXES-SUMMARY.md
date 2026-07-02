---
phase: "12"
plan: "08"
subsystem: optimize-my-income
tags: [review-fixes, ux, interview, throttle, narration]
status: complete

key-files:
  modified:
    - resources/js/Pages/Optimize/Index.tsx
    - app/Services/NarrationService.php
    - app/Services/OptimizationReportNarratorService.php
    - app/Services/InterviewOrchestratorService.php
    - routes/api.php
  created:
    - tests/Feature/OptimizerReportThrottleTest.php

decisions:
  - "Findings card: clamp description to first sentence client-side (no re-narration cost); expand chevron one-at-a-time"
  - "Prompt brevity: EXACTLY 2 sentences / ~40 words, lead with actionable insight — applies to future narrations only"
  - "Interview queue ordering: auto → conditional → battery (specialist stays out — pro-review section only)"
  - "Stale session self-heal: rebuild queue on resume if empty AND findings exist; exclude already-asked keys"
  - "Throttle split: GET show 60/min, regenerate/download/pro-review-export 5/min (independent rate counters)"
  - "Polling: 8s interval while generating, clears when ready; 429 on regenerate shows cached data + gentle note"

metrics:
  duration: "~40 minutes"
  completed: "2026-07-02"
  tasks: 3
  files_changed: 7
  new_tests: 8

commits:
  - hash: "229f07f"
    message: "fix(12-08): findings cards — clamp narration to first sentence with expand chevron"
  - hash: "eea55e7"
    message: "fix(12-08): interview queue — include conditional findings + stale-session self-heal"
  - hash: "c29d429"
    message: "fix(12-08): 429 throttle split + frontend refetch hardening"
---

# Phase 12 Plan 08: Owner Live-Review Fixes Summary

Owner review of /optimize surface surfaced three diagnosed production issues. All three fixed atomically with per-task commits.

---

## Task 1 — Findings cards: truncate narration with expand/collapse

**Root cause:** 17 findings × ~505-char descriptions rendered fully in card bodies. Users won't read walls of text.

**Fix (display-side only, no re-narration cost):**
- `firstSentence()` helper clips description at the first sentence-ending punctuation or 140 chars, whichever is shorter, with ellipsis.
- `FindingSummaryCard` shows clipped text; "Read more / Show less" chevron button toggles the full description.
- One open at a time: `expandedFindingId` state lifted to `OptimizeIndex`, shared across all section cards.
- `NarrationService.SYSTEM_PROMPT`: tightened from "1–3 sentences" to "EXACTLY 2 sentences, ~40 words, lead with actionable insight first" (applies to future narrations).
- `OptimizationReportNarratorService.SYSTEM_PROMPT`: same brevity constraint.
- `NarrationServiceTest`: added `system_prompt instructs max 2 sentences and ~40 words brevity` test.

**taste-skill v2 audits:**
1. Token audit: `ChevronDown`/`ChevronUp` icons added; all new elements use `sw-*` tokens. Pass.
2. Typography audit: expand button 11px `font-medium`, description 12px `leading-relaxed`. Pass.
3. Spacing audit: expand button sits in existing `pl-3 border-l-2` container, 4px rhythm preserved. Pass.
4. Interaction audit: `hover:text-sw-text transition-colors`, `focus-visible:ring-2 ring-sw-accent/50` focus state on button. Pass.

---

## Task 2 — Interview says "No questions yet" — conditional findings excluded from queue

**Root cause:** `buildInitialQueue()` included only `band='auto'` + `finding_type='battery_question'` findings. The owner's 17 findings are `band='conditional'` (16) + `band='specialist'` (1). `conditional` findings are the interview's core purpose — they need user answers to resolve. Empty queue → "No questions yet."

**Fix:**
- `buildInitialQueue`: queue order is now **auto → conditional (non-battery) → battery**. `specialist` band excluded (belongs to professional-review section).
- `startOrResume`: if existing session's queue is empty AND eligible findings now exist → rebuild queue, excluding keys already in `asked[]`. Logs a `stale-queue self-healed` info message. Repairs the owner's stale session created during the pipeline outage.
- 5 new tests added to `InterviewOrchestratorServiceTest`:
  - `conditional_findings_in_queue` — band=conditional non-battery findings enter the queue
  - `conditional_ordering` — auto comes before conditional
  - `specialist_findings_excluded_from_queue` — specialist band stays out
  - `stale_queue_self_heal` — empty queue + conditional findings → resume yields questions
  - `stale_queue_self_heal_skips_already_asked_keys` — no re-asking answered questions
- FLAG-27 battery-bridge test (`SweepsAndScannersTest`) still green — ordering guarantee preserved.

---

## Task 3 — 429 Too Many Requests + refetch storms

**Root cause:** `Route::prefix('optimizer/report')->middleware('throttle:5,1')` applied a 5-request/minute rate limit to ALL routes including `GET show`. The page's generating-state polling and tab switches burned through this budget rapidly.

**Fix — Routes (`routes/api.php`):**
- Removed group-level `middleware('throttle:5,1')`.
- `GET /{year}` → `middleware('throttle:60,1')` (read-only poll, safe to call frequently).
- `POST /{year}/regenerate`, `GET /{year}/download`, `POST /finding/{id}/pro-review-export` → `middleware('throttle:5,1')` (dispatching/expensive endpoints unchanged).

**Fix — Frontend (`Optimize/Index.tsx`):**
- Added `useEffect` polling loop (8s interval) while `report.status === 'generating'`; cleared automatically when status flips to `ready`. Uses `useRef` for cleanup on unmount.
- On 429 from `POST regenerate`: catches error, sets `rateLimited` flag, shows "Refreshing shortly" banner with cached data — never a hard error state.
- Error display: hard error block shows only when no cached report data (`!report`); cached data is shown even when a subsequent poll errors.
- Report data cached in React state via `useApi` — does not refetch on tab switches.

**Route test (`OptimizerReportThrottleTest`):**
- 3 tests: GET show does not 429 on 6 rapid requests; POST regenerate still limits at 6th; throttles are independent.

---

## Gates

| Gate | Result |
|------|--------|
| `npm run build` zero TS errors | PASS |
| `php artisan test --compact` zero NEW failures | PASS (1 pre-existing DashboardFinancialBlocksTest) |
| `vendor/bin/pint --dirty` | PASS |
| Banned-phrase tests still green | PASS (9/9 NarrationServiceTest) |
| FLAG-27 battery-bridge test | PASS |

---

## Deviations from Plan

None — plan executed exactly as diagnosed.

## Self-Check: PASSED
- `resources/js/Pages/Optimize/Index.tsx`: exists and contains `firstSentence`, `expandedFindingId`, `pollRef`
- `app/Services/NarrationService.php`: SYSTEM_PROMPT contains "EXACTLY 2 sentences"
- `app/Services/OptimizationReportNarratorService.php`: SYSTEM_PROMPT contains "EXACTLY 2 sentences"
- `app/Services/InterviewOrchestratorService.php`: buildInitialQueue contains `conditionalFindings` query
- `routes/api.php`: GET show has `throttle:60,1`, regenerate has `throttle:5,1`
- `tests/Feature/OptimizerReportThrottleTest.php`: exists, 3 tests pass
- Commits 229f07f, eea55e7, c29d429 present in git log
