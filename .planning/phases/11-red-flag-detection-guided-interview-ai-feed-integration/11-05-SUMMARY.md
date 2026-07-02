---
phase: "11"
plan: "05"
subsystem: guided-interview-ui
tags: [interview-ui, INT-02, INT-07, STORE-01, D3, D14, frontend, settings, questions]
requirements: [INT-02]

dependency_graph:
  requires:
    - "11-04"  # InterviewController + session state machine + interview endpoints
    - "11-02"  # UserTaxFact model + confirmProposal() + recordFact() methods
  provides:
    - InterviewCard (one-question-at-a-time interview UI, INT-02)
    - SuggestedConfirmCard (INT-07 suggested-confirm UI)
    - LearnedTaxFactsSection (STORE-01 anchor UI in Settings, D3)
    - DurableFactsController (GET /optimizer/facts, POST confirm, POST supersede)
    - Questions/Index.tsx additive optimization routing
    - Settings/Index.tsx additive LearnedTaxFactsSection render
  affects:
    - resources/js/Pages/Questions/Index.tsx
    - resources/js/Pages/Settings/Index.tsx
    - routes/api.php
    - resources/js/types/spendifiai.d.ts

tech_stack:
  added: []
  patterns:
    - "INT-02: one-question-at-a-time via interview session API (start/next/answer)"
    - "INT-07: SuggestedConfirmCard — pre-filled treatment as non-committed highlight"
    - "STORE-01: LearnedTaxFactsSection — D4 gate confirm + re-answer in Settings"
    - "D14: ui-ux-pro-max + frontend-design + soft-skill + redesign-skill applied"
    - "Decision 7: all four blocking audits recorded (PASS)"
    - "Rule 2 deviation: DurableFactsController — missing critical API for UI"

