---
phase: 14-action-center-scenarios-design-elevation
plan: 01
subsystem: api
tags: [config, anthropic, claude, ai-cost-discipline, optimization-scenarios, laravel, pest]

# Dependency graph
requires:
  - phase: 12-13 (Optimize My Income foundation)
    provides: NarrationService, OptimizationReportNarratorService, InterviewOrchestratorService, TransactionCategorizerService, OptimizationFinding, 28-day activity gate precedent (commit 4e75c46)
provides:
  - config/optimization-objectives.php (fact map + fact_aliases + pay_periods_per_year + question_templates + prerequisites + bonus_election domain)
  - config/optimizer-scenarios.php (assumptions, grids, divergence epsilons, tax_objective_priority, tradeoff_templates, checklist_templates skeleton)
  - config/tax-rules.php detection.odc_amount = 500
  - D17 per-call-site anthropic model resolution (narration/wording -> haiku, extraction -> global, categorization -> haiku)
  - D17 per-purpose daily budget caps + Cache day-counters at every sanctioned Claude call site
  - Template-first zero-Claude finding narration path (config/optimization-report.php finding_narration_templates)
  - 28-day activity gate on GenerateOptimizationReport + NarrateOptimizationFindings
  - GET /api/admin/ai-usage per-purpose 7-day counter surface
affects: [14-05, 14-07, 14-08, 14-09, scenario-solver, objective-readiness, change-monitor]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Per-call-site model resolution: config('services.anthropic.model_{purpose}', config('services.anthropic.model'))"
    - "Per-purpose Cache day-counter + budget guard: claude_calls_{purpose}_{date}; null cap => uncapped (PHP_INT_MAX)"
    - "Template-first: config-keyed deterministic copy short-circuits before any Claude/HTTP call"
    - "28-day activity gate on background AI dispatch (last_active_at vs spendifiai.sync.active_threshold_days)"

key-files:
  created:
    - config/optimization-objectives.php
    - config/optimizer-scenarios.php
    - app/Http/Controllers/Admin/AiUsageController.php
    - tests/Unit/TemplateFirstNarrationTest.php
    - tests/Unit/ClaudeBudgetTest.php
    - tests/Unit/BannedPhraseTemplatesTest.php
    - tests/Feature/ActivityGateTest.php
  modified:
    - config/tax-rules.php
    - config/services.php
    - config/optimization-report.php
    - app/Services/NarrationService.php
    - app/Services/OptimizationReportNarratorService.php
    - app/Services/InterviewOrchestratorService.php
    - app/Services/AI/TransactionCategorizerService.php
    - app/Jobs/GenerateOptimizationReport.php
    - app/Listeners/NarrateOptimizationFindings.php
    - routes/api.php

key-decisions:
  - "Each service owns a private checkAndIncrementBudget() helper (mirrored, not shared) to keep the change additive and per-service testable"
  - "finding_narration_templates use qualitative educational copy (no dollar literals); renderTemplate supports a {value} token but shipped copy stays qualitative to preserve SAFE narration discipline"
  - "Categorization budget default null = uncapped (PHP_INT_MAX) so batch throughput is unchanged; the day-counter always increments for the admin surface"
  - "Activity gate follows the research pattern exactly: null last_active_at is NOT gated (only explicitly-stale users skip)"

patterns-established:
  - "D17 per-purpose model + budget/counter guard pattern reused by every future Claude call site"
  - "BannedPhraseTemplatesTest recursively scans present Phase-14 copy config arrays and gracefully skips later-wave keys"

requirements-completed: [SCN-03]

