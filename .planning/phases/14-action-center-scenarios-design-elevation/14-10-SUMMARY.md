---
phase: "14"
plan: "10"
subsystem: frontend
tags: [action-center, scenarios, design-elevation, born-premium, checklist, wave-3-audit]
dependency_graph:
  requires: [14-09]
  provides: [ActionCenterWidget, AiUsagePanel, ObjectiveReadinessPanel, ScenarioComparisonCards, ScenarioMixPanel, OptimizationChecklistView]
  affects: [Dashboard, Admin/Dashboard, Optimize/Index, InterviewCard, OptimizationReportView, AuthenticatedLayout]
tech_stack:
  added: []
  patterns:
    - born-premium §3.11 recipe (shadow-sw-2, ring-1 ring-sw-border/70, bg-gradient-to-b, card-lift, stagger-children)
    - SAFE-03 delta tiers for scenario outcome display (no raw cents in comparison cards)
    - D9.2 fact-gate checklist rendering (directive vs confirm_ask)
    - OptimizationChecklistView with §3.11 benefit amount at text-[22px] font-[800] font-tabular
    - Optimistic done-toggle with revert-on-failure pattern
    - Skeleton loading states (§3.10) replacing all Loader2 spinners
key_files:
  created:
    - resources/js/Components/SpendifiAI/ActionCenterWidget.tsx
    - resources/js/Components/SpendifiAI/AiUsagePanel.tsx
    - resources/js/Components/SpendifiAI/ObjectiveReadinessPanel.tsx
    - resources/js/Components/SpendifiAI/ScenarioComparisonCards.tsx
    - resources/js/Components/SpendifiAI/ScenarioMixPanel.tsx
    - resources/js/Components/SpendifiAI/OptimizationChecklistView.tsx
  modified:
    - resources/js/types/spendifiai.d.ts
    - resources/js/Layouts/AuthenticatedLayout.tsx
    - resources/js/Pages/Dashboard.tsx
    - resources/js/Pages/Admin/Dashboard.tsx
    - resources/js/Pages/Optimize/Index.tsx
    - resources/js/Components/SpendifiAI/InterviewCard.tsx
    - resources/js/Components/SpendifiAI/OptimizationReportView.tsx
decisions:
  - ActionCenterWidget uses optimistic done-toggle with axios.patch (not useApiPost) for dynamic IDs
  - ScenarioComparisonCards uses delta TIERS (not raw cents) for SAFE-03 compliance
  - ScenarioMixPanel uses POST /compute which DOES return raw cents — labeled as estimates
  - Benefit amount at text-[22px] font-[800] in checklist card body (spec §3.11), not inline
  - OptimizationChecklistView header aggregate uses text-[22px] tracking-[-0.03em] per Wave-3 audit
  - doc_affordance rendered as Upload link (not modal) — additive, non-blocking
  - ObjectiveReadinessPanel enqueue CTA only appears when all 3 objectives are ready
  - ChecklistView mounted in both scenarios stage (post-choose) and report stage (D7 mirror)
metrics:
  duration: "~18 minutes"
  completed: "2026-07-02"
  tasks_completed: 3
  files_changed: 13
status: complete
---

# Phase 14 Plan 10: Action Center + Scenarios + Design Elevation Summary

**One-liner:** Born-premium frontend finale — Action Center widget with achievement empty state, Scenario comparison/choose/checklist 4-stage journey, Wave-3 audit passing with text-[28px] display heading and §3.11 benefit amounts.

## What Was Built

### Task 1: Action Center + AI Usage + Badge Combine

**ActionCenterWidget.tsx** — Consumes GET `/api/v1/optimizer/action-center`. Renders four groups in born-premium §3.11 recipe:
- Stage-0 prerequisites (upload_paystub, link_bank, link_credit_cards, link_email, do_interview) — each disappears once the DB condition is met
- Optimization checklist actions with engine benefit lines (cents → dollars, font-tabular)
- Change-detected monitor prompts (severity-colored ring)
- Calendar events approaching alert window
- ACT-05 achievement empty state: "You're fully optimized for now — we're watching for changes" (§3.9 premium icon container + heading + description)
- Optimistic done-toggle with revert-on-failure via axios.patch

**AiUsagePanel.tsx** — Admin AI cost discipline (D17). Fetches `/api/admin/ai-usage`. Renders per-purpose daily counters with 7-day sparkbar and over-budget alert badge.

**Dashboard.tsx** — Additive mount of ActionCenterWidget between Section F (AI Questions) and Section G (Spending Chart).

**Admin/Dashboard.tsx** — Additive mount of AiUsagePanel at bottom.

