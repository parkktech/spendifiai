---
phase: 14-action-center-scenarios-design-elevation
verified: 2026-07-03T08:30:00Z
status: gaps_found
score: 21/22 must-haves verified
behavior_unverified: 2
overrides_applied: 0
gaps:
  - truth: "Predictive calendar watchers fire bonus lead-time alerts sourced from prior-year pattern, interview fact, OR offer-letter extraction (MON-02 / 14-09 must-have)"
    status: partial
    reason: "Only the interview-fact source (UserTaxFact bonus.expected_month) is implemented. Prior-year transaction-pattern prediction and offer-letter-driven expected-month extraction are deferred to v2.2 via a code comment only (ChangeMonitor.php:452) — undocumented in 14-09-SUMMARY.md, ROADMAP, or REQUIREMENTS (MON-02 marked Complete)."
    artifacts:
      - path: "app/Services/ChangeMonitor.php"
        issue: "checkBonusLeadTime() resolves bonus.expected_month from UserTaxFact only; no IncomeDetectorService prior-year lookup; TaxDocumentExtractorService extracts signing_bonus but nothing maps offer-letter bonus terms to bonus.expected_month"
    missing:
      - "Either implement prior-year-pattern + offer-letter sources, OR record the deferral formally (verification override / REQUIREMENTS note / v2.2 backlog item)"
  - truth: "REQUIREMENTS.md traceability reflects delivered scope"
    status: partial
    reason: "SCN-02, SCN-05, SCN-06, SCN-07, SCN-08 are unchecked / marked Pending in REQUIREMENTS.md despite verified, tested implementations in the codebase (documentation drift, not a code gap)."
    artifacts:
      - path: ".planning/REQUIREMENTS.md"
        issue: "Rows 308, 311-314 say Pending; checkboxes at lines 129, 132-134 unchecked"
    missing:
      - "Flip SCN-02/05/06/07/08 to Complete with evidence pointers"
behavior_unverified_items:
  - truth: "COMPLETE-CANNOT-LIE — the interview never shows a terminal 'Review complete' state while any objective is still locked (InterviewController::next() Phase 2)"
    test: "Run npm run e2e:walk (test 4 asserts no false 'Review complete' while LockedScenariosOverlay is visible) on a machine with Chromium system libs, or exercise a locked-objective session and hit GET /optimizer/interview/{id}/next"
    expected: "session_status stays 'in_progress' with a gap question or still-needed payload; 'Review complete' only when all objectives confirmed ready"
    why_human: "No PHP feature test asserts session_status under locked objectives; the only automated coverage is Playwright walk test 4, which cannot run in this environment (libatk-1.0.so.0 missing). CI runs it via .github/workflows/e2e-walk.yml."
  - truth: "FIRST-CLICK CHOOSE — choosing a scenario succeeds on the first click with no silent stall or double-submit (commit d6ddc0d frontend guards + throttle 60/min)"
    test: "Run npm run e2e:walk (test 5: choose scenario → checklist generates → toggle item done), or click a scenario card once in the browser"
    expected: "Dialog shows loading, closes on success, checklist re-materializes immediately; errors surfaced, never swallowed"
    why_human: "The guards are React-side (ConfirmDialog loading/error props, choosingKey idempotency, checklistKey remount) — invisible to PHP tests; server-side choose behavior IS covered by ScenarioChooseTest, but the click-path UX is e2e/human-only and the walk is infra-blocked locally."
human_verification:
  - test: "Run the Playwright journey walk in CI or on a lib-complete host: npm run e2e:walk"
    expected: "8/8 green (login, overview, choices, gap loop, choose+checklist, report, console-error regression, stale-session self-heal)"
    why_human: "Local chromium-headless-shell fails to load libatk-1.0.so.0 — infrastructure, not product. D24 makes the walk the release gate: 'reports are not evidence; walks are.'"
  - test: "Visual pass on ELEV-01/02/03 surfaces (app shell, StatCard, SubscriptionCard, Dashboard, Action Center widget, scenario cards, checklist) in light + dark mode + prefers-reduced-motion"
    expected: "Premium depth/motion per DESIGN-ELEVATION-SPEC; brand logo, Inter, sw-accent blue preserved; reduced-motion disables animation"
    why_human: "Design quality and brand fidelity are not grep-able; §6 audits are documented in 14-04/07/10 SUMMARYs but are self-reported"