coverage:
  - id: D1
    description: "Three additive config files load: optimization-objectives (3 readiness objectives + bonus_election domain, aliases, pay_periods, question_templates, prerequisites), optimizer-scenarios (assumptions/grids/divergence/priority/templates), tax-rules odc_amount=500"
    requirement: SCN-03
    verification:
      - kind: other
        ref: "php artisan tinker --execute (odc=500, 4 objectives, take_home+bonus_election present, no readiness key, no w4/profile alias) — all PASS"
        status: pass
    human_judgment: false
  - id: D2
    description: "Template-first finding narration returns deterministically with zero Claude/HTTP; bespoke finding_type falls through to the Haiku Claude path"
    requirement: SCN-03
    verification:
      - kind: unit
        ref: "tests/Unit/TemplateFirstNarrationTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "Per-purpose daily budget cap skips gracefully (log + null, no HTTP) at cap; below cap increments; null categorization budget = uncapped"
    verification:
      - kind: unit
        ref: "tests/Unit/ClaudeBudgetTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "28-day activity gate blocks AI dispatch for inactive users on GenerateOptimizationReport + NarrateOptimizationFindings; active users not gated"
    verification:
      - kind: e2e
        ref: "tests/Feature/ActivityGateTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "GET /api/admin/ai-usage returns per-purpose 7-day counters for admins; 403 for non-admins"
    verification:
      - kind: e2e
        ref: "tests/Feature/ActivityGateTest.php (admin ai-usage cases)"
        status: pass
    human_judgment: false
  - id: D6
    description: "Categorization resolves model_categorization (Haiku, global fallback) + increments day-counter; confidence-threshold routing proven unchanged (D17.2 safety net)"
    verification:
      - kind: unit
        ref: "tests/Unit/Services/TransactionCategorizerServiceTest.php (6 passed unchanged)"
        status: pass
    human_judgment: false
  - id: D7
    description: "Every present Phase-14 deterministic-copy config template string is free of banned assertive/guaranteed-savings phrases (SAFE-01)"
    verification:
      - kind: unit
        ref: "tests/Unit/BannedPhraseTemplatesTest.php"
        status: pass
    human_judgment: false

# Metrics
duration: 35min
completed: 2026-07-02
status: complete
---

# Phase 14 Plan 01: Config Foundations & D17 AI Cost Discipline Summary

**Three additive scenario/objective config files plus D17 template-first-and-Claude-last plumbing: per-call-site Haiku model resolution, per-purpose daily budget caps with Cache counters, a 28-day activity gate on background AI, and a GET /api/admin/ai-usage spend surface — all additive, confidence-routing safety net proven intact.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-07-02
- **Tasks:** 3
- **Files modified:** 10 modified, 7 created

## Accomplishments
- Created `config/optimization-objectives.php` — the single source of truth for the three readiness objectives (take_home / tax_burden / retirement) with full §A.2 per-fact source chains, plus the `bonus_election` scenario domain (D15 groundwork, tagged `is_scenario_domain`), M3-compliant `fact_aliases` (no w4↔profile alias), the `pay_periods_per_year` map (M8, lives only here), 33 deterministic `question_templates` (SCN-03 zero-Claude gap questions), and `prerequisites` gate pairs.
- Created `config/optimizer-scenarios.php` — assumptions, grids, §C.1 divergence epsilons, tax_objective_priority, §C.3 tradeoff_templates, §D.5 checklist_templates skeleton; no `readiness` key (M5), no pay-frequency map (M8).
- Added `detection.odc_amount = 500` (IRC §24(h)(4), DRIFT-04).
- D17 per-call-site model config: narration/wording → `claude-haiku-4-5`, extraction → global, categorization → `claude-haiku-4-5` (D17.2), with global fallback everywhere.
- D17 budget discipline: every sanctioned Claude call site (NarrationService, OptimizationReportNarratorService, InterviewOrchestratorService::wordQuestion, TransactionCategorizerService) increments a `claude_calls_{purpose}_{date}` Cache counter and skips gracefully at the configured cap; categorization defaults to uncapped so throughput is unchanged.
- Template-first zero-Claude finding narration via `optimization-report.finding_narration_templates`.
- 28-day activity gate added to `GenerateOptimizationReport` and `NarrateOptimizationFindings` (background paths only).
- `GET /api/admin/ai-usage` per-purpose 7-day counter surface, nested in the existing admin middleware group.

## Task Commits

1. **Task 1: Three additive config files** - `1b5c778` (feat)
2. **Task 2: D17 model config + template-first + budget caps + activity gate** - `c82cdfa` (feat)
3. **Task 3: Admin ai-usage endpoint + D17/SCN-03 test harness** - `e9677fa` (feat)

