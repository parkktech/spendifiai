# Owner Decisions — Enhanced Tax Profile Integration (2026-07-01)

Direct product decisions from the project owner for Phase 11/12 planning. These are LOCKED decisions, not suggestions. They came after the five source-doc distillations and must be folded into 11-CONTEXT.md.

## Context: what already exists

- **Enhanced Tax Profile** UI: `resources/js/Components/SpendifiAI/EnhancedProfileSection.tsx` on `resources/js/Pages/Settings/Index.tsx` ("Additional details help us find more tax deductions and credits for you").
  Current fields: student status; spouse name/employment/monthly income; tax-advantaged accounts (HSA, FSA, 529, IRA + single `ira_type`); additional deductions (student loans, childcare/dependent care, military, rental property, education credits AOTC/LLC).
- **Backend**: `UserFinancialProfile` model (`has_hsa`, `has_fsa`, `has_529_plan`, `has_ira`, `ira_type`, `spouse_income` encrypted, `has_rental_property`, `has_student_loans`, `education_credits_eligible`, …), validated by `UpdateFinancialProfileRequest` (`ira_type => nullable|in:traditional,roth,sep,simple`).
- **Phase 10 shipped**: `IncomeOptimizationProfile` snapshot + `IncomeOptimizerDataAssemblerService` already consume these fields; `answerableFields()` exposes them for interview skip-logic.

## Decision 1 — Enhanced Tax Profile is the ANCHOR for the ask-once durable-facts store

The ask-once profile graph (from the detection spec) and the durable-facts store (from the storage architecture) must EXTEND the existing Enhanced Tax Profile system — visible and editable by the user in Settings — not live as a disconnected parallel store. New tax facts learned via interview or document extraction surface in the user's settings where they can review/correct them. (Additive only: new columns/models + additive UI section; do not change existing fields' API shape.)

## Decision 2 — Multi-account retirement representation (schema gap)

Owner's own case: contributes to BOTH Roth AND Traditional IRA. Single `ira_type` cannot represent this.
- Support simultaneous account types (Roth IRA + Traditional IRA + employer 401k types), with per-type contribution amounts where known.
- Correctness reason: the IRA annual limit is SHARED across Roth+Traditional ($7,500 for 2026, +$1,100 catch-up) — TaxRulesEngineService headroom math needs combined-contribution awareness, not just a type label.
- Backwards-compat: keep `ira_type` untouched (existing API), add additive representation (e.g. retirement-accounts facts in the durable store or new nullable columns).

## Decision 3 — Profile-vs-reality conformance checks (Phase 11 detectors)

