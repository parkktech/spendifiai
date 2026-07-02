---
phase: "12"
plan: "06"
subsystem: "frontend-nav-ui"
tags: [nav-sidebar, settings-merge, admin-group, responsive-grid, mobile-fix, ui-batch]
decisions:
  - "Settings and My Profile merged into single 'Profile & Settings' page (Decision 5-SUPERSEDED)"
  - "Admin nav group: collapsible, amber-toned, bottom-pinned, localStorage-persisted"
  - "P12 sections added to Settings in profile-first order"
  - "Dashboard.tsx:479 Home Affordability 2x2 key-numbers grid: kept 2-col (content fits mobile)"
  - "Connect/Index.tsx:912 IMAP Host/Port grid: left as-is (form-field, not card container)"
  - "Savings/Index.tsx:260 Target Amount/Deadline grid: left as-is (form-field)"
metrics:
  duration: "~16 minutes"
  completed: 2026-07-02
  tasks: 4
  files: 8
status: complete
---

# Phase 12 Plan 06: UI Batch Summary

One-liner: Nav sidebar restructure (profile merge + admin group) + responsive stat-card grid + mobile description-column bug fix across Dashboard/Savings/Tax/RecommendationCard.

---

## Tasks Completed

| # | Task | Commit | Key files |
|---|------|--------|-----------|
| 1 | Merge Settings + My Profile, pin to nav bottom | a413dfe | Settings/Index.tsx, AuthenticatedLayout.tsx, routes/web.php |
| 2 | Admin nav group — distinct, collapsed, bottom-pinned | a413dfe* | AuthenticatedLayout.tsx |
| 3 | Responsive stat-card sweep | 1860208 | Tax/Index.tsx, Savings/Index.tsx, Dashboard.tsx |
| 4 | Fix trapped description in ActionCard + RecommendationCard | 2d01579 | Dashboard.tsx, RecommendationCard.tsx |

*Tasks 1 and 2 are committed together (a413dfe) because both touch the same sidebar file (AuthenticatedLayout.tsx) in tightly interleaved sections. The commit message covers both task intents.

---

## Task 1: Merge Settings + My Profile

**Settings/Index.tsx changes:**
- Added imports: `Info`, `AiOnboardingUploadSection`, `FamilyHouseholdSection`, `HsaShoeboxSection`
- Added page-level educational disclaimer (UI-03, inline, non-dismissable)
- Renamed page header: "Settings" → "Profile & Settings"
- Added 3 P12 sections after `LearnedTaxFactsSection` in this order:
  1. `AiOnboardingUploadSection` — fastest path to complete profile via document upload
  2. `FamilyHouseholdSection` — read-only aggregate view of household/dependent facts (note: editing still occurs via the HouseholdSection + DependentsSection already above it on the page)
  3. `HsaShoeboxSection` — STORE-03 receipt tracking
- Remaining section order: My Accountants → Preferences → Cookie Preferences → Security → Danger Zone

**Section order rationale:** Profile content first (Financial Profile, Household edit, Dependents edit, Enhanced Tax, Learned Facts), then AI onboarding upload (prominent per Decision 5), then Family summary + HSA shoebox, then account management/security sections last per decision mandate.

**UX callout:** `FamilyHouseholdSection` was designed as a read-only summary that links to Settings for editing. Now that it's ON Settings, the link-to-settings note is redundant but harmless. Future cleanup: consider replacing `FamilyHouseholdSection` with a non-linkback variant, or removing it if `HouseholdSection` + `DependentsSection` provide sufficient context above it. Not changed here — owner imported as-is per Decision 5.

**web.php:**
```php
Route::redirect('/user-profile', '/settings')->name('user-profile');
```
Named route `user-profile` preserved. No PHP files were dropped.

**AuthenticatedLayout.tsx — nav changes:**
- Removed "My Profile" nav item
- Renamed "Settings" → "Profile & Settings"
- Extracted "Profile & Settings" from `navItems` into dedicated bottom-pinned section (below the main nav `flex-1` overflow block, above the collapse toggle)
- User dropdown top-bar also updated to show "Profile & Settings"

---

## Task 2: Admin Nav Group

**AdminNavGroup rendered ONLY when `isAdmin === true`** — non-admins see nothing.

**All admin routes collected:**
| Label | Route | Was in main nav? |
|-------|-------|-----------------|
| Admin Dashboard | /admin | Yes |
| Providers | /admin/providers | No (added) |
| Charities | /admin/charities | No (added) |
| Storage | /admin/storage | No (added) |
| Consent | /admin/consent | Yes |

