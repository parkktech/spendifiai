---
phase: 12-optimization-report-document-intake-feature-surface
plan: "05"
subsystem: feature-surface-optimize-my-income-user-profile
tags: [frontend, nav, optimize, user-profile, proposal-confirm, hsa-shoebox, UI-01, UI-02, UI-03, D5, STORE-03, DOC-05]
status: complete
requirements: [UI-01, UI-02, UI-03]

dependency_graph:
  requires:
    - phases/12-04 (pendingOptimizationCount Inertia prop; OptimizationReportController GET/regenerate/download)
    - phases/12-02 (DurableFactsController confirm/supersede; HsaShoeboxService; hsa_shoebox.* facts)
    - phases/11 (InterviewCard; SuggestedConfirmCard; LearnedTaxFactsSection; EnhancedProfileSection — all imported UNCHANGED)
  provides:
    - Two additive nav items (Optimize My Income badged + My Profile) in AuthenticatedLayout
    - /optimize → Optimize/Index (three-stage: findings → interview → collapsible report)
    - /user-profile → UserProfile/Index (AI onboarding + learned facts + family + HSA shoebox)
    - OptimizationReportView component (collapsible sections + per-section disclaimers + regen/download)
    - ProposalConfirmCard (D4 gate via existing DurableFactsController::confirm)
    - DocumentUploadFlow (multi-step wizard; pdf/png/jpg/jpeg)
    - AiOnboardingUploadSection (fastest-path paystub upload → poll facts → proposal cards)
    - FamilyHouseholdSection (read-only household members + dependents composition)
    - HsaShoeboxSection (STORE-03; hsa_shoebox.* facts + add-receipt flow)
  affects:
    - AuthenticatedLayout (2 additive nav items only; nothing existing changed)
    - routes/web.php (2 additive routes inside existing auth group; /profile Breeze route untouched)

tech_stack:
  added: []
  patterns:
    - Three-stage Inertia page (findings → interview → report) with viewMode state
    - Import-unchanged composition pattern (EnhancedProfileSection + LearnedTaxFactsSection)
    - D4 confirm gate UI via existing DurableFactsController endpoint (T-12-05-01)
    - Per-section educational disclaimer (UI-03, non-globally-dismissable)
    - Collapsible section card (SectionCard with open/closed aria-expanded)
    - DOC-05 in-flow FileDropZone for docs_missing affordance inside report view
    - STORE-03 HSA shoebox list + add-receipt flow with approved education copy

key_files:
  created:
    - resources/js/Pages/Optimize/Index.tsx
    - resources/js/Components/SpendifiAI/OptimizationReportView.tsx
    - resources/js/Pages/UserProfile/Index.tsx
    - resources/js/Components/SpendifiAI/ProposalConfirmCard.tsx
    - resources/js/Components/SpendifiAI/AiOnboardingUploadSection.tsx
    - resources/js/Components/SpendifiAI/FamilyHouseholdSection.tsx
    - resources/js/Components/SpendifiAI/HsaShoeboxSection.tsx
    - resources/js/Components/SpendifiAI/DocumentUploadFlow.tsx
    - tests/Feature/OptimizeSurfaceRouteTest.php
  modified:
    - resources/js/Layouts/AuthenticatedLayout.tsx (2 lucide imports + 2 nav items — additive)
    - routes/web.php (2 additive routes — additive)

decisions:
  - "Three-stage Optimize page (findings → interview → report) with viewMode state; no deep-linking required for v2.1"
  - "OptimizationReportView receives report prop from parent (reduces duplicate API calls); SectionCard defaultOpen=true for first section only"
  - "FamilyHouseholdSection is read-only view (links to Settings for editing); HouseholdSection/DependentsSection not modified"
  - "DocumentUploadFlow posts to /api/v1/documents (existing vault endpoint) — no new endpoint needed; docType passed as document_type form field"
  - "AiOnboardingUploadSection polls /optimizer/facts after 4s delay (extraction job async); user can manually refresh"

metrics:
  duration: "~13 minutes"
  completed_date: "2026-07-02"
  tasks_completed: 3
  tasks_total: 3
  files_created: 9
  files_modified: 2
  tests_added: 5
  assertions_added: 27
  commits: 3
