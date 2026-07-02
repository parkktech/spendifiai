# Phase 14: Action Center, Scenarios & Design Elevation - Context

**Gathered:** 2026-07-02
**Status:** Ready for planning
**Source:** Owner decisions 9–16 (enhanced-profile-integration-notes.md) + SCENARIOS-SPEC.md + DESIGN-ELEVATION-SPEC.md

<domain>
## Phase Boundary

Phase 14 delivers the product's interactive core: (1) the **Action Center** — a persistent, lifecycle-adaptive to-do surface (Dashboard widget + Optimize page, badged nav) aggregating every actionable item as a large-checkmark to-do with benefit lines and due dates; (2) the **Scenarios engine** — objective-driven data acquisition, the six-knob ScenarioSolverService, A/B/Balanced comparison UI, and the choose-scenario → checklist materialization flow; (3) the **ChangeMonitor** — a single orchestration service watching for both expected changes (benefit-verification claims→verified loop) and unexpected changes (income shifts, doc-refresh prompts) with cadence guards and bonus/year-end calendar watchers; and (4) the **Design Elevation Wave 1–3** rollout — token extensions, app shell, core surfaces, and all new Phase-14 UI born to the luxury-lean spec.

NOT in this phase: new detectors or interview mechanics (P11, shipped); safety/legal hardening (P13, stays last); anything on the Blocked or Future lists; state tax modeling (STATE-01 deferred); FSA computed scenarios (awareness-only per SCENARIOS-SPEC §B.1 K4, pending config constant sign-off).
</domain>

<decisions>
## Implementation Decisions (LOCKED — Decisions 9-16 from enhanced-profile-integration-notes.md)

### D9 — Action Center: actionable checklists with quantified benefit lines
Every finding/report item carries a numbered Action Checklist: imperative, concrete steps checked by the user; per-item done-state persisted in the durable-facts/action store with timestamps. Benefit lines: deterministic arithmetic (TaxRulesEngineService) for short-horizon figures; D9.7 illustration rules (stated config assumptions, range framing, labeled "illustration") for long-horizon projections. Filing-status/allocation items never assert an election in the abstract — they operationalize the user's confirmed facts or route into a confirm-first step. Checklist header aggregates all unlocked steps. Rollout: applies to findings cards, interview wrap-up ("Your action list"), and a report "User Actions Needed" section.

### D10 — Optimization Scenarios engine
Three objectives (`take_home`, `tax_burden`, `retirement`). For each: a fact-requirements map (config-driven), deterministic scenario computation over the 6-knob grid, cross-objective outcome comparison (take-home Δ, tax Δ, retirement Δ per scenario). Option A (income now) / Option B (retirement) / Balanced; single merged plan when objectives agree. Conflict surfacing: knobs where options diverge are explicitly contrasted. User picks → chosen scenario becomes their D9 action checklist. Scenarios are "approaches to consider" (educational frame). Full design: SCENARIOS-SPEC.md.

### D12 — Design posture: LUXURY LEAN
Dial moves to "make the look premium." Refined depth (4-level shadow system), typographic drama, intentional spacing rhythm, micro-interactions, richer card treatments. Extending sw-* token system is ALLOWED and expected (new tokens: elevation scale, motion durations/easings, display type sizes, gradient stops). Preserved: brand logo, Inter, sw-accent blue, all URLs/APIs/functionality, accessibility, light+dark modes. Method: taste-skill v2 full procedure per frontend task. Full token set + component recipes + rollout waves: DESIGN-ELEVATION-SPEC.md.

### D13 — Staleness policy + benefit-verification loop
Freshness window: 30 days (`config/optimization-report.php` `freshness_days`). Immediate-stale: user-action events (interview answers, profile edits, fact confirm, user doc uploads, scenario choice, checklist state changes). Material-change exception: `built_against` aggregates vs current profile via config thresholds. Benefit-verification loop (D13.5): when checklist items are checked done, ChangeMonitor watches the 2-4-week window for the projected change to materialize and surfaces verified outcomes — reuses the SavingsLedger claimed→verified pattern.

