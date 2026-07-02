# DOCUMENTS-FIRST-FUNNEL — The Governing Flow Design (Decision 21, restructured)

> Commissioned restructuring of the owner's Decision-21 sentiment (2026-07-02) into the governing
> product flow for Optimize My Income. This document is the DESIGN OF RECORD for how a user moves
> through the system. It supersedes flow *ordering* assumptions in earlier specs; it changes no
> shipped API, no shipped schema semantics, and no liability/cost decision (D17–D20 all bind here).
>
> Status: DESIGN — feeds surgical deltas into pending plans 14-08/14-09/14-10 and the queued
> D19/D20 executor (§5). Companion specs: SCENARIOS-SPEC.md (engine/readiness),
> enhanced-profile-integration-notes.md (Decisions 1–21), INTEGRATION-MAP.md (FLAG-28, DOC-05).

---

## 0. The owner's sentiment, formalized

The owner's own behavior IS the spec: *"My first instinct was to upload my paycheck to AI. It
immediately found issues, like I was filing wrong. From there it can see where my money is going —
insurance, state tax, 401k etc. We can then follow up for THOSE documents. Only after that should
we dig deeper."*

Six governing principles (each traced to existing decisions):

| # | Principle | One-line rule | Anchors |
|---|-----------|---------------|---------|
| P1 | **Documents first** | The paystub upload is the front door. Findings flow from documents BEFORE any interview. | D4 (AI onboarding), DOC-05 |
| P2 | **Big rocks first** | Filing status / withholding / 401(k) / insurance questions ALWAYS precede transaction micro-probes. Questions are ranked by dollar impact. | D18.4 (value density), §2 below |
| P3 | **Doc-cascade** | Each document reveals which documents to request next (paystub → benefits guide, insurance, retirement statement, spouse paystub). | DOC-05, D20.3 |
| P4 | **Reconciliation loop** | Every extraction cross-checks the profile (name, address, marital, dependents, elections). Discrepancies BECOME the questions; answers update the profile. | FLAG-28, D3, D4 |
| P5 | **Stored forever** | A stored document's answer is never re-asked. Provenance-tracked facts + `isAlreadyAnswered()` skip-logic enforce this mechanically. | D1, STORE-*, A.7 |
| P6 | **Household** | Married (detected → confirmed) → offer dashboard sharing with the spouse. | D21, shipped Household models |

The funnel below is the composition of these six principles over machinery that is ~80% already
built.

---

## 1. THE FUNNEL

```
 Stage A            Stage B              Stage C               Stage D            Stage E
 FRONT DOOR   →     DOC-CASCADE    →     RECONCILIATION   →    DEEP INTERVIEW  →  SCENARIOS +
 upload paystub     "your paystub        profile vs reality;   impact-ranked;     CHECKLIST +
 → instant          shows money going    discrepancies are     only what docs     ACTION CENTER
 findings           to X — send us       the ONLY questions    cannot answer      (built/pending
                    the X document"      at this stage                            14-08/09/10)
```