## Files Created/Modified
- `config/optimization-objectives.php` - objectives fact map + aliases + templates + prerequisites + bonus_election
- `config/optimizer-scenarios.php` - assumptions/grids/divergence/priority/tradeoff+checklist templates
- `config/tax-rules.php` - detection.odc_amount = 500
- `config/services.php` - per-purpose anthropic model keys + daily_budget_* caps
- `config/optimization-report.php` - finding_narration_templates (template-first)
- `app/Services/NarrationService.php` - model_narration + template-first early return + budget helper
- `app/Services/OptimizationReportNarratorService.php` - model_narration + narration budget guard
- `app/Services/InterviewOrchestratorService.php` - wordQuestion model_wording + wording budget guard
- `app/Services/AI/TransactionCategorizerService.php` - model_categorization + categorization counter/guard (routing untouched)
- `app/Jobs/GenerateOptimizationReport.php` - 28-day activity gate
- `app/Listeners/NarrateOptimizationFindings.php` - 28-day activity gate
- `app/Http/Controllers/Admin/AiUsageController.php` - GET ai-usage 7-day counters
- `routes/api.php` - GET /api/admin/ai-usage inside admin group
- `tests/Unit/TemplateFirstNarrationTest.php`, `tests/Unit/ClaudeBudgetTest.php`, `tests/Unit/BannedPhraseTemplatesTest.php`, `tests/Feature/ActivityGateTest.php`

## Decisions Made
- Mirrored (not shared) `checkAndIncrementBudget()` per service — keeps the change strictly additive and each service independently testable without a new shared trait/dependency.
- `finding_narration_templates` shipped as qualitative educational copy; `renderTemplate()` supports a `{value}` token substitution mechanism (satisfying the "figure from the finding row at render time" contract) but the shipped copy stays qualitative to preserve the SAFE no-assertive-dollar narration discipline.
- Categorization daily budget defaults to `null` (uncapped) so existing batch throughput is byte-for-byte unchanged; the counter still always increments for the admin surface.

## Deviations from Plan
None - plan executed exactly as written. No auto-fixes required (Rules 1-4 not triggered); all changes additive per CLAUDE.md safety rules; `.env`/`.env.example` untouched (rule 12).

## Issues Encountered
None. The pre-existing `DashboardFinancialBlocksTest` failure (unrelated to this plan) remains the only full-suite failure and is explicitly excluded by the plan's gate. Pint auto-formatted the tradeoff_templates and narrator phpdoc alignment (expected, no behavior change).

## Verification Results
- `php artisan test --compact --filter=TemplateFirstNarration` — PASS
- `php artisan test --compact --filter=ClaudeBudget` — PASS
- `php artisan test --compact --filter=ActivityGate` — PASS
- `php artisan test --compact --filter=BannedPhraseTemplates` — PASS
- `php artisan test --compact --filter=TransactionCategorizerService` — 6 passed, UNCHANGED (D17.2 safety net proven)
- `php artisan test --compact` — **835 passed, 1 failed (pre-existing DashboardFinancialBlocksTest only), 1 risky (pre-existing)**; new D17/SCN-03 tests: 13 passed (35 assertions)
- `vendor/bin/pint --dirty` — clean

## User Setup Required
None - no external service configuration required. New env keys (`ANTHROPIC_MODEL_NARRATION`, `ANTHROPIC_MODEL_WORDING`, `ANTHROPIC_MODEL_EXTRACTION`, `ANTHROPIC_MODEL_CATEGORIZATION`, `CLAUDE_DAILY_BUDGET_*`) all have config-level defaults; `.env` was intentionally NOT modified (CLAUDE.md rule 12). The owner may add explicit caps later without a code change.

## Next Phase Readiness
- 14-05 (readiness + enqueue) reads `optimization-objectives.php` objectives/question_templates/prerequisites and ships the SCN-03 zero-Claude enqueue proof (ObjectiveEnqueueTest).
- 14-09 (calendar watchers) reads the `bonus_election` scenario domain.
- Every downstream Claude call site now has the D17 model + budget pattern to reuse.
- BannedPhraseTemplatesTest auto-covers 14-09 monitor/DOC-05 copy once those config keys land.

---
*Phase: 14-action-center-scenarios-design-elevation*
*Completed: 2026-07-02*

## Self-Check: PASSED
All 8 created files verified present; all 3 task commits (1b5c778, c82cdfa, e9677fa) verified in git history.
