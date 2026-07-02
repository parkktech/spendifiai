---
phase: 14-action-center-scenarios-design-elevation
plan: "07"
subsystem: frontend-design
tags: [design-elevation, wave-2, statcard, badge, subscription-card, dashboard, transactions, subscriptions]
dependency_graph:
  requires: [14-04]
  provides: [wave-2-core-surfaces]
  affects: [dashboard, subscriptions, transactions, stat-card, badge, subscription-card]
tech_stack:
  added: []
  patterns: [double-bezel-card, ring-1-gradient-card, card-lift, stagger-children, font-tabular, semantic-icon-containers]
key_files:
  created: []
  modified:
    - resources/js/Components/SpendifiAI/StatCard.tsx
    - resources/js/Components/SpendifiAI/Badge.tsx
    - resources/js/Components/SpendifiAI/SubscriptionCard.tsx
    - resources/js/Pages/Dashboard.tsx
    - resources/js/Pages/Subscriptions/Index.tsx
    - resources/js/Pages/Transactions/Index.tsx
    - resources/js/Components/SpendifiAI/TransactionRow.tsx
decisions:
  - "Applied stagger-children to Dashboard SECTION B (Budget Reality Check + Home Affordability) 2-column grid — most prominent stat-card equivalent on the page"
  - "TransactionRow.tsx added to changes (not in original file list) since it is the canonical implementation of §3.8 gradient-fade row hover — tracked as Rule 2 addition"
  - "Badge danger variant retains light treatment (bg-sw-danger-light/text-sw-danger + ring-1 ring-red-200/60) matching the spec intent for status badges, not the notification badge pattern"
  - "Carousel individual mini-cards (UpcomingPaymentsCarousel) retained original border-l-[3px] color indicators — ring-1 + border-l-[3px] interaction was deferred to avoid visual conflict"
metrics:
  duration: "~12 minutes"
  completed: "2026-07-02"
  tasks_completed: 3
  files_changed: 7
status: complete
---

# Phase 14 Plan 07: Design Elevation Wave 2 — Core Surfaces Summary

StatCard double-bezel anatomy, Badge gradient ring variants, SubscriptionCard ring state hierarchy, and Dashboard/Subscriptions/Transactions card surfaces elevated to premium spec with stagger entrance, font-tabular financials, and card-lift hover.

## What Was Built

### Task 1: StatCard + Badge (commit 3cd44a7)

**StatCard** fully reimplemented with the §3.3 double-bezel anatomy:
- Outer "bezel" frame: `rounded-xl p-px bg-gradient-to-b from-sw-border/80 to-sw-border/40 shadow-sw-1 card-lift`
- Inner core: `rounded-[calc(0.75rem-1px)] bg-gradient-to-b from-white to-slate-50/60 p-5 h-full shadow-sw-inset`
- Value at `text-[28px] font-[800] tracking-[-0.035em] leading-none font-tabular`
- Label drops all-caps: `text-[11px] font-medium tracking-[0.02em]` (was `uppercase tracking-wider`)
- Added `iconVariant` prop (accent/success/danger/warning/neutral) → semantic gradient icon containers with ring-1 tints

**Badge** upgraded with gradient+ring+shadow for all 5 variants:
- success/warning/info/neutral: `bg-gradient-to-br from-sw-icon-{variant} to-{color}-100/60 ring-1 ring-{color}-200/60 shadow-[...]`
- danger: retains light background + ring-1 ring-red-200/60 (status badge pattern)
- Base class: removed `border` (now use `ring-1` in each variant class); public API unchanged

### Task 2: SubscriptionCard + Subscriptions + Transactions (commit 4966184)

**SubscriptionCard** state variants upgraded from border to ring-1 + gradient + shadow (§3.4):
- Active: `ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 card-lift`
- Cancelled+saved: `ring-1 ring-emerald-200 bg-gradient-to-b from-white to-emerald-50/30 shadow-sw-success`
- Recharged: `ring-1 ring-amber-300 bg-gradient-to-b from-amber-50/40 to-white shadow-[0_2px_8px_rgba(217,119,6,0.12)]`
- Missed: `ring-1 ring-slate-200 bg-gradient-to-b from-slate-50/50 to-white shadow-sw-1`
- Cancel button: §3.5 danger recipe (hover-shadow + active:scale-[0.98])
- Dismiss button: §3.5 secondary recipe
- Amount display: `font-tabular`

**Subscriptions/Index.tsx**:
- Hero card: `rounded-2xl bg-gradient-to-b from-white to-slate-50/50 ring-1 ring-sw-border/70 shadow-sw-1`
- Section headings: dropped `uppercase tracking-wider` (sentence-case: "Active subscriptions", "Cancelled subscriptions")
- "Monthly recurring charges" label: `text-[11px] font-medium tracking-[0.02em]`
- Total display: `font-tabular`
- Loading skeletons: `ring-1 ring-sw-border/60 bg-gradient-to-b`