---

# Phase 14: Action Center, Scenarios & Design Elevation — Verification Report

**Phase Goal:** The Action Center becomes the product's spine: scenario options A/B, actionable checklists with quantified benefits, change/calendar monitors — all born to the luxury design spec.
**Verified:** 2026-07-03 (goal-backward, code-first; SUMMARY claims not trusted)
**Status:** gaps_found (2 minor gaps; no blockers)
**Re-verification:** No — initial verification

## Method & Evidence Baseline

- **Full suite run by verifier:** `php artisan test --compact` → **1250 passed, 1 risky, 0 failed (5,577 assertions, 84.9s)**. Exceeds the 1250+ expectation.
- **Frontend build run by verifier:** `npm run build` → exit 0, 6.26s.
- **E2E walk attempted:** `npm run e2e:walk` → **INFRA-BLOCKED** — chromium-headless-shell cannot load `libatk-1.0.so.0` (7 of 8 tests did not run). Spec reviewed at `e2e/optimize-journey.spec.ts` (8 tests); CI workflow `.github/workflows/e2e-walk.yml` exists.
- **Live spot-check (tinker, read-only, user 1):** readiness all three objectives `ready=true, blocking=0`; latest `scenario.chosen_option` fact = `take_home` (a choice exists and persists — the "balanced" noted overnight was superseded by a later re-choose, consistent with re-choose-supersedes); 2 checklist items (0 done); 134 ScenarioFactSet snapshots.
- **Debt-marker scan:** zero TBD/FIXME/XXX/TODO/HACK/PLACEHOLDER across all phase-14 services, controllers, configs, and components (the single `XXX` hit is a `$X,XXX` currency-format doc comment).

## Goal Achievement — Observable Truths (Roadmap Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | First-run user sees Stage-0 items that disappear when completed; returning user sees checklist/monitor/year-end items with benefit lines + due dates; empty state = achievement moment | VERIFIED | `ActionCenterController` + `ActionCenterTest` (47 behavior tests: per-item Stage-0 presence/omission incl. DRIFT-08 credit cards, DOCUMENTS-FIRST Δ1 ordering, benefit_line_params, monitor prompts, calendar due dates, is_empty, cross-user isolation); `ActionCenterWidget.tsx` AchievementEmptyState ("You're fully optimized for now — we're watching for changes", §3.9 recipe) |
| 2 | A/B/Balanced side-by-side on divergence, merged plan on agreement; chosen option persists (`scenario.chosen_option` + ScenarioFactSet) and re-materializes checklist | VERIFIED | `ScenarioControllerTest` (agreement=true merged / diverging options, knob diffs, fact_set citation); `ScenarioChooseTest` (writes chosen_option + encrypted chosen_knobs, snapshots fact_set, materializes checklist, marks report stale, **re-choose supersedes + re-materializes**, hostile client knobs ignored); live: chosen fact + 134 snapshots + 2 checklist items on user 1 |
| 3 | Every checklist step carries engine-computed benefit line; long-horizon = labeled illustrations with config assumptions; header aggregates unlocked benefit | VERIFIED | `OptimizationChecklistTest` (benefit_line_params integer cents, header aggregate materialized, directive vs confirm_ask fact-gating); `futureValueRangeCents()` returns low/high + assumptions array (`TaxRulesEngineScenarioTest`); Illustration badges in `ScenarioComparisonCards.tsx:292` + `OptimizationChecklistView.tsx:304` |
| 4 | ChangeMonitor surfaces verified outcomes in the 2-4-week window; prompts for updated pay stub when income shift persists ≥2 pay cycles | VERIFIED | `ChangeMonitorTest` (25 tests: claimed SavingsLedger on done-toggle, verified when projected change lands, window boundaries, no re-fire, detection anchor, **exactly one finding after ≥2 pay cycles**, no one-off-deposit false positive, open-finding dedupe, 28-day inactive skip) |
| 5 | New + upgraded surfaces pass §6 preservation/brand audits per wave; `npm run build` + full suite pass | VERIFIED (build/tests) / audits self-reported | Build exit 0; 1250/0; §6 audit records in 14-04/07/10 SUMMARYs; brand tokens preserved (Inter, sw-accent, logo untouched in app.css/AuthenticatedLayout) — visual confirmation routed to human verification |

