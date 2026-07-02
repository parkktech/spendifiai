---
phase: 14-action-center-scenarios-design-elevation
plan: 05
subsystem: api
tags: [optimization-scenarios, readiness, interview, template-first, zero-claude, typed-answers, prefill-pointer, scn-01, scn-03]

# Dependency graph
requires:
  - phase: 14-01
    provides: config/optimization-objectives.php (objectives fact map, question_templates, prerequisites, bonus_election scenario domain)
  - phase: 14-03
    provides: ScenarioFactResolverService::resolveAll/resolve (two-tier known-vs-confirmed resolution)
  - phase: 12-13 (Optimize My Income foundation)
    provides: InterviewOrchestratorService, InterviewController, PaystubFactExtractorService, IncomeOptimizerDataAssemblerService, UserTaxFact, AIQuestion, InterviewSession
provides:
  - app/Services/ObjectiveReadinessService.php (readiness() single source + enqueueGaps() single enqueue path)
  - app/Http/Controllers/Api/OptimizationObjectiveController.php (GET show + POST enqueue) + 2 routes
  - InterviewOrchestratorService template-first createOptimizationQuestion() branch (D17 zero-Claude) + typed recordAnswer() + merged prerequisite gate map + durable skip()
  - InterviewController::next() additive Phase-14 response fields + POST skip endpoint
  - PaystubFactExtractorService additive maps + RETIREMENT_STATEMENT_FACT_MAP
  - IncomeOptimizerDataAssemblerService estimated_age backfill
  - interview_sessions.skipped column (additive migration)