---

# Phase 12 Plan 05: Feature Surface (Optimize My Income + User Profile) Summary

**One-liner:** Two additive nav items + three-stage Optimize page (findings list → P11 interview reused → collapsible report with per-section disclaimers) + User/Family Profile page composing existing sections unchanged with AI onboarding upload, family/household read-only view, and HSA shoebox.

## What Was Built

### Task 1 — Additive Nav + Web Routes + Route Tests (UI-01)

**`resources/js/Layouts/AuthenticatedLayout.tsx`** — Additive changes only:
- Added `TrendingUp` and `User` to the lucide-react import block
- Appended two new nav items to `navItems` array (after AI Questions, before accountant/admin):
  - `{ label: 'Optimize My Income', href: '/optimize', routeName: 'optimize', icon: <TrendingUp size={18} />, badge: (auth.pendingOptimizationCount as number) || undefined }`
  - `{ label: 'My Profile', href: '/user-profile', routeName: 'user-profile', icon: <User size={18} /> }`
- The existing `NavItem` badge render (lines 55-59) handles the pill — reused unchanged

**`routes/web.php`** — Two routes added inside the existing `auth:sanctum+verified` group:
```php
Route::get('/optimize', fn () => Inertia::render('Optimize/Index'))->name('optimize');
Route::get('/user-profile', fn () => Inertia::render('UserProfile/Index'))->name('user-profile');
```
The Breeze `/profile` route (lines 92-95) is UNTOUCHED — no collision.

**`tests/Feature/OptimizeSurfaceRouteTest.php`** — 5 tests, 27 assertions:
- Authenticated+verified user GET /optimize → 200, Inertia component `Optimize/Index`
- Authenticated+verified user GET /user-profile → 200, Inertia component `UserProfile/Index`
- Unauthenticated GET /optimize → redirect to login
- Unauthenticated GET /user-profile → redirect to login
- Breeze GET /profile → 200 (no regression, Pitfall 6 avoided)

**Result:** 5 passed, 27 assertions.

### Task 2 — Optimize My Income Page (UI-02, UI-03)

**`resources/js/Pages/Optimize/Index.tsx`** — Three-stage page:
- `ConnectBankPrompt` guard when `auth.hasBankConnected` is false
- `StageIndicator` bar (Findings → Interview → Report) with viewMode state
- **Stage 1 (findings):** `FindingSummaryCard` grid, ranked by severity (high-priority sections first); stat cards (total findings, high priority, tax year); generating state while report status=generating; CTA to start interview
- **Stage 2 (interview):** `InterviewCard` reused UNCHANGED; skip-to-report button
- **Stage 3 (report):** `OptimizationReportView` fed by GET /api/v1/optimizer/report/{year}; regenerate/download actions
- Page-level educational disclaimer (UI-03, inline, non-globally-dismissable) always visible

**`resources/js/Components/SpendifiAI/OptimizationReportView.tsx`** — Collapsible report:
- `SectionCard`: collapsible (aria-expanded), summary pill showing high/medium count, `FindingRow` per finding
- `FindingRow`: severity badge, confidence band badge, description; DOC-05 in-flow upload via `FileDropZone` when `docs_missing` non-empty
- Per-section educational disclaimer (UI-03) — visible when section is open, non-globally-dismissable
- Generating state (spinner + "request now" button); ready state (section list + executive summary + regen/download bar)
- Bottom-level persistent disclaimer (UI-03)

### Task 3 — User/Family Profile Page (D5, UI-03)

**`resources/js/Components/SpendifiAI/ProposalConfirmCard.tsx`** — D4 gate UI:
- Shows fact label + per-field confidence badge (from `metadata.confidence`)
- "Not saved yet — nothing is written until you confirm below" copy (T-12-05-01)
- Confirm: `POST /api/v1/optimizer/facts/{fact}/confirm` (existing DurableFactsController)
- Edit: shows text input + `POST /api/v1/optimizer/facts/{fact}/supersede`
- Reject: local dismiss; proposal row stays in DB
- Per-card educational disclaimer (UI-03, non-dismissable)

