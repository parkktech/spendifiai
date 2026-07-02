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

## Decision 5 — SUPERSEDED 2026-07-02 by owner review: ONE merged page, pinned to nav bottom

After reviewing the built P12 UI, the owner ruled: Settings and My Profile are essentially the same page — MERGE into one. Final shape:
- ONE page (the existing /settings route + Settings/Index.tsx as the canonical surface) hosting: financial profile, Enhanced Tax Profile, learned tax facts, family/household, AI-onboarding upload flow, HSA shoebox, AND the account/security sections. The /user-profile route stays alive but redirects to /settings (never break a shipped URL).
- Nav: REMOVE the separate "My Profile" item; the single entry is labeled "Profile & Settings" and is PINNED TO THE BOTTOM of the left nav (visually separated from the main nav group, mt-auto style).
- The P12 components (FamilyHouseholdSection, AiOnboardingUploadSection, HsaShoeboxSection, ProposalConfirmCard, DocumentUploadFlow) are REUSED as-is — this is recomposition, not rebuild.
- Owner-granted preservation-audit exceptions for this change: Settings page composition, the nav reorganization (bottom pin), the label rename, the /user-profile redirect.

(Original option (a)/(b)/(c) analysis retained below for history.)

## Decision 5 (original, superseded) — Rename/reshape "Settings" toward "User/Family Profile" (P12 design decision)

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

## Decision 8 — Admin nav: separate, distinct, bottom-pinned, collapsed (owner, 2026-07-02)