The system must CONFORM the profile to observed reality and surface mismatches educationally (never assert):
- Stated filing status vs paystub W-4/withholding evidence (owner's example: "not head-of-household when they're married-filing-jointly") — this extends FLAG-02 with paystub as the evidence plane.
- Stated IRA/HSA facts vs detected bank transfers and payroll deductions (e.g. profile says no HSA but paystub shows HSA deduction; profile says Roth-only but transactions show Traditional IRA contributions).
- Checkbox facts (rental property, childcare, student loans) vs transaction patterns (rent income deposits, daycare merchants, loan-servicer payments) — both directions: profile says X but no evidence, and evidence says X but profile unchecked.
- All mismatches → OptimizationFinding + educational question in the flow ("Your paystub appears to show X while your profile says Y — want to update your profile or tell us more?").

## Decision 4 — AI onboarding builds the profile from documents

Building/completing the Enhanced Tax Profile can happen by UPLOADING documents (pay stubs first) with AI extraction pre-populating profile fields:
- Upload paystub → vault two-pass extraction → proposed profile updates with per-field confidence → USER CONFIRMS before anything is written (never silently overwrite user-entered values).
- Provenance tracked per fact (source document, extraction date, tax year) per the storage-architecture design.
- This is the P12 doc-intake ↔ profile bridge; the interview (P11) treats confirmed-from-document facts as answered (skip-logic).
- Framed as "AI onboarding": a new user's fastest path to a complete profile is uploading a paystub, not filling checkboxes.

## Decision 5 — Rename/reshape "Settings" toward "User/Family Profile" (P12 design decision)

Owner suggestion (tentative "maybe"): rename Settings to **User/Family Profile**. Design consideration: the current Settings page mixes profile content (financial profile, Enhanced Tax Profile) with account/security functions (password, 2FA, Google connection, delete account) — a pure rename would mislabel the security half. Options for P12:
- (a) **Split (recommended)**: new "User/Family Profile" nav item (or a section of the Optimize My Income surface) hosting the financial + enhanced-tax + family/spouse/dependents profile with the AI-onboarding upload flow; "Settings" keeps account/security. Nav label changes are additive; route/file names unchanged (CLAUDE.md rule: never rename existing routes/files — display labels only).
- (b) Rename display label to "Profile & Settings" (single page, both halves honestly labeled).
- (c) Leave Settings as-is; the profile experience lives inside the Optimize My Income flow.
Final call happens at P12 planning; default to (a) unless owner overrides. The "Family" framing matters — spouse/dependents/household facts are first-class for filing-status math, credits (CTC/EITC/dependent care), and the multiple-support-agreement probes.

## Decision 6 — Frontend implementation tooling (P11 interview UI + P12 surface)

All frontend implementation for this milestone MUST use both installed design skills:
- **`/frontend-design:frontend-design`** (built-in skill) — for distinctive, production-grade component/page implementation, avoiding generic AI aesthetics.
- **`ui-ux-pro-max`** (project skill at `.claude/skills/ui-ux-pro-max/`) — searchable design database (50+ styles, 161 palettes, 57 font pairings, 99 UX guidelines, chart types). Executors query it via `python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<query>"` (verified working, Python 3.12). Use it for: the Optimize My Income page (findings list, interview flow, report), the User/Family Profile surface, and chart/table choices.
Constraint: recommendations from these tools must harmonize with the EXISTING `sw-*` design token system in `resources/css/app.css` and current recharts/Tailwind v4 stack — extend the app's look, don't restyle it. Plans for any frontend task must name these two skills in the task instructions.

Addendum (owner, 2026-07-01): also installed from the taste-skill collection (`.claude/skills/`): **taste-skill** (`design-taste-frontend` — anti-templated design direction), **redesign-skill** (`redesign-existing-projects` — premium upgrades to existing apps without breaking functionality; the best fit for adding pages to the existing sw-* system), **soft-skill** (`high-end-visual-design` — concrete standards for fonts/spacing/shadows/cards that block generic-AI-look defaults). Frontend executors should apply these alongside the two mandated skills — redesign-skill's audit-first approach governs any touch to existing pages.

**Owner design intent (verbatim nuance, 2026-07-01): ELEVATE, DON'T REPLACE.** "Not that I want to replace our design or UI, but edging it to be more high-end would be nice." Interpretation for executors: new v2.1 surfaces must raise the perceived-quality bar using soft-skill/high-end-visual-design standards (spacing rhythm, shadow quality, interaction states, typography discipline) while staying inside the sw-* token system. Small, non-breaking polish refinements to shared primitives are WELCOME where they lift the whole app; full restyles, palette swaps, or layout overhauls of existing pages are NOT. When in doubt: the existing design is the baseline to polish, never a canvas to repaint.

## Decision 7 — Frontend implementation procedure (taste-skill v2 workflow, owner-supplied)

Owner supplied taste-skill v2's recommended workflow. Every frontend plan/task in P11/P12 MUST follow this adapted procedure (autonomous form: the owner-OK stops become blocking self-audit gates; any Fail blocks task completion/commit):

**Standing brief (pre-filled for SpendifiAI):**
- Site: the live SpendifiAI app (resources/js/Pages + Components/SpendifiAI, Inertia 2 + React 19 + Tailwind v4)
- Mode: **PRESERVE BRAND** (locked by owner intent: elevate, don't replace)
- Audience: freelancers, small-business owners, and W-2 employees optimizing taxes/income + their tax accountants
- What works today (keep): `sw-*` token system + self-hosted Inter; StatCard/card/badge patterns; recharts visualizations; sidebar app shell
- What is broken today: nothing declared — the goal is premium polish ("edging it to be more high-end")
- SEO constraint: ALL existing routes, primary nav labels, form field names, headings/anchors on marketing pages (/, /features, /how-it-works, /about, /faq, /contact, legal pages) unchanged

**Procedure per frontend task:**
1. **Section 11.B audit (in writing, into the task log/SUMMARY):** brand tokens in use (primary/accent/type/radii), information architecture (page tree, nav, conversion paths), patterns to preserve (signature interactions, copy voice), patterns to retire (slop tells, broken layouts), inferred dial reading (DESIGN_VARIANCE, MOTION_INTENSITY, VISUAL_DENSITY), SEO baseline.
2. **Declare mode + levers:** mode is Preserve (locked); list the Section 11.D modernisation levers to apply in priority order.
3. **Implement.** URL structure, primary nav labels, form field names, brand logo, legal copy unchanged (exceptions only where a plan explicitly grants one, e.g. the additive "Optimize My Income" nav item).
4. **Blocking audits (in writing; any Fail blocks completion):** em-dash audit; Pre-Flight Check (Section 14); Preservation audit (list every URL/nav label/form field/anchor changed — must be empty except plan-granted additions); Brand fidelity audit (sw-* accent, type stack, logo treatment survived).

## Non-negotiables that still apply

Educational-only framing on every mismatch surface; additive migrations only; no changes to existing `UserFinancialProfile` API responses or `EnhancedProfileSection` behavior (extend, don't alter); encrypted TEXT + `$hidden` for sensitive new fields; all dollar math in TaxRulesEngineService from config.