**Design (ELEVATE DON'T REPLACE):**
- Color: `sw-warning` (#d97706 amber) / `sw-warning-light` (#fffbeb) — distinct from user nav without leaving sw-* system
- Active state: `bg-sw-warning/15 text-sw-warning border-l-2 border-sw-warning`
- Inactive: `text-amber-700/70 hover:bg-sw-warning/10 hover:text-amber-800`
- Group header uses `uppercase tracking-widest text-[10px]` label — visually clearly "admin mode"

**Collapse persistence:** `localStorage.setItem('adminNavExpanded', '1'/'0')` — mirrors the dark-mode toggle pattern.

**Collapsed sidebar:** When sidebar is collapsed (icon-only mode), the admin group shows a ShieldCheck icon. A yellow dot (`w-1.5 h-1.5 rounded-full bg-sw-warning`) appears on the button if any admin route is currently active. Items are still accessible when expanded in collapsed mode (icon-only).

**Bottom-stack order:** Admin group (amber, collapsible) → Profile & Settings (below, always visible).

---

## Task 3: Responsive Stat-Card Sweep

### Changes made

| File | Line | Before | After | Cards |
|------|------|--------|-------|-------|
| Tax/Index.tsx | ~170 | `flex gap-4 mb-6 flex-wrap` | `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6` | 4 |
| Savings/Index.tsx | ~103 | `flex gap-4 mb-6 flex-wrap` | `grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6` | 3 |
| Dashboard.tsx | ~375 | `grid grid-cols-2 gap-3 mb-4` | `grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4` | 2 |

### Judgment calls — items investigated but left unchanged

| Location | What it is | Decision | Reason |
|----------|-----------|----------|--------|
| Dashboard.tsx:479 | Home Affordability key numbers (2x2 grid: Monthly Payment, Rate, DTI, Term) | KEPT 2-col | 4 compact cells with short single values; fits fine in 2-col at mobile width within a constrained card |
| Connect/Index.tsx:912 | IMAP Host/Port form inputs | UNCHANGED | Form-field grid, not a card container — matches "leave form-field 2-col grids alone" rule |
| Savings/Index.tsx:260 | Target Amount/Deadline form inputs | UNCHANGED | Form-field grid — same rule applies |

### P12 pages sweep
`AiOnboardingUploadSection.tsx`, `FamilyHouseholdSection.tsx`, `HsaShoeboxSection.tsx` — no `flex gap-4 mb-6 flex-wrap` StatCard patterns found.

### Intentionally untouched form-field grids (per task instructions)
- `Register.tsx:96` — registration form 2-col
- `Admin/*/Edit` and `Admin/*/Create` pages — admin form layouts
- `Savings/Index.tsx:260` — target amount/deadline
- `Connect/Index.tsx:912` — IMAP host/port
- `Settings/Index.tsx` `grid grid-cols-1 sm:grid-cols-2` (line ~314) — financial profile form — already responsive, no change needed

---

## Task 4: Trapped Description Mobile Fix (Owner-Added)

**Bug:** In `flex justify-between` card headers, the description `<p>` was inside the narrow left `min-w-0` / `flex-1` column alongside the title, beside a `shrink-0` amount column. On mobile, the description text was severely width-constrained.

**Fix pattern:** Move `<p>` description OUTSIDE the flex row (below it), as a standalone full-width block.

| File | Component | Fix applied |
|------|-----------|-------------|
| Dashboard.tsx ~753 | ActionCard (Where to Cut) | Title row = h4 + amount (justify-between); description `<p>` below as `mt-1 text-xs text-sw-muted leading-relaxed` |
| RecommendationCard.tsx ~39 | RecommendationCard (Savings page) | Title+check row + savings amount (justify-between); description `<p>` pulled out below as `mb-2 text-xs text-sw-muted leading-relaxed` |

**Sweep:** `ProjectedSavingsBanner.tsx` checked — no trapped-description pattern (description isn't beside a shrink-0 amount column).

---

## taste-skill v2 Blocking Audits

### Section 11.B: Brand Tokens & IA Audit (in writing)

**Brand tokens in use:**
- Primary: `sw-accent` (#2563eb), `sw-accent-hover` (#1d4ed8), `sw-accent-light` (#eff6ff)
- Type: Inter (self-hosted WOFF2), `sw-text` / `sw-text-secondary` / `sw-muted` / `sw-dim`
- Surfaces: `sw-bg`, `sw-card`, `sw-surface`, `sw-sidebar`
- Borders: `sw-border`, `sw-border-strong`
- Status: `sw-success`, `sw-warning`, `sw-danger`, `sw-info` + light variants
- Radii: `rounded-lg` (8px) / `rounded-xl` (12px) / `rounded-2xl` (16px)

**Patterns preserved:**
- StatCard component used consistently
- Section-card pattern: `rounded-2xl border border-sw-border bg-sw-card p-6`
- Nav item pattern: `border-l-2 border-sw-accent bg-sw-accent/10` (active)
- Badge, ConfirmDialog, connectivity banner — all unchanged
- recharts-based charts — untouched
- Inter font weight / tracking discipline — maintained

**Patterns to retire (not changed by this batch — logged for future):**
- `flex flex-wrap gap-4` for StatCard rows (this batch fixed the main offenders)

**Inferred dial:**
- DESIGN_VARIANCE: Low-Medium (sw-* is disciplined; admin amber is a targeted departure)
- MOTION_INTENSITY: Low (only existing `transition-colors`, `animate-spin`, `animate-pulse`)
- VISUAL_DENSITY: Medium-High (data-dense financial dashboard)

### Declare mode + levers (Section 11.D)

**Mode: PRESERVE (locked)**

Levers applied in priority order:
1. Layout correctness (responsive grid — Task 3)
2. Content hierarchy (description un-trapped to span full width — Task 4)
3. Navigation clarity (profile pin + admin separation — Tasks 1+2)
4. Spacing rhythm (4px/8px maintained; no changes to existing spacing)
5. Color discipline (admin group uses sw-warning; no palette drift)

### Pre-Flight Check (Section 14)

- [x] No new Google Fonts / CDN font imports added
- [x] No `#hex` colors used directly — all sw-* tokens or Tailwind semantic classes
- [x] No `!important` used
- [x] No absolute/fixed positioning added without purpose
- [x] No raw `px` values added (all existing, none new)
- [x] No z-index values above existing z-40 range
- [x] All interactive elements have focus states (inherited from sw-* button patterns)

### Preservation Audit

Changed URLs/routes/labels/fields — must match owner-granted exceptions only:

| Change | Type | Exception granted |
|--------|------|-------------------|
| Nav label "Settings" → "Profile & Settings" | Label rename | Decision 5-SUPERSEDED |
| Nav "My Profile" item removed | Nav reorganization | Decision 5-SUPERSEDED |
| "Profile & Settings" pinned to bottom | Nav reorganization | Decision 5-SUPERSEDED |
| /user-profile → Route::redirect('/settings') | Route behavior | Decision 5-SUPERSEDED |
| Admin items relocated to collapsible group | Nav reorganization | Decision 8 |
| Admin group distinct amber styling | Styling | Decision 8 |
| Card-internals restructure (ActionCard, RecommendationCard) | Layout | Owner-granted exception (Task 4 coordinator message) |

All other routes, form field names, headings, API response shapes: **unchanged**.

### Brand Fidelity Audit

- [x] `sw-accent` as primary CTA color — unchanged everywhere
- [x] Inter typeface — unchanged
- [x] Logo treatment — unchanged
- [x] sw-* token system — no raw hex or Tailwind color classes replacing tokens
- [x] Card/shadow/border patterns — unchanged
- [x] Admin group uses sw-warning (amber) — distinct but within the existing sw-* system

---

## Deviations from Plan

### Auto-fixed Issues

None — plan executed as specified with one task addition.

### Task Addition

**Task 4 (Owner-added during execution):** Mobile trapped-description fix in ActionCard and RecommendationCard. Not in original plan spec; added via coordinator relay during task execution. Owner-granted preservation exception included.

### Bundled Commits

Tasks 1 and 2 committed together (a413dfe) — both implement nav sidebar changes in the same file (AuthenticatedLayout.tsx) with interleaved code. Separating them would require cherry-pick gymnastics with no functional benefit.

---

## Known Stubs

None — no hardcoded empty values, placeholder text, or unwired data sources introduced.

## Threat Flags

None — no new network endpoints, auth paths, or schema changes introduced.

---

## Self-Check: PASSED

**Files verified exist:**
- /home/spendifi/public_html/resources/js/Layouts/AuthenticatedLayout.tsx — FOUND
- /home/spendifi/public_html/resources/js/Pages/Settings/Index.tsx — FOUND
- /home/spendifi/public_html/resources/js/Components/SpendifiAI/RecommendationCard.tsx — FOUND
- /home/spendifi/public_html/.planning/phases/12-optimization-report-document-intake-feature-surface/12-06-UI-BATCH-SUMMARY.md — FOUND

**Commits verified:**
- a413dfe: feat(12-06): merge Profile+Settings, pin to nav bottom, /user-profile redirect — FOUND
- 1860208: fix(12-06): responsive stat-card grid sweep — Tax, Savings, Dashboard — FOUND
- 2d01579: fix(12-06): unstick trapped description in ActionCard + RecommendationCard — FOUND
- 8632a74: test(12-06): update /user-profile route test for redirect behavior — FOUND

**Build:** `npm run build` — ✓ built in ~6s, zero TypeScript errors (3430 modules)

**Tests:** `php artisan test --compact` — 804 passed, 1 failed (DashboardFinancialBlocksTest — known pre-existing), 1 risky. Zero new failures introduced.
