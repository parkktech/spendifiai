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

## D18 Hot-fix (owner question-quality bar)

Owner-reported live defect: the interview rendered "We noticed something related to deductible_saas_microsoft_365 that may affect your taxes. Does this apply to you?" — an internal key leaked into user copy, asking a contentless question. Fixed per Decision 18 (all 5 rules + addenda 6/7), TDD, on `feature/v2.1-optimize-my-income`.

### What changed

1. **Contentless fallback KILLED** (`InterviewOrchestratorService::findingFallbackQuestion`): the generic "We noticed something related to {key}" output is permanently dead. Fallback order: question-phrased narration → FIRST sentence of the treatment + confirm ask → NULL (key skipped, never verbalized). The feed listener (`SurfaceHighPriorityRedFlags`) had the same class of leak ("...related to: {finding_key}") — also fixed.
2. **D18 rule-4 queue gating** (`buildInitialQueue`): findings without a data-grounded question source (config template / dynamic template / question-phrased narration / treatment for auto+battery bands) are EXCLUDED from the interview queue; they remain open in the findings list as suggested-confirm.
3. **`FindingPatternQuestionService` (new)** — dynamic, zero-Claude, data-grounded templates:
   - `pattern.deductible_saas` (classification/multi-select): all `deductible_saas_*` findings collapse into ONE question; labels resolved from live Subscription records; fan-out writes `finding.{key}=yes/no` per item.
   - `penalty_1099k_mismatch` (classification/choice): humanized payer names (memo reference codes stripped), aggregated lead, personal/business/mix/not-sure choices, 1099-K education in context.
   - `category_vehicle_parts` (classification/choice): data lead + detection-spec vehicle purpose tree; mileage/fuel-credit/fraud education in context; `vehicle.usage_log_status` follow-up fans from business-flavored answers.
   - `shape='confirmation'` (exemplar 4): ALL five life-event battery questions + four trigger questions (payroll-stop, new-mortgage, marketplace-premiums, escrow) render as evidence lead + Yes/No/escape; `battery_job_change` derives its evidence from detected employer-switch deposit patterns; battery answers dual-write the canonical `life_event.*` fact (tax-year scoped) via `also_record`. **14-09's monitor prompts must inherit this shape.**
4. **Answer plumbing** (`recordAnswer`): `multi_select` typed conversion (exclusive `none`), per-item fan-out, `follow_ups` front-insertion (gated, one topic per question), `also_record` dual-write, escape-hatch interpretation.
5. **Escape hatch (addendum 7)**: every choice question carries "Something else? Let's talk about it" (`__other__`); `__other__: <text>` answers are interpreted onto the choice set via wording-tier Claude (`model_wording`, `checkAndIncrementBudget('wording')` — the ONE allowed Claude call on this path per D17; genuinely bespoke input); at cap/failure it degrades to a stored `other` with raw text preserved in the encrypted transcript.
6. **Succinctness bar (addendum 6)**: question body ≤ 240 chars and ≤ 2 sentences, enforced by the automated drain test; education lives in the collapsible `options.context` ("Why we're asking").
7. **InterviewCard.tsx (additive)**: structured choice/multi-select rendering (sw-* tokens, checkbox/radio rows mirroring existing option buttons), escape-hatch free-text input, collapsible "Why we're asking" context. `OptimizationQuestionPayload` gains optional `answer_type/choices/none_value/context/objective_tags/doc_affordance/prefill_*`. `npm run build` clean.

### Before / after (the four owner exemplars)

| | Before | After |
|---|---|---|
| SaaS | "We noticed something related to deductible_saas_microsoft_365 that may affect your taxes. Does this apply to you?" (one per merchant) | ONE question: "Which of these software subscriptions do you use primarily for business? Select all that apply." ☐ Microsoft 365 ($9.99/mo) ☐ Adobe Creative Cloud ($54.99/mo) ☐ GitHub ($4/mo) ☐ None of these ☐ Something else? |
| 1099-K/P2P | Dense paragraph with raw memos ("Zelle payment from RAELYN STILES 27868366380", "AMANDA DAVIS BACpz1r5pufa") + full 1099-K education + vague ask | "You've received about $896 across 4 Zelle/Venmo payments this year, including from Raelyn Stiles, Amanda Davis, and April Mayes. What best describes these payments?" ○ Mostly personal ○ Mostly business income ○ A mix ○ Not sure — education in "Why we're asking" |
| Vehicle/powersports | One paragraph mixing mileage methods + fuel credit + fraud warning, ending "Does this reflect your situation?" | "You've made 3 purchases at Rocky Mountain ATV/MC this year (about $925 total). What are these purchases mostly for?" ○ Personal hobby ○ Business work vehicle ○ Race/show vehicles for sponsorship/advertising ○ Items I resell ○ Content I monetize ○ Off-road work equipment — education in context; log follow-up fans from business answers |
| Job change (battery) | Four-topic paragraph (W-4s, severance, rollover, NUA) + "Does this reflect your situation?" | "It looks like you changed jobs — regular deposits from Acme Corp stopped and regular deposits from Globex Industries began. Is that right?" ○ Yes ○ No ○ Something else? — education in context; YES fans the W-4 review probes |

