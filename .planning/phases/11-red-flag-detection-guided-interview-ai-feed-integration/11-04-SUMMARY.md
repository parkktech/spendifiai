---
phase: "11"
plan: "04"
subsystem: guided-interview-feed-bridge
tags: [interview, ai-feed, optimization, tdd, INT-01, INT-04, INT-05, INT-06, INT-07, FEED-01, FEED-02, FEED-03, FEED-04]
requirements: [INT-01, INT-04, INT-05, INT-06, INT-07, FEED-01, FEED-02, FEED-03, FEED-04]

dependency_graph:
  requires:
    - "11-01"   # OptimizationFinding model + TaxRulesEngineService
    - "11-02"   # UserTaxFact append-only durable facts store
    - "11-03"   # OptimizationProfileBuilt event + BuildIncomeOptimizationProfile job
  provides:
    - QuestionType::Optimization enum case
    - SurfaceHighPriorityRedFlags listener (FEED-02)
    - UpdateOptimizationFromAnswer listener (FEED-03)
    - FEED-04 guard (UpdateTransactionCategory early-return)
    - InterviewSession model + state machine
    - InterviewOrchestratorService (startOrResume / nextQuestion / recordAnswer)
    - InterviewController + routes (/api/v1/optimizer/interview/*)
    - interview_sessions Postgres table
  affects:
    - app/Enums/QuestionType.php
    - app/Listeners/UpdateTransactionCategory.php
    - app/Services/AI/TransactionCategorizerService.php
    - app/Http/Controllers/Api/AIQuestionController.php
    - routes/console.php
    - app/Providers/AppServiceProvider.php
    - routes/api.php

tech_stack:
  added:
    - InterviewOrchestratorService (persisted state machine, Claude call for wording only)
    - InterviewSession model (encrypted assertions, JSONB queue/asked, partial unique index)
    - SurfaceHighPriorityRedFlags (ShouldQueue listener, firstOrCreate idempotency)
    - UpdateOptimizationFromAnswer (ShouldQueue listener, UserTaxFact through-write)
    - Interview routes (/api/v1/optimizer/interview/*)
    - AnswerOptimizationQuestionRequest (SAFE-03 compliant)
  patterns:
    - "TDD: 27 tests written (RED) before implementation (GREEN)"
    - "SAFE-03: Claude payload never contains estimated_value_cents or dollar figures"
    - "INT-04: prerequisite gating via GATED_PROBES constant"
    - "INT-06: batch-by-merchant (one AIQuestion per finding_key)"
    - "INT-07: band-driven ai_confidence (auto=1.0, conditional=0.70, specialist=0.30)"
    - "FEED-04: early-return guard in UpdateTransactionCategory for Optimization type"
    - "Partial unique index: one in_progress session per (user_id, tax_year)"
    - "firstOrCreate idempotency: safe under job retry"

key_files:
  created:
    - app/Enums/QuestionType.php (additive Optimization = 'optimization' case)
    - app/Listeners/SurfaceHighPriorityRedFlags.php (FEED-01/02)
    - app/Listeners/UpdateOptimizationFromAnswer.php (FEED-03)
    - app/Models/InterviewSession.php (state machine helpers, encrypted transcript)
    - app/Policies/InterviewSessionPolicy.php (owner-only)
    - app/Services/InterviewOrchestratorService.php (INT-01/04/05/06/07)
    - app/Http/Controllers/Api/InterviewController.php
    - app/Http/Requests/AnswerOptimizationQuestionRequest.php
    - database/factories/InterviewSessionFactory.php
    - database/migrations/2026_07_02_115000_make_ai_question_transaction_nullable.php
    - database/migrations/2026_07_02_120000_create_interview_sessions_table.php
    - tests/Unit/QuestionTypeEnumTest.php
    - tests/Feature/OptimizationFeedIntegrationTest.php
    - tests/Feature/InterviewSessionStateTest.php
    - tests/Feature/InterviewOrchestratorServiceTest.php
  modified:
    - app/Listeners/UpdateTransactionCategory.php (FEED-04 guard at top of handle())
    - app/Services/AI/TransactionCategorizerService.php (handleUserAnswer null guard)
    - app/Http/Controllers/Api/AIQuestionController.php (null transaction_id handling, index cleanup)
    - routes/console.php (exclude Optimization questions from daily expiry)
    - app/Providers/AppServiceProvider.php (two new event listeners + model binding + policy)
    - routes/api.php (interview route group)
    - config/tax-detection.php (interview.initial_cap key)

decisions:
  - "QuestionType::Optimization added as additive enum case — no migration needed (VARCHAR column accepts new values)"
  - "fact_key stored in options JSON (not a dedicated column) to avoid schema migration"
  - "UpdateOptimizationFromAnswer and UpdateTransactionCategory are separate listeners on UserAnsweredQuestion — separation-of-concerns avoids coupling interview logic to transaction categorization"
  - "InterviewOrchestratorService calls Claude ONLY for question wording (SAFE-01/SAFE-03) — payload explicitly excludes estimated_value_cents and all dollar figures from details"
  - "Partial unique index enforces one in_progress session per user+year at the DB level (Pitfall 8)"
  - "firstOrCreate keyed on (user_id, question_type, ai_best_guess, status) for SurfaceHighPriorityRedFlags idempotency under job retry"
  - "Claude 'wording-only' call uses Http::fake() in tests to prevent stray HTTP calls (SAFE-01)"

metrics:
  duration: "~180 min (across two conversation sessions)"
  completed: "2026-07-01"
  tasks_completed: 3
  tasks_total: 3
  files_created: 15
  files_modified: 7
  tests_added: 27
  assertions_added: 63
  suite_result: "548 passed, 1 known pre-existing failure (DashboardFinancialBlocksTest)"
  tdd_gate: "PASS — RED commit (4098636) precedes GREEN commit (c17953b)"

status: complete
---

# Phase 11 Plan 04: Guided Interview & AI-Feed Bridge Summary

One-line: Wired QuestionType::Optimization + persisted one-question-at-a-time interview state machine (InterviewSession + InterviewOrchestratorService) onto the existing AI-Questions feed, with FEED-04 early-return guard protecting transaction categorization from regression.

## Objective

Turn OptimizationFindings (produced by Phase 11-03's BuildIncomeOptimizationProfile job) into a guided educational interview: auto-band findings surface as AIQuestion(Optimization) rows in the existing /api/v1/questions feed; a new persisted state machine (InterviewSession) delivers them one-at-a-time; user answers write to the UserTaxFact durable store; the UpdateTransactionCategory listener is guarded against touching these questions.

## Commits

| # | Hash | Type | Description |
|---|------|------|-------------|
| 1 | c6cac5a | test | RED — QuestionType::Optimization + feed bridge integration tests (FEED-01..04) |
| 2 | 2f48a94 | feat | GREEN — QuestionType::Optimization + AI-feed bridge (FEED-01..04) |
| 3 | 4098636 | test | RED — InterviewSession state machine + orchestrator service tests (INT-01..07) |
| 4 | c17953b | feat | GREEN — InterviewSession state machine + orchestrator (INT-01/04/05/06/07) |

## Tasks Completed

### Task 1: QuestionType::Optimization + AI-Feed Bridge (FEED-01..04)

**What was built:**
- `QuestionType::Optimization = 'optimization'` additive enum case (no migration — VARCHAR column)
- `SurfaceHighPriorityRedFlags` — ShouldQueue listener on `OptimizationProfileBuilt`. Queries auto-band open findings, creates `AIQuestion(Optimization)` rows via `firstOrCreate` (idempotent under job retry). Options JSON carries `{fact_key, finding_id, band, suggested_treatment, transaction_ids}`. ai_confidence=1.0 for auto-band (INT-07 suggested-confirm).
- `UpdateOptimizationFromAnswer` — ShouldQueue listener on `UserAnsweredQuestion`. Returns early for non-Optimization questions. Reads `fact_key` from `options` JSON. Calls `UserTaxFact::recordFact()` with `source_type='interview_answer'`. Never writes `estimated_value_cents` (SAFE-03).
- FEED-04 guard: `UpdateTransactionCategory::handle()` returns early at the TOP if `question_type === QuestionType::Optimization`.
- `TransactionCategorizerService::handleUserAnswer()` null-transaction guard (prevents crash at `$transaction->refresh()` for optimization questions).
- `AIQuestionController::index()` cleanup: excludes Optimization questions from null-transaction auto-expiry.
- `AIQuestionController::answer()` null-safe transaction response.
- `routes/console.php` expiry task excludes Optimization questions.
- Additive migration: `make_ai_question_transaction_nullable` (Rule 1 bug fix — original schema had NOT NULL on transaction_id).

**FEED-04 regression boundary:** The guard is the FIRST statement in `handle()`, before any existing logic. Existing transaction categorization behavior is completely unchanged.

### Task 2: InterviewSession + InterviewOrchestratorService (INT-01/04/05/06/07)

**What was built:**
- `interview_sessions` table: `user_id` FK (cascadeOnDelete), `tax_year`, `status` (created/in_progress/paused/completed), `queue` JSONB, `asked` JSONB, `assertions` TEXT (encrypted), `initial_cap`. Postgres partial unique index: one `in_progress` session per `(user_id, tax_year)`.
- `InterviewSession` model: `queue` and `asked` cast as arrays, `assertions` cast as encrypted. State machine helpers: `activate()`, `pause()`, `complete()`, `isActive()`, `isComplete()`. Transcript: `appendTranscript(array $entry)` decrypts, appends, re-saves encrypted. `markAsked()`, `dequeueKey()`. `scopeForUser()`.
- `InterviewSessionFactory`: default in_progress, states: `created()`, `paused()`, `completed()`.
- `InterviewSessionPolicy`: standard owner-only pattern (view/update/delete).
- `InterviewOrchestratorService`:
  - `startOrResume(userId, taxYear)`: firstOrCreate with in_progress partial unique index — resumes paused sessions, creates new with queue seeded from auto-band findings.
  - `nextQuestion(session)`: Pattern-5 loop — pops queue key, checks INT-04 prerequisite gating (GATED_PROBES constant), checks UserTaxFact skip-logic (CTX-04), creates AIQuestion(Optimization) with INT-06 batch metadata, marks asked.
  - `recordAnswer(session, factKey, value)`: writes UserTaxFact (append-only + supersession), appends transcript, marks asked/dequeues.
  - Claude called ONLY for question wording (SAFE-01/SAFE-03). Payload: `{fact_key, finding_type, severity, treatment, legal_basis, band, existing_description, potential_range}`. `estimated_value_cents` explicitly excluded. Dollar figures from `details` excluded.
- `InterviewController`: index, start, next, answer endpoints. All write endpoints require `authorize('update', $interview)`.
- `AnswerOptimizationQuestionRequest`: `answer` string max 500 chars. SAFE-03 compliant — no `estimated_value_cents` field accepted.
- Route group: `/api/v1/optimizer/interview/*` (under existing auth:sanctum + throttle:120 middleware).
- `AppServiceProvider`: model binding `interview → InterviewSession`, policy `InterviewSession → InterviewSessionPolicy`, two new event listeners (`SurfaceHighPriorityRedFlags`, `UpdateOptimizationFromAnswer`).

### Task 3: Full Regression + Suite Run (Gate)

**Result:** 548 passed, 1 known pre-existing failure (`DashboardFinancialBlocksTest::it shows budget waterfall shows deficit correctly`) which predates this plan. Zero new failures. Full TDD gate compliant.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] ai_questions.transaction_id NOT NULL violation**
- **Found during:** Task 1 (SurfaceHighPriorityRedFlags creates AIQuestion with null transaction_id)
- **Issue:** Original schema had NOT NULL constraint on `ai_questions.transaction_id`. Optimization questions have no associated transaction — the insert was rejected by PostgreSQL.
- **Fix:** Additive migration `2026_07_02_115000_make_ai_question_transaction_nullable.php` — relaxes the NOT NULL constraint via `->change()`. Zero data destroyed.
- **Files modified:** `database/migrations/2026_07_02_115000_make_ai_question_transaction_nullable.php`
- **Commit:** 2f48a94

**2. [Rule 1 - Bug] TransactionCategorizerService null-transaction crash in handleUserAnswer()**
- **Found during:** Task 1 (UpdateTransactionCategory guard analysis)
- **Issue:** `handleUserAnswer()` calls `$transaction->refresh()` after the switch statement. For Optimization questions with null transaction_id, `$question->transaction` is null → PHP fatal error.
- **Fix:** Added early-return guard for `QuestionType::Optimization` BEFORE the null-unsafe `$transaction = $question->transaction` line.
- **Files modified:** `app/Services/AI/TransactionCategorizerService.php`
- **Commit:** 2f48a94

**3. [Rule 2 - Missing Critical] AIQuestionController index() null-transaction cleanup gap**
- **Found during:** Task 1 review of AIQuestionController
- **Issue:** The daily expiry cleanup in `routes/console.php` and the index() query cleanup would process Optimization questions (which have null transaction_id), risking auto-resolution of questions that should never expire.
- **Fix:** Added `->where('question_type', '!=', QuestionType::Optimization->value)` to both the index() cleanup query and the console expiry task.
- **Files modified:** `app/Http/Controllers/Api/AIQuestionController.php`, `routes/console.php`
- **Commit:** 2f48a94

**4. [Rule 1 - Bug] AIQuestionController answer() null-transaction response crash**
- **Found during:** Task 1 (null-transaction guard analysis)
- **Issue:** `answer()` always called `new TransactionResource($question->transaction->fresh())` regardless of whether transaction_id was null — PHP fatal at `->fresh()` on null.
- **Fix:** Changed to conditional: `$question->transaction_id ? new TransactionResource($question->transaction->fresh()) : null`.
- **Files modified:** `app/Http/Controllers/Api/AIQuestionController.php`
- **Commit:** 2f48a94

## TDD Gate Compliance

| Gate | Commit | Status |
|------|--------|--------|
| Task 1 RED | c6cac5a `test(11-04): RED — QuestionType::Optimization + feed bridge integration tests` | PASS |
| Task 1 GREEN | 2f48a94 `feat(11-04): GREEN — QuestionType::Optimization + AI-feed bridge` | PASS |
| Task 2 RED | 4098636 `test(11-04): RED — InterviewSession state machine + orchestrator service tests` | PASS |
| Task 2 GREEN | c17953b `feat(11-04): GREEN — InterviewSession state machine + orchestrator` | PASS |

RED committed before GREEN in both tasks. Gate criteria satisfied.

## Known Stubs

None. All wired functionality is backed by real models and services. The Claude wording call falls back to `finding->description ?? generic string` when the API is unavailable in test environments.

## Threat Surface Scan

No new threat surface introduced beyond what is already in the plan's threat model:

- `/api/v1/optimizer/interview/*` routes are behind `auth:sanctum` + `throttle:120` — no unauthenticated access.
- `InterviewSessionPolicy` blocks cross-user access at the authorization layer.
- `AnswerOptimizationQuestionRequest` rejects any dollar-amount inputs (SAFE-03).
- `InterviewOrchestratorService::wordQuestion()` explicitly excludes `estimated_value_cents` and `details` from the Claude payload — no dollar figures leak into the AI call.
- `assertions` (transcript) stored encrypted via Laravel's `'encrypted'` cast on a TEXT column — consistent with existing project encryption patterns.

## Self-Check

- [x] 548 test suite passed; 0 new failures
- [x] All 4 task commits exist: c6cac5a, 2f48a94, 4098636, c17953b
- [x] interview_sessions table created and migrated
- [x] FEED-04 guard verified: mocked `shouldNotReceive('detectSubscriptions')` test passes
- [x] SAFE-03 verified: `answer_does_not_write_estimated_value_cents` test passes
- [x] INT-04 backdoor-Roth gating verified in InterviewOrchestratorServiceTest
- [x] INT-06 batch-by-merchant verified: 40 transaction_ids → one AIQuestion
- [x] INT-07 band confidence verified: auto → ai_confidence=1.0 with suggested_treatment in options
- [x] Pint clean: `vendor/bin/pint --dirty` → `{"result":"pass"}`

## Self-Check: PASSED