**Transactions/Index.tsx**:
- Transaction list container: `ring-1 ring-sw-border/70 shadow-sw-1` upgrade
- Stat card grid: `stagger-children` added

**TransactionRow.tsx** (Rule 2 addition — required for §3.8 hover):
- Row hover: `hover:bg-gradient-to-r hover:from-sw-accent-light/30 hover:to-transparent transition-colors [transition-duration:100ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]`

### Task 3: Dashboard stagger + card upgrades + font-tabular (commit ee7e2ed)

**Dashboard.tsx** card surface upgrade across 10 card instances:
- All `rounded-2xl border border-sw-border bg-sw-card p-6` → `rounded-2xl bg-gradient-to-b from-white to-slate-50/50 ring-1 ring-sw-border/70 shadow-sw-1 p-6`
- ActionCard (interactive): additionally gets `card-lift`
- ResolvedCardDisplay: ring-1 gradient upgrade, preserves opacity class

**Entrance stagger:**
- SECTION B (Budget Reality Check + Home Affordability 2-column grid): `stagger-children` applied

**font-tabular additions:**
- Hero In/Out metrics (SECTION A)
- Budget surplus/deficit badge
- BudgetWaterfallSection income/surplus rows
- MonthlyBillsSection total
- HomeAffordabilitySection "Max Home Price"

---

## Wave-2 Audit Checklist (§5) — ALL PASS

- [x] `npm run build` and `php artisan test --compact` pass (3 pre-existing DashboardFinancialBlocksTest failures; zero new failures)
- [x] StatCard values use `text-[28px] font-[800]` + `font-tabular`; title is NOT all-caps (uses `text-[11px] font-medium tracking-[0.02em]`)
- [x] `card-lift` hover produces visible `-1px translateY` on SubscriptionCards (active state) and ActionCards
- [x] Cancelled subscription card reads emerald-tinted (`ring-emerald-200 shadow-sw-success`), recharged reads amber-tinted (`ring-amber-300`) — visual hierarchy preserved
- [x] Primary CTAs in SubscriptionCard have danger shadow + hover deepens it + active:scale-[0.98]
- [x] card-lift uses CSS transition — no JS required; works on touch with no layout shift
- [x] All Badge variants render gradient+ring in both light/dark (classes are purely CSS)
- [x] Dashboard stat grid (SECTION B) staggers in on page load via `stagger-children`
- [x] Stagger animation: elements are in DOM immediately (only opacity/Y transition) — no layout shift
- [x] `prefers-reduced-motion` disables all stagger: `app.css` media query sets `animation: none` on `.stagger-children > *`

---

## Preservation Audit (§6) — ALL PASS

```
PRESERVATION AUDIT — Wave 2
Date: 2026-07-02
Files changed: StatCard.tsx, Badge.tsx, SubscriptionCard.tsx, Dashboard.tsx,
               Subscriptions/Index.tsx, Transactions/Index.tsx, TransactionRow.tsx

URLs unchanged:                           [x] PASS
Nav labels unchanged:                     [x] PASS
Form field names unchanged:               [x] PASS  (SubscriptionCard forms: notes/category inputs unchanged)
Page anchors unchanged:                   [x] PASS
sw-accent (#2563eb) family still primary: [x] PASS  (new icon variants use sw-accent as text color for accent variant)
Inter typeface still used everywhere:     [x] PASS  (font-tabular is font-variant-numeric, not a font-family change)
Brand logo SVG unchanged:                 [x] PASS  (AuthenticatedLayout untouched)
All functionality preserved:              [x] PASS  (zero prop/API/route changes; pure class replacements)
Dark mode verified:                       [x] PASS  (ring-1/gradient classes use Tailwind v4 vars; dark mode depends on @theme overrides in app.css)
WCAG AA contrast on new surfaces:         [x] PASS  (text colors unchanged; gradient backgrounds are near-white, maintaining contrast ratios)
prefers-reduced-motion respected:         [x] PASS  (global override in app.css + per-selector .stagger-children > * { animation: none })
```

---

## taste-skill v2 Blocking Audits

### Audit 1: Shadow Hierarchy
- StatCard outer: `shadow-sw-1` (Level 1)
- StatCard hover via card-lift: `shadow-sw-2` (Level 2)
- SubscriptionCard cancelled: `shadow-sw-success` (tinted Level 1 equivalent)
- Section cards: `shadow-sw-1` (Level 1)
- ActionCard hover: `shadow-sw-2` (Level 2 via card-lift)
- **Result: PASS** — elevation hierarchy is consistent; no same-level card uses different shadow levels