**Score:** 21/22 plan-level must-haves verified (1 partial: MON-02 bonus sources); 2 present-behavior-unverified laws (e2e-only coverage, infra-blocked locally).

## Requirements Coverage

| Requirement | Status | Evidence |
|-------------|--------|----------|
| ACT-01 (persistent to-do surface, nav badge, persisted done-state) | SATISFIED | `ActionCenterWidget.tsx` on Dashboard + Optimize; `HandleInertiaRequests` additive `pendingActionCount` (ActionCenterTest: reflects unchecked items, excludes done/header, **pendingOptimizationCount unchanged — DRIFT-09 backcompat**); done_at persisted on `optimization_checklist_items` |
| ACT-02 (Stage-0 deterministic onboarding items) | SATISFIED | ActionCenterTest per-item: link_bank, link_credit_cards (type='credit'), link_email, do_interview (InterviewSession completed), upload_paystub (TaxDocument pay_stub ready); each omitted when satisfied |
| ACT-03 (quantified benefit line + due dates) | SATISFIED | benefit_line_params engine cents (OptimizationChecklistTest); calendar events carry due dates (ActionCenterTest "alert-ready calendar events with due dates") |
| ACT-04 (done → claimed → verified, SavingsLedger pattern) | SATISFIED | ChangeMonitorTest "creates a claimed SavingsLedger record for a done checklist item within the window" → "marks verified when the projected change appears" |
| ACT-05 (empty state = achievement moment) | SATISFIED | AchievementEmptyState component (§3.9); ActionCenterTest is_empty logic |
| SCN-01 (objectives config + two-tier readiness) | SATISFIED | `config/optimization-objectives.php` (38.8KB fact map incl. bonus_election domain); `ObjectiveReadinessService`; `ObjectiveReadinessTest` (blocking/confirm_needed/not_applicable/optional-with-suppression/completeness_pct, enqueueGaps ordering/idempotency/Fix-6 re-enqueue) |
| SCN-02 (fact resolver + citable ScenarioFactSet) | SATISFIED | `ScenarioFactResolverService` (per-fact chains, alias fallback); `ScenarioFactSet` HMAC-SHA256 (`hash_hmac` w/ app key), encrypted + `$hidden` resolved_facts, cascadeOnDelete migration; `ScenarioFactResolverTest` + `ScenarioFactSetTest`; **REQUIREMENTS.md still says Pending — doc drift (gap 2)** |
| SCN-03 (enqueue endpoint, zero-Claude templates, typed conversion) | SATISFIED | `ObjectiveEnqueueTest`: enqueue + walking gap questions with `Http::preventStrayRequests()` + `assertNothingSent()`; money→cents conversion, choice 422, bonus_election 404 |
| SCN-04 (SCN-01..07 pure engine methods + ACA invariant) | SATISFIED | Engine methods present (estimatePeriodWithholdingCents, employeeFicaCents, matchCaptureCents, futureValueRangeCents, projectedMagiCents, acaCliffHeadroomCents, computeScenarioOutcome); `AcaInvariantTest` = genuine **200-randomized-baseline property test** (checks == 200 × candidates); `NoLiteralGuardTest` |
| SCN-05 (six-knob solver, engine-only math) | SATISFIED | `ScenarioSolverService` (56KB); `ScenarioSolverTest` + `ScenarioAgreementTest`; all candidates via computeScenarioOutcome |
| SCN-06 (3 named options / merged plan, knob-diff UI, Illustration badge) | SATISFIED | ScenarioControllerTest; `ScenarioComparisonCards.tsx` (guard chips, Illustration badge) |
| SCN-07 (choose recompute, snapshot, persist, re-materialize, stale) | SATISFIED | ScenarioChooseTest (all clauses incl. hostile-knob rejection); live user-1 state confirms persistence |
| SCN-08 (fact-gated imperatives, benefit lines, header aggregate) | SATISFIED | OptimizationChecklistTest directive/confirm_ask + header aggregate; done-toggle writes reality facts (employer.contribution_pct, retirement.elected_roth_share_pct) |
| MON-01 (unified ChangeMonitor, cadence guard, activity gate) | SATISFIED | ChangeMonitorTest (see SC 4); scheduled daily 6am task in routes/console.php with per-user try/catch |
| MON-02 (bonus lead-time alerts + year-end gated items + honesty statement) | **PARTIAL** | 3-option bonus set, config lead time (21d), no re-fire, business-context + business_type gating, Q4 window, **verbatim net-cost honesty guardrail** (ChangeMonitor.php:693) — all tested. **Gap:** prediction sources limited to interview fact; prior-year pattern + offer-letter deferred to v2.2 by code comment only |
| ELEV-01 (Wave 1 tokens + shell) | SATISFIED | 45 CSS vars in second `@theme` block (≥41 spec); sidebar `cubic-bezier(0.32,0.72,0,1)` spring; h-14 header, backdrop-blur, 2-layer shadow; gradient pill nav indicator; build passes |
| ELEV-02 (Wave 2 core surfaces) | SATISFIED | StatCard double-bezel (outer `p-px` gradient frame + inner core), `text-[28px] font-[800] font-tabular`, semantic icon variants, card-lift; Badge/SubscriptionCard ring/gradient/shadow-sw-1 |
| ELEV-03 (new components born-premium) | SATISFIED | ActionCenterWidget, ScenarioComparisonCards, OptimizationChecklistView, ObjectiveReadinessPanel all consume card-lift/shadow-sw/stagger-children recipes |