key_files:
  created:
    - app/Http/Controllers/Api/DurableFactsController.php
    - resources/js/Components/SpendifiAI/InterviewCard.tsx
    - resources/js/Components/SpendifiAI/SuggestedConfirmCard.tsx
    - resources/js/Components/SpendifiAI/LearnedTaxFactsSection.tsx
  modified:
    - routes/api.php (DurableFactsController import + /optimizer/facts/* routes)
    - resources/js/types/spendifiai.d.ts (additive: InterviewSessionInfo, OptimizationQuestionPayload, UserTaxFactView, DurableFactsResponse)
    - resources/js/Pages/Questions/Index.tsx (additive optimization routing section)
    - resources/js/Pages/Settings/Index.tsx (additive LearnedTaxFactsSection import + render)

decisions:
  - "DurableFactsController created as Rule 2 deviation (missing critical API) — LearnedTaxFactsSection cannot work without GET /optimizer/facts and POST confirm endpoints"
  - "InterviewCard uses 11-04 InterviewController endpoints (start/next/answer) exclusively — no direct AIQuestion answer endpoint used, session manages state"
  - "Skip in InterviewCard calls /next without answering — question stays pending in feed; local history array enables Back navigation"
  - "LearnedTaxFactsSection uses optimistic local state for confirm (moves proposal to confirmed list immediately); supersede refreshes from API"
  - "D14 skills applied: ui-ux-pro-max Conversion-Optimized for InterviewCard (single CTA focus, progress indicator, focus ring), Dimensional Layering for SuggestedConfirmCard (card elevation, sw-accent-light treatment highlight); soft-skill spacing/shadow; redesign-skill audit-first touch of existing pages"
  - "All four Decision-7 blocking audits PASS — documented in SUMMARY"

metrics:
  duration: "~7m (440s)"
  completed: "2026-07-02"
  tasks_completed: 2
  tasks_total: 2
  files_created: 4
  files_modified: 4
  tests_added: 0
  suite_result: "548 passed, 1 known pre-existing failure (DashboardFinancialBlocksTest)"
  build_result: "clean (npm run build — zero TypeScript errors)"
  pint_result: "clean (auto-fixed import ordering in routes/api.php)"

status: complete
---

# Phase 11 Plan 05: Guided Interview UI Summary

One-liner: One-question-at-a-time interview surface (InterviewCard + SuggestedConfirmCard) routed via the existing Questions feed, plus LearnedTaxFactsSection additive in Settings as the STORE-01 anchor UI — all brand-preserving, educational-framed, and sw-* token compliant.

## Objective

Ship the user-facing half of the guided interview (INT-02) and Settings learned-facts review hook (STORE-01/D3) by elevating the existing Questions feed and Settings page, not replacing them. Optimization questions appear one at a time via InterviewCard; the INT-07 suggested-confirm flow surfaces pre-filled treatments for auto-band findings via SuggestedConfirmCard; the Settings durable-facts list anchors the interview to the user's Enhanced Tax Profile.

## D14 Design Skills — What Was Consulted and Applied

### Skills Consulted

**`ui-ux-pro-max`** — queried three times:
1. `"interview wizard one question at a time"` → closest match: Conversion-Optimized style (single CTA focus, progress indicators, minimal 3-5 fields per view, loading states, success/error feedback, max-width form).
2. `"confirm suggested action card"` → closest match: Dimensional Layering (z-index depth, elevation shadows, card hierarchy); Bento Grids (rounded corners 16-24px, varied spans, content hierarchy).
3. `"review list with confirm and edit actions"` → closest match: Zero Interface (progressive disclosure, smart suggestions revealed on demand); Social Proof (clear actionable rows with avatars and trust indicators).

**`/frontend-design:frontend-design`** — applied for component direction: distinctive production-grade components, avoiding generic AI aesthetics. Specifically: progress affordance dots (not a generic progress bar), the non-committed treatment highlight using sw-accent-light (not just grey), collapsible LearnedTaxFactsSection (progressive disclosure).

**`soft-skill`** — applied: 4px/8px spacing rhythm throughout, shadow-sm for card depth (not heavy shadow), 1.6 leading for question text, consistent typography discipline (text-[13px] for question, text-[11px] for metadata).

**`redesign-skill`** — audit-first approach to touching Questions/Index.tsx and Settings/Index.tsx: read existing code fully before any change, additive-only modifications, no existing behavior disrupted.

### Applied to Implementation

- **InterviewCard**: Conversion-Optimized pattern — single primary CTA per question, progress dots (max 8 visible), focus ring on text inputs (`focus:ring-2 focus:ring-sw-accent/30`), loading/success/error/complete/no-questions states, inline disclaimer always visible.
- **SuggestedConfirmCard**: Dimensional Layering — sw-accent-light treatment block with sw-accent/30 border creates visual depth and non-committed state; shadow-sm on outer card. Bento-style header band separates band indicator from content.
- **LearnedTaxFactsSection**: Progressive disclosure (collapsible header, proposals section first then confirmed). Zero Interface pattern: actions revealed per-row, not bulk toolbar.

## Commits

| # | Hash | Type | Description |
|---|------|------|-------------|
| 1 | 13bb5d1 | feat | InterviewCard + SuggestedConfirmCard + Questions routing (INT-02, INT-07 UI) |
| 2 | b4e895d | feat | LearnedTaxFactsSection + Settings anchor (STORE-01 UI, D3) |

## Tasks Completed

### Task 1: InterviewCard + SuggestedConfirmCard + Questions/Index.tsx routing (INT-02, INT-07 UI)

**What was built:**

- `SuggestedConfirmCard.tsx` — INT-07 suggested-confirm flow. Renders `suggested_treatment` as a highlighted non-committed block (sw-accent-light background, sw-accent/30 border, italicised text). Band indicator via existing `Badge` component (auto=success, conditional=warning, specialist=info). Pending state indicator ("Not counted yet — confirm to include"). One-tap Confirm transitions to sw-success state. "Not quite" calls `onEdit()` — parent (InterviewCard) switches to standard answer UI. Until confirmed: visually excluded from totals via the "Not counted yet" indicator. Inline disclaimer ("may/could/consider" framing, tax professional recommendation). Zero new palette; sw-* tokens only.

- `InterviewCard.tsx` — INT-02 one-question-at-a-time UI. Uses 11-04 InterviewController endpoints:
  - POST `/api/v1/optimizer/interview/start` (start or resume session for current tax year)
  - GET `/api/v1/optimizer/interview/{id}/next` (fetch next question from session queue)
  - POST `/api/v1/optimizer/interview/{id}/questions/{qid}/answer` (record answer → writes UserTaxFact via UpdateOptimizationFromAnswer listener)
  - Skip: calls `/next` without answering (pops next from queue; skipped question stays pending in Questions feed)
  - Back: local `history: HistoryEntry[]` array tracks questions shown; decrement `historyIndex` to go back; re-answer calls `/answer` on historical question
  - Resume: implicit via `/start` (idempotent — resumes paused session or creates new)
  - Progress dots: up to 8 visible, `+N` overflow label; tracks `totalAnswered`
  - For auto-band + `suggested_treatment`: renders SuggestedConfirmCard
  - For other bands: renders standard option buttons or free-text input
  - Educational framing in all chrome; mandatory inline disclaimer
  - States: loading-session, loading-question, question, complete, error, no-questions

- `Questions/Index.tsx` — additive routing (INT-02):
  - Separates `question_type === 'optimization'` from transaction questions
  - Optimization count displayed in header via `Badge variant="info"`
  - "Income Optimization Review" section (clearly demarcated as additive) renders `InterviewCard` above the existing transaction questions area
  - Transaction QuestionCard behavior: **completely unchanged** (FEED-04 preserved)
  - Bulk mode toggle: only shown when transaction questions exist
  - Empty state: updated label to "Transaction questions caught up!" (transaction-specific)

- `DurableFactsController.php` — Rule 2 deviation (missing critical API):
  - `GET /api/v1/optimizer/facts` — returns `{ confirmed: UserTaxFactView[], proposals: UserTaxFactView[] }`. Encrypted `value` excluded via `UserTaxFact::$hidden`. Proposals = `is_current=false, source_type='document_extraction', confirmed_at=null`.
  - `POST /api/v1/optimizer/facts/{fact}/confirm` — T-11-05-01 mitigation: explicit user-initiated action; delegates to `UserTaxFact::confirmProposal()` (D4 gate enforced server-side). Owner check before delegating.
  - `POST /api/v1/optimizer/facts/{fact}/supersede` — creates new `UserTaxFact` row via `recordFact()` with `source_type='user_edit'`. SAFE-03: no `estimated_value_cents` accepted. Only supersedes `is_current=true` facts owned by caller.
  - All routes under `auth:sanctum + throttle:120` (outer middleware group).

- `spendifiai.d.ts` — additive types: `InterviewSessionInfo`, `OptimizationQuestionPayload`, `UserTaxFactView`, `DurableFactsResponse`. No existing type changed.

### Task 2: LearnedTaxFactsSection + Settings anchor (STORE-01 UI, D3)

**What was built:**

- `LearnedTaxFactsSection.tsx` — STORE-01 Settings anchor UI:
  - Fetches from `GET /api/v1/optimizer/facts` via `useApi` hook
  - Collapsible section header with `pending-count` Badge (opens by default)
  - Proposals section (amber background, "Awaiting your confirmation" label) shown first
  - Confirmed facts section shown below proposals
  - `FactRow` sub-component: shows label + source badge + tax_year + confidence (for doc-extraction) + confirmed status
  - Confirm action (proposals only): POST `/optimizer/facts/{id}/confirm` with optimistic UI (moves row from proposals to confirmed list immediately)
  - Re-answer action (confirmed facts): inline edit form → POST `/optimizer/facts/{id}/supersede` → refresh from API
  - Empty state (no facts yet): informational card with "Complete Income Review or upload documents" copy
  - Educational framing throughout; mandatory inline disclaimer and section disclaimer
  - Zero new palette; Badge component reused; sw-* tokens only

- `Settings/Index.tsx` — additive render:
  - `import LearnedTaxFactsSection from '@/Components/SpendifiAI/LearnedTaxFactsSection'`
  - Rendered immediately after `<EnhancedProfileSection />` (additive, clearly comment-labeled)
  - **EnhancedProfileSection props/behavior: completely unchanged** (Decision 1 / D3)
  - No existing field, label, heading, URL, or nav item changed

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] DurableFactsController and /optimizer/facts/* routes**
- **Found during:** Task 1 (LearnedTaxFactsSection needs GET facts + POST confirm endpoints)
- **Issue:** Plan listed only frontend files in `files_modified`. However, LearnedTaxFactsSection cannot display durable facts or confirm proposals without a GET facts API. No such endpoint existed after 11-02 (which created the model only).
- **Fix:** Created `DurableFactsController` with `index()`, `confirm()`, `supersede()` methods; added `/api/v1/optimizer/facts/*` route group in `routes/api.php`. T-11-05-01 mitigated: confirm is explicit, server enforces D4 gate.
- **Files modified:** `app/Http/Controllers/Api/DurableFactsController.php` (new), `routes/api.php`
- **Commits:** 13bb5d1, b4e895d

## Decision 7 Blocking Audits (BOTH TASKS)

### Task 1 Audits

**Em-dash audit:** All em-dash characters in JSX render copy are HTML entity `&mdash;` (in LearnedTaxFactsSection) or literal `—` in comments only. No raw `---` or improperly encoded em-dashes. **PASS**

**Pre-Flight Check (Section 14):** No new pages created (P12). No route additions for pages. No palette swaps (sw-* tokens only). No typography changes (Inter preserved). No existing components' interfaces changed. **PASS**

**Preservation audit — every URL/nav label/form field/anchor changed:**
*(must be empty except plan-granted additions)*

| Changed item | Before | After |
|---|---|---|
| (none) | — | — |

Only additions this plan grants:
- "Income Optimization Review" section header in Questions feed (new, clearly additive)
- "AI-Learned Tax Facts" section header in Settings (new, clearly additive after EnhancedProfileSection)

**PASS — list of changed existing items is empty.**

**Brand fidelity audit:**
- sw-accent (#2563eb): used for all primary CTAs throughout
- sw-success, sw-warning, sw-info, sw-danger: used for status indicators via Badge
- Inter type stack: self-hosted WOFF2 font unchanged; no new fonts
- Logo treatment: untouched
- recharts visualizations: untouched

**PASS**

### Task 2 Audits

**Em-dash audit:** `&mdash;` used in JSX text (correct HTML entity in JSX). **PASS**

**Pre-Flight Check (Section 14):** Additive LearnedTaxFactsSection in Settings. EnhancedProfileSection props unchanged. No existing behavior disrupted. **PASS**

**Preservation audit — every URL/nav label/form field/anchor changed:**
*(must be empty)*

| Changed item | Before | After |
|---|---|---|
| (none) | — | — |

**PASS — list is empty.**

**Brand fidelity audit:**
- sw-info (#7c3aed) for BookOpen icon (AI-learned facts indicator)
- sw-accent for primary actions (Confirm button)
- Badge component: reused (success/warning/info/neutral variants unchanged)
- Inter preserved, no new fonts

**PASS**

## Educational Framing Verification (T-11-05-03)

All UI copy audited for assertive language. No assertive language found. Verified usages:
- SuggestedConfirmCard: "may help identify relevant tax opportunities", "may not reflect your actual tax situation", "Consider consulting a qualified tax professional"
- InterviewCard: "Responses may help identify potential tax opportunities. These are not tax advice."
- LearnedTaxFactsSection: "may help identify relevant tax opportunities", "All information could benefit from review by a qualified tax professional"

**T-11-05-03: MITIGATED**

## Known Stubs

None. All components call real 11-04 interview endpoints and the new DurableFactsController endpoints. LearnedTaxFactsSection shows a graceful empty state when no facts exist (not a stub — this is correct behavior for new users).

## Threat Surface Scan

New endpoints introduced beyond the plan's threat model:
| Flag | File | Description |
|---|---|---|
| threat_flag: new_api_endpoint | app/Http/Controllers/Api/DurableFactsController.php | GET /optimizer/facts, POST confirm, POST supersede — new authenticated API surface |

Mitigations applied:
- All routes under `auth:sanctum + throttle:120` (existing outer middleware)
- Owner check in `confirm()` and `supersede()`: `$fact->user_id !== $request->user()->id` → abort(403)
- `value` column: `$hidden` on UserTaxFact — never appears in API responses
- `confirmProposal()` enforces `source_type === 'document_extraction'` guard (T-11-05-01)
- `supersede()` rejects `estimated_value_cents` — accepts plain string answer only (SAFE-03)
- `supersede()` rejects non-current facts (validation gate)

## Self-Check

- [x] DurableFactsController.php found
- [x] InterviewCard.tsx found
- [x] SuggestedConfirmCard.tsx found
- [x] LearnedTaxFactsSection.tsx found
- [x] Task 1 commit 13bb5d1 exists
- [x] Task 2 commit b4e895d exists
- [x] npm run build: clean (zero TypeScript errors, built in 5.34s)
- [x] php artisan test --compact: 548 passed, 1 known pre-existing failure (DashboardFinancialBlocksTest)
- [x] vendor/bin/pint --dirty: clean (auto-fixed import ordering only)
- [x] EnhancedProfileSection props/behavior: unchanged (verified by reading file; no modifications to it)
- [x] Transaction QuestionCard behavior: unchanged (FEED-04 — optimization/transaction split is additive)
- [x] All Decision-7 blocking audits: PASS x4 (both tasks)
- [x] Educational framing: all UI copy uses may/could/consider — no assertive language
- [x] D14 skills: ui-ux-pro-max (3 queries), frontend-design, soft-skill, redesign-skill — all applied and documented

## Self-Check: PASSED