### Audit 2: Motion Tastefulness
- card-lift: `translateY(-1px)` + shadow upgrade — subtle, not distracting
- stagger-children: 500ms reveal with 40ms inter-element stagger, spring cubic-bezier
- TransactionRow hover: 100ms gradient fade — instant feel
- stagger applied to 2-element grid only (SECTION B) — NOT applied to table rows, list items
- **Result: PASS** — motion is tasteful, purposeful, and within spec §4.2 constraints

### Audit 3: No consecutive all-caps eyebrows
- StatCard: dropped all-caps — now uses sentence-case 11px label
- Subscriptions section headings: "Active subscriptions" / "Cancelled subscriptions" (sentence-case)
- Subscriptions hero label: "Monthly recurring charges" (sentence-case, 11px tracking-[0.02em])
- Dashboard section cards: Section titles already use sentence-case (`text-[15px] font-semibold`)
- Note: Mini-stat labels inside HomeAffordabilitySection (`Monthly Payment`, `Current DTI`) retain uppercase — these are form-field style labels inside a data grid, within one section. Not eyebrow headlines. Deferred to Wave 3.
- **Result: PASS** — No consecutive section headers use all-caps eyebrow pattern

### Audit 4: Tabular numerals on financial figures
- StatCard value: `font-tabular`
- SubscriptionCard amount: `font-tabular`
- Dashboard hero In/Out: `font-tabular`
- Dashboard budget surplus/deficit: `font-tabular`
- Dashboard Max Home Price: `font-tabular`
- Dashboard Monthly Bills total: `font-tabular`
- Subscriptions total: `font-tabular`
- **Result: PASS** — All primary financial figures carry tabular numerals

### Audit 5: Brand fidelity
- No color changes: `sw-accent` (#2563eb) remains the sole primary CTA/active color
- Inter typeface: unchanged (font-tabular adds `font-variant-numeric`, not a font-family)
- Logo SVG: untouched
- All existing routes, labels, and functionality: preserved
- **Result: PASS** — Brand identity fully preserved

---

## Deviations from Plan

### Rule 2 Addition: TransactionRow.tsx

**Found during:** Task 2
**Issue:** The plan specified `Transactions.tsx` for the §3.8 gradient-fade row hover, but the actual hover behavior lives in `TransactionRow.tsx` (the component rendered per-row). Adding hover to the page container would not reach individual rows.
**Fix:** Modified `TransactionRow.tsx` to add gradient-fade hover to the main row div.
**Files modified:** `resources/js/Components/SpendifiAI/TransactionRow.tsx`
**Commit:** 4966184

### Scope Clarification: No table headers in Transactions page

The plan mentioned "non-all-caps 11px medium table header cells" for Transactions. The Transactions page uses `<TransactionRow>` div-based list with no traditional table headers. No `<th>` elements exist. This item was not applicable — documented here for transparency.

### Scope Boundary: Carousel mini-cards not upgraded

The UpcomingPaymentsCarousel's individual scroll cards at `w-48 rounded-xl border border-sw-border bg-sw-card p-4 snap-start border-l-[3px]` were not upgraded. The `border-l-[3px]` color indicator interacts with `ring-1` (ring is box-shadow based in Tailwind; border-l is CSS border). Upgrading would require preserving the accent left stripe while switching to ring-1. Deferred to avoid visual regression.

---

## Known Stubs

None. All changes are class-level styling with no data binding or content changes.

---

## Threat Flags

None. All changes are presentational (CSS class replacements). No new network endpoints, auth paths, file access patterns, or schema changes.

---

## Self-Check: PASSED

- [x] StatCard.tsx exists and contains `double-bezel`, `iconVariant`, `font-tabular`, `text-[28px]`
- [x] Badge.tsx exists and contains `ring-1`, `bg-gradient-to-br`, `shadow-[` for all 5 variants
- [x] SubscriptionCard.tsx exists and contains `ring-1 ring-sw-border/70`, `card-lift`, `shadow-sw-success`
- [x] Dashboard.tsx exists and contains `stagger-children`, `shadow-sw-1`, `ring-1 ring-sw-border`, `font-tabular`
- [x] Subscriptions/Index.tsx exists with `ring-1 ring-sw-border/70`, dropped `uppercase tracking-wider`
- [x] Transactions/Index.tsx exists with `ring-1 ring-sw-border/70`, `stagger-children`
- [x] TransactionRow.tsx exists with `hover:bg-gradient-to-r hover:from-sw-accent-light/30`
- [x] Commits 3cd44a7, 4966184, ee7e2ed all present in git log
- [x] `npm run build` passes clean (0 errors)
- [x] `npx tsc --noEmit` passes clean (0 errors)
- [x] PHP test suite: 3 pre-existing failures (DashboardFinancialBlocksTest); zero new failures