Stages are NOT modal gates — a user can jump to the interview at any time (never trap anyone
without documents; every blocking fact keeps its interview template per §A.2's rule "documents are
accelerators, never the only path"). The funnel governs **defaults, prominence, and ordering**:
what the primary CTA is, what the Action Center surfaces first, and what order questions arrive in.

### Stage A — The front door: upload a paystub, get instant findings

**The moment of magic to engineer:** upload → ~60 seconds → "Your paystub shows your W-4 filing
status doesn't match your profile" + "here's where your money is going." That is the owner's
first-session experience, made the default for every user.

**Machinery (BUILT — this stage is composition, not construction):**

| Step | Shipped component |
|---|---|
| Upload UI | `DocumentUploadFlow.tsx` (4-step wizard, `compact` prop) — posts to `POST /api/v1/documents` |
| Pipeline | `TaxDocument` status flow `Upload → Classifying → Extracting → Ready` (category auto-classified; `pay_stub` in `TaxDocumentCategory`) |
| Extraction | `TaxDocumentExtractorService::PAY_STUB_FIELDS` (21 fields: `gross_pay`, `federal_tax_withheld`, `ytd_gross`, `ytd_federal_tax`, `pay_period_start/end`, `pay_date`, pretax deduction lines) — the ONE sanctioned Sonnet call per document (D17) |
| Facts | `PaystubFactExtractorService::proposeFacts()` → `UserTaxFact` proposals (`source_type='document_extraction'`, confirm-gated per D4; map now includes `federal_tax_withheld`, `gross_pay` per 14-05) |
| Confirm UI | `AiOnboardingUploadSection.tsx` polls `/api/v1/optimizer/facts`, renders `ProposalConfirmCard` per proposal |
| Instant findings | `ProfileConformanceDetector` (FLAG-28: `conformance_filing_status`, `conformance_ira_hsa`, `conformance_checkbox`) + `WithholdingGapDetector` + `CrossSourceReviewService` |

**The two gaps to close (both land in pending plans, §5):**

1. **Placement.** Today the upload lives on Settings (`AiOnboardingUploadSection`) and the Optimize
   page has NO upload CTA — its front door is "Start Income Review" (the interview). Delta: the
   paystub upload becomes the PRIMARY CTA in two places — Action Center Stage-0 item #1 (14-09)
   and a doc-first hero on `Optimize/Index.tsx` when no Ready paystub exists (14-10).
2. **The reveal moment.** On `TaxDocument` reaching `Ready` for `pay_stub`: run the scoped
   detector pass (conformance + withholding-gap — both deterministic, zero Claude) and surface a
   **"What your paystub told us"** panel: (a) proposals to confirm (ProposalConfirmCard list),
   (b) any conformance findings in educational framing ("Your paystub appears to show X while your
   profile says Y"), (c) the Stage-B cascade items ("we can see money flowing to insurance and a
   401(k) — want us to look at those next?"). The existing document-Ready event path triggers this;
   the panel is a 14-10 composition of shipped cards.

**Exit criteria:** ≥1 Ready paystub + its proposals confirmed/rejected. Stage-0 paystub item
disappears (14-09 completion check `TaxDocument category=pay_stub status=ready` already planned).

### Stage B — The doc-cascade: the paystub's money flows name the next documents

Every deduction line on the paystub is a pointer to an authoritative document. Instead of asking
the user questions about insurance or 401(k) details, the system requests the DOCUMENT that
answers them wholesale (§3 cascade map is the full rulebook).

**Mechanics (config-driven, deterministic, zero Claude):**

- New `config/document-cascade.php`: rules of shape
  `trigger (extracted field / fact present) → request (TaxDocumentCategory) + why-copy template + priority`.
- New thin `DocumentCascadeService` (listener on document-Ready, after `proposeFacts()`):
  evaluates rules against the doc's `extracted_data` + current facts, files
  `DocumentRequest` rows (DOC-05 model; `category`, `tax_year`, `status=pending`), **deduped
  against open requests per (category, tax_year)** — one ask per document per year, the D14
  cadence-guard pattern.
- Each open `DocumentRequest` renders as an Action Center item (14-09) and inside the Stage-A
  reveal panel, with benefit-forward why-copy from the template: *"Your paystub shows $412/mo
  going to health premiums — upload your benefits guide and we'll check whether your plan options
  are working for you."*
- Fulfillment is already wired: upload → auto-classify → `fulfilled_document_id` set (DOC-05
  auto-fulfillment) → that document runs ITS extraction + ITS cascade rules (the cascade is
  recursive but shallow — see §3; depth is naturally ≤2 from the paystub root).
- Declining: "I don't have this" → `status=dismissed` → the facts that document would have
  answered fall back to their interview templates (P5 never blocks on a missing doc).

**Question retirement (P5):** every cascade document carries a `retires` list (§3): fact keys its
extraction proposes. Once confirmed, `isAlreadyAnswered()` / `currentFact()` skip-logic (shipped)
removes those questions from the interview forever. This is measurable: the owner's complaint
"if AI has all the documents, some of these questions are unnecessary" becomes a countable metric
(questions retired per document — show it: "This document answered 6 questions for you").

### Stage C — Reconciliation: discrepancies are the ONLY questions at this stage

Every extraction cross-checks the profile. The user is not asked to fill anything in; they are
asked ONLY to resolve conflicts between what documents show and what the profile says.

**Built planes (FLAG-28, `ProfileConformanceDetector`):**
1. Filing status — `profile.tax_filing_status` vs snapshot vs `w4.filing_status` (the owner's own
   "I was filing wrong" discovery).
2. IRA/HSA — profile checkbox vs payroll-deduction evidence (`employer.hsa_deduction_ytd` etc.).
3. Checkbox facts — rental/childcare/student-loan claims vs transaction patterns (both directions).

**New planes (additive detector extensions — same finding shape, `finding_type='conformance'`,
band `conditional`, educational copy):**

| Plane | Compared | Source of evidence |
|---|---|---|
| Name | profile name vs employee name on paystub/W-2 | needs `employee_name` added to `PAY_STUB_FIELDS` (same single extraction call — zero added AI cost) |
| Address | profile/state context vs paystub address + state-tax line | `employee_address`, `state_tax_withheld` field additions |
| Marital | `profile.filing_status` vs W-4 evidence + spouse signals (spouse name on insurance doc, MFJ withholding table) | existing + insurance_doc extraction |
| Dependents | `family.dependents_count` vs `w4.dependents_claimed` | both facts exist — this delta IS the K1 "update your dependents from 0 to 3" directive feed |
| Elections | profile HSA/FSA/401k checkboxes + `ira_type` vs deduction lines | shipped (plane 2) + `benefits.fsa_ytd_cents` |

**The question shape (D18 confirmation shape, zero Claude):** *"Your paystub shows [X]. Your
profile says [Y]. Which is right?"* → ○ The paystub — update my profile ○ My profile is right
○ Something else? Let's talk about it. Answers write through the ONE existing path:
`recordFact()` (user assent = `confirmed`) and, where the answer corrects a profile field, the
existing profile write path (user-confirmed only — never a silent write; D4 untouched).

**Ordering guarantee:** conformance questions are tier-0 (§2) — they are structurally "the only
questions at this stage" because at this point in the funnel nothing else outranks them and
micro-probes haven't unlocked yet.

### Stage D — Deep interview: impact-ranked, only what documents cannot answer

Entered when the user exhausts document-answerable ground: readiness gaps that no owned/declined
document covers, plus surviving finding questions. Everything here is already built (14-05:
readiness, enqueueGaps, template-first questions, D18 quality bar) — the ONLY change is ordering
(§2) and doc-awareness:

- **Doc-pending suppression:** a question whose template carries `doc_affordance` and whose
  category has an OPEN `DocumentRequest` is deferred (availability weight, §2) — the system never
  asks what it is simultaneously waiting on a document for. Resolves on fulfillment (question
  retired) or dismissal (question re-eligible). This is D20.3's "get it from my documents" answer
  choice, generalized to the queue itself.
- The D20 eligibility predicates and conversational escape hatch apply unchanged (queued executor).

### Stage E — Scenarios, checklist, Action Center (designed; 14-08/09/10)

Unchanged in substance: readiness → scenario comparison → choose → materialized checklist →
Action Center with benefit verification (D13.5) and change monitoring (D14/D15). The funnel feeds
it better inputs: by the time a user reaches scenarios, their fact set is document-sourced
(higher `confirmed` ratio → more directives render unlocked per D9.2, fewer confirm-asks).
Stage-0 of the Action Center IS Stage A of this funnel (§5 delta 1).

---

## 2. IMPACT-RANKED QUESTION ORDERING (P2 — "big rocks before micro-probes")

**Today's defect, precisely:** `buildInitialQueue()` seeds band-ordered (auto → conditional →
battery) and applies `initial_cap` (10) in SEED order; `enqueueGaps()` front-inserts. Dollar
impact plays no role — a $9.99 SaaS multi-select can occupy a cap slot while a withholding
question waits. The owner: *"we're jumping into questions that make up tiny optimizations compared
to the big picture: income, deductions, insurance and 401k."*

**Design: a deterministic two-level priority — tier (primary, ascending) then score (secondary,
descending).** No schema change: `InterviewSession.queue` stays a plain string array; priority is
computed transiently at build/insert time from data that already exists.

### 2.1 Tiers (config: `optimization-objectives.interview_priority.tiers`)

| Tier | Name | Membership (deterministic rules) |
|---|---|---|
| 0 | **big_rock** | (a) every §A.2 objective fact key (gap questions + suggested-confirms — filing status, withholding, pay, 401(k)/IRA/HSA, dependents, insurance coverage: the owner's exact list); (b) all `finding_type='conformance'` (Stage-C discrepancies); (c) any finding with `estimated_value_cents ≥ big_rock_cents` (config, default 100_000 = $1,000/yr); (d) band `time_critical` |
| 1 | **standard** | findings with `estimated_value_cents` ≥ `standard_cents` (default 25_000 = $250/yr), or severity `high` with no estimate |
| 2 | **micro** | everything else — the transaction-pattern probes (`deductible_saas_*`, `category_*`, `penalty_1099k_*` aggregations) live here by construction |
| 3 | **battery** | life-event battery — formalizes the existing tail-position invariant |

### 2.2 Score within tier: `score = B × S × P` (integers, config weights)

- **B — impact band (1–4):** from `estimated_value_cents` thresholds (config band edges); gap
  questions take their band from the fact's `impact_class` in config (`filing_status`/`withholding`/
  `retirement`/`insurance` classes default 4; `income`/`household` 3; others 2).
- **S — confidence/severity weight:** finding `severity` high=100 / medium=75 / low=50; band
  auto=100 / conditional=75 / specialist=50; gap questions=100 (they block scenario math — the
  highest-value answer class in the system).
- **P — prerequisite availability:** 100 when all prerequisites (`GATED_PROBES` + config
  `prerequisite`) are resolved; **25 when an OPEN `DocumentRequest` covers the fact's
  `doc_affordance` category** (doc-pending suppression, Stage D) — the question sinks, the
  document item surfaces instead. (Unsatisfied prerequisites keep today's semantics — dropped at
  pop time; P only orders askable questions.)

**Everything the score reads already exists:** `OptimizationFinding.severity`, `.band`,
`.estimated_value_cents` (engine-computed), config fact maps (14-01), `DocumentRequest.status`.
No dollar value is exposed — the score is computed server-side and never serialized.

### 2.3 How the queue consumes it (surgical, three touch points)

1. **`buildInitialQueue()`:** gather candidates exactly as today (D18 rule-4 gating intact) →
   sort by (tier asc, score desc, existing seed order as stable tiebreak) → **apply `initial_cap`
   AFTER the sort.** This one-line ordering change is the highest-leverage fix: the cap now keeps
   the biggest rocks instead of the first-seeded band.
2. **`enqueueGaps()`:** keeps M6 front-insert semantics (user explicitly requested these — they
   stay immediate) but sorts WITHIN the inserted block by the same score. Front-inserted gaps are
   tier-0 by definition, so no inversion is possible.
3. **`follow_ups` front-insertion (D18):** unchanged — topical adjacency beats global order for a
   single bounded follow-up.

**Invariant to test:** for any queue state, no tier-2 key is ever popped while a tier-0 key with
P=100 remains. (Pest: seed SaaS findings + a conformance finding + withholding gap → assert pop
order; assert cap-after-sort keeps tier-0 under initial_cap pressure; `Http::assertNothingSent()`
throughout — ordering is zero-Claude.)

**Where it lives:** pure sorting logic in `InterviewOrchestratorService` (private) or a tiny
`InterviewPriorityService`; config in `optimization-objectives.php`. **Implementation home: the
queued D19/D20 executor** — identical file seam (orchestrator + templates), avoiding collisions
(§5 delta 4).

---

## 3. DOC-CASCADE MAP (P3) — per-document: reveals → triggers → retires

Paystub is the root node. All categories below exist in `TaxDocumentCategory` today. "Retires"
lists the question templates/fact keys the document's confirmed extraction removes forever (P5).
Trigger conditions are cascade-config rules (§1 Stage B); every trigger files one deduped
`DocumentRequest` + Action Center item with why-copy naming the observed money flow.

### `pay_stub` (ROOT — Stage A)

- **Reveals:** gross + YTD income, federal withholding (per-period + YTD), W-4 evidence
  (`w4.filing_status`, dependents signal), 401(k) trad/Roth deductions, HSA/FSA deductions,
  health/dental/vision premium lines, state tax, ESPP, employer name, pay frequency (resolver
  derivation `frequency_from_paystub`), employee name/address (new fields, Stage C).
- **Triggers next:**
  | Condition observed | Request | Why-copy anchor |
  |---|---|---|
  | any benefits deduction line OR employer identified | `benefits_guide` | "see what your employer offers that you're not using" |
  | health premium line | `insurance_doc` | "check your coverage type and plan options" |
  | 401(k) deduction > 0 OR `employer.has_401k` | `retirement_statement` | "check your balance, match capture, and split" |
  | filing status married (confirmed) + household member absent | spouse `pay_stub` (via §4 flow) | "MFJ math needs both incomes" |
  | year-boundary (Jan–Apr) | `w2` | "verify the full year against your final numbers" |
- **Retires:** `pay.frequency`, `pay.gross_per_period_cents`,
  `pay.federal_withholding_per_period_cents`, `employer.federal_withholding` (derived), the T6
  YTD election set (`retirement.traditional_401k_ytd_cents`, `retirement.roth_401k_ytd_cents`,
  `hsa.ytd_contribution_cents`, `benefits.fsa_ytd_cents`), W-4 evidence facts.

### `benefits_guide`

- **Reveals (BENEFITS_FACT_MAP, shipped — 15 `employer.*` facts):** `has_401k`, match formula,
  `hdhp_hsa_available`, FSA/dependent-care FSA, ESPP + terms, after-tax 401(k), in-plan Roth
  conversion, commuter/legal/§127 benefits.
- **Triggers:** `retirement_statement` (if 401(k) confirmed and no statement yet); `insurance_doc`
  (if HDHP ambiguity blocks `health.hsa_eligible`).
- **Retires:** R5/R6/R7 gates (`employer.has_401k`, match pair), R9 (mega-backdoor availability),
  B5 proxy (`hdhp_hsa_available`), the FSA-availability asks. Also the D15 plan-capability fact
  (separate bonus election) when stated.

### `insurance_doc`

- **Reveals:** coverage type self/family → `hsa.coverage_type`; HDHP status → `health.hsa_eligible`;
  employer vs marketplace source (feeds `AcaCliffMonitor` context); covered persons (spouse/
  dependents evidence for Stage C marital/dependents planes).
- **Triggers:** none (leaf).
- **Retires:** B5 (`health.hsa_eligible`), B6 (`hsa.coverage_type`),
  `marketplace.pays_marketplace_premiums`.

### `retirement_statement`

- **Reveals (RETIREMENT_STATEMENT_FACT_MAP, shipped):** `retirement.statement_balance_cents`,
  YTD contributions (cross-check only, never canonical 401(k) YTD), account type.
- **Triggers:** none (leaf).
- **Retires:** R8 (balance / `ira.balance_range` coarse ask); strengthens (not replaces) T6
  confirmations via cross-check.

### spouse `pay_stub` (household-scoped, §4)

- **Reveals:** `spouse.annual_income_cents` (annualized), `spouse.covered_by_retirement_plan`
  (401(k) deduction line present = 'yes' — direct evidence for M13), spouse withholding (future
  joint-withholding accuracy).
- **Retires:** T9, B12 — the two spouse interview asks.

### `w2` (year-boundary verifier)

- **Reveals:** authoritative annual gross, Box-12 codes (D=401k, W=HSA — election truth), state.
- **Triggers:** none; feeds `CrossSourceReviewService` (`w2_deposit_mismatch`) and supersedes
  paystub-annualized derivations with exact values.
- **Retires:** C2 fallback ask (`income.annual_gross_cents`); re-verifies the T6 set.

### `offer_letter`

- **Reveals:** salary, bonus terms → feeds the D15 predictive bonus watcher
  (`OptimizationCalendarEvent`, 14-09).
- **Retires:** bonus month/amount interview asks; **Triggers:** bonus lead-time calendar event.

**Cascade properties:** depth ≤ 2 from root (paystub → guide/insurance/statement → leaf); at most
5 open requests at once (config cap — respect volume like D18.5 respects question volume);
requests ordered by the §2 impact class of the facts they unlock (benefits_guide and
insurance_doc outrank offer_letter). Zero Claude anywhere: rules are config, copy is templates,
extraction remains the existing per-document call.

---

## 4. SPOUSE / HOUSEHOLD SHARING (P6 — a v2.1 seed, not a portal)

**Almost everything exists:** `Household` + `HouseholdInvitation` (token, expiry,
pending/accepted status, `$hidden` token) + `Dependent` models; `HouseholdController` +
`DependentController`; `BelongsToHousehold` trait with `isInSameHousehold()` consumed by
household-aware policies (`UserTaxFactPolicy`, `BankConnectionPolicy`, `BankAccountPolicy`,
`DependentPolicy`); `FamilyHouseholdSection.tsx` / `HouseholdSection.tsx` UI. The v2.1 work is a
TRIGGER plus SCOPE DEFINITION — not new machinery.

**Trigger (deterministic):** `profile.filing_status` becomes `married_joint` at CONFIRMED tier
(interview answer, user edit, or confirmed doc proposal — never a raw detection) AND the user's
`household_id` has no second member AND no pending invitation → emit ONE Action Center item
(dismissible; one prompt per freshness window, D14 cadence pattern):
*"You told us you file jointly. Want to share your dashboard with your spouse? MFJ optimization
gets sharper with both incomes."*

**Invitation flow (reuse, don't build):** item CTA → existing `HouseholdController` invite path →
`HouseholdInvitation` email with token → spouse registers/logs in → accept → `household_id` set,
`household_role='member'`. Decline/dismiss stores a durable fact
(`household.sharing_declined='yes'`, volatility stable) so it is never re-prompted (P5).

**Scope — what acceptance shares in v2.1 (small, explicit, consent-framed):**

| Shared | NOT shared (v2.1) |
|---|---|
| Household membership + `FamilyHouseholdSection` (both members visible) | Bank connections, accounts, transactions (policies stay owner-only) |
| Dependents (already household-scoped via `DependentPolicy`) | Individual documents and their extractions |
| Family-level facts (`family.*`) readable per the existing household-aware `UserTaxFactPolicy` rules | Interview transcripts, findings, reports, checklists |
| The spouse gets their OWN full account + their OWN Stage-A front door ("Upload your paystub") — which is exactly how `spouse.annual_income_cents` gets document-sourced (§3) | Any cross-member money values in shared surfaces |

**Consent framing (binding copy):** the invite states exactly what is and is not shared; either
member can leave the household at any time (existing controller); sharing language is symmetric
("you'll each see the family section; your accounts stay your own"). Educational-only framing
carries over — sharing never asserts filing strategy.

**Explicitly deferred to v2.2+:** joint dashboard views, combined-income surfaces, shared
checklists, MFS comparison (Blocked list), cross-member document visibility. This section ships as
one Action Center item + one durable fact + copy. Nothing else.

---

## 5. DELTAS TO PENDING PLANS (concrete, surgical)

Ground truth: 14-01..14-07 are BUILT (cost infra, engine, resolver, readiness + enqueueGaps +
D18 question shapes, solvers, design waves). 14-08/09/10 are PENDING. The D19/D20 executor
(structured output + interview intelligence) is QUEUED. All deltas below are additive and respect
D17 (zero new Claude call sites — the entire funnel's new logic is config + deterministic
services + template copy).

### Δ1 — 14-09 (Action Center backend): Stage-0 becomes the funnel's front door

- **Reorder Stage-0 items — "Upload a pay stub" is item #1 and THE primary CTA** (currently
  listed fifth). New order: ① Upload a pay stub ("takes a minute — we'll read it and find what's
  off") ② Link your bank ③ Link credit cards ④ Link your emails ⑤ Do the interview (**demoted to
  last** — the interview is the fallback for what documents didn't answer, per P1). Amend the
  ACT-02 truth's item enumeration accordingly; completion checks are unchanged (the
  `TaxDocument category=pay_stub status=ready` check already exists in the plan).
- **Three new deterministic item groups** in `ActionCenterController` (all from existing tables,
  zero Claude): (a) unconfirmed doc proposals → "Confirm N things your paystub told us" (links
  the ProposalConfirmCard flow); (b) open `DocumentRequest` rows → cascade items with template
  why-copy (§3); (c) open `conformance` findings → discrepancy items (Stage C). Items ordered by
  the §2 tier system (conformance and cascade items are tier-0-adjacent — above all micro items).
- **Add `DocumentCascadeService` + `config/document-cascade.php` + a document-Ready listener to
  the 14-09 scope** (§1 Stage B, §3 map). It is item-generation machinery — 14-09 is its natural
  home. Includes the open-request dedupe + config cap (≤5 open).
- **Spouse trigger:** the §4 married-confirmed rule emits its Action Center item here (one
  conditional + one durable-fact check; the invitation flow itself is shipped).

### Δ2 — 14-10 (frontend): the Optimize page and widget lead with documents

- **`ActionCenterWidget`:** the Stage-0 paystub item mounts `DocumentUploadFlow` (compact) INLINE
  — upload happens in the widget, not after a navigation to Settings.
- **`Optimize/Index.tsx`:** when no Ready `pay_stub` exists, the findings stage renders a
  **doc-first hero** — upload CTA primary, "Start Income Review" demoted to secondary text link.
  After upload: the **"What your paystub told us"** panel (Stage A reveal) = ProposalConfirmCard
  list + conformance findings + cascade items, reusing `AiOnboardingUploadSection`'s polling
  pattern. (The ViewMode union gains no new member — this is conditional rendering within
  'findings'; the 14-10 'scenarios' stage addition proceeds unchanged.)
- **`ObjectiveReadinessPanel` reframed document-first:** blocking facts whose template carries
  `doc_affordance` render "Upload your [pay stub]" as the PRIMARY unlock action and "answer N
  questions" as secondary — readiness presents as document completeness first, interview second.
  (API already exposes `doc_affordance`; this is presentation ordering only.)
- **`InterviewCard`:** render the doc-pending state for questions deferred by an open
  `DocumentRequest` ("waiting on your benefits guide — we'll skip this unless you'd rather answer
  now"), with an answer-now override.

### Δ3 — 14-08 (scenario/checklist APIs): minimal touch

- `ScenarioChecklistService` orders checklist GROUPS by engine-computed annual benefit descending
  (big rocks first in the rendered checklist — one sort over `benefit_line_params`; per-step
  order within groups unchanged). Nothing else in 14-08 changes — its liability/persistence
  design already conforms.

### Δ4 — D19/D20 queued executor: absorbs the queue-ordering change

- **Fold §2 impact-ranked ordering into this executor** (tier sort, cap-AFTER-sort, doc-pending
  availability weight): identical file seam (`InterviewOrchestratorService` + config templates)
  as D19/D20's eligibility predicates and structured-output work — collocating avoids the
  same-file collision the owner's sequencing rules exist to prevent.
- D20.3 ("get this from my documents" answer choice) gains one wiring note: choosing it files a
  `DocumentRequest` through the SAME cascade store, so the Action Center and dedupe see it — one
  request system, not two.
- Everything else in D19/D20 (structured contracts, eligibility predicates, conversational hatch)
  proceeds exactly as specified in the notes file.

### Δ5 — New thin follow-up plan ("14-11 documents-first reconciliation"), AFTER 14-10

Scope deliberately excluded from the queued executor to keep it surgical:
- **Identity-plane extensions** (§1 Stage C): add `employee_name`, `employee_address`,
  `state_tax_withheld` to `PAY_STUB_FIELDS` (same single extraction call — no added AI cost);
  extend `ProfileConformanceDetector` with name/address/marital/dependents planes + their D18
  confirmation-shape templates; profile-update write path on user confirmation (existing D4/
  recordFact plumbing).
- **Question-retirement counter** ("this document answered 6 questions") — presentation over
  existing skip-logic data.
- **Spouse Stage-A onboarding polish** (§4) beyond the invite item.

### Sequencing summary

```
now:        D19/D20 executor (+ §2 ordering folded in)          — queued, unchanged seam
wave 4-6:   14-08 (Δ3) → 14-09 (Δ1) → 14-10 (Δ2)               — pending plans, amended scopes
follow-up:  14-11 (Δ5) identity planes + retirement counter     — new thin plan
v2.2+:      joint household views, shared checklists            — deferred (§4)
```

---

## 6. GUARDRAILS CARRIED THROUGH (nothing here relaxes them)

- **D17 cost discipline:** the funnel adds ZERO new Claude call sites. Document extraction remains
  the one sanctioned Sonnet call per document (user-triggered = inherently activity-gated).
  Cascade rules, conformance detection, priority scoring, all Action Center items, and all
  question/why-copy are config + templates + deterministic services. Budget counters and model
  tiering untouched.
- **D4 confirm gate:** no extraction ever writes profile or current facts silently — proposals +
  user confirmation, always. The reveal panel makes confirmation the interaction, not a bypass.
- **Educational framing (D3/FLAG-28/SAFE-03):** every discrepancy surface stays "your paystub
  appears to show X while your profile says Y" — evidence-led, never assertive; all dollar figures
  engine-computed; no dollar values in `AIQuestion.options` (pointer pattern) or readiness bodies.
- **D18 question bar:** conformance and cascade prompts use the confirmation shape — data lead,
  one-sentence ask, plain choices, escape hatch. Cascade why-copy is benefit-forward and specific.
- **Additive only:** no shipped route/API/schema semantics change; new config files, one new
  service, detector extensions, presentation reordering. Forward-only migrations only if 14-11
  needs any (none identified — all stores exist).
- **P5 stored forever:** provenance-tracked facts + `isAlreadyAnswered()`/`currentFact()` make
  never-re-ask mechanical; declines are durable facts too (doc dismissals, spouse-share decline).

---

## Addendum (owner inputs that arrived post-draft — BINDING, see D21 addendum in enhanced-profile-integration-notes.md)

1. **Mixed-account question (FLAG-14-gated):** evidence-led per-account classification (run business here / shared / personal-only / hatch) whose answer writes `BankAccount.purpose` via the existing updatePurpose cascade + a confirmed fact. Reconciliation-stage (C), Tier 1.
2. **Label ≠ behavior:** account labels are hints; observed transactions are truth. A "mixed" answer triggers pragmatic INTRA-account classification: merchant-level rules via the existing category-propagation machinery + batch-confirm multi-selects — never per-transaction interrogation. Tax delivery (Schedule C aggregation) is transaction-classification-based across ALL accounts.
3. **Builder-at-a-loss persona:** business expenses > business income funded from personal deposits = recognized pattern (owner's own). Harvest deductions wherever they live (the personal account IS the deduction source); honest hobby-loss horizon watch (9-factor education, loss-year counter, pro-routing) — educational only.
4. **Owner account ground truth (seeded):** Personal Checking ...7300 = MIXED (≈90% of business activity); Parkk Technologies ...9111 + AirBnB ...9958 = business; a "...4871 business account" was named by the owner but is NOT among linked accounts — surface as a connect-your-account Action Center item.