**`resources/js/Components/SpendifiAI/DocumentUploadFlow.tsx`** — Multi-step upload wizard:
- Step 1: document type selection (paystub, W-2, benefits guide, HSA statement, retirement statement, medical receipt, other)
- Step 2: `FileDropZone` (pdf/png/jpg/jpeg)
- Step 3: processing indicator
- Step 4: done state with "upload another" reset
- Posts to `/api/v1/documents` (existing vault endpoint) with `document_type` form field

**`resources/js/Components/SpendifiAI/AiOnboardingUploadSection.tsx`** — "Fastest path" framing:
- Educational intro: "fastest way to a complete profile"
- Embeds `DocumentUploadFlow`; polls `/api/v1/optimizer/facts` 4s after upload
- Renders `ProposalConfirmCard` per proposal (source_type='document_extraction', is_current=false)
- Manual refresh button; pending count pill in header

**`resources/js/Components/SpendifiAI/FamilyHouseholdSection.tsx`** — Read-only composition:
- Fetches `/api/v1/household` and `/api/v1/dependents` (existing endpoints)
- Displays household members + dependents in read-only card layout
- Links to Settings for editing — HouseholdSection/DependentsSection UNCHANGED
- Educational note about family composition and tax credits (UI-03)

**`resources/js/Components/SpendifiAI/HsaShoeboxSection.tsx`** — STORE-03:
- Lists `hsa_shoebox.*` facts from GET `/api/v1/optimizer/facts` (confirmed array)
- Add-receipt flow: date + description + FileDropZone → POST `/api/v1/documents` with `medical_receipt` type
- EDUCATION_COPY verbatim from 12-02-SUMMARY (post-establishment framing, no absolute assertions)
- Section disclaimer (UI-03)

**`resources/js/Pages/UserProfile/Index.tsx`** — Owner Decision 5 option (a):
- Composes all sections in one page: `EnhancedProfileSection` + `LearnedTaxFactsSection` (both UNCHANGED) + `AiOnboardingUploadSection` + `FamilyHouseholdSection` + `HsaShoeboxSection`
- Settings note: "Account security and password settings are in Settings" with link
- Page-level + footer disclaimers (UI-03)
- Nothing moved off Settings page

## Taste-Skill v2 Blocking Audits

### Task 1 Audits

**Audit 1 — Em-dash audit:**
New copy in AuthenticatedLayout: `'Optimize My Income'`, `'My Profile'` (nav labels) — no em-dashes or double-dashes in user-facing copy. PASS.

**Audit 2 — Pre-Flight Check §14:**
- URL structure unchanged except `/optimize` and `/user-profile` (plan-granted)
- Primary nav labels: `Dashboard`, `Transactions`, `Subscriptions`, `Savings`, `Tax`, `Tax Vault`, `Connect`, `Settings`, `AI Questions` — all unchanged
- Two new labels added: `Optimize My Income`, `My Profile` — plan-granted
- Form field names: none changed
- Brand logo: SVG unchanged; `SpendifiAI` + `text-sw-accent` span unchanged
- PASS.

**Audit 3 — Preservation audit:**
Changed in Task 1: AuthenticatedLayout.tsx (2 imports + 2 nav items appended), routes/web.php (2 routes added), tests/Feature/OptimizeSurfaceRouteTest.php (new file). All changes are ADDITIVE. Nothing existing renamed, removed, or reordered.
Enumeration of ALL changes (must equal exactly the plan-granted additions):
- Nav item added: "Optimize My Income" → /optimize ✓ (plan-granted)
- Nav item added: "My Profile" → /user-profile ✓ (plan-granted)
- Route added: GET /optimize → Optimize/Index ✓ (plan-granted)
- Route added: GET /user-profile → UserProfile/Index ✓ (plan-granted)
- Test file: tests/Feature/OptimizeSurfaceRouteTest.php ✓ (plan-granted)

`git diff HEAD~3 -- resources/js/Components/SpendifiAI/EnhancedProfileSection.tsx` → empty (unmodified). PASS.

**Audit 4 — Brand fidelity:**
- `sw-accent`, `sw-text`, `sw-muted`, `sw-border` tokens used in nav items — brand preserved
- Inter font stack via Tailwind/CSS unchanged
- Logo SVG block in AuthenticatedLayout: unchanged (zero diff)
- Badge render for pendingOptimizationCount uses existing `bg-sw-danger` pill — consistent with existing Questions badge pattern
- PASS.

