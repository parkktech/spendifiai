# Phase 14: Action Center, Scenarios & Design Elevation - Research

**Researched:** 2026-07-02
**Domain:** Optimization scenarios engine, action center lifecycle surface, ChangeMonitor orchestration, design elevation token system
**Confidence:** HIGH (codebase-grounded — every claim verified against live files on release/v2.0.0)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- D9 — Action Center: actionable checklists with quantified benefit lines; per-item done-state persisted; D9.7 illustration rules for long-horizon projections; fact-gated directives; rollout to findings cards, interview wrap-up, report "User Actions Needed" section.
- D10 — Optimization Scenarios engine: three objectives (take_home, tax_burden, retirement); deterministic computation over 6-knob grid; cross-objective comparison; Option A/B/Balanced; single merged plan when objectives agree; user's pick IS the election; full design in SCENARIOS-SPEC.md.
- D12 — Design posture: LUXURY LEAN; extend sw-* token system with 41 additive tokens; taste-skill v2 full procedure per frontend task; full token set + component recipes + rollout waves in DESIGN-ELEVATION-SPEC.md; LOCKED.
- D13 — Staleness policy + benefit-verification loop: 30-day freshness window; immediate-stale on user-action events (scenario choice, checklist state changes added); built_against material-change comparison; D13.5 benefit-verification loop reuses SavingsLedger claimed→verified pattern.
- D14 — Proactive change monitor + doc-refresh prompts: ChangeMonitor detects income shifts (≥2 pay-cycle persistence), CrossSourceReview discrepancies, life-event triggers; creates OptimizationFinding(change_detected) + AIQuestion + DOC-05 doc request; cadence guard; one service for both verification watch and change detection.
- D15 — Bonus optimization: pre-bonus election alerts with 3-option scenario set; bonus_election objective domain in config/optimization-objectives.php; ChangeMonitor calendar extension (first calendar-event domain).
- D16 — Unified Action Center: ONE persistent to-do surface aggregating checklist steps, bonus alerts, change-monitor doc requests, year-end items; Stage-0 onboarding items (link bank, cards, emails, interview, upload paystub); big checkmarks; year-end items GATED on confirmed business context with HONESTY GUARDRAIL.
- D17 — AI COST DISCIPLINE (BINDING): template-first/Claude-last; per-call-site model config (model_narration → haiku, model_extraction → sonnet); extend 28-day activity gate to ALL AI-triggering paths; no mass backfills; per-service daily call counters in Admin + config daily budget caps.

### Claude's Discretion
(None designated — all decisions D9–D17 are locked.)

### Deferred Ideas (OUT OF SCOPE)
- Debt-optimization tier (D11) — gated to P13 sign-off or Future milestone
- State tax modeling (STATE-01) — federal-only
- FSA computed scenarios — awareness-only until FSA annual limit constant confirmed
- Advertising/ads system — Future
- STORE-04 contemporaneous log features — Future
- YEAR-01 full Q4 calendar engine — Future (Phase 14 lays the watcher infrastructure only)
- P13 Safety, Validation & Hardening — runs last after P14 ships
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ACT-01 | Lifecycle-adaptive persistent to-do surface (Dashboard widget + Optimize page; nav badge) | Action Center API + composed query pattern; Inertia prop extension |
| ACT-02 | Stage-0 onboarding items derived deterministically from connection/profile state | User.hasBankConnected, User.hasEmailConnected, BankAccount.type='credit', InterviewSession.status, UserFinancialProfile.employment_type — no DB storage needed |
| ACT-03 | Every Action Center item carries quantified benefit line and due date | benefit_line_params JSON on checklist items; deadline field already on OptimizationFinding |
| ACT-04 | Done → claimed state in ChangeMonitor 2-4-week window → verified when change materializes | SavingsLedger pattern; ChangeMonitor verification side |
| ACT-05 | Empty-list state as achievement moment | DESIGN-ELEVATION-SPEC §3.9 premium empty state template |
| SCN-01 | config/optimization-objectives.php + ObjectiveReadinessService | New config file (T1); new service (T5) |
| SCN-02 | ScenarioFactResolverService + ScenarioFactSet migration | New service (T3); new migration (T4) |
| SCN-03 | POST enqueue endpoint with deterministic templates, zero Claude | T6; Http::fake() assertion in tests |
| SCN-04 | TaxRulesEngineService += SCN-01…SCN-07 pure methods; ACA-cliff guard; 200-baseline property test | T2; new engine methods; CI property test |
| SCN-05 | ScenarioSolverService: 6-knob solver over 3 objectives | T7 |
| SCN-06 | Three options or merged; side-by-side comparison; Illustration badge on FV figures | T12 frontend |
| SCN-07 | choose → recompute server-side → snapshot FactSet → persist facts → materialize checklist → mark report stale | T9; uses MarkOptimizationReportStale pattern |
| SCN-08 | Materialized checklist items as fact-gated imperatives; benefit lines from attributeBenefits | T8 + T13 frontend |
| MON-01 | ChangeMonitor: verification watch + change detection; cadence guard; ≥2 pay-cycle persistence | New service; scheduled task integration |
| MON-02 | Predictive calendar watchers; bonus lead-time alerts; year-end items | ChangeMonitor extension; optimization_calendar_events table |
| ELEV-01 | Wave 1: 41 additive tokens in app.css + AuthenticatedLayout.tsx shell polish | Exact insertion point identified |
| ELEV-02 | Wave 2: StatCard, SubscriptionCard, Badge, Dashboard, Subscriptions, Transactions | Component recipe from DESIGN-ELEVATION-SPEC §3.3–3.8 |
| ELEV-03 | Wave 3: all new Phase-14 UI components born-premium | DESIGN-ELEVATION-SPEC §3.11 canonical recipe |
</phase_requirements>

---

## Summary

Phase 14 builds on a well-structured Phase 12/13 foundation. The critical shipped pieces are confirmed:
`OptimizationReport.built_against` exists, `ReportStalenessPolicy` service is complete, `MarkOptimizationReportStale` handles three event types, and the stale-while-revalidate controller pattern is in place. The 28-day activity gate exists in scheduled tasks but not yet in background job dispatches or narration call sites. Six new config files/keys, five new services, three new controllers, two new migrations, and the complete frontend scenarios stage are greenfield work.

The SCENARIOS-SPEC.md merge-fix table (§M, 16 entries) is normative — every plan task MUST reference it. The most important merge fixes for planners: M1 (`pay.frequency` not `income.pay_frequency`), M3 (do NOT alias `w4.filing_status` ↔ `profile.filing_status`), M4 (objective ids: `take_home`/`tax_burden`/`retirement` — not `income`/`tax`), M5 (`ObjectiveReadinessService` is the ONLY readiness source), M6 (`enqueueGaps()` is the ONLY enqueue path), M9 (exactly TWO additive migrations: `scenario_fact_sets` + `optimization_checklist_items`), M12 (W-4 facts are optional-with-suppression, NOT blocking).