### Seeded owner facts (user_id=1, via UserTaxFact::recordFact, source=interview_answer)

For 14-08/09 executors — wire Action Center items to these keys; the interview never re-asks them:
- `category_vehicle_parts` = `sponsorship_advertising` (Rocky Mountain ATV/MC and this merchant pattern = race vehicles used for sponsorship/advertising; yellow-band docs-needed path)
- `vehicle.usage_log_status` = `willing_to_start` (mileage/gallons log NOT kept, owner willing to start — the CHECKLIST-able state; the gallons/mileage-log Action Center item keys off this)

### Tests / gates

- `tests/Feature/Scenarios/D18QuestionQualityTest.php` — 19 tests / 176 assertions: internal-key regex sweep over all rendered copy (question, context, choice labels), length+sentence ceilings, pattern aggregation, fan-out, follow-up fanning, exclusion rule, fallback-dead proofs, escape hatch (interpreted + budget-capped), confirmation shape, never-re-asks e2e.
- D17 intact: every template path asserts `Http::assertNothingSent()`; the only Claude call on new paths is the escape-hatch interpretation (wording tier, budget-counted).
- Full suite: zero NEW failures. Remaining failures at time of writing: DashboardFinancialBlocksTest (known pre-existing) + ScenarioSolver/ScenarioAgreement (14-06/14-02 executors' in-flight solver work, not this change's code paths).
- Pint clean; `npm run build` clean.

### D18 rule-1 template sweep (deliverable 4)

Audited all `config/optimization-objectives.php` question_templates: every entry asks for a concrete fact in plain English (no "does this apply to you"-class items) — no changes needed. The two contentless fallback strings (orchestrator + feed listener) were the rule-1/2 violations and are fixed. The four dense-paragraph sources (SaaS sweep, 1099-K sweep, vehicle module, life-event battery/triggers) now render via data-grounded templates; their detector `treatment` strings are unchanged (they now serve as findings-list/context copy only).

### Commits (D18 hot-fix)

- 3cad80c — test: failing D18 question-quality tests (key-leak regex, aggregation, fallback kill)
- e837a35 — feat: kill contentless fallback, SaaS multi-select aggregation, queue gating, feed-listener fix
- 8e0e801 — test: failing exemplar-2 tests (1099-K/P2P)
- 3b261e5 — test: failing exemplar-3 tests (vehicle/powersports)
- 2c77acf — test: failing addendum-6/7 tests (succinctness + escape hatch)
- 0f4a3fc — feat: exemplars 2+3, succinctness bar, escape hatch
- f648510 — feat: InterviewCard multi-select/choice rendering, escape hatch, context collapsible
- e669894 — test: failing exemplar-4 tests (job-change confirmation shape)
- 29036f1 — feat: confirmation shape for life-event battery + triggers, also_record, employer-switch evidence

---

## D19/D20 batch

Owner-mandated upgrade batch: five cohesive improvements to the interview/narration seam.
Implemented via TDD, atomic commits per upgrade, on `feature/v2.1-optimize-my-income`.

### What changed

#### D19 — Structured AI output contracts

**NarrationService**: system prompt changed from "Return ONLY the 2-sentence description" to a
JSON output contract requesting `{hook ≤120 chars, detail ≤2 sentences, action_cue ≤1 sentence}`.
- `callClaudeStructured()`: parses JSON, strips markdown fences, validates field presence
- `validateStructuredNarration()`: enforces per-field caps; hook capped at 120 chars, detail/action_cue counted by sentences
- Single shorter-prompt retry on cap violation; template/omit fallback (never renders an oversized blob)
- `narrateFinding()` returns the hook string for backward compat; also writes `narration_structured` JSONB column
- Migration `2026_07_02_210000`: adds `narration_structured JSONB` to `optimization_findings` + `executive_summary_structured JSONB` to `optimization_reports` (idempotent with `Schema::hasColumn()` guard)

**OptimizationReportNarratorService**: `narrateSection()` / `narrateExecutiveSummary()` return
`array{summary, bullets}` (was `string|null`); `narrateSectionProse()` backward-compat accessor.
`validateStructuredSection()`: summary ≤2 sentences, bullets ≤5 (truncated), each ≤15 words (truncated).

**OptimizationReportGeneratorService**: stores `narrator_structured` + `narrator_prose` (backward compat) in section JSON; stores `exec_summary_structured` + `executive_summary` (compat).

**Frontend**: `OptimizationReportView.tsx` and `Optimize/Index.tsx` prefer `narrator_structured`/`narration_structured` (field rendering: summary + bullet list), fall back to prose string with `line-clamp-3`.

**Zero-Claude template paths preserved (D17 intact)**: `narrateSection()` for template narrations still writes only `description` — `narration_structured` stays null; renderers fall back gracefully.

#### D20.1 — Eligibility predicates + tier ordering + format_version