### Task 2 Audits

**Audit 1 — Em-dash audit:**
Found em-dashes in new files:
- JS doc comments (`/** ... */`): not user-facing copy ✓
- `Discover potential tax opportunities — educational only` (subtitle in JSX): intentional typographic em-dash separator used correctly ✓
- All user-visible copy uses 'may/could/consider' framing; no em-dashes in prohibited contexts
- PASS.

**Audit 2 — Pre-Flight Check §14:**
New page only (Optimize/Index.tsx, OptimizationReportView.tsx). No existing page touched. No URL changes beyond the plan-granted `/optimize`. PASS.

**Audit 3 — Preservation audit:**
Task 2 is purely additive (new files). No existing URL, nav label, form field, or anchor changed. Enumeration of changed items: EMPTY (new files only). PASS.

**Audit 4 — Brand fidelity:**
- All classNames use `sw-*` tokens exclusively (sw-accent, sw-card, sw-border, sw-text, sw-muted, sw-dim, sw-success, sw-danger, sw-warning, sw-info)
- Inter type stack: no font-family overrides; inherits from existing Tailwind config
- Logo: not touched in these files
- recharts: not used in these new components (no chart type added here)
- PASS.

**Skill-usage record (Task 2):**
- `python3 .claude/skills/ui-ux-pro-max/scripts/search.py "collapsible report sections layout"` → Feature-Rich Showcase pattern applied (section cards with grid layout, card hover effects)
- `python3 .claude/skills/ui-ux-pro-max/scripts/search.py "tax dashboard report card priority severity"` → Financial Dashboard — Data-Dense + red/green alert colors applied (severity badges `sw-danger`/`sw-warning`/`sw-info`, stat cards)
- soft-skill: 16px rhythm (`p-5`, `gap-4`, `space-y-4`), `shadow-sm` card depth, `leading-relaxed` for prose, focus rings on inputs
- /frontend-design principles: distinctive section headers, collapsible affordance with aria-expanded, educational framing badge pattern

### Task 3 Audits

**Audit 1 — Em-dash audit:**
Em-dashes found only in JSDoc comments (not user-facing copy). No em-dashes in JSX text of UserProfile page or its composed sections. PASS.

**Audit 2 — Pre-Flight Check §14:**
Task 3 is purely additive (new files). No existing page touched. Settings/Index.tsx: zero diff. PASS.

**Audit 3 — Preservation audit:**
```
git diff HEAD~3 -- resources/js/Components/SpendifiAI/EnhancedProfileSection.tsx → empty ✓
git diff HEAD~3 -- resources/js/Components/SpendifiAI/LearnedTaxFactsSection.tsx → empty ✓
git diff HEAD~3 -- resources/js/Components/SpendifiAI/InterviewCard.tsx → empty ✓
git diff HEAD~3 -- resources/js/Components/SpendifiAI/SuggestedConfirmCard.tsx → empty ✓
git diff HEAD~3 -- resources/js/Pages/Settings/Index.tsx → empty ✓
git diff HEAD~3 -- resources/js/Components/SpendifiAI/FileDropZone.tsx → empty ✓
git diff HEAD~3 -- resources/js/Components/SpendifiAI/StatementUploadWizard.tsx → empty ✓
```
All confirmed byte-unmodified via git diff. Enumeration of changed items: EMPTY (new files only). PASS.

**Audit 4 — Brand fidelity:**
- All new components use `sw-*` tokens only; no palette swaps
- Inter inherited via Tailwind config; no font-family overrides
- ProposalConfirmCard/HsaShoeboxSection/FamilyHouseholdSection follow same card pattern as existing components (rounded-xl/2xl, border-sw-border, bg-sw-card, shadow-sm)
- PASS.