**Primary recommendation:** Follow the SCENARIOS-SPEC §I wave order exactly (T1→T2→T3→T4→T5→T6→T7→T8→T9→T10→T11→T12→T13→T14), front-loading pure config and engine work (Waves 1–2) before controller/frontend work (Waves 3–4). This order avoids circular dependencies and allows each wave to be independently tested with `Http::fake()`+`assertNothingSent()` guards before wiring the next.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Scenario math (knob vectors, tax/FICA, FV) | API / Backend (TaxRulesEngineService) | — | All dollar math stays in the engine; zero Claude, zero frontend |
| Objective readiness | API / Backend (ObjectiveReadinessService) | — | Must reflect an answer given 1 second ago; no caching in v1 |
| Fact resolution | API / Backend (ScenarioFactResolverService) | — | Read-side; must reflect just-confirmed facts |
| Template question creation | API / Backend (InterviewOrchestratorService additive) | — | Deterministic templates; skip Claude for these |
| Scenario clamping / guarding | API / Backend (ScenarioSolverService) | — | ACA cliff guard is arithmetic, not narration |
| Action Center aggregation | API / Backend (ActionCenterController) | — | Composed query; Stage-0 items derived from DB state |
| ChangeMonitor detection | API / Backend (ChangeMonitor + scheduled) | — | Bank data analysis; not UI-visible until findings created |
| Checklist done-state + reality facts | API / Backend (OptimizationChecklistController) | — | Writes UserTaxFact; needs auth'd user context |
| Design elevation (tokens) | CDN / Static (app.css @theme) | — | Pure CSS vars; additive to existing sw-* block |
| Design elevation (components) | Frontend (TSX components) | — | Class replacements; no behavior change |
| Scenario comparison UI | Frontend (Optimize/Index.tsx + new components) | API | 3-up card grid with live compute round-trips |
| Action Center widget (Dashboard) | Frontend (Dashboard.tsx + ActionCenterWidget) | API | useApi with enabled=hasBankConnected |

---

## Standard Stack

### Core (all VERIFIED: live files on release/v2.0.0)

| Component | Location | Purpose | Status |
|-----------|----------|---------|--------|
| TaxRulesEngineService | app/Services/TaxRulesEngineService.php | Pure computation; SCN-01..07 methods to be added | VERIFIED EXISTING |
| InterviewOrchestratorService | app/Services/InterviewOrchestratorService.php | Session state machine; additive template branch + typed conversion | VERIFIED EXISTING |
| NarrationService | app/Services/NarrationService.php | Narration; D17 per-purpose model key + template-first path to be added | VERIFIED EXISTING |
| OptimizationReportGeneratorService | app/Services/OptimizationReportGeneratorService.php | Report assembly; chosen_plan section to be added | VERIFIED EXISTING |
| OptimizationReportNarratorService | app/Services/OptimizationReportNarratorService.php | narrator; narrateScenarioComparison() to be added | VERIFIED EXISTING |
| ReportStalenessPolicy | app/Services/ReportStalenessPolicy.php | D13 freshness + material-change; complete, used by ChangeMonitor | VERIFIED EXISTING |
| MarkOptimizationReportStale | app/Listeners/MarkOptimizationReportStale.php | Flag flip; scenario-choice + checklist paths fire it directly (no new listener) | VERIFIED EXISTING |
| OptimizationReport | app/Models/OptimizationReport.php | Report model; built_against column exists | VERIFIED EXISTING |
| SavingsLedger | app/Models/SavingsLedger.php | claimed/verified pattern for D13.5 benefit-verification | VERIFIED EXISTING |
| ScenarioFactResolverService | app/Services/ | NEW; read-side fact resolution across all objective maps | TO BUILD (T3) |
| ObjectiveReadinessService | app/Services/ | NEW; readiness computation + enqueueGaps | TO BUILD (T5) |
| ScenarioSolverService | app/Services/ | NEW; 6-knob solver over 3 objectives | TO BUILD (T7) |
| ChangeMonitor | app/Services/ | NEW; unifies verification watch + change detection | TO BUILD (MON-01/02) |
| config/optimization-objectives.php | config/ | NEW; full §A.2 fact map, aliases, templates, prerequisites | TO BUILD (T1) |
| config/optimizer-scenarios.php | config/ | NEW; assumptions, grids, divergence epsilons, tradeoff/checklist templates | TO BUILD (T1) |

### Design Elevation Stack

| Component | Source | Status |
|-----------|--------|--------|
| 41 additive sw-* tokens | DESIGN-ELEVATION-SPEC.md §2 | TO ADD to app.css after line 50 |
| card-surface, card-lift, btn-press utilities | DESIGN-ELEVATION-SPEC.md §2 | TO ADD as direct CSS rules after @theme block |
| stagger-children + sw-fade-up animation | DESIGN-ELEVATION-SPEC.md §4.2 | TO ADD to app.css |
| framer-motion / Motion | NOT in package.json | DO NOT USE — CSS-only motion |

---

## Package Legitimacy Audit

Phase 14 installs zero new npm or Composer packages. All functionality is built from existing dependencies. No legitimacy audit required.

**Packages removed due to SLOP verdict:** none
**Packages flagged as suspicious:** none

---

## Architecture Patterns

### System Architecture Diagram

```
User browser ──► Optimize/Index.tsx (ViewMode: findings → interview → scenarios → report)
                        │
                        ├── GET /optimizer/objectives/{year}  ──► ObjectiveReadinessService
                        │         (readiness cards; tick-down on answer)
                        │
                        ├── POST /optimizer/objectives/{year}/{obj}/enqueue
                        │         ──► ObjectiveReadinessService::enqueueGaps()
                        │                   ──► InterviewOrchestratorService::startOrResume()
                        │                   ──► front-insert gap keys into session.queue
                        │
                        ├── GET /{interview}/next  ──► InterviewOrchestratorService::nextQuestion()
                        │         [additive: objective_tags, answer_type, choices, prefill, doc_affordance]
                        │
                        ├── GET /optimizer/scenarios/{year}  ──► ScenarioSolverService::solve()×3
                        │         ──► TaxRulesEngineService (SCN-01..07, pure cents)
                        │         ──► ObjectiveReadinessService::readiness()
                        │         [60s cache keyed on fact_set_hash]
                        │
                        ├── POST /optimizer/scenarios/{year}/compute  ──► SCN-07 (live mix panel)
                        │
                        ├── POST /optimizer/scenarios/{year}/choose
                        │         ──► server recomputes → ScenarioFactResolverService::snapshotFactSet()
                        │         ──► UserTaxFact::recordFact() ×2 (chosen_option + chosen_knobs)
                        │         ──► materialize optimization_checklist_items
                        │         ──► fire MarkOptimizationReportStale pattern (report stale)
                        │
                        └── PATCH /optimizer/checklist/items/{item}
                                  ──► done-toggle → reality-fact writes (UserTaxFact::recordFact)
                                  ──► SavingsLedger claimed record (D13.5)

Dashboard ──► GET /optimizer/action-center  ──► ActionCenterController (composed queries)
                  Stage-0 items:    BankAccount.type='credit' check + hasBankConnected + hasEmailConnected
                                    + InterviewSession.status + UserFinancialProfile.employment_type
                  Checklist items:  optimization_checklist_items WHERE done_at IS NULL
                  MON prompts:      OptimizationFinding WHERE finding_type='change_detected' AND status='open'
                  Year-end items:   OptimizationFinding WHERE deadline IS NOT NULL AND deadline > now()

Bank sync chain:
SyncBankTransactions → CategorizePendingTransactions → (event) → ChangeMonitor::checkVerificationWindows()
                                                                 → ChangeMonitor::detectIncomeShifts()
Scheduled daily (activity-gated):  ChangeMonitor::runCalendarWatchers()  [bonus lead-time alerts]
```