- `InterviewOrchestratorService::FORMAT_VERSION = 2`: `startOrResume()` detects sessions with `format_version` < FORMAT_VERSION → clears queue, bumps version (stale-queue rebuild trigger)
- Session creation stamps `format_version => FORMAT_VERSION`
- `FACT_TIER_MAP`: 30 fact keys assigned tiers 1-4 (1=identity/reconciliation, 2=big-dollar, 3=income-classification, 4=micro-probes)
- `buildInitialQueue()`: sort group→tier→impact DESC; cap applied AFTER sort (owner's "cap after sort" complaint mechanism)
- `passesEligibilityPredicate()` / `evaluateWhenPredicate()` / `resolvePredicateFact()`: template-level `when` predicates evaluated before queueing; unevaluable (fact unknown) → true (may ask); false → never queued
- `config/optimization-objectives.php`: `when` predicates on `family.qualifying_children_under_17` (`dependents_count > 0`), `spouse.annual_income_cents` / `spouse.covered_by_retirement_plan` (`filing_status = married_joint`), `hsa.ytd_contribution_cents` (`hsa_eligible = yes`)
- `doc_source_label` added to 6 paystub/retirement-statement templates (used by D20.3 UI)
- Migration `2026_07_02_162700`: `format_version TINYINT NULL` on `interview_sessions` (idempotent)

#### D20.2 — Conversational escape hatch question detection

- `isQuestion(string $text): bool`: detects free text ending in `?` or containing common question-opener words (what, how, why, when, where, is, can, will, etc.)
- `answerHatchQuestion(array $template, string $userQuestion): ?array`: calls Claude haiku-tier (`model_wording`, `wording` budget, counted); returns `{educational_answer, interpreted_value}` or null at cap
- System prompt: 1-2 educational sentences, non-assertive ("may"/"could"), no dollar amounts, then state best-guess interpreted answer as a separate field
- The calling controller presents the answer + interpreted value for one-tap ✓/✗ confirm before `recordAnswer()` is called — never silent interpret-and-advance

#### D20.3 — "Get it from my documents" choice

- `InterviewOrchestratorService::DOC_SOURCE_VALUE = '__doc_source__'`: sentinel for the doc-source choice
- `handleDocSourceAnswer()`: files `DocumentRequest::firstOrCreate()` (self-initiated: `accounting_firm_id=null`, `accountant_id=null`); marks `AIQuestion.options.doc_pending=true` + `doc_category`; dequeues fact_key (question not re-asked until doc extracted)
- `nextQuestion()` now includes `doc_source_label` in options (from config template `doc_source_label` key)
- Migration `2026_07_02_220000`: makes `accounting_firm_id` and `accountant_id` nullable on `document_requests` (forward-only: relaxing NOT NULL never destroys existing rows)

### Before / after — structured narration

| | Before | After |
|---|---|---|
| NarrationService prompt | "Return ONLY the 2-sentence description. No JSON." | JSON contract: `{hook: ≤120 chars, detail: ≤2 sentences, action_cue: ≤1 sentence}` |
| Narration response stored | `description` (full prose blob) | `description` = hook (backward compat) + `narration_structured` JSONB = `{hook, detail, action_cue}` |
| Renderer (report view) | Prose string with `line-clamp-3` (truncated if oversized) | `hook` headline + expanded `detail` + `action_cue` call-to-action; fallback to prose + clamp |
| Over-length response | Rendered full blob (violates D19 contract) | Retry with shorter prompt → template/omit fallback (never renders oversized blob) |

### Test results (exact)

- D19 narration tests: **9 passed (NarrationServiceTest + ClaudeBudgetTest + TemplateFirstNarrationTest)** — all fakes updated to return valid JSON `{hook, detail, action_cue}`
- D20 tests: **19 new tests all passed** (`tests/Feature/D20InterviewIntelligenceTest.php`)
- ObjectiveReadinessTest: `format_version` fix for 2 tests that used pre-D20 sessions (now stamp FORMAT_VERSION to prevent unexpected queue rebuild)
- **Full suite**: `php artisan test --compact` → **990 passed, 1 failed, 1 risky (4595 assertions)**
- The single failure is the **known pre-existing** `DashboardFinancialBlocksTest` — unchanged from prior sessions; this batch adds zero new failures
- `vendor/bin/pint --dirty` → clean (operator/unary spacing in InterviewOrchestratorService)
- `npm run build` → clean (TypeScript, no new errors)

### Gates verified

- D17 intact: template path tests assert `Http::assertNothingSent()`; only `answerHatchQuestion()` (hatch answer) and `narrateFinding()` (D19 structured) are new Claude calls, both wording/narration-tiered and counted
- D18 intact: all D18 tests green; escape hatch, pattern aggregation, confirmation shape unchanged
- SAFE-01: system prompts audited — no assertive phrases; banned-phrase test (`NarrationServiceTest::banned_phrases_absent`) still passes
- SAFE-03: `estimated_value_cents` excluded from all payloads; `claude_never_receives_value_cents` test still passes

### Commits

- `550d7db` — feat(14-05): D19 structured AI output contract — {hook,detail,action_cue} / {summary,bullets}
- `4b89960` — feat(14-05): D20 — interview eligibility predicates, escape hatch detection, doc-source choice
- `4df2d96` — fix(14-05): D19/D20 migration idempotency + test D19 JSON contract compliance