affects: [14-06 (ScenarioController consumes readiness()), 14-08 (checklist fact-gating), 14-09 (bonus scenario domain), 14-10 (InterviewCard upgrades consume additive next() fields)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Template-first question construction: config question_templates → deterministic AIQuestion, zero Claude (D17)"
    - "Two-tier readiness (known for math, confirmed for directives) as the single readiness source (M5)"
    - "Front-insert gap enqueue as the ONLY enqueue path (M6); battery stays last; initial_cap not applied"
    - "prefill_source POINTER in AIQuestion.options (never a value); transient prefill resolution at next() read time (SAFE-03)"
    - "Server-side typed answer conversion in the orchestrator (money→cents, integer/year, choice) with 422 on mismatch; FormRequest stays string|max:500"
    - "Durable skip persistence excluded from stale-queue self-heal rebuild"

key-files:
  created:
    - app/Services/ObjectiveReadinessService.php
    - app/Http/Controllers/Api/OptimizationObjectiveController.php
    - database/migrations/2026_07_04_000001_add_skipped_to_interview_sessions_table.php
    - tests/Feature/Scenarios/ObjectiveReadinessTest.php
    - tests/Feature/Scenarios/PrefillPointerTest.php
    - tests/Feature/Scenarios/ObjectiveEnqueueTest.php
    - tests/Feature/Scenarios/InterviewSkipPersistenceTest.php
  modified:
    - app/Services/InterviewOrchestratorService.php
    - app/Services/AI/PaystubFactExtractorService.php
    - app/Services/IncomeOptimizerDataAssemblerService.php
    - app/Models/InterviewSession.php
    - app/Http/Controllers/Api/InterviewController.php
    - routes/api.php
    - resources/js/Components/SpendifiAI/InterviewCard.tsx

decisions:
  - "ObjectiveReadinessService iterates ONLY take_home/tax_burden/retirement; bonus_election (is_scenario_domain) is skipped by both readiness() and enqueueGaps() and rejected (404) by the enqueue endpoint."
  - "questions_to_unlock counts blocking_missing keys that have a DIRECT template. Keys whose config chain terminates in ask:{other_key} (e.g. employer.federal_withholding → pay.federal_withholding_per_period_cents) are not directly templated and are not counted; the askable sibling is templated and enqueues on its own. Documented, acceptable for v1."
  - "The §A.5.5 suggested-confirm 'confirm' sentinel is the literal string 'confirm'; recordAnswer resolves the fact via the resolver server-side and records the resolved value (never client-supplied). A replacement (non-'confirm') value is typed-converted and recorded instead."
  - "DEFECT 1: wordQuestion's fallback now only reuses a finding description when it is already phrased as a question (ends with '?'); otherwise a real answerable question is emitted. Finding narration is never surfaced as a question. The template-first branch removes objective gap questions from the Claude/narration path entirely."
  - "DEFECT 2 skip persistence uses ai_best_guess (the queue key: finding_key or canonical fact key), not options.fact_key (which may be the 'finding.'-prefixed durable key), so the skip actually dequeues and is excluded on rebuild."

metrics:
  duration_minutes: 17
  tasks_completed: 3
  files_created: 7
  files_modified: 7
  tests_added: 26
  completed: 2026-07-02

status: complete
---

# Phase 14 Plan 05: ObjectiveReadinessService + Objectives API + Interview Additions Summary

Built the objective readiness engine (`ObjectiveReadinessService` — the single readiness source, M5) that consumes the 14-03 resolver to compute two-tier per-objective readiness and front-inserts zero-Claude template gap questions via `enqueueGaps()` (the only enqueue path, M6). Added the additive orchestrator branches (deterministic template-question construction D17, config-merged prerequisite gates, typed answer conversion + template volatility/tax_year, prefill-pointer resolution), additive `PaystubFactExtractorService` maps + a new `RETIREMENT_STATEMENT_FACT_MAP`, the assembler `estimated_age` backfill, `OptimizationObjectiveController` (+ 2 routes), and the additive `InterviewController::next()` Phase-14 fields. Also reproduced and fixed the two owner-reported live interview defects.

## What was built

### Task 1 — ObjectiveReadinessService (TDD)
- `readiness(user, year)` computes, per objective, `blocking_missing` / `confirm_needed` / `optional_missing` / `blocking` (state per fact) / `ready` / `questions_to_unlock` / `completeness_pct` over the config fact map + `resolveAll()`.
- Two tiers (§A.7): a blocking fact resolved-but-`confirmed=false` lands in `confirm_needed` and does NOT block readiness; `ready = count(blocking_missing) === 0`.
- Conditional-blocking facts (B5/B6 HSA, R6/R7 match) block only when their `condition` holds (deterministic condition evaluator supporting ` OR `, `= > < >= <=`, and bare-key truthy). A prerequisite answered `'no'` flips dependents to `not_applicable` (counts as resolved, §A.4.3).
- T4 (`w4.filing_status`, `w4.dependents_claimed`) is optional-with-suppression — never in `blocking_missing` (M12).
- `enqueueGaps()` front-inserts templated blocking + suggested-confirm gap keys before the existing queue, dedupes vs queue ∪ asked ∪ skipped ∪ answered, keeps battery last, ignores `initial_cap`, idempotent on double call.

### Task 2 — Orchestrator/extractor/assembler additions
- `createOptimizationQuestion()` additive template-first branch: template keys build a zero-Claude AIQuestion (options carry `choices`/`answer_type`/`objective_tags`/`doc_affordance`); suggested-confirm (known-but-unconfirmed) sets `band='auto'`, `ai_confidence=1.0`, and `options.prefill_source` POINTER only. Finding-driven questions keep the Claude path.
- `recordAnswer()` typed conversion for template keys: `money_dollars`→cents-string, `integer`/`year` digit-string + min/max, `choice` membership → 422 on mismatch; `volatility`/`taxYear`/`label` from the template. `'confirm'` resolves the pointer server-side and records the resolved value as `interview_answer`. Non-template keys keep exact legacy behavior (DRIFT-02).
- Prerequisite gates merged: `GATED_PROBES` ∪ `config('optimization-objectives.prerequisites')` (const wins on collision).
- `PaystubFactExtractorService`: additive `federal_tax_withheld`/`gross_pay` PAYSTUB entries + new `RETIREMENT_STATEMENT_FACT_MAP` (`account_balance`, `ytd_contributions` cross-check-only). All proposals (`document_extraction`, D4 gate untouched). Pay-frequency derivation stays in the resolver (M7).
- Assembler `buildProfile()`: additive `estimated_age` backfill from `person.birth_year` (`taxYear − birth_year`), only when a valid fact exists.

### Task 3 — Objectives API + interview-next additive fields
- `OptimizationObjectiveController::show(year)` returns the §E projection (keys/labels/states only, no money — safe to log); `enqueue(year, objective)` validates `{objective}` against readiness objectives → 404 otherwise (scenario domains rejected) and calls `enqueueGaps()`. Routes registered under `auth:sanctum` WITHOUT `bank.connected` (throttle 60/1 show, 10/1 enqueue).
- `InterviewController::next()` additive fields: `objective_tags`, `answer_type`, `choices`, `doc_affordance`, and transient `prefill_display`/`prefill_value` (pointer resolved per-request, never stored). Existing keys byte-for-byte unchanged.

## Owner-reported defects — repro → fix → proof

### DEFECT 1 — "Not asking a question" (narration rendered instead of an answerable question)
- **Repro:** conditional-band findings with no template fell through `createOptimizationQuestion()` to `wordQuestion()`, whose fallback returned `$finding->description` verbatim — narration text (e.g. "…Consider discussing this with a tax professional"), not a question.
- **Fix:** (a) The template-first branch removes every objective gap/probe question from the Claude/narration path — they are now deterministic, typed, answerable templates (D17). (b) `findingFallbackQuestion()` only reuses a finding description when it is already phrased as a question (ends with `?`); otherwise it emits a real answerable question. Narration is never surfaced as a question.
- **Proof:** `ObjectiveEnqueueTest` walks enqueued gap questions and asserts each is `options.template === true` with zero HTTP to Anthropic (`Http::assertNothingSent`). Existing `InterviewOrchestratorServiceTest` (Claude wording path for template-less findings) stays green.

### DEFECT 2 — "Skip doesn't skip, just refreshes" (infinite loop at Q1)
- **Repro:** skip was not persisted for finding-backed items, and the 12-08 stale-session self-heal rebuilt the queue from open findings excluding only `asked` — a skipped-but-not-asked item was re-inserted at position 1 on reload.
- **Fix:** added a durable `interview_sessions.skipped[]` column (additive migration) + `InterviewSession::markSkipped()`; `InterviewOrchestratorService::skip()` persists the skip and dequeues by `ai_best_guess` (the queue key); the self-heal rebuild now excludes `asked ∪ skipped ∪ answered` (via `isAlreadyAnswered`). New `POST /{interview}/questions/{question}/skip` endpoint returns the next question; `InterviewCard.handleSkip` calls it.
- **Proof:** `InterviewSkipPersistenceTest` — enqueue 2 finding-backed items → skip Q1 (endpoint returns Q2) → skip persisted → resume (`/start`) does NOT re-insert Q1 → `next()` after resume never returns Q1; a second test proves the self-heal rebuild excludes a pre-skipped key while still surfacing a fresh one.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Skip dequeued the wrong key**
- **Found during:** Task 3 (InterviewSkipPersistenceTest RED — skipped[] carried `finding.finding_probe_one`, not the queue key).
- **Issue:** the skip controller used `options.fact_key` (the `finding.`-prefixed durable key), which does not match the queue entry (`finding_key`).
- **Fix:** skip now uses `$question->ai_best_guess` (always the queue key) for markSkipped/dequeue.
- **Files modified:** app/Http/Controllers/Api/InterviewController.php
- **Commit:** 6b18f99

**2. [Rule 1 - Bug] Money-leak regression test false-positive on JSON unicode escapes**
- **Found during:** Task 3 (`/\d{4,}/` matched `—`/`’` in the escaped JSON body).
- **Fix:** the assertion re-encodes with `JSON_UNESCAPED_UNICODE` before the regex — it targets real numeric leakage, not escape sequences. The genuine money value assertion (`not->toContain('7250000')`) is unaffected.
- **Files modified:** tests/Feature/Scenarios/ObjectiveEnqueueTest.php
- **Commit:** 6b18f99

No architectural (Rule 4) deviations. No new packages. The frontend `InterviewCard.handleSkip` touch (not in the plan's files list) is a minimal additive change required to make DEFECT 2's live fix real; the P11 InterviewCard otherwise keeps working (existing interview tests green, `npm run build` succeeds).

## Test results (exact)

- `php artisan test --compact --filter=ObjectiveReadiness` → **13 passed (39 assertions)**.
- `php artisan test --compact --filter=PrefillPointer` → **5 passed (13 assertions)**.
- `php artisan test --compact --filter=ObjectiveEnqueue` → **6 passed**.
- `php artisan test --compact --filter=InterviewSkipPersistence` → **2 passed**.
- `php artisan test --compact --filter=Interview` → **22 passed (59 assertions)** (existing interview suite unaffected).
- **Full suite** `php artisan test --compact` → **889 passed, 1 failed, 1 risky (3111 assertions), 147.61s**.
- `vendor/bin/pint --dirty` → clean.
- `npm run build` → built in 5.42s (TSX touched).

### The single failure is the KNOWN pre-existing one
`Tests\Feature\Dashboard\DashboardFinancialBlocksTest > it shows b…` at line 149 — `Failed asserting that 333.33 is less than 0` (`budget_waterfall.monthly_surplus`). This is the identical pre-existing failure named in 14-03-SUMMARY (a dashboard waterfall fixture/arithmetic issue). This plan does not touch DashboardController. New-test delta: the suite went from 863 → 889 (+26), all 26 new tests green; zero NEW failures.

`migrate:fresh`/seeders were NOT run. The additive `skipped` column migration was applied to the app DB via `php artisan migrate --force` (5.04ms). Test DB re-migrates under RefreshDatabase.

## Known Stubs
None. `questions_to_unlock` intentionally counts only directly-templated `blocking_missing` keys (ask-indirection siblings enqueue on their own) — documented in decisions, not a stub. `RETIREMENT_STATEMENT_FACT_MAP.ytd_contributions` is a cross-check proposal only by design.

## Threat Flags
None. All new surface is covered by the plan's threat register: prefill pointer (T-14-05-01, PrefillPointerTest), zero-Claude enqueue (T-14-05-02, assertNothingSent), no-money readiness body (T-14-05-03, ObjectiveEnqueueTest), objective/typed validation (T-14-05-04, 404 + 422), cross-user scoping (T-14-05-05, auth()->id() + policies).

## Commits
- a5560bb — test(14-05): failing specs for ObjectiveReadinessService (SCN-01, RED)
- 99fbcce — feat(14-05): ObjectiveReadinessService — two-tier readiness + enqueueGaps (SCN-01)
- adb4905 — feat(14-05): orchestrator template-first branch + typed answers + paystub/assembler additions
- 6b18f99 — feat(14-05): objectives API + interview-next additive fields + durable skip endpoint

## Self-Check: PASSED
- app/Services/ObjectiveReadinessService.php — FOUND
- app/Http/Controllers/Api/OptimizationObjectiveController.php — FOUND
- database/migrations/2026_07_04_000001_add_skipped_to_interview_sessions_table.php — FOUND
- tests/Feature/Scenarios/{ObjectiveReadiness,PrefillPointer,ObjectiveEnqueue,InterviewSkipPersistence}Test.php — FOUND
- commits a5560bb, 99fbcce, adb4905, 6b18f99 — FOUND
- skipped-column migration applied — DONE (5.04ms)
