---
gsd_state_version: 1.0
milestone: v2.1
milestone_name: Optimize My Income
current_phase: 14
current_phase_name: Action Center, Scenarios & Design Elevation
status: verifying
stopped_at: Completed 14-09-PLAN.md
last_updated: "2026-07-03T02:13:17.315Z"
last_activity: 2026-07-02
last_activity_desc: Phase 14 execution started
progress:
  total_phases: 5
  completed_phases: 4
  total_plans: 26
  completed_plans: 30
  percent: 80
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-07-01)

**Core value:** AI-powered personal finance platform bridging taxpayers and their accountants
**Current focus:** Phase 14 — Action Center, Scenarios & Design Elevation

## Current Position

Phase: 14 (Action Center, Scenarios & Design Elevation) — EXECUTING
Plan: 10 of 10
Status: Phase complete — ready for verification
Last activity: 2026-07-02 — Phase 14 execution started

## v2.1 Roadmap Summary

Continuing global phase numbering from v2.0 (ended Phase 9). Granularity: coarse.

| Phase | Goal | Requirements |
|-------|------|--------------|
| 10 — Foundation: Tax Rules Engine & Cross-Source Snapshot | Deterministic 2026 tax math + per-user snapshot, zero Claude in numbers | TAX ×7, CTX ×4 (11) |
| 11 — Detection, Guided Interview & AI Feed Integration | Deterministic red flags surfaced via resumable interview + existing AI feed | FLAG ×6, INT ×5, FEED ×4 (15) |
| 12 — Report, Document Intake & Feature Surface | Exportable educational report + new doc types + Optimize My Income surface | RPT ×4, DOC ×3, UI ×3 (10) |
| 13 — Safety, Validation & Hardening | Certify the educational-only liability boundary on the complete feature | SAFE ×5 (5) |

**Hard dependencies:** TaxRulesEngineService + config/tax-rules.php + snapshot (P10) before detectors/interview (P11); report generation (P12) requires completed interview sessions; validation/hardening (P13) last on the complete feature.

**UI phases:** 11, 12 (frontend surfaces — consider /gsd-ui-phase before executing).

## Performance Metrics

**Velocity:**

- Total plans completed: 16 (15 v1.0 + 1 v2.0)
- Average duration: 5min
- Total execution time: ~1.35 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1-5 (v1.0) | 15 | 1.3h | 5m |
| 6-9 (v2.0) | 16 | ~50m | ~3m |