When the logged-in user is a site admin (`isAdmin` shared prop), all admin menu entries must be:
- **Separate and distinct from the normal user nav — NEVER mixed into the user menu options.** Remove any admin items currently inline in the main nav group.
- **Pinned to the bottom** of the left sidebar as their own group (bottom stack order: Admin group, then "Profile & Settings" at the very bottom — both visually separated from the main nav).
- **Collapsed by default** — an expandable group header ("Admin") the owner can expand to manage admin functionality when needed; expansion state may persist in localStorage like the existing dark-mode toggle pattern.
- **A different color** — visually distinct accent from the user nav (use an sw-* consistent distinct tone, e.g. warning/amber-toned or the sw-info violet family — pick via the design skills, ELEVATE-DON'T-REPLACE still applies; non-admins see nothing).
- Collect ALL existing admin routes/pages into this group (cancellation-provider admin, Super Admin storage config, and any other admin surfaces found in the codebase).
- Owner-granted preservation-audit exceptions: relocating existing admin nav entries into the new group, the group's distinct styling.

## Decision 9 — Actionable checklists, not "worth reviewing" (owner, 2026-07-02)

Owner (live review): "The plan just says worth reviewing. I want clear distinct instructions for the user to do. Deliver this as a checklist where needed. User Actions needed: Contact employer and change payroll form from head of household to married filing jointly; Contact employer and adjust your 401k to X% traditional / X% Roth; update your dependents from 0 to 3; set up direct deposit to savings of $500 every 2 weeks. A clear actionable set of instructions."

**Design (preserves the educational-only boundary while delivering directives):**
1. Every finding/report item carries an **Action Checklist**: numbered, imperative, concrete steps ("Contact your payroll department and...", "Log into your 401(k) portal and...", "Set up an automatic transfer of $X every pay period..."). Checkbox UI; per-item done-state persisted (reuse the response/ledger pattern; store in the durable-facts/action store with provenance).
2. **Fact-gated directives**: steps that depend on a user fact render as directives ONLY after the user confirmed that fact (interview answer / confirmed profile / confirmed doc extraction). The framing anchors to THEIR stated facts: "...to match the filing status you confirmed (Married Filing Jointly)". Until confirmed, the checklist step IS the confirmation ask ("Confirm with your plan administrator whether X — then the next step unlocks").
3. **All numbers from the engine** (dollar amounts, percentages, per-pay-period math) — TaxRulesEngineService computes; narration never invents figures (SAFE-03 unchanged).
4. Filing-status/allocation items NEVER assert what the user SHOULD elect in the abstract — they operationalize what the user confirmed, or route the choice into a confirm-first step. One compact professional-review line remains only where genuinely specialist-band; not boilerplate on every card.
5. Checklist copy tone: imperative, short, one action per step, employer/portal/form named where derivable (W-4, plan portal, payroll dept, bank auto-transfer).
6. Rollout: content templates per detector/finding type sourced from the distillation "what to do" material; applies to findings cards, the interview wrap-up ("Your action list"), and a report "User Actions Needed" section that aggregates all unlocked steps as THE primary deliverable.
7. **BENEFIT SUMMARY per action (owner addition, 2026-07-02):** every checklist item carries a one-line, quantified benefit — "Updating your paycheck withholding and dependents increases your take-home by ~$X/paycheck ($Y/yr)"; "This automatic transfer saves you $13,000/yr"; "These 401(k) changes add ~$X toward retirement by age Y". Rules:
   - Deterministic arithmetic (withholding deltas from brackets/dependents, annualized transfers, match capture) → exact engine-computed figures via NEW TaxRulesEngineService benefit methods (config-driven; narrator words them, never computes).
   - Long-horizon projections (retirement timing/growth) → clearly-labeled ILLUSTRATIONS with stated assumptions from config (e.g. illustrative growth rate), range framing ("could mean roughly $X–$Y more by 65" / "could bring retirement ~N years closer under these assumptions") — NEVER guarantee language (locked out-of-scope: guaranteed dollar savings).
   - Checklist header aggregates: "Completing these N actions ≈ +$X/mo take-home · +$Y/yr saved · ~$Z more toward retirement (illustration)".

Sequencing: runs AFTER the in-flight review-fixes executor (same files). Push on review-fixes green proceeds as ordered; checklist lands as follow-up commits to the same PR.

## Decision 10 — Optimization Scenarios engine (owner, 2026-07-02)

Owner: plan and think through (1) how we GET the info from the user to understand their financial picture; (2) where we can optimize for additional take-home income; (3) where we can optimize to lower tax burden; (4) where we can optimize for retirement. When objectives CONFLICT, suggest MULTIPLE APPROACHES: "Option A — Optimize for income. Option B — Optimize for retirement." Each option shows the concrete choices to make given the user's current financial picture.

**Design directives:**
1. **Objective-driven data acquisition**: for each of the three objectives, a defined fact-requirements map (what we need: W-4 status/dependents, pay frequency, gross, current withholding, pre-tax elections, employer match formula, balances, age, target age...). Sources in priority order: already-known (profile/facts/paystub extraction/bank data) → then interview asks ONLY the gaps. Show per-objective readiness ("Take-home optimization ready · answer 2 more questions to unlock Retirement optimization").
2. **Deterministic scenario computation**: for each objective, the knob settings (W-4 alignment, trad/Roth split, HSA/FSA elections, match capture, auto-transfers) that favor that objective, with computed outcomes for ALL THREE metrics per scenario (take-home Δ, tax Δ, retirement Δ [illustration rules per Decision 9.7]) — side-by-side comparison. All math = TaxRulesEngineService extensions from config; Claude words it only.
3. **Conflict surfacing**: knobs where options diverge get explicitly contrasted ("Option A: 0% Roth — Option B: 10% Roth") with the trade-off in one line ("A gives +$120/mo now; B adds ~$40k by 65 [illustration]").
4. **Choice → checklist**: user picks an option (or mixes per-knob); the chosen option's steps become their Decision-9 action checklist with benefit lines. Persist the chosen scenario (facts store).
5. Scenario count: A (income now), B (retirement), and a Balanced default when conflicts exist; single merged plan when objectives agree.
6. Liability: scenarios are presented as "approaches to consider" grounded in user-confirmed facts + engine math; election choices remain the user's (pick-an-option IS the confirmation); illustration rules for long-horizon numbers; educational framing intact.

Sequencing: SUBSUMES the Decision-9 checklist implementation — design first (spec), then implement checklist+benefits+scenarios as one coherent unit AFTER the review-fixes push. Design spec: .planning/reference/SCENARIOS-SPEC.md.

## Decision 12 — Design posture: LUXURY LEAN (owner, 2026-07-02)

Owner: "Let's lean in a little more on the design skill. We can be making better more luxy design choices. The site is a bit bland still." This AMENDS the conservative reading of "elevate don't replace":
- The dial moves from "polish within the existing look" to **"make the look premium."** The site should feel expensive: refined depth (layered shadow system), typographic drama (scale contrast, tighter tracking on display sizes), intentional spacing rhythm (more generous section breathing), micro-interactions/motion vocabulary (hover states, transitions, staggered reveals — performance-conscious), richer card treatments (subtle gradients/borders/glass where tasteful), premium empty-states and loading states.
- **Extending the sw-* token system is ALLOWED and expected** (new tokens: elevation scale, motion durations/easings, display type sizes, gradient stops) — additive tokens, applied app-wide for coherence.
- **Still preserved**: brand logo, Inter as the type family (weight/scale usage may get bolder), the sw-accent blue family as primary recognition, all URLs/APIs/functionality, accessibility (contrast, reduced-motion respect), light+dark modes.
- **Method (binding)**: taste-skill v2 full procedure — Section 11.B audit → mode "Preserve brand, elevate premium" → Section 11.D modernisation levers in priority order; soft-skill (high-end-visual-design) standards as the concrete bar; ui-ux-pro-max queried for fintech-premium styles/palettes/typography; redesign-skill audit-first discipline. Blocking audits continue (preservation audit = URLs/nav/forms/anchors; brand-fidelity audit amended to the preserved list above).
- **Rollout**: (a) DESIGN-ELEVATION-SPEC.md defines the lever set + token extensions first; (b) all NEW UI (scenarios/checklists) is born to that spec; (c) a dedicated elevation pass upgrades the core existing surfaces (app shell/nav, Dashboard, cards, Optimize flow) — staged, verified with npm build + audits per batch.

## Decision 13 — Report staleness policy + the 2-4 week action-lag model (owner, 2026-07-02)

Owner: "It can't be stale if it's run in the last month. Or unless a user made changes and confirmed they made changes and the system starts seeing the changes in their income, saving etc." + "From the time a user runs this, makes changes, and the changes start showing in their transactions, it takes a min of 2 weeks to a month."

**Staleness policy (replaces flag-on-every-event):**
1. **Freshness window: 30 days** (config `optimization-report.freshness_days`). Within it, routine data-churn events (bank syncs, categorization progress, profile rebuilds from scheduled jobs) do NOT stale the report.
2. **Immediate-stale exceptions (user acted — they expect it reflected):** optimization interview answers, profile edits, fact confirm/supersede, document uploads by the user, scenario choice, checklist item state changes.
3. **Material-change exception (the system "starts seeing changes"):** at generation the report stores `built_against` aggregates (income, savings rate, key metric fingerprints). Churn events within the window compare current profile aggregates vs `built_against` using config thresholds (e.g. income ±5%, savings ±10%, new high-severity finding keys) — material shift → stale even inside the window.
4. **Rationale (owner's lag model):** user actions take 2-4 WEEKS to appear in transactions/paystubs. Early regeneration = burn without signal. The material-change detector is precisely tuned to catch when the actions LAND.
5. **Connected loop (implement with Decision 9/10 unit):** BENEFIT VERIFICATION — when checklist items are marked done, watch the 2-4 week window for the projected change to materialize (new recurring transfer detected, take-home delta on next paystub/deposit pattern) and surface verified outcomes ("Your new $500 transfers are live — take-home rose ~$148/paycheck vs the ~$155 projected"). Reuses the existing SavingsLedger claimed→verified pattern. This is the retention loop: act → see it verified → trust → act again.

## Decision 14 — Proactive change monitor + document-refresh prompts (owner, 2026-07-02)

Owner: "We probably need to wire in a change monitor. To say we noticed your income went up from your employer — want to send us an updated check stub or screenshot of your check to make sure it's optimized."

**Design (thin orchestration over existing plumbing — nearly everything exists):**
1. **Detection sources (all shipped or in-flight):** D13's material-change comparison (built_against vs current aggregates — the D13 executor is building it now), life-event triggers from 11-07 (payroll-stop, new-mortgage, marketplace-premiums, escrow), IncomeDetectorService deposit-pattern shifts, CrossSourceReviewService discrepancies.
2. **On detection → user-facing engagement:** create an OptimizationFinding (`finding_type=change_detected`, educational copy naming WHAT we noticed: "Your deposits from [employer] increased ~X% starting [month]") + an AIQuestion in the feed (badge count already wired) + a document request ("Upload an updated pay stub or a screenshot of your check so we can re-optimize with accurate numbers") that routes into the EXISTING in-flow upload (DOC-05) → extraction → confirm-gated proposals (D4) → re-optimization via the D13 user-action path.
3. **Copy pattern (educational, specific, benefit-forward):** "We noticed [specific change]. Send an updated [doc] and we'll check whether your [withholding/401k/transfers] are still optimized — a raise often unlocks [X]." Never alarming, never assertive.
4. **Cadence guard:** one change-prompt per detected change per freshness window (no nagging); dedupe against open requests; respects the 2-4 week lag model (a change must persist ≥2 pay cycles before prompting — filters one-off deposits/bonuses, config threshold).
5. **Symmetry with the benefit-verification loop (D13.5):** same watcher infrastructure — one watches for EXPECTED changes (verify benefits), this watches for UNEXPECTED changes (prompt re-optimization). Build them as one ChangeMonitor service when the scenarios unit lands.

Sequencing: fast-follow executor AFTER the D13 staleness executor lands (it consumes D13's comparison outputs); the watcher/verification halves unify in the scenarios+checklist implementation unit.

## Decision 15 — Bonus optimization: pre-bonus election alerts (owner, 2026-07-02)

Owner: "An alert prior to your bonus to flag it… to not send a portion to your 401k, or send more to your 401k, send none to your 401k to further optimize your bonus. I know last year a decent percentage of my bonus went into my 401k and I would have rather it not."

**Product shape:**
1. **Bonus prediction (the alert must land BEFORE payroll runs):** sources — prior-year transaction pattern (bonus deposit detected same period last year via IncomeDetectorService/history), user facts (interview: "annual bonus? which month?"), offer-letter extraction (bonus terms — doc type exists). Alert fires with LEAD TIME (config, ~3-4 weeks before expected date — users need time to change plan elections before the payroll cutoff).
2. **Bonus scenario set (a D10 scenario domain):** Option A — max cash now (set bonus 401k deferral to 0%); Option B — max deferral (bracket management / catch up on the annual limit, esp. if behind on match/headroom); Option C — standing election. Computed outcomes per option: bonus take-home, tax withheld, 401k limit headroom effect, match interaction. All engine math; ACA-cliff guard applies (a big bonus + deferral choice can cross the cliff — the sequencing rule extends here).
3. **Withholding education (owner's "flag it as tax except"):** bonuses are supplemental wages — NOT tax-exempt, but the WITHHOLDING method matters (flat 22% supplemental rate vs aggregate method; over/under-withholding vs actual bracket). Scenario copy educates on what the user's expected withholding does vs their real bracket and what to review with payroll. Educational framing only.
4. **The checklist output:** "Before [date]: log into your 401(k) portal / contact HR and set your BONUS deferral election to X% [matching the option you chose]" + benefit line ("keeps ~$X of your bonus in cash now" / "adds ~$X toward your limit + saves ~$Y in current-year tax").
5. **Plan-capability fact:** whether the user's plan allows a separate bonus election = an ask-once fact (benefits guide/SPD extraction or interview); if not allowed, the scenario degrades honestly ("your plan applies your standing 6% — to change it for the bonus you'd temporarily adjust your election, then revert — two checklist steps with dates").
6. **Infrastructure:** extends ChangeMonitor (D14) with PREDICTIVE/CALENDAR watchers (expected-event schedule per user), not just change detection. First slice of the parked Year-End/Q4 calendar-engine concept.

Sequencing: add as a scenario domain in SCENARIOS-SPEC (post-design addendum if the in-flight spec lacks it); implement with or immediately after the scenarios unit; predictive watcher rides the D14 ChangeMonitor build.

## Decision 16 — The prominent To-Do list ("Action Center") + year-end liability strategy items (owner, 2026-07-02)

Owner: bonus alerts should pop up in a TODO LIST along with tax review strategy — "you are going to have a large tax liability unless you spend X on charity, new race car, new work truck, computer, tractor, salon products etc for their personal/business. Make this Todo list check marks prominent and the user checks them when done."

**1. Unified Action Center (the checklist UI's final form — supersedes per-report-only placement):**
- ONE persistent, prominent to-do surface aggregating EVERY actionable item: scenario checklist steps (D9/D10), bonus election alerts (D15), change-monitor doc requests (D14), year-end timing items, document requests. Big checkmarks; user checks items done; state persists in the action/facts store with timestamps.
- Placement: top-of-Dashboard widget + the Optimize page; nav badge = open item count (badge infra shipped). Items carry benefit lines (D9.7) and due-dates where real (bonus cutoffs, Dec 31).
- Checking an item feeds the benefit-verification watcher (D13.5): claimed → watch the 2-4 week window → verified ("your take-home rose ~$148 ✓").

**2. Year-end liability strategy items (promotes the playbook §8B year-end engine slice into the scenarios domain):**
- Liability benchmark projection uses FLAG-18-compliant framing (prior-year-liability arithmetic + safe-harbor benchmark; detected income as surfacing trigger only — never "your liability WILL be $X").
- Purchase-timing items are GATED on confirmed business/personal context and keyed to THEIR confirmed business type: §179/bonus-depreciation equipment for the categories they actually operate (work truck >6,000 lbs GVWR [config constant exists], computer, tractor for a confirmed farm, salon products for a confirmed salon, motorsport equipment ONLY for a confirmed motorsport business per the gray-area gating), charitable bunching education.
- **HONESTY GUARDRAIL (binding copy rule):** never "spend to save" naked framing. Every purchase-timing item carries the net-cost truth: "Buying reduces taxes only if you needed it anyway — a $10,000 purchase in the 24% bracket saves ~$2,400 in tax and costs ~$7,600 net cash. If it was already planned for your business, completing it before Dec 31 may let it count this year." Educational, timing-focused, professional-review routing on big-ticket items.

**3. Lifecycle-adaptive list (owner addendum, 2026-07-02):** the Action Center drives the ENTIRE user journey, adapting to state through the year:
- **Stage 0 — first-run onboarding items (a new user's very first to-dos):** ① Link your bank account ② Link your credit cards ③ Link your emails ④ Do the onboarding interview (+ ⑤ Upload a pay stub — the AI-onboarding fast path). Generated from connection state (hasBankConnected, account types incl. credit cards, email connections, interview/profile completeness) — each disappears as completed, replaced by what it unlocks.
- **Adaptive generation (deterministic, rule-driven):** items derive from live state — readiness scores per objective (D10: "answer 2 questions to unlock Retirement"), detected changes (D14), calendar events (D15 bonus windows, D16 year-end season), verification watches (D13.5), doc requests. The list a user sees in February ≠ August ≠ December.
- The empty-list state is an achievement moment ("You're fully optimized for now — we're watching for changes"), not a dead end.

Sequencing: the Action Center IS the primary UI deliverable of the scenarios+checklist implementation unit (D9/D10/D13.5/D14/D15/D16 all converge there). Year-end items = a scenario/content domain within it. The Stage-0 onboarding items make it the app's default landing surface for incomplete accounts.

## Decision 17 — AI cost discipline (owner, 2026-07-02, BINDING on Phase 14 and all future work)

Owner: "Make sure this is optimized to use the less expensive AI model and AI calls are as few as possible — for users and especially stale users — so this website isn't racking up AI bills when I'm not actually making money."

**1. Template-first, Claude-last (the biggest lever):** deterministic template copy for every STANDARD output — gap questions (already decided SCN-03: no Claude), standard probe wording (the distillations contain verbatim question trees — use them), standard finding narrations (finding type + engine numbers → templated sentence; Claude ONLY for genuinely bespoke narrative). Target: the common path costs ZERO AI calls.
**2. Model tiering per call site (config-driven):** per-service model keys (`services.anthropic.model_<purpose>` with global default) — narration/question wording/short structured tasks → `claude-haiku-4-5` (cheapest capable); document extraction stays `claude-sonnet-4-6` (accuracy-critical); transaction categorization (the volume king) → evaluate Haiku behind the EXISTING confidence-threshold safety net (low confidence already routes to review/questions — degradation is graceful by design). Tunable without code changes.
**3. Activity gating everywhere:** extend the existing 28-day activity gate (commit 4e75c46 precedent) to ALL AI-triggering paths — categorization, narration, detectors, report regen: NO AI calls for users inactive ≥ threshold; lazy re-warm on their next login/visit. Stale users cost $0.
**4. No mass backfills ever automatic:** owner-triggered only (the 2,461-txn backfill precedent).
**5. Spend observability + budget caps:** per-service daily Claude-call counters (cache/DB, surfaced in the Admin drawer); config daily budget per service — jobs skip gracefully + log when over budget. The owner can SEE bill drivers.
**6. Already-shipped levers that stay:** D13 freshness window + material-change gate + regen cooldown (report narration ≤5 calls/regen); batching (categorization 10/batch); ShouldBeUnique coalescing; no AI on page loads (jobs only).

## Decision 18 — The question-quality bar (owner, 2026-07-02, BINDING on all user-facing questions)

Owner (live testing, verbatim frustration): "This is another dumb question. It's in my account — of course it belongs to me. We need questions that are not this dumb. Is there something specific AI needs to know about this? And are we going to go through every transaction with these dumb questions? This system needs to add value and not waste people's time."

**THE BAR — every interview/feed question MUST:**
1. **Show the data, ask for the unknown.** Lead with the concrete facts we already have (merchant name, amount, cadence: "You pay $9.99/mo for Microsoft 365") and ask ONLY the fact the system genuinely cannot infer (use/purpose/intent: "Do you use it primarily for your business?"). Never ask whether something in their own account "applies to them."
2. **Never leak internals.** Raw finding keys, fact keys, enum values, underscores — banned from user copy. Human labels only (merchant names via DetectionMerchant/MerchantAlias, plain-English categories). Add an automated test: no `[a-z]+_[a-z_]+` internal-key patterns render in question text.
3. **Aggregate patterns — never per-item interrogation.** One question per PATTERN with multi-select where items share the same fact-need: "Which of these subscriptions are primarily for business? ☐ Microsoft 365 $9.99/mo ☐ Adobe $54.99/mo ☐ GitHub $4/mo ☐ None of these." One answer fans out to all selected items' facts.
4. **Value-density rule:** if the answer would not change a finding, scenario, or checklist item, DO NOT ASK. Findings without a real, data-grounded question template are EXCLUDED from the interview queue (they remain visible in the findings list as suggested-confirm) — a contentless fallback question is worse than no question.
5. **Volume respect:** the initial-cap stays; per-pattern aggregation keeps total questions low; the interview should feel like a sharp advisor's intake, not a survey.

Applies to: interview templates (14-01 config), orchestrator fallback (14-05 — the generic findingFallbackQuestion path that produced the offending copy is DEPRECATED by this decision), monitor prompts (14-09), checklist copy (14-08/10).

**D18 ADDENDUM (owner, 2026-07-02):** (6) SUCCINCTNESS BAR — questions less wordy, more succinct; the user gets REAL CLARITY on their options and exactly what's being asked (data lead ≤1 sentence, question ≤1 sentence, plain-labeled choices, education collapsed). (7) THE ESCAPE HATCH — every choice question ends with "Something else? Let's talk about it" opening a free-text box that routes to the EXISTING AI chat/interpret path (this is the CORRECT use of a Claude call per D17: genuinely bespoke input; still haiku-tiered via model_wording, activity-gated, counted). The answer interpretation writes facts through the same recordFact path. Owner note: "I'm not sure the best most simplistic approach but I think we are closing in on it" — the design language is converging; keep refining against live feedback.

## Decision 19 — Structured AI output contract (owner, 2026-07-02): "the formatting might be due to how you are prompting AI and how it's told to respond — let's really make this cleaner"

**The rule: NO Claude call site may request or return free prose paragraphs.** Every AI output is a STRUCTURED object with per-field length caps, validated on receipt; the UI composes the layout from fields. Prose blobs are a schema violation, not a style problem.

Contracts per call site:
1. **Finding narration (NarrationService):** `{hook: ≤120 chars — the one-line what-and-why, detail: ≤2 short sentences, action_cue: ≤1 sentence}` — findings cards render hook prominently, detail+action_cue in the expand. (Replaces the display-side first-sentence clamp with born-structured content.)
2. **Report section narratives (OptimizationReportNarratorService):** `{summary: ≤2 sentences, bullets: ≤5 × ≤15 words}` — report sections render summary + bullet list, never paragraphs. Blade/PDF + React renderers updated to the fields.
3. **Escape-hatch interpretation (D18.7):** already structured (fact extraction) — keep.
4. **Any future call site:** structured contract + caps + response validation (retry once with a "shorter" instruction on violation; on second violation fall back to template/omit — never render an oversized blob).
5. Prompts state the contract explicitly (JSON schema in the request, low max_tokens per D17 economy); banned-phrase + length checks run on the parsed FIELDS.
Existing stored prose (current finding descriptions/report sections) re-renders under the display clamp until naturally regenerated — no mass re-narration (D17).

Sequencing: dedicated executor immediately after the D18 agent lands (adjacent files, avoid collision). Binding on 14-08/09/10 renderers.

## Decision 20 — Interview intelligence: eligibility gating, conversational hatch, document-sourced answers (owner, 2026-07-02)

Owner live-test: was asked "Did you enroll in Medicare this year?" (he's decades under 65 — "I thought Medicare was for old people"); typed "I don't think so, what's Medicare?" into the escape hatch and the system silently advanced; noted "I have employer insurance the system should know about — or ask for my employee benefits to get all the details if I don't know."

**1. ELIGIBILITY PREDICATES on every question (extends D18.4 value-density):** each template carries a `when` predicate over KNOWN facts, evaluated before queueing — Medicare-enrollment gated to age ≥64 (or disability signals), catch-up probes ≥50, senior deduction ≥65, dependents questions gated on family facts, etc. The age fact (person.birth_year / estimated_age) already exists — USE it. A question whose predicate can't be evaluated may ask; a question whose predicate is FALSE never appears.
**2. CONVERSATIONAL ESCAPE HATCH (extends D18.7):** when the user's free text contains a QUESTION, answer it briefly (1-2 educational sentences, haiku via model_wording, D17-counted), then STATE the interpreted answer for one-tap confirmation ("Got it — recording: not enrolled. ✓ Correct / ✗ Not quite") before advancing. NEVER silently interpret-and-advance past a user's question.
**3. "GET IT FROM MY DOCUMENTS" answer choice:** fact-questions whose authoritative source is a document (insurance coverage, benefits elections, plan features, match formula) include the choice "I'm not sure — get this from my benefits guide/paystub" → files the document request (existing DOC-05 in-flow upload + D4 confirm pipeline) → extraction materializes the answer as a confirmed fact; the question resolves as doc-pending, not re-asked. Employer-insurance specifics: infer coverage signals from paystub deduction lines where present before ever asking.

Sequencing: D19+D20 ship together as one "interview intelligence + structured output" executor immediately after the D18 agent lands (same seam). Binding on 14-09 monitor prompts + 14-10 UI.

## Decision 21 (RAW — Fable restructuring commissioned) — Documents-first funnel, big-rocks-first ordering (owner, 2026-07-02)

Owner sentiment (near-verbatim): "One thing we might be missing is the collection of documents. If AI has all the documents from my employer, some of these questions will be unnecessary. We're getting off track a bit. The other day I was trying to optimize my income — my first instinct was to upload my paycheck to AI. It immediately found issues, like I was filing wrong. From there it can see where my money is going — insurance, state tax, 401k etc. We can then follow up for THOSE documents to see if changes should be made. Only after that should we dig deeper. … If we have uploaded these details they should be stored so the user isn't asked again — and it updates and confirms their profile info: checking against address, name, married, kids, 401k etc against their profile. Discrepancies are what we ask about, and update the profile. If they're married, ask if they want to share this dashboard with their spouse. Right now we're jumping into questions that make up tiny optimizations compared to the big picture: income, deductions, insurance and 401k optimizations."

Core principles to formalize: (1) DOCUMENTS-FIRST: the paystub upload is the front door; findings flow from documents before any interview; (2) BIG-ROCKS-FIRST ordering: filing status/withholding/401k/insurance before transaction-pattern micro-probes — questions ranked by impact; (3) DOC-CASCADE: each document reveals which documents to request next (paystub → benefits guide, insurance, 401k statement); (4) RECONCILIATION LOOP: every extraction cross-checks profile (name, address, marital, dependents, elections) — discrepancies become the questions, answers update the profile (extends FLAG-28/D3/D4); (5) STORED FOREVER: never re-ask what a stored document answered; (6) SPOUSE: married → offer household dashboard sharing (isInSameHousehold policy pattern exists).

Restructured spec: .planning/reference/DOCUMENTS-FIRST-FUNNEL.md (Fable-commissioned).

**D21 addendum — mixed-account activity question (owner, 2026-07-02):** review spending habits for business-activity-in-personal-account signals (the shipped FLAG-14 commingling detector is the evidence source). ONLY when transactions lead that direction, ask per-account (D18 classification shape, evidence lead): "We see mixed activity in [Account Name] — [N business-looking transactions like X, Y this year]. Which best describes this account?" ○ I run a business from this account ○ Shared business/personal account ○ Personal account — no business activity (+ escape hatch). THE ANSWER WRITES BankAccount.purpose via the EXISTING updatePurpose cascade (business/mixed/personal — updates all account transactions + dashboard cache + becomes the strongest AI-categorization signal) plus a confirmed fact. Never asked without evidence; never re-asked once answered (purpose set = fact known). Routes into the funnel's reconciliation stage (C) ahead of micro-probes.

## Non-negotiables that still apply

Educational-only framing on every mismatch surface; additive migrations only; no changes to existing `UserFinancialProfile` API responses or `EnhancedProfileSection` behavior (extend, don't alter); encrypted TEXT + `$hidden` for sensitive new fields; all dollar math in TaxRulesEngineService from config.