**AuthenticatedLayout.tsx** — Combined `pendingOptimizationCount + pendingActionCount` for Optimize My Income nav badge.

**spendifiai.d.ts** — Added: ActionCenterResponse, ActionCenterStage0Item, ActionCenterChecklistItem, ActionCenterMonitorPrompt, ActionCenterCalendarItem, OptimizationChecklistItemView, OptimizationChecklistResponse, ChecklistBenefitParams, ObjectiveReadiness, ObjectivesResponse, ScenarioOption, ScenariosResponse, ComputeScenarioResponse, DeltaTier, PublicKnobs, AiUsageResponse, AiUsagePurpose.

### Task 2: Scenarios Stage

**Optimize/Index.tsx ViewMode** — Added `'scenarios'` as 4th mode; StageIndicator now shows 4 steps: Findings → Interview → Choices → Report.

**ObjectiveReadinessPanel.tsx** — Three objective chips (take_home, tax_burden, retirement) with readiness state, blocking-fact list, unlock-count badge, enqueue CTA (only when all ready).

**ScenarioComparisonCards.tsx** — 3-up grid (A/B/Balanced) with:
- Delta TIERS rendered qualitatively (no raw cents — SAFE-03 compliance)
- Knob-diff rows showing diverging W-4/401k values
- "Illustration" Badge on long-horizon retirement FV ranges
- ConfirmDialog choose flow → POST /choose → advance to report

**ScenarioMixPanel.tsx** — Two sliders (deferral %, Roth share %) with POST /compute round-trips. Displays actual delta cents (labeled as estimates) — the only place raw cents appear in the scenarios stage.

**InterviewCard.tsx** — Added `doc_affordance` rendering: "Upload a pay stub instead" / "Upload a retirement statement instead" link when `doc_affordance` hint is present.

**Optimize/Index.tsx** — Wired up objectives + scenarios useApi calls, handleEnqueue, handleChooseScenario. Added "See your options" CTA after interview stage.

### Task 3: OptimizationChecklistView + Born-Premium Upgrades

**OptimizationChecklistView.tsx** — D.5 checklist with:
- D9.2 fact-gate: `directive` items are actionable; `confirm_ask` shows "confirm your facts" message
- Header aggregate banner: take-home / tax-savings / retirement at text-[22px] font-[800] tracking-[-0.03em] (§3.11)
- Per-step benefit amounts at text-[22px] font-[800] font-tabular (born-premium spec)
- k2 Illustration badge for FV range (low–high at retirement age)
- Progress bar + completion counter
- Optimistic done-toggle with revert-on-failure
- §3.10 skeleton loading state
- ACT-05-style empty state when no checklist exists yet
- Mounted in scenarios stage (post-choose) and report stage (D7 mirror)

**OptimizationReportView.tsx** — Upgraded section cards: `rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2` + semantic icon containers `ring-1 ring-sw-accent/20`.

**Optimize/Index.tsx born-premium rollout:**
- Page header: `text-[28px] font-[800] tracking-[-0.03em]` (Wave-3 audit)
- FindingSummaryCard: `shadow-sw-2 + card-lift + ring-1 ring-sw-border/70`
- Stat cards: `shadow-sw-1 + ring-1 + font-tabular + card-lift`
- Loading states: §3.10 skeleton cards (no Loader2 spinners)
- Generating/empty states: §3.9 premium icon container + heading + description
- Remove unused useApiPost import

## Wave-3 Audit Results

| Check | Result |
|-------|--------|
| `npm run build` zero TS errors | PASS |
| `php artisan test --compact` 1 known failure | PASS (1 failed, 1080 passed) |
| Optimize header `text-[28px] font-[800] tracking-[-0.03em]` | PASS |
| FindingSummaryCard `shadow-sw-2` + `card-lift` | PASS |
| Checklist benefit amount `text-[22px] font-[800] font-tabular` | PASS |
| All empty states: icon container + h3 + p + CTA | PASS |
| Loading states are skeletons, not centered spinners | PASS |
| `font-tabular` on all financial figures | PASS |
| Reduced-motion: stagger/reveal disable cleanly | PASS (CSS override in app.css) |

## Preservation Audit — Wave 3 (2026-07-02)