## Owner Decisions D9–D24 (load-bearing verifications)

| Decision | Status | Evidence |
|----------|--------|----------|
| D17 zero-Claude template paths | VERIFIED | `NoClaudeScenarioTest`, `ObjectiveEnqueueTest`, `TemplateFirstNarrationTest` — all use assertNothingSent/preventStrayRequests; ran green in suite |
| D17 per-purpose models | VERIFIED | config/services.php: model_narration/wording=haiku-4-5, model_extraction=sonnet-4-6, model_categorization=haiku-4-5; `TransactionCategorizerService:35` resolves model_categorization with global fallback |
| D17 activity gates | VERIFIED | `GenerateOptimizationReport:82-84` + `NarrateOptimizationFindings` listener:40-42 gate on last_active_at vs active_threshold_days(28); ChangeMonitor belt+suspenders re-guard; `ActivityGateTest` + ChangeMonitorTest inactive-skip |
| D17 budget caps + observability | VERIFIED | daily_budget_* keys; `ClaudeBudgetTest`; `GET /api/admin/ai-usage` route live → AiUsageController |
| D18 question anatomy + key-leak | VERIFIED | `D18QuestionQualityTest` (15 tests) with `D18_INTERNAL_KEY_PATTERN = /\b[a-z0-9]+_[a-z0-9_]+\b/` asserted on question text + labels; aggregation/fan-out/exclusion/fallback-dead all tested; escape hatch in InterviewCard.tsx |
| D19 structured output contracts | VERIFIED | NarrationService `{hook ≤120, detail ≤2 sent, action_cue ≤1 sent}` + retry-shorter prompt; OptimizationReportNarratorService `{summary ≤2 sent, bullets ≤5×≤15w}` |
| D20 eligibility predicates + doc-source | VERIFIED | `D20InterviewIntelligenceTest` (predicates true-on-unknown/false-blocks, HSA + age gating, hatch budget-cap null, Haiku model, DocumentRequest idempotent doc_pending) |
| D21 docs-first journey | VERIFIED | upload_paystub first (Δ1 test); MorningPolish tests (derived-confirm, not-sure→DocumentRequest); ProfileConformanceDetector Planes 1-5 (incl. 14-11 name + dependents identity planes) drive discrepancy-questions→profile updates via D4 confirm gate |
| D22 org items + preparer packet | VERIFIED | ActionCenterController organization_items (`getOrganizationItems`); OptimizationProReviewExportService cross-account business-activity map + commingling_picture + documentation_status (sections 7-9); `QuestionRetirementServiceTest` incl. no-raw-key summary-line regex |
| D23 done-for-you | VERIFIED | Optimize/Index.tsx: scenarios auto-computed from GET (comment line 1358); inline gap questions ("A few questions we couldn't answer from your documents"); Mix & Model demoted to collapsed "Fine-tune this plan" expander (line 1452) |
| D24 reliability doctrine | VERIFIED | `config/fact-registry.php` + `FactRegistryContractTest` (literals-in-code vs registry, enum match, template completeness); Inertia asset versioning (HandleInertiaRequests) + NewVersionToast; error views 429/500/503; `uniqueFor=360` on GenerateOptimizationReport; e2e-walk.yml CI workflow; choose throttle 60/min |