### Recommended Project Structure (new files only)

```
app/
├── Services/
│   ├── ScenarioFactResolverService.php   # T3 — fact resolution + derivations
│   ├── ObjectiveReadinessService.php     # T5 — readiness + enqueueGaps
│   ├── ScenarioSolverService.php         # T7 — solver + attributeBenefits + diffKnobs
│   ├── ScenarioChecklistService.php      # T8 — template→items materialization
│   └── ChangeMonitor.php                 # MON-01/02 — both watch sides + calendar
├── Models/
│   ├── ScenarioFactSet.php               # T4
│   └── OptimizationChecklistItem.php     # T8
├── Http/Controllers/Api/
│   ├── OptimizationObjectiveController.php   # T6
│   ├── ScenarioController.php                # T9
│   └── OptimizationChecklistController.php   # T9
├── Http/Requests/
│   ├── ComputeScenarioRequest.php
│   └── ChooseScenarioRequest.php
└── Policies/
    └── OptimizationChecklistItemPolicy.php   # user_id ownership

config/
├── optimization-objectives.php    # T1 — §A.3 full fact map
└── optimizer-scenarios.php        # T1 — assumptions, grids, templates

database/migrations/
├── 2026_07_XX_create_scenario_fact_sets_table.php         # T4
├── 2026_07_XX_create_optimization_checklist_items_table.php  # T8
└── 2026_07_XX_create_optimization_calendar_events_table.php  # MON-02

resources/js/
├── Components/SpendifiAI/
│   ├── ActionCenterWidget.tsx         # Dashboard widget (ACT-01, Stage-0)
│   ├── ObjectiveReadinessPanel.tsx    # T11 — readiness cards + enqueue CTA
│   ├── ScenarioComparisonCards.tsx    # T12 — 3-up grid
│   ├── ScenarioMixPanel.tsx           # T12 — customize/compute round-trip
│   └── OptimizationChecklistView.tsx  # T13 — fact-gated steps + header aggregate
└── Pages/Optimize/Index.tsx           # T11–13: 4th ViewMode 'scenarios', StageIndicator
```

---

## Spec-vs-Code Drift Report

> All findings below are HIGH confidence — verified by direct file inspection.

### DRIFT-01 (NON-BLOCKING): `InterviewController::next()` lacks Phase-14 additive fields [VERIFIED: app/Http/Controllers/Api/InterviewController.php L83-96]

**Current response shape** (lines 83–96):
```php
'question' => [
    'id', 'question', 'question_type', 'options',
    'ai_confidence', 'ai_best_guess', 'band',
    'suggested_treatment', 'transaction_count'
]
```

**Phase 14 requires adding** (SCENARIOS-SPEC §A.5.5, §E): `objective_tags`, `answer_type`, `choices`, `prefill_display`, `prefill_value`, `doc_affordance`. These are purely additive to the existing response — all are optional keys the frontend checks for presence. Existing interview frontend (InterviewCard.tsx) that doesn't read these keys is unaffected. Task T6 implements this.

**Planner action:** T6 must add these fields in the `next()` response body. The `prefill_display`/`prefill_value` are computed transiently (pointer → value lookup) at request time, never stored.

---

### DRIFT-02 (NON-BLOCKING): `InterviewOrchestratorService::recordAnswer()` hardcodes `volatility: 'stable'` and `taxYear: null` [VERIFIED: SCENARIOS-SPEC §A.5.3]

The spec documents this as the known "before" state. Task T5 adds the additive early branch: look up the template for `$factKey` in `config('optimization-objectives.question_templates')`; if found, use `volatility` and `tax_year_scoped` from the template. Non-template keys KEEP the current hardcoded behavior exactly.

**Planner action:** T5 touches `recordAnswer()` with a guard: `if (array_key_exists($factKey, config('optimization-objectives.question_templates', [])))`. The change is additive — no existing tests break.

---

### DRIFT-03 (BLOCKER if missed): `config/optimization-objectives.php` and `config/optimizer-scenarios.php` do NOT exist [VERIFIED: config/ directory listing]

Both config files are Wave 1 (T1). All services in Waves 2–4 depend on them. The plan MUST place T1 as the first parallel-safe task.

---

### DRIFT-04 (NON-BLOCKING): `detection.odc_amount` is missing from `config/tax-rules.php` [VERIFIED: grep on config/tax-rules.php]

`detection.ctc_amount` (2,200) exists. `detection.odc_amount` (500 for the W-4 "Credit for Other Dependents") does NOT exist. SCN-01's W-4 credit computation uses both. T1 must append this key inside the existing `detection` block:
```php
'odc_amount' => 500,  // [CITED: IRC §24(h)(4); $500 non-refundable, not inflation-indexed]
```

---

### DRIFT-05 (D17 GAP): Per-purpose AI model keys do not exist; single global `services.anthropic.model` only [VERIFIED: config/services.php L66-69]

`NarrationService::__construct()` reads `config('services.anthropic.model', 'claude-sonnet-4-6')`. D17 mandates `model_narration → haiku-4-5`. The migration path is additive:
1. Add to `config/services.php` anthropic block: `'model_narration' => env('ANTHROPIC_MODEL_NARRATION', 'claude-haiku-4-5')`, `'model_extraction' => env('ANTHROPIC_MODEL_EXTRACTION', env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'))`
2. Update `NarrationService::__construct()`: `$this->model = config('services.anthropic.model_narration', config('services.anthropic.model'))` 
3. Update `InterviewOrchestratorService::wordQuestion()` similarly
4. Update `OptimizationReportNarratorService` similarly
5. `PaystubFactExtractorService` / `TaxDocumentExtractorService` keep reading the global model (they are extraction, use sonnet)

The existing `NarrationService::SYSTEM_PROMPT` const and all test mocking stay identical — only the model string changes.