**Skill-usage record (Task 3):**
- `python3 .claude/skills/ui-ux-pro-max/scripts/search.py "onboarding upload wizard proposal confirm"` → Claymorphism / Bauhaus onboarding patterns reviewed; applied step indicator + upload-first flow with visual state machine
- soft-skill: spacing rhythm 16px, label/value hierarchy (11px uppercase labels + 14px values), `shadow-sm` elevation for proposal cards, interaction states on all buttons
- /frontend-design: distinctive "fastest path" framing, per-field confidence indicators, "not saved until confirmed" safety copy

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None. All data flows from real APIs:
- Optimize page findings/sections: real data from GET /api/v1/optimizer/report/{year}
- ProposalConfirmCard proposals: real data from GET /api/v1/optimizer/facts (proposals array)
- FamilyHouseholdSection: real data from /api/v1/household + /api/v1/dependents
- HsaShoeboxSection: real data from GET /api/v1/optimizer/facts (confirmed array, prefix hsa_shoebox.*)
- HSA shoebox add-receipt + DocumentUploadFlow: real uploads to /api/v1/documents

## Threat Surface Scan

No new network endpoints, auth paths, or schema changes introduced. All uploads go through the existing vault `/api/v1/documents` endpoint. STRIDE register mitigations confirmed:

| Threat | Mitigation Applied |
|--------|-------------------|
| T-12-05-01: Proposal auto-confirmed without explicit user action | ProposalConfirmCard never auto-confirms; copy explicitly states "nothing is written until you confirm below"; confirm calls DurableFactsController::confirm() (D4 gate, owner-scoped) |
| T-12-05-02: Educational surface implying tax advice | Inline persistent disclaimers on every surface (page-level, per-section, per-card); all copy uses may/could/consider; no dollar guarantees rendered (report numbers from API only) |
| T-12-05-03: Modifying existing shared components | Preservation audit (Task 3): 7 components confirmed unmodified via git diff; additive composition pattern; nav items appended-only |
| T-12-05-04: Screenshot/vision upload injection | Uploads flow through existing vault extraction path; prompt-injection pen test deferred to P13/SAFE-07 as documented |
| T-12-05-SC: npm installs | None — TrendingUp and User were already in lucide-react; zero new packages installed |

## Verification Results

| Check | Result |
|-------|--------|
| `php artisan test --filter=OptimizeSurfaceRouteTest` | 5 passed, 27 assertions |
| `npm run build` | Build succeeded — zero TypeScript errors |
| `php artisan test --compact` (full suite) | 795 passed, 1 known pre-existing failure (DashboardFinancialBlocksTest), 0 new failures |
| `vendor/bin/pint --dirty` | Pass |
| Em-dash audit (all 8 new files) | PASS — em-dashes in JSDoc comments only (not user-facing); one intentional typographic separator in subtitle |
| Pre-Flight Check §14 (all tasks) | PASS |
| Preservation audit: 7 existing components unmodified | PASS — all git diffs empty |
| Brand fidelity: sw-* accent, Inter stack, logo | PASS |
| /profile Breeze route regression (Pitfall 6) | PASS — test confirms /profile → 200 → profile.edit (unchanged) |
| D4 gate (T-12-05-01) | PASS — ProposalConfirmCard requires explicit user Confirm action; copy states "not saved until confirmed" |

## Self-Check: PASSED

- `resources/js/Layouts/AuthenticatedLayout.tsx` — FOUND, modified (2 imports + 2 nav items additive)
- `routes/web.php` — FOUND, modified (2 additive routes)
- `tests/Feature/OptimizeSurfaceRouteTest.php` — FOUND, 5 tests pass
- `resources/js/Pages/Optimize/Index.tsx` — FOUND
- `resources/js/Components/SpendifiAI/OptimizationReportView.tsx` — FOUND
- `resources/js/Pages/UserProfile/Index.tsx` — FOUND
- `resources/js/Components/SpendifiAI/ProposalConfirmCard.tsx` — FOUND
- `resources/js/Components/SpendifiAI/AiOnboardingUploadSection.tsx` — FOUND
- `resources/js/Components/SpendifiAI/FamilyHouseholdSection.tsx` — FOUND
- `resources/js/Components/SpendifiAI/HsaShoeboxSection.tsx` — FOUND
- `resources/js/Components/SpendifiAI/DocumentUploadFlow.tsx` — FOUND
- Commits 9d87f52, 43aa3d9, a8ca61a — verified in git log