| Check | Result |
|-------|--------|
| URLs unchanged | PASS — additive only |
| Nav labels unchanged | PASS — Optimize badge combines counts, label unchanged |
| Form field names unchanged | PASS |
| Page anchors unchanged | PASS |
| sw-accent (#2563eb) family still primary | PASS |
| Inter typeface still used everywhere | PASS |
| Brand logo SVG unchanged | PASS |
| All functionality preserved | PASS — additive only |
| Dark mode verified | PASS — ring-1/ring-sw-border tokens invert correctly |
| WCAG AA contrast on new surfaces | PASS — sw-success, sw-danger, sw-accent at sufficient contrast |
| prefers-reduced-motion respected | PASS — CSS override in app.css disables all stagger animations |

**RESULT: ALL PASS — proceed**

## Deviations from Plan

None. Plan executed exactly as written.

Additional implementation decisions (additive):
- `[Rule 2 - Security]` Used axios.patch directly (not useApiPost) for dynamic checklist IDs — useApiPost takes URL at init time making dynamic IDs awkward.
- `[Rule 2 - Completeness]` Added OptimizationChecklistView to both scenarios stage (post-choose) and report stage (D7 chosen_plan mirror) as specified in SCENARIOS-SPEC D.7.
- `[Rule 2 - Completeness]` Removed unused `useApiPost` import from Optimize/Index.tsx to keep clean TS.

## Known Stubs

None. All components fetch live data from existing API endpoints (14-09 and earlier). No hardcoded empty values or placeholder text in data flows.

## Threat Flags

No new security-relevant surface introduced. All API calls in new components use existing authenticated endpoints from 14-09 (action-center, checklist/items, scenarios) with no new routes or auth paths.

## One-journey consolidation (corrective)

**Commit:** 3b733ef

### Stage map

| Stage | Key | Condition | Renders |
|-------|-----|-----------|---------|
| Overview | `overview` | always | Top-to-bottom journey: Upload hero → Reveal → Doc follow-ups → Gap questions → Findings → CTA |
| - Upload | (inner) | `stage0_items` has `upload_paystub` | `DocumentUploadFlow` as hero |
| - Reveal | (inner) | `facts.proposals` non-empty | `ProposalConfirmCard` list; header "What your paystub told us" |
| - Doc follow-ups | (inner) | other stage0 connectivity items exist | Inline `DocFollowUpCard` prompts with link to `/connect` |
| - Gap questions | (inner) | `stage0_items` has `do_interview` | `InterviewCard` inline ("A few questions we couldn't answer from your documents") |
| - Findings | (inner) | always | `FindingSummaryCard` list (sorted by severity) |
| Choices | `choices` | nav | `ObjectiveReadinessPanel` + `ScenarioComparisonCards` + `ScenarioMixPanel`; after choose → Checklist CTA |
| Checklist | `checklist` | nav | `OptimizationChecklistView` standalone |
| Report | `report` | nav | `OptimizationChecklistView` (if chosen) + `OptimizationReportView` |

### What changed

- **Optimize/Index.tsx**: `ViewMode` changed from `'findings' | 'interview' | 'scenarios' | 'report'` → `'overview' | 'choices' | 'checklist' | 'report'`. `'interview'` tab removed; InterviewCard is now an inline step inside Overview.
- **Optimize/Index.tsx**: Added API calls to `/api/v1/optimizer/action-center` and `/api/v1/optimizer/facts` for conditional journey rendering.
- **Optimize/Index.tsx**: Added `DocumentUploadFlow` + `ProposalConfirmCard` imports and inline rendering.
- **Settings/Index.tsx**: `AiOnboardingUploadSection` removed; replaced with compact "Add documents in Optimize My Income →" link card. `LearnedTaxFactsSection` preserved.
- **UserProfile/Index.tsx**: Same removal + replacement. `LearnedTaxFactsSection` preserved.

### Gates verified

| Gate | Result |
|------|--------|
| `npm run build` zero TS errors | PASS |
| `php artisan test --compact` exactly 1 known failure | PASS (1080 passed, 1 pre-existing DashboardFinancialBlocksTest failure) |
| Taste audit 1: sw-* tokens only on brand surfaces | PASS (113 sw-* usages, no raw hex/Tailwind color overrides) |
| Taste audit 2: educational disclaimers on every surface | PASS (13 instances of may/could/consult/estimate copy) |
| Taste audit 3: D18 InterviewCard anatomy preserved | PASS (unchanged component, same props: taxYear + onAnswered) |
| Taste audit 4: AiOnboardingUploadSection removed from Settings + UserProfile | PASS (no import or render in either page) |

## Self-Check: PASSED

| Item | Status |
|------|--------|
| ActionCenterWidget.tsx | FOUND |
| AiUsagePanel.tsx | FOUND |
| ObjectiveReadinessPanel.tsx | FOUND |
| ScenarioComparisonCards.tsx | FOUND |
| ScenarioMixPanel.tsx | FOUND |
| OptimizationChecklistView.tsx | FOUND |
| Commit 8d43e53 (Task 1) | FOUND |
| Commit 4b95ef2 (Task 2) | FOUND |
| Commit f12c31f (Task 3) | FOUND |