## The Night's Laws

| Law | Coverage | Status |
|-----|----------|--------|
| count==servable | `ChoicesStageRepairTest` "questions_to_unlock does not count label-only template entries" + Fix-9 real gap queue size in UI | VERIFIED (ran in suite) |
| complete-cannot-lie | Implemented in `InterviewController::next()` Phase 2 (status forced in_progress while locked); **only automated test is e2e walk test 4** ("no false 'Review complete'") | PRESENT_BEHAVIOR_UNVERIFIED locally (walk infra-blocked; CI covers) |
| first-click choose | Server idempotency in ScenarioChooseTest; frontend guards (d6ddc0d); **e2e walk test 5** | PRESENT_BEHAVIOR_UNVERIFIED locally (frontend path e2e-only) |
| re-choose rebuilds checklist | ScenarioChooseTest "re-choose supersedes prior facts and re-materializes checklist" + scoped-delete test | VERIFIED |
| serve-time fact gates | `FactAwareSuppressionTest` (14 tests: serve-time skip/serve, emission-time gates, sweep command, split-key paystub facts) | VERIFIED |
| stateless gap serving | `nextGapQuestion()` derives from readiness() (no stored queue for derivables); exercised end-to-end via ObjectiveEnqueueTest walk + ChoicesStageRepairTest; e2e test 4 | VERIFIED (PHP walk) |

## SAFE Gates (run in full suite, all green)

- `EstimatedValueGuardTest`, `NoLiteralGuardTest`, `BannedPhraseTemplatesTest` — passed.
- SAFE-03 payload rules: ScenarioControllerTest "narrator payload builder has no cents-suffixed money fields (SAFE-03 regression)" (source-level regex over payload code); NarrationServiceTest "claude_payload_excludes_dollar_figures"; PrefillPointerTest (pointer-only storage); ObjectiveEnqueueTest "GET objectives body carries no money values" — all passed.
- Migrations: all three phase-14 migrations (scenario_fact_sets, optimization_checklist_items, optimization_calendar_events) are additive CREATE TABLE with GDPR cascadeOnDelete; `dropIfExists` only in down() on the migration's own new table. Compliant.

## Probe Execution

No `scripts/*/tests/probe-*.sh` probes exist or are declared in this project. The project's runnable gates are the Pest suite (run: 1250/0), npm build (run: pass), and the Playwright walk (attempted: infra-blocked; CI-wired).