### D14 — Proactive change monitor + doc-refresh prompts
ChangeMonitor detects: D13 material-change comparison, life-event triggers (FLAG-27, shipped), IncomeDetectorService deposit-pattern shifts, CrossSourceReviewService discrepancies. On detection → OptimizationFinding (`finding_type=change_detected`) + AIQuestion in feed + DOC-05 document request with educational, benefit-forward copy. Cadence guard: one prompt per detected change per freshness window; ≥2 pay cycles persistence required before prompting (filters one-off deposits/bonuses); dedupe against open requests. Both halves (verification watch + change detection) built as one ChangeMonitor service.

### D15 — Bonus optimization: pre-bonus election alerts
Bonus prediction from: prior-year pattern (IncomeDetectorService/history), interview fact ("bonus month?"), offer-letter extraction. Alert fires with config lead time (~3-4 weeks before expected payroll cutoff). Bonus scenario set (a D10 domain): Option A (0% deferral = max cash now), Option B (max deferral = bracket management), Option C (standing election). Engine computes take-home, tax withheld, 401(k) headroom per option. Withholding education: supplemental rate (22%) vs aggregate method vs actual bracket — educational only. Checklist output: "Before [date]: log into your 401(k) portal and set your BONUS deferral to X%" + benefit line. Predictive/calendar watcher extends ChangeMonitor as its first calendar-event domain.

### D16 — Unified Action Center (final form) + year-end items
ONE persistent, prominent to-do surface aggregating: scenario checklist steps (D9/D10), bonus election alerts (D15), change-monitor doc requests (D14), year-end timing items, document requests. Big checkmarks; user checks items done; state persists with timestamps. Stage-0 onboarding items for new users (link bank, cards, emails, interview, upload paystub) — disappear as completed, replaced by what they unlock. Year-end liability strategy items: GATED on confirmed business/personal context and the user's confirmed business type; HONESTY GUARDRAIL (binding): "Buying reduces taxes only if you needed it anyway — a $10,000 purchase in the 24% bracket saves ~$2,400 in tax and costs ~$7,600 net cash. If it was already planned for your business, completing it before Dec 31 may let it count this year."
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Primary specs (read both in full before planning)
- `.planning/reference/SCENARIOS-SPEC.md` — Full implementation-ready design for Decisions 9 + 10: canonical fact-requirements maps (§A.2), ScenarioFactResolverService (§A.6), ObjectiveReadinessService (§A.8), ScenarioSolverService with 6 knobs (§B.1–B.7, SCN-01–SCN-07), A/B/Balanced comparison (§C), choice-to-checklist materialization (§D), ChangeMonitor wiring, test plan (§T), and implementation wave breakdown (§I: W1 config, W2 data substrate, W3 engine orchestration, W4 frontend, W5 hardening). §M (Merge Fixes) is normative — supersedes both part-1/part-2 files on any conflict.
- `.planning/reference/DESIGN-ELEVATION-SPEC.md` — 41 additive sw-* token extensions (§2), component treatment recipes (§3.1–3.11 covering app shell, header, StatCard, content cards, buttons, badges, tables, empty states, skeletons, report/scenario surfaces), motion vocabulary (§4), 3-wave priority rollout (§5), and preservation audit template (§6). LOCKED — implementation reference for all frontend work.

### Owner decisions
- `.planning/reference/enhanced-profile-integration-notes.md` — Decisions 9–16 (Action Center, Scenarios, Design, Staleness, ChangeMonitor, Bonus, Year-End/To-Do) — BINDING

### Shipped foundations to build on (do not modify existing interfaces)
- P10: `TaxRulesEngineService` (additive SCN-01–SCN-07 methods); `IncomeOptimizationProfile` (additive `built_against` fields for D13 material-change detection)
- P11: `UserTaxFact`/`DurableFactsController`; `InterviewOrchestratorService` (additive template branch in `createOptimizationQuestion()`, config-merged gate map); `PaystubFactExtractorService` (additive `PAYSTUB_FACT_MAP` + new `RETIREMENT_STATEMENT_FACT_MAP` entries); `InterviewController::next()` (additive prefill pointer fields); `InterviewSession` (queue/asked string arrays — no schema change)
- P12: `OptimizationReportGeneratorService` (additive `chosen_plan` section); `OptimizationReport` + `MarkOptimizationReportStale` listener (already wired to correct events); `SavingsLedger` claimed→verified pattern (D13.5 reuse); `OptimizationFinding` `deadline`/`net_cash_cost`/`cliff_bonus_value` fields (already in FLAG-13)
- v2.0: existing AIQuestion/feed pipeline for ChangeMonitor doc requests; SavingsLedger claimed→verified for verification loop
</canonical_refs>