*Updated after each plan completion*
| Phase 10 P01 | 12 | 3 tasks | 4 files |
| Phase 10-foundation-tax-rules-engine-cross-source-snapshot P02 | 316 | 3 tasks | 5 files |
| Phase 10 P03 | 355 | 3 tasks | 7 files |
| Phase 11 P01 | 45m | 3 tasks | 6 files |
| Phase 11 P02 | 632 | 3 tasks | 12 files |
| Phase 11 P04 | 180 | 3 tasks | 22 files |
| Phase 11 P05 | 440s | 2 tasks | 8 files |
| Phase 11 P07 | 90min | 3 tasks | 13 files |
| Phase 12 P01 | 192 | 2 tasks | 4 files |
| Phase 12 P03 | 12m | 3 tasks | 11 files |
| Phase 12-optimization-report-document-intake-feature-surface P02 | 18 minutes | 3 tasks | 12 files |
| Phase 12 P04 | 14 | 3 tasks | 13 files |
| Phase 12 P05 | 13 | 3 tasks | 11 files |
| Phase 14 P01 | 35min | 3 tasks | 17 files |
| Phase 14 P04 | 8m | 2 tasks | 2 files |
| Phase 14 P05 | 17 | 3 tasks | 14 files |
| Phase 14 P02 | 15min | 3 tasks | 4 files |
| Phase 14 P07 | 12m | 3 tasks | 7 files |
| Phase 14 P06 | 120 | 2 tasks | 4 files |
| Phase 14 P08 | 90 | 3 tasks | 18 files |
| Phase 14 P10 | 18 minutes | - tasks | - files |
| Phase 14 P11 | 45 | 7 tasks | 10 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [v2.1]: All IRS math lives in deterministic TaxRulesEngineService reading year-versioned config/tax-rules.php — Claude never computes dollar amounts
- [v2.1]: Zero new Composer/npm packages — plain PHP bracket math; additive architecture only (new services/models/enums, no rewrites)
- [v2.1]: Educational-only liability boundary — modal framing, persistent non-dismissable disclaimers, no filing-status/refund assertions
- [v2.1]: Reuse v2.0 Tax Document Vault + two-pass extraction, AIQuestion feed, DashboardCacheService — additive integration (QuestionType::Optimization, guarded UpdateTransactionCategory listener)
- [v2.1]: SAFE category grouped into a final hardening phase (P13); constraints still honored while building P10-12, formally audited/pen-tested at the end
- [v2.0]: All v2.0 decisions carry forward (see PROJECT.md Key Decisions table)
- [Phase ?]: All IRS dollar amounts live in config/tax-rules.php only — zero literals in TaxRulesEngineService
- [Phase ?]: QBI Phase 10 scope: below-threshold 20% estimate; above-threshold non-SSTB returns professional-review sentinel (deduction_cents=null)
- [Phase ?]: mandatory_roth_catchup_threshold tagged [ASSUMED] — confirm exact 2026 indexed value from IRS final regs before Phase 13
- [Phase ?]: filing_status normalised from married_jointly to married_joint in assembler to match tax-rules.php config keys
- [Phase ?]: Bank deposit classification logic inlined in assembler to enforce calendar-year Jan 1-Dec 31 window and avoid IncomeDetectorService rolling-window pitfall
- [Phase ?]: passesMaterialityGate auto-floor does not apply to recurring patterns; recurring gates on annual total only
- [Phase ?]: Pest.php TestCase binding extended to Unit/ root to enable config facade in plan-11 unit tests
- [Phase ?]: [ASSUMED] markers on 11 IRS constants in detection block — P13 sign-off gate before production
- [Phase ?]: recordFact: flip is_current=false before insert prevents partial-unique index violation
- [Phase ?]: FEED-04 guard at TOP of UpdateTransactionCategory::handle() for Optimization questions
- [Phase ?]: QuestionType::Optimization additive enum case; no migration needed (VARCHAR column)
- [Phase ?]: InterviewOrchestratorService: Claude wording-only; payload excludes estimated_value_cents (SAFE-03)
- [Phase ?]: FLAG-03 gap computed by engine; detector only decides emit/skip
- [Phase ?]: score_threshold=2; no numeric probability in treatment
- [Phase ?]: FLAG-28 D13 locked; test-enforced no-write assertion
- [Phase 11]: FLAG-18 REFRAMED: SafeHarborBenchmark uses penalty-avoidance benchmark framing only; business inflows excluded from computation by construction (Threat T-11-07-01 mitigated)
- [Phase ?]: 18 additive TaxDocumentCategory enum cases — zero existing cases altered
- [Phase ?]: 6 extraction FIELDS const arrays (PAY_STUB, BENEFITS_GUIDE, OFFER_LETTER, RETIREMENT_STATEMENT, STOCK_STATEMENT, INSURANCE) — DOC-06 substantiation falls through to TIER2_FIELDS
- [Phase ?]: DOC-02: existing buildDocumentContent() vision branch confirmed for JPEG/PNG — no new library required
- [Phase ?]: D4 gate proven end-to-end: PaystubFactExtractorService creates is_current=false proposals; confirmProposal() ordering bug fixed; confirm() is the only promotion path
- [Phase ?]: Runtime shape of extracted_data confirmed as nested-with-confidence; defensive fallback pattern in PaystubFactExtractorService + assembler PayStub arm
- [Phase ?]: RPT-05: MarkOptimizationReportStale is flag-flip only (never dispatches); DispatchReportGeneration debounces 30s + ShouldBeUnique coalesces bursts
- [Phase ?]: D17 per-purpose Claude model resolution + daily budget Cache counters at all 4 sanctioned call sites; categorization on Haiku behind unchanged confidence-routing safety net (14-01)
- [Phase ?]: config/optimization-objectives.php is the single readiness source (M5); bonus_election added as scenario domain for 14-09 (D15)
- [Phase ?]: Second @theme block added after existing closing brace per Pitfall 6 (Tailwind v4 merges multiple @theme declarations)
- [Phase ?]: Floor guard isolates now() in assembleBaseline — solve() is purely deterministic
- [Phase ?]: diffKnobs IRA epsilon accepts optional remainingIraRoomCents for dynamic max(config, room/4) threshold
- [Phase ?]: Route model binding 'checklistItem' avoids collision with OrderItem binding
- [Phase ?]: writeRealityFact K2/K3: taxYear=null for stable employer facts (§A.1.2)
- [Phase ?]: SAFE-03: choose() emits delta-tier strings not raw cents in response
- [Phase ?]: Income-shift persistence anchored on optimization_calendar_events NOT report.stale_since (resolves Research A3)
- [Phase ?]: Stage-0 paystub first per DOCUMENTS-FIRST-FUNNEL Δ1; action-center route has no bank.connected middleware
- [Phase ?]: pendingActionCount additive in HandleInertiaRequests; pendingOptimizationCount unchanged (DRIFT-09, ACT-01)
- [Phase ?]: ActionCenterWidget uses axios.patch directly for dynamic checklist IDs; ScenarioComparisonCards uses delta TIERS for SAFE-03 compliance; Wave-3 audit passing
- [Phase ?]: RetirementOpportunitySweep: fact keys corrected to employer.after_tax_401k_available (bool_field=yes/no) and retirement.{roth,traditional}_401k_ytd_cents from UserTaxFact; BENEFITS_FACT_MAP/PAYSTUB_FACT_MAP drift gates added

### Pending Todos

None yet.

### Blockers/Concerns

- Phase 10 planning: lock config/tax-rules.php structure and TaxRulesEngineService API contracts before Claude integration (per research: load-bearing foundation)
- Phase 11 planning: finalize each deduction probe's prerequisite gates via legal review before coding; validate 5-question initial cap
- Phase 12 planning: confirm report section ordering/emphasis with 2-3 accountants
- Carried from v2.0: APP_KEY rotation runbook needed before production with encrypted extraction data

## Session Continuity

Last session: 2026-07-03T02:13:17.305Z
Stopped at: Completed 14-09-PLAN.md
Resume file: None