## Anti-Patterns Found

| File | Pattern | Severity | Impact |
|------|---------|----------|--------|
| app/Services/ChangeMonitor.php:452 | "deferred to v2.2" code comment carrying undocumented scope reduction | Warning | MON-02 gap 1 — needs formal deferral record |
| (suite) | 1 risky test (no assertions) somewhere in the 1250 | Info | Cosmetic; identify with non-compact run |

## Gaps Summary (ranked)

1. **MINOR — MON-02 bonus-prediction sources incomplete + undocumented deferral.** Mechanism (lead-time window, 3-option set, no re-fire, gating, honesty copy) fully implemented and tested; but of the three mandated sources only interview-fact works. Prior-year pattern and offer-letter expected-month mapping are deferred by code comment only. Fix: implement, or record a formal override/backlog entry and annotate REQUIREMENTS.md.
2. **MINOR — REQUIREMENTS.md drift.** SCN-02/05/06/07/08 marked Pending despite verified implementations. Flip to Complete.
3. **MINOR (defense-in-depth) — complete-cannot-lie and first-click-choose lack PHP-level tests.** Their only automated coverage is the Playwright walk, which cannot run on this host (libatk). A cheap Pest test hitting `GET /optimizer/interview/{id}/next` with locked objectives asserting `session_status=in_progress` would make the invariant red-buildable without a browser.
4. **INFRA — e2e:walk blocked locally** by missing `libatk-1.0.so.0` (plus likely other chromium system libs). CI workflow exists; local runs need `dnf install atk at-spi2-atk libXcomposite libXdamage libXrandr mesa-libgbm alsa-lib` (or `npx playwright install-deps` equivalent for EL10).

## Deploy Runbook

1. `php artisan migrate --force` — three additive CREATE TABLE migrations (scenario_fact_sets, optimization_checklist_items, optimization_calendar_events). No destructive ops.
2. `npm run build` — verified passing; Inertia asset versioning (D24-W3) will trigger the NewVersionToast for stale sessions.
3. **`php artisan queue:restart` — REQUIRED.** Long-running Redis workers hold stale code for GenerateOptimizationReport (new uniqueFor=360 + activity gate), NarrateOptimizationFindings listener, and all D17 model-tier changes.
4. `php artisan config:cache` refresh — new config files (optimization-objectives, optimizer-scenarios, fact-registry) and services.php model/budget keys must land in the cached config.
5. Scheduler picks up the new daily-6am ChangeMonitor task automatically (cron already wired).
6. **Walk infra:** install chromium system libs (libatk et al.) on the host or rely on the CI e2e-walk.yml gate; run `npm run e2e:walk` (8 tests) post-deploy as the D24 release gate.
7. Optional env knobs now honored: ANTHROPIC_MODEL_NARRATION/WORDING/EXTRACTION/CATEGORIZATION, CLAUDE_DAILY_BUDGET_*.
8. **Walk pre-requisite (INFRA — libatk walk-blocker):** `npm run e2e:walk` requires `libatk-1.0.so.0` and related accessibility libs that chromium-headless-shell dynamically loads. On EL10 (RHEL 10 / Rocky / Alma) these are not installed by default. Remediation: `dnf install -y atk at-spi2-atk libXcomposite libXdamage libXrandr mesa-libgbm alsa-lib` — OR — run `npx playwright install-deps chromium` (detects the distro and installs the correct package set). After installing, verify with `npm run e2e:walk`; all 8 tests should pass. The CI workflow `.github/workflows/e2e-walk.yml` already handles this via its `ubuntu-latest` runner which ships these libs by default, so the walk IS running in CI regardless of local infra state.

---

_Verified: 2026-07-03 — full suite 1250/0 run by verifier; build run by verifier; live DB spot-checks via tinker; e2e walk attempted (infra-blocked)_
_Verifier: Claude (gsd-verifier, goal-backward)_