---

### DRIFT-06 (NON-BLOCKING): D17 activity gate exists in scheduled tasks ONLY — NOT in background job dispatch [VERIFIED: routes/console.php, app/Jobs/]

The 28-day gate at `4e75c46` covers: subscription detection, savings analysis, email sync, email retry. It does NOT cover: `GenerateOptimizationReport` job, `NarrateOptimizationFindings` listener, and the new `ChangeMonitor` scheduled work.

**Extension pattern** (same as `detect-subscriptions` in console.php):
```php
// In GenerateOptimizationReport::handle() — add at top:
$thresholdDays = config('spendifiai.sync.active_threshold_days', 28);
if ($user->last_active_at && $user->last_active_at->lt(now()->subDays($thresholdDays))) {
    Log::info('GenerateOptimizationReport: skipped inactive user', ['user_id' => $userId]);
    return;
}
```
Apply the same guard to `NarrateOptimizationFindings` and ChangeMonitor scheduled closures.

**Note:** The gate should NOT apply to on-demand endpoints (the user is clearly active if they're hitting an endpoint). It applies only to scheduled/background AI calls.

---

### DRIFT-07 (D17 GAP): No call-counter infrastructure or daily budget caps exist [VERIFIED: no cache counter pattern found in any service]

D17 requires per-service daily call counters in the Admin drawer and config daily budget caps. This is new infrastructure:
```php
// Pattern to add to NarrationService and any new Claude call sites:
$date = now()->toDateString();
$key = "claude_calls_narration_{$date}";
$cap = config('services.anthropic.daily_budget_narration', PHP_INT_MAX);
if ((int) Cache::get($key, 0) >= $cap) {
    Log::info('NarrationService: daily budget cap hit, skipping');
    return null;
}
Cache::increment($key);
// ... proceed with API call
```
Config addition in `services.php`: `'daily_budget_narration' => env('CLAUDE_DAILY_BUDGET_NARRATION', 200)`. Admin endpoint `GET /api/admin/ai-usage` returns the Redis cache counters for the current day.

---

### DRIFT-08 (ACTION CENTER): No `hasCreditCards` User method or Inertia prop exists [VERIFIED: User.php, HandleInertiaRequests.php]

Stage-0 item "Link your credit cards" (ACT-02) checks whether the user has credit card accounts (Plaid `type='credit'`). `BankAccount` model has `type` and `subtype` Plaid fields. No `hasCreditCards()` helper exists on User.

**Decision:** The Action Center API endpoint (`GET /api/v1/optimizer/action-center`) computes Stage-0 states server-side and returns them as a structured response — no new Inertia prop required. Credit card check:
```php
$hasCreditCards = BankAccount::where('user_id', $userId)->where('type', 'credit')->exists();
```
This keeps the credit-card check out of the middleware and avoids a DB query on every page load.

---

### DRIFT-09 (ACTION CENTER NAV BADGE): `pendingOptimizationCount` needs extension for checklist items

`HandleInertiaRequests` already shares `pendingOptimizationCount` (open findings with narrated description). Phase 14 (ACT-01) needs the nav badge to also count unchecked Action Center checklist items.

**Recommended approach:** Add a separate Inertia prop `pendingActionCount` in `HandleInertiaRequests` that queries `optimization_checklist_items WHERE user_id=$id AND done_at IS NULL` (guarded by `hasBankConnected`). Keep `pendingOptimizationCount` unchanged for backwards compatibility. Frontend combines both into the badge display.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Fact source-priority chain | Custom resolver logic | `ScenarioFactResolverService` with config-driven chain array | Config-driven chains are testable per §T.1; custom ad-hoc reads break alias fallback |
| Readiness computation | Per-objective if-chains | `ObjectiveReadinessService::readiness()` consuming `resolveAll()` | SCENARIOS-SPEC M5: single readiness source; avoids duplicate computation |
| Scenario option selection | Client-side option filtering | Server always emits all computed options; client reads the `readiness` block | M5 normative |
| Knob clamping in the controller | Validation-only guards | Engine clamps a COPY inside `computeScenarioOutcome()` regardless of input | Clamping is the security boundary; validation is UX nicety only |
| Gap question phrasing | New Claude call | Template from `config/optimization-objectives.php question_templates` | D17 mandate: zero Claude for template questions; §T.5 asserts via Http::fake() |
| Trade-off one-liners | New Claude call | `optimizer-scenarios.tradeoff_templates` with token substitution | D17 mandate; §C.3 in spec |
| Checklist step text | New Claude call | `optimizer-scenarios.checklist_templates` with token slots | D17 mandate; §D.5 in spec |
| ACA cliff guard as a separate service | Standalone guard logic | Hard guard inside `computeScenarioOutcome()` (Step 2 of §B.4) | Cliff-before-Roth must be arithmetic inside the engine — narration ordering alone is insufficient |
| Income shift detection threshold | Inline constant | `optimization-report.material_change.income_pct` config | All thresholds in config; materiality-test pattern from FLAG-08 |
| ChangeMonitor cadence tracking | Time-based query | One prompt per detected change per freshness window — track via OptimizationFinding.created_at dedupe | Reuses the existing staleness window concept; no new table needed for cadence |

---

## Common Pitfalls

### Pitfall 1: Aliasing `w4.filing_status` ↔ `profile.filing_status` in the resolver (MERGE FIX M3)
**What goes wrong:** K1 withholding alignment math silently uses the confirmed filing status instead of the W-4-on-file evidence, masking the divergence the feature is supposed to surface.
**Why it happens:** Part 1 of the original spec included this alias; it was deleted in the merged spec.
**How to avoid:** `config/optimization-objectives.php` fact_aliases array must NOT contain either of these as an alias for the other. `ScenarioFactResolverService` tests must assert both keys resolve independently (§T.1 M3 regression test).
**Warning signs:** `ProfileConformanceDetector` tests fail because the two-key comparison collapses.

---

### Pitfall 2: Wrong objective id strings (MERGE FIX M4)
**What goes wrong:** Frontend, config, and API pass `'income'`/`'tax'` and the server can't match them.
**Why it happens:** Part 2 used `income`/`tax` as ids; the merged spec uses `take_home`/`tax_burden`.
**How to avoid:** The config validation `{objective}` validated against `config('optimization-objectives.objectives')` keys → 404 catches this at runtime. Tests should POST `'income'` and assert 404.
**Warning signs:** Readiness panel shows no options for `'tax_burden'`.

---

### Pitfall 3: Storing resolved money values in `AIQuestion.options` (SAFE-03 / §A.5.5)
**What goes wrong:** A cent value stored in the unencrypted `options` JSON column is visible in DB, logs, and API responses. This is a liability and a test failure.
**Why it happens:** Suggested-confirm questions show a prefill value — it's tempting to cache it in the question row.
**How to avoid:** Use the `prefill_source` pointer pattern (`'prefill_source': 'snapshot:w2_wages'`) in `options`. Resolve at `InterviewController::next()` read time as a transient response field. Test: assert no `/\d{4,}/` pattern in `options` JSON (§T.6 regression).
**Warning signs:** §T.6 test fails: "prefill value persisted in options".

---

### Pitfall 4: Using `$request->user()->id` in ScenarioController::choose() without server-side recomputation
**What goes wrong:** A malicious client submits a manipulated knob vector; the server trusts it and materializes a checklist with incorrect benefit lines.
**Why it happens:** The client already has the solver output from the GET; it's tempting to just write it.
**How to avoid:** `choose` ALWAYS recomputes from the server's own solver output. Never trust client knob values for materialization. The `ChooseScenarioRequest` only validates the option_key; knobs are derived server-side from the validated key (or from client input only for `custom`, after server-side clamping).

---

### Pitfall 5: Calling `TaxRulesEngineService` methods with dollars instead of cents
**What goes wrong:** Tax computation is off by 100x; subtle because the engine returns cents which get divided by 100 for display.
**Why it happens:** Config values are stored as whole dollars (e.g., `standard_deduction: 15_000`); the engine converts to cents internally. Callers must pass cents to SCN-01..07.
**How to avoid:** `assembleBaseline()` converts all YTD and income values to cents before passing them. Tests (§T.9) verify exact cent outputs against hand-computed values.
**Warning signs:** SCN-03 match capture returns 100x the expected amount.

---

### Pitfall 6: Adding the Design Elevation token block BEFORE the existing @theme { } instead of after it (ELEV-01)
**What goes wrong:** Tailwind v4 @theme block merging behavior may not work correctly; variables shadow each other.
**Why it happens:** `app.css` line 11 opens `@theme {` and line 50 closes it. The insertion point is immediately AFTER line 50 (the `}`).
**How to avoid:** The spec is explicit: "Add the following block IMMEDIATELY AFTER the existing `@theme { }` block." The new block is a SECOND `@theme { }` declaration, not content inside the first one.

---

### Pitfall 7: Bypassing the `min_take_home_ratio` floor in solver objects
**What goes wrong:** A scenario proposes zero take-home (all income into retirement) which is financially dangerous and legally educational-frame-breaking.
**Why it happens:** Greedy fill in `TAX_BURDEN` solver fills 401(k) then IRA without checking floor.
**How to avoid:** After each step in the greedy fill, check `takeHome(candidate) >= min_take_home_ratio * takeHome(current)`. The floor config key is `optimizer-scenarios.assumptions.min_take_home_ratio` (0.90, tightened to 0.97 when `finance.is_cash_constrained`). Test: assert take-home delta never causes take-home below 90% of baseline.

---

### Pitfall 8: ChangeMonitor fires for every bank sync (cadence guard gap)
**What goes wrong:** Users get income-shift prompts weekly; trust is eroded; the feature becomes noise.
**Why it happens:** `CategorizePendingTransactions` fires after every sync; ChangeMonitor is wired to it.
**How to avoid:** ChangeMonitor's detection side checks: (a) ≥2 pay cycles of persistence (not just one sync), (b) no open OptimizationFinding with the same `finding_key` + `finding_type='change_detected'` in the current freshness window, (c) 28-day user activity gate. Only when all three pass does it emit a finding.

---

### Pitfall 9: framer-motion not available — CSS-only motion required (ELEV-01..03)
**What goes wrong:** Import of `motion/react` crashes the build.
**Why it happens:** framer-motion is NOT in `package.json` (verified: no `framer-motion` or `motion` in deps).
**How to avoid:** DESIGN-ELEVATION-SPEC §4 says "If Motion (framer-motion) is already in package.json, it may be used." It is NOT. All animations must use CSS `@keyframes`, Tailwind utilities, and the `stagger-children` CSS utility class. No JS-driven animations.

---

## D17 AI Cost Discipline — Implementation Guide

### Template-First Architecture

**Gap questions** (SCENARIOS-SPEC §A.3): When `$factKey` exists in `config('optimization-objectives.question_templates')`, `createOptimizationQuestion()` builds the `AIQuestion` from the template and skips `wordQuestion()` entirely. This is the additive early branch in T5.

**Trade-off lines** (SCENARIOS-SPEC §C.3): Built from `config('optimizer-scenarios.tradeoff_templates')` with token substitution. Zero Claude.

**Checklist steps** (SCENARIOS-SPEC §D.5): Built from `config('optimizer-scenarios.checklist_templates')` with token slots filled from `attributeBenefits()` output. Zero Claude.

**When Claude IS called** (sanctioned paths only):
1. `NarrationService::narrateFinding()` — finding prose only (no $ figures in payload)
2. `OptimizationReportNarratorService::narrateSection()` — section overviews
3. `OptimizationReportNarratorService::narrateScenarioComparison()` — 2-3 sentence intro (new, §C.3; knob names + guard flags only, no cents)
4. `InterviewOrchestratorService::wordQuestion()` — only for NON-template fact keys

### Per-Purpose Model Resolution Pattern

```php
// services.anthropic config additions (additive):
'model'            => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),      // global fallback
'model_narration'  => env('ANTHROPIC_MODEL_NARRATION', 'claude-haiku-4-5'),  // finding prose
'model_extraction' => env('ANTHROPIC_MODEL_EXTRACTION', env('ANTHROPIC_MODEL', 'claude-sonnet-4-6')),  // doc extraction
'model_wording'    => env('ANTHROPIC_MODEL_WORDING', 'claude-haiku-4-5'),    // interview wording
'daily_budget_narration'  => env('CLAUDE_DAILY_BUDGET_NARRATION', 200),
'daily_budget_wording'    => env('CLAUDE_DAILY_BUDGET_WORDING', 100),

// Resolution pattern (fallback chain):
$this->model = config('services.anthropic.model_narration', config('services.anthropic.model'));
```

### Call Counter Pattern (Cache-based, Redis-backed)

```php
// In NarrationService (and each Claude call site):
private function checkAndIncrementBudget(string $purpose): bool
{
    $date = now()->toDateString();
    $key = "claude_calls_{$purpose}_{$date}";
    $cap = config("services.anthropic.daily_budget_{$purpose}", PHP_INT_MAX);
    $current = (int) Cache::get($key, 0);
    if ($current >= $cap) {
        Log::info("Claude daily budget cap hit: {$purpose}", ['date' => $date, 'cap' => $cap]);
        return false;  // skip call
    }
    Cache::increment($key, 1);
    return true;  // proceed
}
```

**Admin surface:** `GET /api/admin/ai-usage` returns `Cache::get("claude_calls_{$purpose}_{$date}")` for each purpose for the last 7 days.

### Activity Gate Extension (from commit 4e75c46 precedent)

```php
// Add at top of GenerateOptimizationReport::handle() and NarrateOptimizationFindings::handle():
$thresholdDays = config('spendifiai.sync.active_threshold_days', 28);
$user = User::find($this->userId);
if ($user && $user->last_active_at && $user->last_active_at->lt(now()->subDays($thresholdDays))) {
    Log::info(self::class . ': skipped inactive user', ['user_id' => $this->userId]);
    return;
}
```

The gate applies to: scheduled runs, background jobs triggered by cron. It does NOT apply to user-initiated API requests (they are by definition active).

---

## ChangeMonitor Design

### Placement and Cadence

```php
final class ChangeMonitor
{
    // Verification side (D13.5): called from scheduled daily task AFTER bank sync
    public function checkVerificationWindows(int $userId, int $taxYear): void;

    // Detection side (D14): called from scheduled daily task, activity-gated
    public function detectIncomeShifts(int $userId, int $taxYear): void;

    // Calendar side (D15/MON-02): called from scheduled daily task
    public function runCalendarWatchers(int $userId, int $taxYear): void;
}
```

**Scheduling** (additive to routes/console.php):
```php
// Daily at 6am — ChangeMonitor verification + detection (activity-gated)
Schedule::call(function () {
    $thresholdDays = config('spendifiai.sync.active_threshold_days', 28);
    $taxYear = (int) date('Y');
    $monitor = app(ChangeMonitor::class);
    User::whereHas('bankConnections')
        ->where('last_active_at', '>', now()->subDays($thresholdDays))
        ->each(function ($user) use ($monitor, $taxYear) {
            $monitor->checkVerificationWindows($user->id, $taxYear);
            $monitor->detectIncomeShifts($user->id, $taxYear);
            $monitor->runCalendarWatchers($user->id, $taxYear);
        });
})->dailyAt('06:00')->name('change-monitor');
```

### Persistence: `optimization_calendar_events` (MON-02)

```php
Schema::create('optimization_calendar_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->integer('tax_year');
    $table->string('event_type', 50);      // 'bonus' | 'year_end_purchase' | ...
    $table->timestamp('expected_at');
    $table->unsignedSmallInteger('lead_time_days')->default(21);
    $table->timestamp('alert_fired_at')->nullable();
    $table->json('metadata')->nullable();   // no money values — only periods/types
    $table->timestamps();
    $table->index(['user_id', 'tax_year']);
});
```

### Income Shift Persistence Filter (≥2 pay cycles)

The `ReportStalenessPolicy::isMaterialChange()` method already compares `income_cents` and `savings_cents` against `built_against` snapshot with config thresholds. ChangeMonitor's income-shift detection uses the SAME comparison:

```php
// In ChangeMonitor::detectIncomeShifts():
$report = OptimizationReport::forUser($userId)->where('tax_year', $taxYear)->first();
$profile = IncomeOptimizationProfile::forUser($userId)->where('tax_year', $taxYear)->first();
if (!ReportStalenessPolicy::isMaterialChange($report, $profile)) {
    return; // no material shift
}
// Check persistence: has this been true for ≥2 pay cycles (≈60 days)?
if ($report->stale_since && $report->stale_since->gt(now()->subDays(60))) {
    return; // shift detected but hasn't persisted ≥2 cycles yet
}
// Emit OptimizationFinding(finding_type='change_detected') + AIQuestion + DOC-05 request
```

---

## Action Center Data Model

**Architectural decision: One `ActionCenterController` using composed queries, NOT a new `ActionItem` model.**

Rationale: Action items span three different source tables (checklist items, findings/MON prompts, calendar events) with fundamentally different schemas. A unified model would require polymorphism that adds complexity without benefit. A composed query in the controller is simpler, more performant, and directly testable.

**Response shape** (GET /api/v1/optimizer/action-center):

```json
{
  "stage0_items": [
    {"id": "link_bank", "title": "Link your bank account", "completed": false, "unlocks": "..."},
    {"id": "link_credit_cards", "title": "Link your credit cards", "completed": false},
    {"id": "link_email", "title": "Connect your email", "completed": false},
    {"id": "do_interview", "title": "Complete the optimization interview", "completed": false},
    {"id": "upload_paystub", "title": "Upload a pay stub", "completed": false}
  ],
  "checklist_items": [...],    // from optimization_checklist_items (unchecked, benefit_line_params)
  "monitor_prompts": [...],    // from OptimizationFinding WHERE finding_type='change_detected'
  "calendar_items": [...],     // from optimization_calendar_events WHERE alert ready
  "total_open": 5,
  "empty": false
}
```

Stage-0 completion checks:
- `link_bank`: `User::hasBankConnected()`
- `link_credit_cards`: `BankAccount::where('user_id', $id)->where('type', 'credit')->exists()`
- `link_email`: `User::hasEmailConnected()`
- `do_interview`: `InterviewSession::forUser($id)->where('status', 'completed')->exists()`
- `upload_paystub`: `TaxDocument::where('user_id', $id)->where('category', 'pay_stub')->where('status', 'ready')->exists()` (or equivalent)

Stage-0 items disappear once completed — the endpoint always recomputes. No done-state persistence needed for Stage-0 items.

---

## Scenario Engine Integration Points

### `OptimizationReportGeneratorService` — chosen_plan section (T10)

The spec (§D.7) says a new section is injected when `scenario.chosen_option` fact exists. Pattern:
```php
// In OptimizationReportGeneratorService::generate():
$chosenFact = UserTaxFact::currentFact($user->id, 'scenario.chosen_option', null, $taxYear);
if ($chosenFact !== null) {
    $chosenSection = $this->buildChosenPlanSection($user, $taxYear, $chosenFact);
    // Insert BEFORE 'documents_missing' wrapper section
    array_splice($sections, $documentsWrapperIndex, 0, [$chosenSection]);
}
```

The `narrateScenarioComparison()` narrator call receives ONLY: chosen option key, three metric LABELS (not amounts), diverging knob names, and guard flag names. Zero cents in the payload.

### Report Stale Triggers Added by Phase 14

Phase 14 adds two more immediate-stale events, per D13:
- `scenario_choice`: `ScenarioController::choose()` fires `MarkOptimizationReportStale` directly after writing the facts (not via a new listener — the controller calls the existing pattern inline)
- `checklist_state_change`: `OptimizationChecklistController::update()` does the same

No new listener registrations required. These are user-action events (immediate-stale path in `ReportStalenessPolicy`).

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest PHP 3 (existing: 131 tests, 459 assertions) |
| Config file | `phpunit.xml` / Pest configuration |
| Quick run command | `php artisan test --compact --filter=Scenario` |
| Full suite command | `php artisan test --compact` |

### Phase Requirements → Test Map (Phase 14 new tests only)

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SCN-01 | ObjectiveReadinessService: blocking/optional/not_applicable states | Unit | `php artisan test --compact --filter=ObjectiveReadiness` | No — Wave 0 |
| SCN-01 | T4 suppression: W-4 facts optional-with-suppression, not blocking | Unit | `php artisan test --compact --filter=ObjectiveReadiness` | No — Wave 0 |
| SCN-02 | Resolver source-priority: identity fact-first, money snapshot-first | Unit | `php artisan test --compact --filter=ScenarioFactResolver` | No — Wave 0 |
| SCN-02 | M3 regression: w4.filing_status and profile.filing_status resolve independently | Unit | `php artisan test --compact --filter=ScenarioFactResolver` | No — Wave 0 |
| SCN-02 | FactSet HMAC stability + isStale() + encrypted hidden | Unit | `php artisan test --compact --filter=ScenarioFactSet` | No — Wave 0 |
| SCN-03 | Template questions: zero Claude (`Http::fake()->assertNothingSent()`) | Feature | `php artisan test --compact --filter=ObjectiveEnqueue` | No — Wave 0 |
| SCN-03 | Typed conversion: money→cents; choice validation 422 on mismatch | Feature | `php artisan test --compact --filter=ObjectiveEnqueue` | No — Wave 0 |
| SCN-04 | SCN-01..07 vs hand-computed bracket values | Unit | `php artisan test --compact --filter=TaxRulesEngineScenario` | No — Wave 0 |
| SCN-04 | ACA invariant: 200 randomized marketplace baselines | Property | `php artisan test --compact --filter=AcaInvariant` | No — Wave 0 (CI profile) |
| SCN-04 | No-literal guard: solver + engine methods have no raw threshold literals | Static grep | `php artisan test --compact --filter=NoLiteralGuard` | No — Wave 0 |
| SCN-05 | Solver determinism: same baseline → identical vectors (run twice) | Unit | `php artisan test --compact --filter=ScenarioSolver` | No — Wave 0 |
| SCN-05 | Objective dominance: take_home option take-home ≥ others | Unit | `php artisan test --compact --filter=ScenarioSolver` | No — Wave 0 |
| SCN-06 | Agreement rule: converged → merged option; divergent → exactly 3 options | Feature | `php artisan test --compact --filter=ScenarioController` | No — Wave 0 |
| SCN-07 | choose recomputes server-side; writes chosen_option + chosen_knobs + snapshot FactSet | Feature | `php artisan test --compact --filter=ScenarioChoose` | No — Wave 0 |
| SCN-07 | Re-choose supersedes previous choice; old checklist items deleted (scoped) | Feature | `php artisan test --compact --filter=ScenarioChoose` | No — Wave 0 |
| SCN-07 | No-Claude: zero HTTP in objectives/scenarios/checklist endpoints | Feature | `php artisan test --compact --filter=NoClaude` | No — Wave 0 |
| SCN-08 | Checklist materialization from templates; confirmed fact → directive, unconfirmed → confirm-ask | Feature | `php artisan test --compact --filter=OptimizationChecklist` | No — Wave 0 |
| SCN-08 | Done-toggle → reality-fact writes (employer.contribution_pct supersession) | Feature | `php artisan test --compact --filter=OptimizationChecklist` | No — Wave 0 |
| MON-01 | ChangeMonitor: income shift detection with ≥2 pay-cycle persistence | Feature | `php artisan test --compact --filter=ChangeMonitor` | No — Wave 0 |
| MON-01 | ChangeMonitor: verification window closes after materialization | Feature | `php artisan test --compact --filter=ChangeMonitor` | No — Wave 0 |
| ACT-01 | Action Center: Stage-0 items computed from real DB state; credit card check | Feature | `php artisan test --compact --filter=ActionCenter` | No — Wave 0 |
| D17 | Activity gate: inactive user → no Claude calls dispatched | Feature | `php artisan test --compact --filter=ActivityGate` | No — Wave 0 |
| D17 | Daily budget cap: at cap → skip + log, no API call | Unit | `php artisan test --compact --filter=ClaudeBudget` | No — Wave 0 |
| D17 | Template-first: finding_type with template → narrateFinding() returns without HTTP call | Unit | `php artisan test --compact --filter=TemplateFirst` | No — Wave 0 |
| D17 | prefill_source pointer: no money value `/\d{4,}/` in stored AIQuestion.options | Regression | `php artisan test --compact --filter=PrefillPointer` | No — Wave 0 |
| D17 | Readiness API: response body contains no money values (no `/\d{4,}/` beside year/IDs) | Regression | `php artisan test --compact --filter=ReadinessNoCents` | No — Wave 0 |
| D17 | Narrator payload: no `estimated_value_cents` in narrative call payloads | Regression grep | `php artisan test --compact --filter=NarratorNoCents` | No — Wave 0 (existing pattern) |

### Sampling Rate

- Per task commit: `php artisan test --compact --filter={task_filter} && vendor/bin/pint --dirty`
- Per wave merge: `php artisan test --compact` (all 131 + new)
- Phase gate: Full suite green before `/gsd-verify-work`

### Wave 0 Gaps (test files to create before implementation)

- [ ] `tests/Feature/Scenarios/ObjectiveReadinessTest.php` — REQ SCN-01
- [ ] `tests/Feature/Scenarios/ScenarioFactResolverTest.php` — REQ SCN-02 (incl. M3 regression)
- [ ] `tests/Feature/Scenarios/ScenarioFactSetTest.php` — REQ SCN-02 (HMAC, encryption)
- [ ] `tests/Feature/Scenarios/ObjectiveEnqueueTest.php` — REQ SCN-03 (zero-Claude assertion)
- [ ] `tests/Unit/TaxRulesEngineScenarioTest.php` — REQ SCN-04 (SCN-01..07 boundary values)
- [ ] `tests/Feature/Scenarios/AcaInvariantTest.php` — REQ SCN-04 property test (200 baselines)
- [ ] `tests/Unit/ScenarioSolverTest.php` — REQ SCN-05
- [ ] `tests/Feature/Scenarios/ScenarioControllerTest.php` — REQ SCN-06/07
- [ ] `tests/Feature/Scenarios/OptimizationChecklistTest.php` — REQ SCN-08
- [ ] `tests/Feature/ChangeMonitorTest.php` — REQ MON-01/02
- [ ] `tests/Feature/ActionCenterTest.php` — REQ ACT-01..05
- [ ] `tests/Unit/ClaudeBudgetTest.php` — REQ D17 budget caps
- [ ] `tests/Unit/TemplateFirstNarrationTest.php` — REQ D17 template-first
- [ ] `tests/Unit/NoLiteralGuardTest.php` — REQ SCN-04 (grep gate over new services)

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Yes (existing) | Sanctum bearer + cookie |
| V3 Session Management | Yes | InterviewSession ownership via policy |
| V4 Access Control | Yes | OptimizationChecklistItemPolicy (`user_id` ownership); ScenarioController scoped to auth user |
| V5 Input Validation | Yes | ComputeScenarioRequest (numeric bounds, grid membership); ChooseScenarioRequest (option_key enum); engine clamps regardless |
| V6 Cryptography | Yes | ScenarioFactSet.resolved_facts encrypted TEXT; `scenario.chosen_knobs` value encrypted (encrypted cast on UserTaxFact) |

### Known Threat Patterns for this Stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Knob vector injection (client submits hostile cents) | Tampering | Engine clamps a COPY; server recomputes on choose; never trusts client figures |
| Money value in Claude payload (SAFE-03) | Information Disclosure | Template-first zero-Claude paths; existing EstimatedValueGuardTest grep gate; narrator no-dollars payload test |
| Cross-user checklist item access | Elevation of Privilege | `OptimizationChecklistItemPolicy` + route-model binding; scopeForUser() on all queries |
| Prefill value exposed in unencrypted options JSON | Information Disclosure | Pointer pattern only (`prefill_source` key, never the value); `prefill_display`/`prefill_value` computed transiently at request time |
| ACA cliff pushed over by Roth knob | Harm | Hard arithmetic guard in `computeScenarioOutcome()` Step 2 (cliff-before-Roth); property test for 200 baselines |

---

## Sources

### Primary (HIGH confidence)

All findings are VERIFIED against live files on `release/v2.0.0` by direct `Read` tool inspection.

- `app/Models/OptimizationReport.php` — `built_against` column existence, `fetchOrInit()`, `scopeForUser()`
- `app/Services/ReportStalenessPolicy.php` — full policy implementation verified
- `app/Listeners/MarkOptimizationReportStale.php` — trigger classification, three handlers
- `app/Http/Controllers/Api/OptimizationReportController.php` — stale-while-revalidate pattern
- `app/Http/Controllers/Api/InterviewController.php` — current `next()` response shape (no Phase-14 additive fields yet)
- `app/Services/InterviewOrchestratorService.php` — GATED_PROBES const, recordAnswer hardcoded values
- `app/Services/NarrationService.php` — single model config key, SYSTEM_PROMPT, no per-purpose keys
- `app/Http/Middleware/HandleInertiaRequests.php` — shared props including `pendingOptimizationCount`; no `hasCreditCards`
- `app/Models/BankAccount.php` — `type`, `subtype` fields from Plaid; no `hasCreditCards()` helper
- `app/Models/User.php` — `hasBankConnected()`, `hasEmailConnected()` methods
- `config/services.php` — single `services.anthropic.model` key
- `config/tax-rules.php` — `detection.ctc_amount` (2200) confirmed; `odc_amount` absent
- `resources/css/app.css` — existing @theme block confirmed (lines 11–50); no elevation tokens yet
- `resources/js/Pages/Optimize/Index.tsx` — `ViewMode = 'findings' | 'interview' | 'report'` (3 values)
- `routes/console.php` — 28-day activity gate pattern in scheduled tasks
- `config/` directory listing — `optimization-objectives.php` and `optimizer-scenarios.php` DO NOT EXIST
- `.planning/reference/SCENARIOS-SPEC.md` — canonical implementation design (merged, normative)
- `.planning/reference/DESIGN-ELEVATION-SPEC.md` — locked design token + component spec
- `package.json` — framer-motion NOT present; confirmed CSS-only motion requirement

### Secondary

- SCENARIOS-SPEC §M (Merge Fixes 1–16) — normative resolution of part-1/part-2 conflicts

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `TaxDocument` model has a `category` column comparable to `TaxDocumentCategory::PayStub` for the `upload_paystub` Stage-0 completion check | Action Center Data Model | Stage-0 item never marks completed; need to verify actual column/enum name |
| A2 | `InterviewSession` has a `completed` status string (vs `complete`) for the interview Stage-0 check | Action Center Data Model | Stage-0 interview item never marks completed |
| A3 | The ChangeMonitor's `stale_since` timestamp on `OptimizationReport` is a reliable proxy for "how long has this material change been detected" | ChangeMonitor Design | The persistence filter (60 days) may use the wrong anchor; may need a separate `income_shift_detected_at` column |

**Action for A1/A2:** Planner should add a verification step to check `TaxDocumentCategory` enum values and `InterviewSession` status strings before coding Stage-0 completion checks.

**Action for A3:** Planner may add a dedicated `income_shift_detected_at` nullable timestamp to `optimization_calendar_events` or use `OptimizationFinding.created_at` as the persistence anchor instead of `stale_since`.

---

## Open Questions

1. **ChangeMonitor `income_shift_detected_at` anchor** — (RESOLVED)
   - What we know: `OptimizationReport.stale_since` tracks when the report was last flagged stale
   - What's unclear: Using `stale_since` as the 2-cycle persistence anchor conflates "stale due to any reason" with "stale due to income shift." A separate income-shift timestamp may be needed.
   - Recommendation: Store the income shift detection time in `optimization_calendar_events.metadata` or add a lightweight `income_shifts` table. Planner resolves.
   - **RESOLVED → 14-09 Task 1:** persistence anchors on a dedicated `optimization_calendar_events.metadata` detection timestamp + `OptimizationFinding.created_at` dedupe — NOT `report.stale_since`.

2. **`hasCreditCards` Inertia prop vs endpoint-only** — (RESOLVED)
   - What we know: No such prop or User method exists today
   - What's unclear: If future pages need credit-card state beyond Action Center, a shared Inertia prop is cleaner; but adding it costs a DB query on every page load
   - Recommendation: Endpoint-only for Phase 14. Revisit if more surfaces need it.
   - **RESOLVED → 14-09 Task 3:** endpoint-only — the credit-card check is a `BankAccount type='credit'` query inside `ActionCenterController` (DRIFT-08); no shared Inertia prop added.

3. **`optimization_checklist_items` scoped delete on re-choose** — (RESOLVED)
   - What we know: §D.6 says "old rows deleted for that user+year+source_type" on re-choose
   - What's unclear: "rows this feature owns" scope — confirm that deleting `WHERE user_id=$id AND tax_year=$year AND source_type='scenario_choice'` is the intended scope
   - Recommendation: This is correct per spec. Document the scope in the policy class.
   - **RESOLVED → checklist-materialization plan (14-07/14-08):** re-choose deletes exactly `WHERE user_id + tax_year + source_type='scenario_choice'` per §D.6; scope documented in the checklist controller/policy.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all verified against live files
- Architecture: HIGH — based on verified existing patterns + spec §I wave plan
- Spec-vs-code drift: HIGH — drift items A1/A2 are LOW confidence and flagged as assumptions
- D17 patterns: HIGH — extends existing verified patterns from commit 4e75c46
- Design elevation: HIGH — spec is locked; insertion point verified in app.css

**Research date:** 2026-07-02
**Valid until:** 2026-08-01 (stable stack; spec is locked)