<specifics>
## Specific Implementation Notes

- SCENARIOS-SPEC §I defines implementation waves (W1–W5) with exact task breakdown; follow this order: T1 config foundations, T2 engine methods, T3 resolver, T4 fact-set migration, T5 readiness + orchestrator additive touches, T6 objectives controller, T7 solver, T8 checklist migration, T9 scenario/checklist controllers, T10 report integration, T11–T13 frontend, T14 hardening.
- Two new additive migrations: `scenario_fact_sets` (HMAC-SHA256 hash, encrypted `resolved_facts` TEXT, `cascadeOnDelete()` for GDPR) and `optimization_checklist_items` (fact-gated steps, reality-fact writes on done-toggle, `fact_set_id` linkage).
- Zero Claude calls in any scenarios/checklist/objectives endpoint — assert in all scenario tests via `Http::fake()` + `assertNothingSent()`.
- ACA cliff invariant property test (200 randomized marketplace baselines near the 400%-FPL threshold) must be added to CI profile per SCENARIOS-SPEC §T.10.
- FSA scenario knob is awareness-only until `config/tax-rules.php` gains the FSA annual limit constant with owner sign-off — do not block Phase 14 on this; surface as educational note.
- DESIGN-ELEVATION-SPEC §5 rollout order: Wave 1 (`app.css` token block + `AuthenticatedLayout.tsx` only, ~3-4h, lowest risk) → Wave 2 (`StatCard`, `Badge`, `SubscriptionCard`, `Dashboard`, `Subscriptions`, `Transactions`, ~5-7h) → Wave 3 (Optimize/Index, all new Phase-14 components born-premium, empty states, skeletons). Each wave closes with the §6 preservation audit before proceeding.
- Action Center Stage-0 items are deterministic and rule-driven (no Claude): derive from `hasBankConnected`, bank account types (credit-card accounts), email connections count, interview session state, and UserFinancialProfile completeness — each item disappears once its prerequisite condition is met.
- Fact-key conventions: use canonical keys per SCENARIOS-SPEC §A.1.2 (`pay.frequency`, `hsa.coverage_type`, `person.birth_year`, etc.); alias map lives in `config/optimization-objectives.php`; detectors must NOT be rewritten to use canonical keys — only the resolver uses the alias map (scope rule §0.3 from the spec).
- D9.2 fact-gate rule for checklist directives: a step renders as an imperative ONLY when every fact it anchors to is `confirmed` (source = `interview_answer`, `user_edit`, or confirmed `document_extraction`); otherwise the step renders as the confirmation ask until confirmed.
- The bonus domain (D15) is a scenario type within D10's framework — add a `bonus_election` objective domain to `config/optimization-objectives.php` following the three-objective pattern; calendar watchers extend `ChangeMonitor` rather than living in a separate service.
</specifics>

<deferred>
## Deferred

- Debt-optimization tier (D11 / `.planning/reference/debt-optimization-tier-capture.md`) — gated to P13 sign-off or a Future milestone; do not implement in P14
- State tax modeling (STATE-01) — federal-only for this milestone; scenarios are federal math only
- FSA computed scenarios — awareness-only until the FSA annual limit constant lands in `config/tax-rules.php` with owner sign-off
- Advertising/ads system (`.planning/reference/advertising-system-capture.md`) — parked Future
- STORE-04: Contemporaneous log features (mileage log, STR hour log, Augusta day-count tracker, kids-on-payroll timesheets, off-road gallons log) — Phase 14 ships doc-checklist exports only; the log infrastructure is Future
- YEAR-01 full Q4 calendar engine — Phase 14 lays the predictive-watcher infrastructure via ChangeMonitor; the full bracket-trajectory projector, purchase-list interview battery, and full December cadence are a later milestone
- P13 (Safety, Validation & Hardening) stays LAST in the execution order — runs on the complete feature after P14 ships
</deferred>

---
*Phase: 14-action-center-scenarios-design-elevation*
*Context gathered: 2026-07-02 by orchestrator from owner decisions (D9–D16) + SCENARIOS-SPEC + DESIGN-ELEVATION-SPEC*
