# INTEGRATION MAP — v2.1 Optimize My Income (Master Source → Disposition Map)

**Synthesized:** 2026-07-01
**Sources (6):**
- `TD-v1` = `.planning/reference/transaction-detection-distilled.md`
- `PB-v1` = `.planning/reference/tax-strategy-playbook-distilled.md`
- `EXP` = `.planning/reference/tax-strategy-expansion-distilled.md`
- `PB-v2Δ` = `.planning/reference/tax-strategy-playbook-v2-delta-distilled.md`
- `TD-v2Δ` = `.planning/reference/transaction-detection-v2-delta-distilled.md`
- `OWN` = `.planning/reference/enhanced-profile-integration-notes.md` — six LOCKED owner decisions (Enhanced-Tax-Profile anchoring, multi-account retirement, profile-vs-reality conformance, doc-extraction confirmation gate, User/Family-Profile reshape, frontend skill mandate)

**Precedence:** where the adversarial fact-check/liability screen contradicted the initial gap analysis, the fact-checker wins. `OWN` decisions are LOCKED and override synthesis defaults. Every liability reframe is applied inline in the disposition text below and in `REQUIREMENTS-ADDENDUM.md`. Blocked items appear ONLY in the Blocked section — never as build requirements.
**Owner-decision batch status:** the 11 decisions_for_user items are resolved by orchestrator default rulings, each annotated "[default ruling — owner review pending]" where applied; no liability decision was loosened and nothing blocked was promoted.

**Legend:**
- **Kind:** pipeline / engine-rule / detector / sweep / scanner / probe / store / schema / config / doc-type / education / guard / persona / report / UX / compliance
- **Disposition:** existing REQ-ID · new REQ-ID (see REQUIREMENTS-ADDENDUM.md) · `config` (constant only) · FUTURE (parked, Future Requirements) · STATE-01 · BLOCKED / OWNER-GATED
- **Liability:** LOW / MED / HIGH / BLOCKED
- New REQ-IDs referenced here are defined in `REQUIREMENTS-ADDENDUM.md`: TAX-08/09, FLAG-08..18, FLAG-20..28, INT-06/07, STORE-01..03, RPT-05..08, DOC-05..07, SAFE-06/07 (FLAG-19, STORE-04, NOTIF-01, DOC-08, DOC-09, PERS-xx etc. are Future entries). Existing FLAG-07 is promoted from Future into P11 [default ruling — owner review pending].

---

## 1. Engine Framework & Pipeline

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| Enrichment layer (merchant/MCC/amount/recurrence/payee normalization, processor extraction, MCC-less fallback) | TD-v1 §0 | pipeline | Shipped v1.0 (Transaction + MerchantAlias + SubscriptionDetectorService normalization) | LOW | — |
| Recurrence engine (recurring payees, loan servicers, grouping) | TD-v1 §0/§2.9 | pipeline | Shipped v1.0 (SubscriptionDetectorService + HousingDetectionService) | LOW | — |
| Hypothesis-generation core (deterministic detectors; Claude describes only) | TD-v1 §0 | engine | Existing FLAG-01 | LOW | — |
| Materiality gates ($100 floor / $500-yr pattern / $1,000 single / address & loan-servicer always) | TD-v1 §0.1, §13 | engine-rule | **FLAG-08 (new, P11)** | LOW | Thresholds in config, never in service code |
| Ask-once profile graph (vehicles, properties, entities, people, methods elected) | TD-v1 §0.2; OWN D1/D2 | store | **STORE-01 (new, P11)** — UserTaxFact + TaxProfileEntity per storage design; extends `answerableFields()`; ANCHORED to the existing Enhanced Tax Profile (UserFinancialProfile + EnhancedProfileSection.tsx in Settings) per LOCKED owner Decision 1 — learned facts are visible/editable in Settings via an additive UI hook, never a disconnected parallel store | LOW | People = existing Dependent/Household models, not duplicated. FACT-CHECK FIX: assembler must be extended additively to read `business_type` + `housing_status` (readProfileFlags currently omits them). OWN Decision 2 (correctness): multi-account retirement representation — simultaneous Roth + Traditional IRA + employer 401(k) types with per-type contributions; legacy `ira_type` untouched; IRA limit is SHARED across Roth+Traditional, so TaxRulesEngineService headroom consumes COMBINED contributions |
| "Know what you know before asking" skip logic | TD-v1 §0.2 | engine-rule | Shipped CTX-04 + STORE-01 extension | LOW | — |
| Method-conflict guards (mileage↔actual, simplified↔actual home office, §121 recapture tracking, accountable-plan routing) | TD-v1 §0.3 | guard | **FLAG-09 (new, P11)** | LOW | Annual method comparison = TaxRulesEngineService math (deduction arithmetic, not liability computation — clean pass) |
| Output contract (transaction_ids, treatment, legal_basis, assumptions, band, user_assertions, docs, estimated_value, pro_export_ready) | TD-v1 §12 | schema | **FLAG-13 (new, P11)** additive OptimizationFinding migration | LOW | + year-end forward-compat fields from TD-v2Δ §16 (`deadline`, `lead_time_days`, `net_cash_cost`, `tax_saved`, `cliff_bonus_value`, `reversible`). The spec's `estimated_value` ships as column `estimated_value_cents` (integer cents), per PHASE-11-CONTEXT D6 — addendum text aligned. `legal_basis`/`assumptions` are STATIC config-sourced citations, never Claude output (kills citation hallucination; test under SAFE-03) |
| estimated_value engine-only, presented as range never guarantee | TD-v1 §12 | guard | Existing SAFE-03 + shipped TaxRulesEngineService | LOW | — |
| Rule expiration/provenance schema (rule_id, authority, effective dates, phaseouts, source_url, last_verified, status, band incl. suppress/hard_block) | TD-v2Δ §9 | schema | **TAX-09 (new, P11)** — adopt for every rule from day one | LOW | CANONICAL row; EXP-M16 merged here (identical intent, TD-v2Δ has fuller schema). Retrofitting is expensive — ship in 11a |
| M16 Current-Law Expiration Validator | EXP M16 | schema | Merged → TAX-09 | LOW | Dedupe: TD-v2Δ §9 is canonical |
| Module build-order roadmap (scanners → sweeps → hypothesis engine → paystub plane → ACA → entity → life events → personas → vault/pro-export → routers) | TD-v2Δ §10 | architecture | Phase-11 CONTEXT decision (items 1–3 = P11; 4 & 9 = P12; 5–8 & 10 = future) — do not invert without owner sign-off | — | ACA monitor (item 5) partially promoted to P11 as FLAG-22 per synthesis instruction (highest-value probe) |
| Confidence-band cutpoints (auto/conditional/specialist) | TD-v1 §10.5/§13 | config | TAX-08 constants + INT-07 behavior | LOW | Mirror `config/spendifiai.php` threshold pattern |
| Push notification at moment of detection | TD-v1 §10.3/§15 | UX | FUTURE (**NOTIF-01**) — v2.1 uses AI Questions feed + UI-01 badge | LOW | No notification system exists yet |

## 2. Question Engine / Interview / Feed

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| Plain-language, one-legal-test-per-question + static legal-test metadata | TD-v1 §10.1 | engine-rule | Existing INT-01 + SAFE-01; metadata → FLAG-13 `legal_basis` | LOW | — |
| Leading-not-assuming; USER asserts facts; timestamped assertion log = audit defense | TD-v1 §10.2 | engine-rule | Existing SAFE-01 + FLAG-13 `user_assertions[]` + InterviewSession (STORE-01 design) | LOW | — |
| Moment-of-detection surfacing | TD-v1 §10.3 | UX | Existing FEED-02 + UI-01 | LOW | — |
| Documentation capture in the same interaction | TD-v1 §10.3/§14 | doc-flow | **DOC-05 (new, P12)** — reuse v2.0 DocumentRequest auto-fulfillment; P11 interview ships `docs_missing` + "you'll be asked to upload X" affordance | LOW | Sequencing RESOLVED: kept as synthesized — capture/vault wiring lands P12; P11 ships the affordance only [default ruling — owner review pending] |
| Batch by pattern, not transaction (40 charges = 1 conversation, applied retroactively) | TD-v1 §10.4 | engine-rule | **INT-06 (new, P11)** | LOW | Mirrors existing propagate-to-matching-merchants |
| Confidence bands drive response mode | TD-v1 §10.5 | engine-rule | **INT-07 (new, P11) — REFRAMED per liability screen:** high-confidence findings PRE-FILL "suggested — confirm" with one-tap confirm/undo; until confirmed the treatment is excluded from `user_assertions[]`, `estimated_value` aggregation, and `pro_export_ready`. Auto-classification without confirm ONLY for bookkeeping category (existing v1 ≥0.85), NEVER for tax-treatment fields | MED | Liability screen's one NEW violation — fixed in requirement text |
| Every "no" is also data (basis ledger, HSA shoebox, commingling) | TD-v1 §10.6 | engine-rule | STORE-02 + STORE-03 + FLAG-14 | LOW | — |
| Answers flow back via UserAnsweredQuestion | TD-v1 §10 | bridge | Existing FEED-03 — listener writes through to UserTaxFact (STORE-01) | LOW | — |
| Guard: optimization questions never touch transaction categorization | TD-v1 §10 | guard | Existing FEED-04 | LOW | — |
| Interview state machine, one-question-at-a-time, resume, caps, prerequisite gating | TD-v1 §0 | engine | Existing INT-01..05 (+ InterviewSession table per storage design) | LOW | — |

## 3. Category Detectors (Transaction Plane)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| Vehicle / powersports / race parts detector (incl. GVWR>6,000 §179 branch, sponsorship module, inventory/COGS, content-business, off-road fuel credit w/ gallons-log export block) | TD-v1 §1 | detector | **FLAG-10 (new, P11)** | MED | Sponsorship + fuel credit = gray-area: question + doc checklist + static defensibility rating + pro-review routing ONLY, never assertions (bound into FLAG-10 text). Sales-tax/nexus → informational flag only, STATE-01 |
| Auto-loan interest sub-detector (US-assembled, 2025+, $10K cap 2025–2028) | TD-v1 §1 sub; PB-v1 W2-14 | detector | FLAG-10 sub-detector + FLAG-17 OBBBA probe; constants → TAX-08 | LOW | Also fired by FLAG-12 retroactive missed-deduction scan |
| Solar / battery / energy detector (loan-servicer signal, WHEN-first tree, rental MACRS/ITC pro tier, basis for 2026+ primary home, roof-bundle overclaim warning, EV-charger/generator mirrors) | TD-v1 §2 | detector | **FLAG-10** + FLAG-12 (retroactive §25D) | MED | §25D recovery REFRAMED: educational range from config, never a promised amount. 2026+ primary-home outputs must say "no federal credit" (SAFE-06 never-surface guard, per PB-v2Δ §8B.3) |
| Pool / spa detector (property routing, basis ledger, medical-pool module, daycare time-space, HELOC-vs-unsecured financing question, Augusta amenity note) | TD-v1 §3 | detector | **FLAG-10** | MED | Medical pool = gray-area question-only + prescription letter + appraisal + pro tier. Financing question = the transaction-inferable form of TD-v2Δ §12 guard #3 |
| Landscaping / hardscape detector (repair-vs-improvement, Langer grounds question, xeriscape rebates reduce basis, pre-sale → §121 projection) | TD-v1 §4 | detector | **FLAG-10** | MED | Grounds deduction = gray-area, pro tier |
| Home-improvement stores one-tap destination question (Grainger/McMaster business skew + R&D-supply flag) | TD-v1 §5 | detector | **FLAG-10** | LOW | Destination answer feeds STORE-01 facts + STORE-02 basis ledger; R&D flag routes to FLAG-17 §41 question |
| Animals & security detector (guard dog, foster/*Van Dusen*, pest cats, security-system routing) | TD-v1 §6; PB-v1 8A animals | detector | **FLAG-10** | MED | Gray-area modules: question + checklist + pro routing ONLY ("your dog IS deductible" assertions locked out-of-scope). This is the FLAG-05 "pet" probe content |
| Medical / health detector (HSA-first routing, §213 gym/program w/ prescription, CareCredit surfacing, bunching education, diagnosed-condition cluster, performer rule) | TD-v1 §7; PB-v1 8A medical | detector | **FLAG-10** + STORE-03 shoebox (P12) | MED | Diagnosed-condition cluster 🟢 with diagnosis docs. *Hess* body-mod → SAFE-06 refuse-material, never probed |
| Travel-pattern cluster correlator (airline+hotel+conference/client-city window; per-diem vs actual; sandwich days; spouse warning; primarily-business airfare test) | TD-v1 §8; PB-v1 8A travel | detector | **FLAG-10** (new multi-transaction correlation logic) | MED | Per-diem rates → TAX-08; client-city fact → STORE-01 |
| RV/boat-as-second-home (loan-payment detectable; sleeping+cooking+toilet test) | PB-v1 8A | detector | **FLAG-10** addition | LOW | FACT-CHECK missing item — now dispositioned |
| Masters/14-day rule standalone detector (Airbnb-style deposits WITHOUT ongoing rental pattern → day-count question; day 15 = all taxable) | PB-v1 RE-8 | detector | **FLAG-10** addition | LOW | FACT-CHECK missing item; 14-day cap → TAX-08 |
| Recurring-payee sweeps: same individuals → worker classification; childcare → dependent-care (day camp yes / overnight no); tuition/loans → AOTC/LLC/student-loan/§127/scholarship election; charitable → bunching/DAF; storage/coworking → allocation; insurance → SE-health/§105-HRA | TD-v1 §9; PB-v1 BO-9, W2-10/16, SH-5 | sweep | **FLAG-11 (new, P11)** monthly scheduled, activity-gated | MED | Worker classification + §105-HRA = warn-and-educate/question-only. Scholarship election carries the "narrate carefully" flag (counterintuitive). Appreciated-asset substitution prompt REFRAMED: "some donors give appreciated holdings…" mechanics only, no directive; >$5K non-cash always pairs the appraisal checklist |
| Software/SaaS Schedule C sweep | TD-v1 §9 | sweep | Existing **FLAG-07 — PROMOTED to P11** [default ruling — owner review pending]: nearly free on FLAG-11 infrastructure (reuses Subscription records), pure educational surfacing | LOW | Traceability row moves from Future to Phase 11; ships in wave 11b |
| Crypto exchange-payee sweep | TD-v1 §9 | sweep | FUTURE (**FLAG-19**) — KEPT FUTURE: detection spec's parking wins over playbook's promotion [default ruling — owner review pending] | HIGH | Playbook said P11, detection spec said park — resolved in the spec's favor |
| Gambling merchant signals (DraftKings/FanDuel/BetMGM/Caesars, casino ATM) | TD-v2Δ §13; PB-v2Δ 2D.1 | detector | Merchant signals registered in FLAG-10 seed table now; full module FUTURE (**PERS-07**) | HIGH | 90%-loss limit → TAX-08 with effective dating; NEVER surfaced as fully-deductible (SAFE-06 never-surface list). Wellbeing/risk lens DEFERRED with the module — genuinely the owner's product-values call [default ruling — owner review pending] |
| Merchant knowledge/seed table (aliases JSON, CancellationProviderSeeder precedent) | TD-v1 §§1–8 | infra | **FLAG-10** (seeded detection table) | LOW | — |

## 4. Retroactive Scanners & Durable Stores

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| Missed-credit scanner (§25D solar 3-yr amended recovery, §30D EV pre-Oct-2025, §25C pre-2026) | TD-v1 §11.1 | scanner | **FLAG-12 (new, P11)** | MED | REFRAMES bound in text: §25D = config range + "a professional could evaluate", never a promise; §30D = strictly past-window fact, date-gated, never presented as currently available |
| Missed-deduction scanner (SE health, unclaimed home office, auto-loan 2025+; AZ credits hook suppressed) | TD-v1 §11.2 | scanner | **FLAG-12** | LOW | State output suppressed → STATE-01 |
| Basis reconstruction sweep (contractor/improvement payments → property ledger) | TD-v1 §11.3 | scanner | **FLAG-12** feeding **STORE-02 (new, P11)** | LOW | — |
| Method-election review (mileage / home-office comparisons on actuals) | TD-v1 §11.4 | scanner | **FLAG-12** + FLAG-09 (comparison math = TaxRulesEngineService) | LOW | — |
| Estimated-tax exposure scan (safe-harbor gap) | TD-v1 §11.5 | scanner | **FLAG-18 (new, P11)** — see reframe in §8 below | MED | Companion to existing FLAG-03 |
| Onboarding 12–36-month history run alongside BuildIncomeOptimizationProfile (plaid:backfill as needed) | TD-v1 §11 | infra | FLAG-12 operational note (Phase 11 description) | LOW | Scanners emit standard OptimizationFinding records |
| Per-property basis ledger (improvements accumulate toward §121; rebates reduce basis; maintenance excluded; recapture years tracked) | TD-v1 §§2–4, §10.6, §11.3; PB-v1 RE-7 | store | **STORE-02 (new, P11)** — ledger entries on TaxProfileEntity attributes, receipts referenced by tax_document_id | LOW | — |
| HSA shoebox (track out-of-pocket medical even when not deductible; receipts → Vault) | TD-v1 §7.4; PB-v1 W2-3; EXP HSA table | store | **STORE-03 (new, P12)** | LOW | REFRAME applied: education states reimbursement applies only to expenses incurred AFTER HSA establishment, receipts required |
| Ask-once durable-facts store (UserTaxFact append-only, volatility tiers permanent/stable/annual, reconfirm taps, supersession chain, provenance) | TD-v1 §0.2 + storage design; OWN D1/D2/D4 | store | **STORE-01 (new, P11)** — ANCHORED to the Enhanced Tax Profile per LOCKED owner Decision 1 (see §1 row); document-extracted facts enter as PROPOSED and require user confirmation before becoming current (owner Decision 4); multi-account retirement representation with shared-IRA-limit combined-contribution headroom (owner Decision 2) | LOW | Full schema in PHASE-11-CONTEXT-DRAFT.md `<specifics>` |
| Profile-vs-reality conformance detectors (stated filing status vs paystub W-4/withholding — FLAG-02 paystub evidence plane; stated IRA/HSA facts vs detected transfers/payroll deductions; checkbox facts vs transaction patterns, BOTH directions) | OWN D3 (LOCKED) | detector | **FLAG-28 (new, P11)** — every mismatch → OptimizationFinding + educational question ("Your paystub appears to show X while your profile says Y — want to update your profile or tell us more?") | MED | Never asserts; profile updates only via user confirmation. Ships in wave 11b |
| Avoidance-vs-evasion education + killer-doctrines list (avoidance = real transactions under the code, *Gregory v. Helvering*; evasion = misrepresented facts, §7201; economic substance §7701(o), substance-over-form, step transaction, sham transaction; "borderline is not a viable product tier") | PB-v1 §9 (educate, verbatim-worthy) | education | **RPT-07** explainer module (P12 report glossary) + P13 framing-review reference copy (SAFE-05/SAFE-06 wording input) | LOW | Distinct from the §9 audit-risk inputs (→ FLAG-15) and hard-block list (→ SAFE-06), which were already dispositioned |
| Contemporaneous log features (mileage, STR hours, Augusta day-count/minutes, kids timesheets, gallons) | PB-v1 §9, SH-2; TD-v1 §14 | store | FUTURE (**STORE-04**) — v2.1 ships doc-checklist exports only | LOW | — |
| Commingling monitor (personal spend in business-purpose accounts → separate-account education) | TD-v1 §10.6; PB-v1 SH-0, §9 | detector | **FLAG-14 (new, P11)** | LOW | Wording locked: "Business owners commonly keep a separate account… the single most effective record in a hobby-loss review." Never "you qualify as a business" |
| Audit-risk score (hobby-loss 9-factor, 100% vehicle use, round numbers, deposit mismatch, charitable outliers, disproportionate HO+meals+travel, missing 1099s, mill-flag credits) | PB-v1 §9; TD-v1 §1.3 | detector | **FLAG-15 (new, P11)** feeding FLAG-06 severity | MED | REFRAME locked: "Returns with patterns like [X] commonly receive additional scrutiny — here is the documentation that typically resolves it." Never accusation, never numeric audit probability. Deposit reconciliation = shipped CTX-03; deeper matching = CTX-05 Future |
| Deposit-vs-reported reconciliation ("killer feature") | PB-v1 §9 input 4 | detector | Shipped CTX-03 (CrossSourceReviewService); extensions → CTX-05 Future | LOW | — |
| Missing-1099 cross-check | PB-v1 §9 input 7 | detector | Shipped CTX-03 (partial); full matching → CTX-05 Future | LOW | — |

## 5. W-2 Employee Strategies (PB-v1 §2 + EXP + PB-v2Δ merges)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| W2-1 401(k) maximization + unclaimed match | PB-v1; EXP table | probe | Shipped TAX-04/05 + existing FLAG-04 + **FLAG-20** | LOW | — |
| W2-2 Mega backdoor Roth (plan-feature questions) | PB-v1; EXP M2 Q6-7 | probe | **FLAG-20 (new, P11)** gated probe — "if your plan allows" framing mandatory; 415(c) → TAX-08 | MED | — |
| W2-3 HSA stealth IRA | PB-v1 | probe | Shipped TAX-04 + FLAG-20; receipt-hoarding → STORE-03 | LOW | REFRAME: "HSA funds may be investable; consider discussing with a professional" — encoded in SAFE-01 constraints. Never "invest your HSA" |
| W2-4 Backdoor Roth IRA (pro-rata IRA-balance gate) | PB-v1 | probe | Existing INT-04 (canonical example) | LOW | FACT-CHECK attribution fix: Roth MAGI phase-out constants live under TAX-01 config; `rothIraEligibility()` is shipped engine code — TAX-05 is the Trad-vs-Roth band |
| W2-5 RSU under-withholding | PB-v1 | detector | Existing FLAG-03 (gap > $500) | LOW | — |
| W2-5 ISO — AMT crossover modeling | PB-v1 | probe | **BLOCKED — RESOLVED: DO NOT BUILD** [default ruling — owner review pending]; only the safe one-liner ships: "ISO exercises may have AMT implications — consider a professional review before exercising" | BLOCKED | See Blocked §15 |
| W2-5 NSO timing | PB-v1 | education | REFRAMED into FLAG-17/RPT-07: "NSO exercises are generally taxed as ordinary income at exercise… a professional could review timing considerations." Directive stripped | MED | — |
| W2-5 ESPP | PB-v1; EXP; PB-v2Δ 2B.5 | probe | **FLAG-20** non-participation detection + participation education | MED | REFRAME: ban "free money"/guaranteed-return language; qualifying-vs-disqualifying taxed differently; no disposition modeling |
| W2-6 83(b) 30-day alarm | PB-v1 | alarm | **FLAG-16 (new, P11)** highest severity | MED | Urgency stated as fact + consider-a-professional = compliant |
| W2-7 0% LTCG gain harvesting | PB-v1 | strategy | **BLOCKED** (RIA) — see Blocked §1; the educational bracket-awareness glossary LINE (no sell/rebuy language) is APPROVED for RPT-07 [default ruling — owner review pending] | BLOCKED | Strategy/detector stays blocked; glossary line only |
| W2-8 Roth conversion in low-income years | PB-v1; EXP M9 | probe | Shipped TAX-02/05 + **FLAG-17** income-drop trigger | MED | Guard bound in text: trigger keys off payroll/income signals ONLY, never asset values (distinguishes from blocked CR-2) |
| W2-9 Tax-loss harvesting alerts | PB-v1 | detector | **BLOCKED** (RIA) as a detector; the glossary FACTS ($3K offset, wash-sale rule) are APPROVED for RPT-07 glossary [default ruling — owner review pending] | BLOCKED | Never a detector or alert; glossary facts only |
| W2-10 Charitable bunching + DAF | PB-v1 | probe | FLAG-11 charitable sweep + shipped TAX-03 + RPT-07 module; floors → TAX-08 | LOW | Appreciated-stock REFRAME applied (see FLAG-11 row) |
| W2-11 NQDC | PB-v1; EXP | education | **RPT-07 (new, P12)** — employer-credit-risk warning mandatory | MED | — |
| W2-12 Tips deduction (OBBBA) | PB-v1 | probe | **FLAG-17** + TAX-08 (cap, phaseouts, 2025–2028 sunset) | LOW | — |
| W2-13 Overtime deduction (OBBBA, W-2 box 12 TP/TT) | PB-v1 | probe | **FLAG-17** + TAX-08; box codes read from vault-extracted W-2s (shipped v2.0 extraction) | LOW | — |
| W2-15 Senior deduction (OBBBA) | PB-v1 | probe | **FLAG-17** + TAX-08 | LOW | Distinct from age-65 standard-deduction addition already in config |
| W2-16 Dependent care FSA + credit (day camp yes / overnight no) | PB-v1 | probe | FLAG-11 childcare sweep + **FLAG-23** credit scanner + TAX-08 | LOW | — |
| W2-17 529 plans (K-12 $20K, superfunding, 529→Roth $35K) | PB-v1 | probe | **FLAG-17** — federal parts ONLY (guard in text); state deduction → STATE-01 | LOW | — |
| W2-18 Trump Account | PB-v1 | probe | **FLAG-17** family probe + **FLAG-20** employer-contribution detection + TAX-08 | LOW | — |

## 6. W-2 Benefits Plane & New W-2 Modules (EXP M-series ⟂ PB-v2Δ §2B ⟂ TD-v2Δ §6 — deduped)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| M2 / §2B.5 / spec §6 — W-2 Benefit Arbitrage Engine (paystub + benefits-guide plane; "failed to elect" detection; 13-item benefit list + group legal + employer Trump Account; 11 parse targets) | EXP M2; PB-v2Δ 2B.5; TD-v2Δ §6; OWN D4 | engine | **FLAG-20 (new, P11)** findings from interview answers + (when available) extracted facts; **DOC-07 (new, P12)** benefits-guide doc category + extraction schema — with the LOCKED owner-Decision-4 gate: extracted facts are PROPOSED with per-field confidence and USER-CONFIRMED before any write (never silently overwrite user-entered values) | MED | CANONICAL merge row (EXP primary + delta's group-legal/Trump-Account additions + spec §6 parse-target list). Paystub state-withholding-vs-residence cross-check: record only, output suppressed → STATE-01. Open-enrollment + onboarding upload prompts in DOC-07 ("AI onboarding": paystub upload is the fastest path to a complete profile) |
| M3 / §2B.2 — Public-sector 457(b)/403(b) stacking (separate $24,500 limits; 3-yr catch-up; governmental no-10%-penalty; non-governmental creditor risk) | EXP M3; PB-v2Δ 2B.2 | probe | **FLAG-21 (new, P11)** employer-type-gated branch (incl. EXP M3 Q5 pension-income-projection question, answer persisted as durable fact); limits → TAX-08 | LOW/MED | Merge: EXP question block + delta numbers/caveats. Non-governmental creditor-risk caveat MANDATORY before any 457(b) content |
| Pension income projections (data-source item 5, flagged "NEW, ASK"; EXP M3 Q5) | EXP data-ingestion §(c) item 5; EXP M3 | doc-type | ASK-level interview question ships in P11 inside FLAG-21's M3 battery; the projection DOCUMENT type is FUTURE (**DOC-09**) — extraction schema/vault category deferred to the public-sector persona (PERS-adjacent / decumulation data plane) | LOW | ASK-level item resolved by default disposition — owner review pending |
| M5 / §2B.4 — Reimbursement beats deduction (routing rule; survivor categories: impairment-related, reservists, performing artists, fee-basis officials) | EXP M5; PB-v2Δ 2B.4 | engine-rule | **FLAG-25 (new, P11)** routing rule for W-2 users; Employer Reimbursement Request Packet generator → FUTURE (**PKT-01**) | LOW | Merge: EXP primary + delta survivor list + HR-explainer page (packet, future) |
| M7 / §2B.6.1 — IRA→HSA qualified funding distribution (one-time lifetime; 5-trigger gate; testing-period rules) | EXP M7; PB-v2Δ 2B.6.1 | probe | **FLAG-24 (new, P11)** gated probe (INT-04 pattern) | MED | Identical in both sources — EXP canonical. Testing-period caveat mandatory in wording |
| §2B.1 — ACA subsidy cliff (400% FPL back; no repayment cap post-2025; MAGI management; mid-year monitor; interaction warnings) | PB-v2Δ 2B.1 | detector | **FLAG-22 (new, P11)** awareness monitor — marketplace-premium detection + engine-computed cliff proximity from TAX-08 FPL constants; MAGI-management education sequenced BEFORE any Trad-vs-Roth narration for marketplace enrollees | HIGH | DELTA canonical (EXP M9/M10.7 thin). Awareness/education only — never presents a computed subsidy or clawback as the user's amount. P13 hardening explicitly covers cliff wording |
| M10 / §2B.6.6 — Refundable-credit scanner (EITC w/ investment-income limit, CTC/ACTC, dependent care, AOTC/LLC, Saver's Credit + Match-2027, PTC, adoption, ABLE) | EXP M10; PB-v2Δ 2B.6.6 | scanner | **FLAG-23 (new, P11)** — deterministic config eligibility surfacing, "may be eligible" never "you qualify"; state credits → STATE-01 | LOW | Merge: EXP 10-item list + delta EITC caveat + Saver's-Match 2027 date gate (TAX-09 effective dating) |
| M1 — Tax Savings Control Center (12 tax surfaces, 7 orienting questions) | EXP M1 | UX-framing | P12 framing input for existing UI-02; full 12-surface coverage = FUTURE north star (**CTRL-01**) | LOW | Not a separate feature in v2.1 |
| Settings → "User/Family Profile" reshape, option (a) split | OWN D5 (LOCKED default) | UX | P12 design decision: new "User/Family Profile" nav item (or Optimize My Income section) hosts financial + enhanced-tax + family/spouse/dependents profile with the AI-onboarding upload flow; "Settings" keeps account/security. Additive display labels ONLY — routes/files/components never renamed. Final call at P12 planning; default to (a) unless owner overrides | LOW | Family framing is first-class (filing-status math, CTC/EITC/dependent-care, multiple-support probes). All P12 UI + P11 interview UI carry the OWN D6 skill mandate (`/frontend-design:frontend-design` + `ui-ux-pro-max`, harmonized with `sw-*` tokens) |
| M15 / spec §8 — Penalty-prevention sweeps (13/16-item list) | EXP M15; TD-v2Δ §8; PB-v2Δ 8B.5 | sweep | **FLAG-26 (new, P11)** transaction-observable subset: excess IRA/Roth/HSA (TAX-04 headroom inverted), Roth income-limit breach awareness, HSA-near-Medicare heuristic warning, 1099-K/deposit mismatch (CTX-03-adjacent); under-withholding = existing FLAG-03; missed estimates = FLAG-18. Rest (late elections, RMD, wash sales, passive-loss, payroll failures) → FUTURE; nexus/sales-tax → STATE-01 | LOW/MED | "We stop tax problems before they exist" framing → RPT sections (P12). Continuous scheduling via existing scheduler, activity-gated |
| M9 / spec §7 — Life-event trigger engine (21 triggers; 4 data-detectable) | EXP M9; TD-v2Δ §7 | detector | **FLAG-27 (new, P11)**: 4 data-detectable triggers (payroll stops → low-income-year education window; new mortgage → basis-ledger start; escrow inflow → §121 education; marketplace premiums → FLAG-22 activation) + small annual interview battery (marriage/divorce, birth/adoption, job change, inheritance → step-up documentation prompt, Medicare enrollment). Full 21-trigger engine → FUTURE (**LIFE-01**) | LOW | Reuses IncomeDetectorService / HousingDetectionService / SubscriptionDetectorService signals |
| M11 — Student / young-worker planner | EXP M11; PB-v2Δ 2B.6.3 | persona | FUTURE (**PERS-02**) — cheap items already land elsewhere: student-loan interest (FLAG-11 tuition sweep), W-4 setup (FLAG-03 framing), AOTC (FLAG-23) | LOW | EXP superset canonical |
| M4 / §2B.3 — Travel-worker tax-home engine (G/Y/R classification; 12-month hinge; itinerant rule) | EXP M4; PB-v2Δ 2B.3 | persona | FUTURE (**PERS-01**) — classification-only output; never assert stipend taxability | HIGH | "Bad advice here can blow people up" — owner's Conditional band |
| M6 — Small-business benefit design (employer side; §127 $5,250) | EXP M6 | persona | FUTURE (**BIZ-01**); §127 constant → TAX-08 now | LOW/MED | Overlaps PB-v1 BO-6 (see §8) |
| M8 — Multi-state residency / domicile evidence file | EXP M8; TD-v2Δ §7 | persona | STATE-01 / FUTURE — RESOLVED: the domicile evidence checklist STAYS OUT until STATE-01; nothing state-residency-touching ships in v2.1 [default ruling — owner review pending] | HIGH | Do NOT build in P11/P12 |
| M12 / §2B.6.5 — Immigrant/expat/nonresident specialist router | EXP M12; PB-v2Δ 2B.6.5 | persona | FUTURE (**PERS-03**) — warnings + referral ONLY, never auto-recommend | HIGH | EXP canonical |
| M13 / §2B.6.4 — Clergy module (housing allowance, SECA, Form 4361) | EXP M13; PB-v2Δ 2B.6.4 | persona | FUTURE (**PERS-04**) question-and-refer only; FLAG-17 occupation gate may route clergy → specialist referral (no strategy content in v2.1). "Start a ministry" structures → SAFE-06 HARD-BLOCK | HIGH | Delta adds Form 4361 + ministry hard-block |
| M14 / §2B.6.2 — Caregiver/disability module (+multiple-support agreements) | EXP M14; PB-v2Δ 2B.6.2 | persona | FUTURE (**PERS-05**); medical-transaction triggers REGISTERED NOW in FLAG-10 medical detector seed table; ABLE/dependent-care items covered by FLAG-23 | LOW/MED | Delta adds multiple-support agreements to the merge |

## 7. Real Estate (PB-v1 §4)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| RE-1 STR loophole | PB-v1 | probe | **FLAG-17** question-only, specialist-referral output; hour log = doc checklist (DOC-06) | HIGH | — |
| RE-2 Cost segregation + bonus | PB-v1 | education | **RPT-07** specialist-referral education ALWAYS | HIGH | — |
| RE-3 REPS | PB-v1 | probe | **FLAG-17** question-only + RPT-07 referral | HIGH | — |
| RE-4 1031 exchange | PB-v1 | education | **RPT-07** (45/180-day facts) | MED | — |
| RE-5 Buy, borrow, die | PB-v1 | strategy | **BLOCKED** — strategy stays blocked; the stepped-up-basis glossary LINE in RPT-07 is APPROVED [default ruling — owner review pending] | BLOCKED | Glossary line only; no leverage/estate strategy content |
| RE-6 Opportunity Zones 2.0 + pre-2027 QOF mandatory recognition end-2026 | PB-v1 | alarm | **FLAG-16** time-critical detector ("loss harvesting or re-deferral is the pro's call, not ours") + RPT-07 education | MED | — |
| RE-7 §121 exclusion + basis-building tracker | PB-v1 | probe/store | **STORE-02** + FLAG-17/RPT-07 education (partial-exclusion facts) | LOW | — |
| RE-8 Masters 14-day rule | PB-v1 | detector | **FLAG-10** standalone detector (see §3) | LOW | FACT-CHECK missing item — dispositioned |

## 8. Business Owners (PB-v1 §5)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| SH-0 Hobby-vs-business + commingling | PB-v1 | detector | **FLAG-14** + **FLAG-15** | LOW/MED | Owner-specified feature; wording locked (see §4) |
| SH-1 Home office (+8A commuting-conversion bonus) | PB-v1 | probe | Existing FLAG-05 + TAX-08 (simplified $5/sqft, $1,500 cap) | MED | Commuting-conversion note ships as FLAG-05 educational content |
| SH-2 Vehicle/mileage | PB-v1 | probe | Existing FLAG-05 + TAX-08 (72.5¢); log feature → STORE-04 FUTURE | MED | 100% business use never encouraged (FLAG-15 input) |
| SH-3 §179/bonus equipment | PB-v1 | probe | Existing FLAG-05 (electronics) + TAX-08 | LOW | — |
| SH-4 Ordinary expense cluster + 50% meals | PB-v1 | probe | Existing FLAG-05 | LOW | Education skill-maintenance-vs-new-trade distinction in question text |
| SH-5 SE health insurance | PB-v1 | probe | FLAG-11 insurance sweep + FLAG-12 retro + FLAG-17 | LOW | — |
| SH-6 Estimated-tax safe harbor / quarterly scheduler | PB-v1 | scheduler | **FLAG-18 (new, P11) — REFRAMED:** output is a "safe-harbor benchmark", never "your estimated taxes". All arithmetic uses ONLY user-supplied or vault-extracted prior-year liability (1040 line 22) + detected IRS payment outflows; detected business inflows are a surfacing TRIGGER only, never a computation input. Boundary wording APPROVED as reframed [default ruling — owner review pending] | MED | Owner-specified feature; prior-year-liability arithmetic only |
| SH-7 Solo 401(k) | PB-v1 | probe | **FLAG-17** (Schedule-C-proxy net > $10K; employee gate mandatory) + TAX-08 | LOW/MED | — |
| SH-8 QBI | PB-v1 | engine | Shipped TAX-06; above-threshold W-2-wage/UBIA test — RESOLVED: professional-review sentinel RETAINED for v2.1, no implementation [default ruling — owner review pending]; compliant re-deferral under the specialist band | LOW | — |
| SH-9 Hire your kids | PB-v1 | probe | **FLAG-17** question-only + DOC-06 checklist (timesheets, W-2s) | MED | Never "hire your kids to save $X" |
| SH-10 Augusta rule | PB-v1 | probe | **FLAG-17** entity-gated question-only + DOC-06 checklist (comps, minutes, invoices, ≤14 days) | MED/HIGH | — |
| SH-11 Entity decision tree | PB-v1 | probe/education | **FLAG-17** question (net > $50K) + **RPT-07** module — REFRAMED: 60-month-lock warning surfaces BEFORE any classification content; "commonly considered at this level" is the ceiling; trade-offs stated (reasonable comp, $1.5–3K/yr cost, QBI interaction). Recommendation form BLOCKED | MED/HIGH | See Blocked §16 |
| SH-12 PTET | PB-v1 | strategy | STATE-01 (recorded, not built) | — | — |
| SH-13 Accountable plan | PB-v1 | probe | **FLAG-17** entity-gated + FLAG-09 guard #4 | LOW | — |
| BO-1 Cash balance / DB plan | PB-v1 | probe | **FLAG-17** (age > 45 + profit > $300K) specialist-referral output + TAX-08 | MED | — |
| BO-2 Depreciation/capex stack + heavy vehicles | PB-v1 | education | **RPT-07** + TAX-08; GVWR branch in FLAG-10 | LOW/MED | — |
| BO-3 §174A R&D amend-back | PB-v1 | probe | **FLAG-17** question + specialist referral | MED | — |
| BO-4 §41 R&D credit | PB-v1 | probe | **FLAG-17** with mandatory credit-mill audit warning | MED | — |
| BO-5 QSBS §1202 | PB-v1 | alarm | **FLAG-16** early-eligibility flag at C-corp formation (paired with §1244 note) + specialist ALWAYS; caps → TAX-08 | HIGH | Trust stacking = specialist territory, education only |
| BO-6 Compensation & fringe stack (QSEHRA/ICHRA, group health, employ spouse, FLPs, S-corp family education) | PB-v1 | education | **RPT-07** modules (FLP = specialist referral); employer-side design engine → FUTURE (BIZ-01/EXP-M6) | LOW/MED | FACT-CHECK missing item — dispositioned |
| BO-7 Employer child-care credit ($500K/$600K) | PB-v1 | education | **RPT-07** + TAX-08 constant | LOW | FACT-CHECK missing constant — added to TAX-08 table |
| BO-8 WOTC | PB-v1 | education | **RPT-07** (Form 8850 screening question) | LOW | — |
| BO-9 Worker classification review | PB-v1 | sweep | **FLAG-11** — pure warn-and-educate, never "reclassify them" | MED | — |
| BO-10 Timing & method (12-month prepay, de minimis, corporate charitable) | PB-v1 | education | **RPT-07** + TAX-08 ($2,500 de minimis) | LOW | — |
| BO-11 Exit planning menu | PB-v1 | education | **RPT-07** specialist-referral ALWAYS | HIGH | — |

## 9. Investors & Crypto (PB-v1 §6/§6A) — RIA collision zone

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| IV-1 Asset location | PB-v1 | strategy | **BLOCKED** (RIA) — strategy stays blocked; the account-type-TAXATION facts reframe (how account types are taxed; no asset classes named, no placement advice) is APPROVED for RPT-07 [default ruling — owner review pending] | BLOCKED | Educational account-taxation facts only |
| IV-2 Direct indexing | PB-v1 | strategy | **BLOCKED — permanent** (no reframe preserves value) | BLOCKED | — |
| IV-3 Muni/Treasury selection | PB-v1 | strategy | **BLOCKED** for v2.1 (RIA + STATE-01) | BLOCKED | — |
| IV-4 NIIT education | PB-v1 | education | **RPT-07** + TAX-08 | MED | — |
| IV-5 Qualified-dividend 61-day fact | PB-v1 | education | **RPT-07** glossary | LOW | — |
| IV-6 / CR-6 Gift appreciated assets to kids | PB-v1 | strategy | REFRAMED: gifting-mechanics + kiddie-tax education only (RPT-07; $19K exclusion + under-24 student rule from TAX-08). "To realize gains at 0%" clause **BLOCKED** | MED | — |
| IV-7 Estate/gifting glossary | PB-v1 | education | **RPT-07** (superfunding, exclusions; trusts = specialist referral) + TAX-08 | MED | Upstream gifting (IV-8) EXPLICITLY EXCLUDED from this umbrella |
| IV-8 Upstream gifting | PB-v1 | strategy | **BLOCKED / OWNER-GATED** — must not ride into RPT-07 | BLOCKED | — |
| CR-0 Crypto basis reconciliation | PB-v1 §6A | infra | FUTURE (**CRYP-01**); the "basis records matter" warning is FLAG-19 material | MED | — |
| CR-1 Crypto in Roth solo 401(k)/SDIRA | PB-v1 | strategy | **BLOCKED** — strategy stays blocked; the *McNulty*/prohibited-transaction/UBTI WARNING form is APPROVED [default ruling — owner review pending] but ships only whenever FLAG-19 is promoted (FLAG-19 itself kept Future per default ruling) | BLOCKED | Warning-only form; never account/trading advice |
| CR-2 Drawdown-triggered Roth-conversion alerts | PB-v1 | detector | **BLOCKED** — permitted substitute = income-drop trigger (FLAG-17), keyed off income never asset prices | BLOCKED | — |
| CR-3 §1256 crypto futures comparison | PB-v1 | strategy | **BLOCKED** for v2.1 | BLOCKED | — |
| CR-4 Crypto sell/rebuy loss harvesting | PB-v1 | strategy | **BLOCKED / OWNER-GATED** — glossary-fact form w/ legislation-risk tag, FLAG-19 future at most | BLOCKED | — |
| CR-5 Specific-ID / HIFO education | PB-v1 | education | FLAG-19 FUTURE (accounting-method education) | MED | Owner-gated with the crypto package |
| CR-7 Donate appreciated crypto (>$5K appraisal, CCA 202302012) | PB-v1 | probe | FLAG-19 FUTURE (question + appraisal checklist) | MED | Parked with crypto package pending owner promotion |
| CR-8 OZ rollover of crypto gains | PB-v1 | education | RPT-07 QOF education covers the 180-day fact; crypto-specific framing → FLAG-19 | MED | — |
| CR-9 Borrow against crypto | PB-v1 | strategy | **BLOCKED** — only the forced-liquidation-=-deemed-sale warning survives (FLAG-19, future) | BLOCKED | — |
| CR-10 Mining/staking as business | PB-v1 | probe | FLAG-19 FUTURE — warning REFRAMED: "inflows like these are generally treated as ordinary income at FMV on receipt (Rev. Rul. 2023-14) — this may apply to you; a professional could confirm" | MED | — |
| CR-11 Puerto Rico Act 60 | PB-v1 | strategy | **BLOCKED** for v2.1 (STATE-01 + specialist; owner-gated mention at most) | BLOCKED | — |
| CR-12 PPLI + offshore wrappers | PB-v1 | compliance | **SAFE-06** — never auto-recommend; hard-block adjacent pitches (offshore crypto-IRA, FBAR/FATCA concealment) | BLOCKED | — |
| Malta pension / foreign trusts (Dirty Dozen) | PB-v1 §8 risk table (explicit P13 HARD-BLOCK row) | compliance | **SAFE-06** HARD-BLOCK (P13) — named on the refusal list; detect, refuse-and-educate, never monetize | BLOCKED | Distinct from PERS-03's foreign-pension/foreign-trust compliance WARNING surface (which is a referral warning, not this hard-block); also distinct from the generic "offshore structures / FBAR-FATCA concealment" entry |
| Crypto protective-warnings package (disposal events, wallet-transfer basis continuity, staking income, wallet-by-wallet records, donation appraisal, HIFO education) | PB-v1 §6A warnings; TD-v1 §9 | detector | FUTURE (**FLAG-19**) — KEPT FUTURE: detection spec's parking wins over playbook's promotion [default ruling — owner review pending] | MED | All warnings carry general-law + may-framing reframes |

## 10. Nonprofits (PB-v1 §7)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| NP-0 Nonprofit-as-personal-shelter | PB-v1 | compliance | **SAFE-06** HARD-BLOCK + §4958 education | BLOCKED | — |
| NP-1 DAF | PB-v1 | education | **RPT-07** + FLAG-11 charitable sweep | LOW | — |
| NP-2 Donate appreciated stock | PB-v1 | education | **RPT-07** — REFRAMED (mechanics stated, directive stripped, appraisal checklist paired) | LOW/MED | — |
| NP-3 QCDs (70½+, ~$108K) | PB-v1 | probe | **FLAG-17** age-gated probe + TAX-08 | LOW | — |
| NP-4 Private foundation | PB-v1 | education | **RPT-07** referral-framed | MED | — |
| NP-5 CRT | PB-v1 | education | **RPT-07** specialist referral | HIGH | — |
| NP-6 UBIT trap | PB-v1 | education | **RPT-07** protective warning | LOW | — |
| NP-7 Exempt-category routing + entity-confusion catches (Wyoming/Nevada mythology; corporation sole / pure trust) | PB-v1 | education | **RPT-07** routing education + Wyoming/Nevada debunk; corporation sole / pure trust → **SAFE-06** HARD-BLOCK | LOW–BLOCKED | FACT-CHECK missing item — dispositioned |

## 11. Minutiae Library (PB-v1 §8A) — question-only + doc-checklist + defensibility rating + pro-export, per owner engine rule

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| Animals cluster (guard dog, pest cats, foster, pet business, service animal) | PB-v1 8A | probe | **FLAG-10** module content (FLAG-05 pet-probe superset) | MED | Gray-area constraint in FLAG-10 text |
| Medical cluster (diagnosed-condition 🟢 items, medical travel, performer rule) | PB-v1 8A | probe | **FLAG-10** medical module content + TAX-08 ($50/night lodging) | MED | *Hess* → SAFE-06 refuse-material |
| Home daycare exception (time-space) | PB-v1 8A | probe | **FLAG-10** (daycare license → DOC-06) | LOW | — |
| Home office → commuting conversion | PB-v1 8A | education | FLAG-05 content note | LOW/MED | — |
| Per-diem M&IE | PB-v1 8A | education | **FLAG-10** travel module | LOW | — |
| 12-month prepay + de minimis | PB-v1 8A | education | **RPT-07** + TAX-08 | LOW | — |
| Antiques used in trade (*Simon*) | PB-v1 8A | education | **RPT-07** module | MED | — |
| Racing/vehicle sponsorship | PB-v1 8A | probe | **FLAG-10** sponsorship module (gray-area, full doc file, pro routing) | MED/HIGH | — |
| Free product as promotion (*Sullivan*) | PB-v1 8A | education | **RPT-07** module | LOW | — |
| Fuel tax credit (off-road) | PB-v1 8A; TD-v1 §1.5 | probe | **FLAG-10** — gallons log REQUIRED; docs_missing blocks pro_export_ready (FLAG-13) | MED/HIGH | IRS top bogus-claim flag — audit warning paired |
| FICA tip credit (Form 8846) | PB-v1 8A | education | **RPT-07** module | LOW | FACT-CHECK missing — dispositioned |
| Disabled access credit | PB-v1 8A | education | **RPT-07** module | LOW | FACT-CHECK missing — dispositioned |
| §1244 stock | PB-v1 8A | alarm | **FLAG-16** formation-time note (pairs QSBS/83(b)) + TAX-08 | LOW/MED | — |
| §105 HRA + employed spouse | PB-v1 8A | probe | **FLAG-11** insurance sweep routing + FLAG-17 — question-only w/ doc checklist | MED | — |
| Cruise-ship conventions ($2,000) | PB-v1 8A | education | **RPT-07** + TAX-08 | MED | FACT-CHECK missing — dispositioned |
| Mixed business+vacation travel / sandwich days | PB-v1 8A | probe | **FLAG-10** travel module | MED | — |
| Loss probes (bad debt, worthless securities §165(g), Ponzi safe harbor incl. crypto rug pulls, §1341) | PB-v1 8A | probe | **FLAG-17** loss probes + TAX-08 ($3K §1341 threshold) | LOW/MED | — |
| Family/education sleepers (scholarship election, 529→Roth, day camp, adoption/foster/ABLE, jury pay, educator $300, reservist travel, §127) | PB-v1 8A | probe | FLAG-11 (scholarship — narrate carefully) + FLAG-17 (occupation/above-the-line) + FLAG-23 (credits) + TAX-08 | LOW | — |
| FEIE + clergy/combat occupation items | PB-v1 8A | probe | **FLAG-17** — FEIE federal parts ONLY (domicile → STATE-01); clergy routes to specialist referral (module = PERS-04 future) | MED | — |
| AZ state credits | PB-v1 8A | strategy | STATE-01 | — | — |

## 12. Decumulation (PB-v2Δ §2C — entirely new; no EXP overlap except missed-RMD)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| 2C.1 Roth conversion window (vs IRMAA/torpedo) | PB-v2Δ | probe | FUTURE (**PERS-06** decumulation module) | HIGH | Naive convert-to-bracket-top can be wrong by thousands — module-level modeling required |
| 2C.2 Social Security tax torpedo + withdrawal ordering | PB-v2Δ | education | FUTURE (PERS-06) | HIGH | Withdrawal ordering brushes allocation advice — needs careful design |
| 2C.3 IRMAA cliffs (2-yr lookback; SSA-44) | PB-v2Δ | education | FUTURE (PERS-06); breakpoints → config when built | HIGH | — |
| 2C.4 NUA check before 401(k) rollover | PB-v2Δ; TD-v2Δ §12.1 | guard | FUTURE (**GUARD-01** interrupt class) — rollover "initiated" not visible in bank data; signal registered for future | HIGH | "One of the largest irreversible mistakes in the code" |
| 2C.5 QCDs at 70½+ | PB-v2Δ | probe | **FLAG-17** age-gated probe + TAX-08 (~$108K) | LOW | Same as NP-3 — one probe |
| 2C.6 RMD compliance + first-year doubling | PB-v2Δ | sweep | FUTURE (PERS-06 / FLAG-26 extension when retirement data plane exists) | MED | — |
| 2C.7 Widow(er)'s penalty | PB-v2Δ | education | FUTURE (PERS-06) — grief-adjacent UX guard noted for P13 of that milestone | HIGH | — |
| 2C.8 Inherited IRA 10-year rule | PB-v2Δ | education | FUTURE (PERS-06) | MED | — |
| 2C.9 Early-access doors (rule of 55, 72(t), 457(b), Roth basis) | PB-v2Δ | education | FUTURE (PERS-06); 457(b) fact appears in FLAG-21 caveats | MED | — |
| 2C.10 Medicare/HSA trap (6-month Part A lookback) | PB-v2Δ; TD-v2Δ §8.8/§12.6 | detector | **FLAG-26** heuristic warning (age + HSA contributions + Medicare-premium detection) — detect and warn | MED | The one 2C piece cheap/safe enough for v2.1 |

## 13. Additional Personas (PB-v2Δ §2D)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| 2D.1 Sports bettors (90% loss limit, phantom income, session logs, W-2G) | PB-v2Δ; TD-v2Δ §13 | persona | FUTURE (**PERS-07** gambling module); merchant signals seeded in FLAG-10 now; 90% constant → TAX-08 w/ effective dating + SAFE-06 never-surface (never present losses as fully deductible). Wellbeing lens DEFERRED with the module — the wellbeing/product-values call is genuinely the owner's [default ruling — owner review pending] | HIGH | — |
| 2D.2 §475(f) traders (prior-April-15 election) | PB-v2Δ | persona | FUTURE (**PERS-08**) | HIGH | Spot crypto outside §475 flagged unsettled |
| 2D.3 Military (DFAS) | PB-v2Δ | persona | FUTURE (**PERS-09**) | LOW/MED | — |
| 2D.4 Farmers/ranchers (Sch J, §1062, deferrals) | PB-v2Δ | persona | FUTURE (**PERS-10**); Sch J occupation probe already in FLAG-17 | LOW/MED | — |
| 2D.5 Divorcing users (Form 8332, QDRO, §121 timing, alimony) | PB-v2Δ | persona | FUTURE (**PERS-11**) — filing-status adjacency = HIGH; only life-event divorce question ships in v2.1 (FLAG-27) | HIGH | — |
| 2D.6 Household employers (nanny tax, Schedule H) | PB-v2Δ | persona | FUTURE (**PERS-12**); recurring-Zelle-to-caregiver signal cross-links FLAG-11 same-individual sweep + FLAG-11 childcare sweep | MED | State registration piece → STATE-01-adjacent |
| 2D.7 Truckers/DOT 80% meals | PB-v2Δ | persona | FUTURE (**PERS-13**); constant → config when built | LOW | Cross-link, don't merge, with travel-worker persona |
| 2D.8 MFS filing-status optimizer (community-property aware) | PB-v2Δ | persona | **BLOCKED** — collides with locked "asserting filing status" + "build state-aware" hits STATE-01. RESOLVED: ONLY the educational ceiling line ships ("MFS may be worth modeling with your preparer", RPT-07); persona otherwise blocked [default ruling — owner review pending] | BLOCKED | — |

## 14. Year-End Engine (PB-v2Δ §8B ⟂ TD-v2Δ §16 — merged)

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| Full Q4 engine (bracket-trajectory projector, purchase-list interview, ranked timing, cadence Oct 1→Jan 15) | PB-v2Δ 8B; TD-v2Δ §16 | engine | FUTURE (**YEAR-01**) — flagship of a later milestone; depends on paystub + prior-return planes + profile-graph maturity | HIGH | v2.1 lays forward-compat only (rows below) |
| Year-end output-contract fields (deadline, lead_time_days, net_cash_cost, tax_saved, cliff_bonus_value, reversible) | TD-v2Δ §16 | schema | **FLAG-13** additive migration NOW | LOW | Cheap now, expensive later |
| Cliff-proximity constants (ACA, IRMAA-future, QBI/SSTB, tips/OT, 0% LTCG, EITC investment limit, child-credit phaseouts) | PB-v2Δ 8B.6 | config | **TAX-08** | LOW | 0% LTCG constant enters config for education gating; surfacing of the bracket-awareness glossary LINE is approved [default ruling — owner review pending] — never sell/rebuy language |
| Anti-waste hard rules (never recommend spending solely to deduct; tax_saved + net_cash_cost paired; no asserted need → no card) | PB-v2Δ 8B prime directive; TD-v2Δ §16 | guard | **SAFE-06** clause now (prompt/render principle); full render guards ship with YEAR-01 | LOW | Owner says "encode as a hard rule" |
| Dec-31 deadline wall + Jan-15/April items | PB-v2Δ 8B.4 | report | **RPT-08 (new, P12)** time-sensitive report section driven by findings' deadline metadata | LOW | 529 state deductions + PTET items suppressed → STATE-01 |
| December rescue kit / withholding time machine (evenly-deemed-paid) | PB-v2Δ 8B.5; TD-v2Δ §16 | education | FUTURE (YEAR-01) as calculator; the evenly-deemed-paid FACT ships as FLAG-03/FLAG-18 remediation education copy | MED | ⟐ EXP-M15.1–2 dedupe |
| Solar year-end honesty gates | PB-v2Δ 8B.3 | guard | FLAG-10/FLAG-12 reframes + **SAFE-06** never-surface (residential credit 2026+) | HIGH | Top misinformation surface |
| Equipment timing rules (placed-in-service, mid-quarter, listed property, GVWR, §179 income cap) | PB-v2Δ 8B.2; TD-v2Δ §16 | config/education | TAX-08 constants now; card logic → YEAR-01 | MED | — |

## 15. Remaining TD-v2Δ Sections

| Item | Source | Kind | Disposition | Liability | Dedupe / notes |
|---|---|---|---|---|---|
| §11 Prior-year-return ingestion: carryforward tracker (capital-loss/passive/NOL/charitable/FTC/AMT), missed-item diff, method elections on record, depreciation continuation, prior AGI/liability | TD-v2Δ §11 | doc-plane | FUTURE (**DOC-08**) for schedules/8582/carryforward tracker (headline feature of a later milestone). NOW: prior-year liability via existing v2.0 1040 extraction or user-supplied value feeds FLAG-18; method elections when known seed STORE-01 | MED | 1040 already among v2.0 form types |
| §12 Irreversible-moment interrupt guards (8 triggers) | TD-v2Δ §12 | guard | FUTURE (**GUARD-01**) as an alert class. Transaction-inferable subsets already land in v2.1: 83(b) clock (FLAG-16), contractor-financing question (FLAG-10), ACA mid-year (FLAG-22), HSA/Medicare (FLAG-26), 60-month-lock confirmation (FLAG-17). Wording must stay educational; best-effort (not monitoring guarantee) disclaimer → SAFE-06/SAFE-05 | HIGH | No push system — "interrupt" = feed priority + banner for now |
| §13 Gambling module | TD-v2Δ §13 | persona | See §13 row 2D.1 (single canonical row) | HIGH | Dedupe |
| §14 IRS notice-response module | TD-v2Δ §14 | module | FUTURE (**NOTICE-01**) — defensible shape: classify notice + assemble evidence packet + route to licensed pro. Auto-generated rebuttal letters = OWNER/LEGAL decision, treated as blocked until legal review | HIGH | Photo intake reuses vault extraction (P12 pattern) when built |
| §15 Business/liability layer (CPA/EA network, revenue, SOC 2, §7216 consent) | TD-v2Δ §15 | compliance | P13 encodes disclaimer + terminate-at-licensed-human rules (SAFE-06/RPT-05 text); CPA network, SOC 2 program, §7216 consent language = owner business decisions — flag, don't build | HIGH | §7216 consent language needs attorney review before pro-export ships |

---

## Blocked Items (what + why) — none of these may appear in any PLAN.md

| # | Item | Why blocked | Surviving form (if any) |
|---|---|---|---|
| 1 | W2-7 / CR-6 — 0% LTCG gain harvesting ("sell winners, rebuy immediately") | Instructing securities transactions = RIA out-of-scope | Educational bracket-awareness glossary line APPROVED [default ruling — owner review pending]; no detector/question/module may reference selling or rebuying |
| 2 | W2-9 — detector-driven tax-loss-harvesting alerts | Instructing sales = RIA | Glossary facts ($3K offset, wash-sale rule) APPROVED for RPT-07 glossary [default ruling — owner review pending]; never a detector |
| 3 | IV-1 — asset location | Tells users where to hold securities = RIA | Account-type tax-treatment reframe (no asset classes named) APPROVED for RPT-07 [default ruling — owner review pending] |
| 4 | IV-2 — direct indexing | Continuous trading strategy = RIA | None — permanently blocked |
| 5 | IV-3 — muni/Treasury selection | Instrument selection = RIA + state layer = STATE-01 | One-line RPT-07 glossary fact, owner-approved only |
| 6 | IV-6 / CR-6 — "gift to realize gains at 0%" clause | Securities-transaction instruction | Gifting mechanics + kiddie-tax education ships (RPT-07); the realize-gains clause is stripped |
| 7 | IV-8 — upstream gifting | Attorney-required; HIGH | Excluded from estate glossary; mention-with-specialist-referral only if owner approves |
| 8 | CR-1 — crypto inside Roth solo 401(k)/SDIRA as strategy | Account-education vs trading-advice line is thin (playbook's own words) | McNulty/prohibited-transaction/UBTI WARNING form APPROVED [default ruling — owner review pending], but lives inside FLAG-19 which stays FUTURE (per default ruling) |
| 9 | CR-2 — drawdown-triggered Roth-conversion alerts | Market-timing alerts = investment advice | Income-drop trigger (FLAG-17) keyed off INCOME, never asset prices |
| 10 | CR-3 — §1256 crypto futures comparison | Instrument selection = RIA | None for v2.1 |
| 11 | CR-4 — crypto sell/rebuy loss harvesting | Explicit sell/rebuy = RIA + legislation risk | Glossary-fact form w/ legislation-risk tag, owner-gated, FLAG-19 future at most |
| 12 | CR-9 — borrow against crypto | Leverage-against-holdings = RIA-adjacent | Protective "forced liquidation = deemed sale" warning in FLAG-19 (future) |
| 13 | RE-5 — buy, borrow, die | RIA-adjacent leverage + attorney-required estate strategy | Stepped-up-basis glossary line in RPT-07 APPROVED [default ruling — owner review pending]; strategy content stays blocked |
| 14 | CR-11 — Puerto Rico Act 60 | STATE-01 deferral + heavily-audited specialist territory | Mention-with-specialist-referral, owner-gated |
| 15 | W2-5 — ISO AMT crossover modeling | AMT modeling ≈ computing a liability (PTIN territory) | RESOLVED: DO NOT BUILD [default ruling — owner review pending]; only the safe one-liner: "ISO exercises may have AMT implications — consider a professional review before exercising" |
| 16 | SH-11 — entity "Recommendation" form ("you should elect S-corp") | SAFE-01 banned assertive language | Reframed thresholds-to-consider education (FLAG-17/RPT-07), 60-month lock stated first |
| 17 | 2D.8 — MFS filing-status optimizer | "Asserting filing status" locked out-of-scope + "build state-aware" hits STATE-01 | "MFS may be worth modeling with your preparer" educational line ALLOWED; persona otherwise blocked [default ruling — owner review pending] |
| 18 | §14 — auto-generated IRS rebuttal letters | Circular 230 representation/practice territory | Classify + evidence packet + route-to-pro (future NOTICE-01), pending legal review |
| 19 | Abusive-scheme list: 831(b) micro-captives, syndicated conservation easements, offshore structures / FBAR-FATCA concealment, **Malta pension / foreign trusts (Dirty Dozen — PB-v1 §8 explicit P13 HARD-BLOCK row)**, nonprofit-as-personal-shelter (§4958), corporation sole / pure trust, crypto non-reporting, cash structuring, PPLI / offshore-crypto-IRA auto-pitches, *Hess*-style body-mod probes, "start a ministry" structures | Listed transactions / Dirty Dozen / criminal exposure | SAFE-06 refuse-and-educate enforced in code; feeds RPT-06 refusal section (WHAT/WHY at high level only — never HOW the schemes work) |
| 20 | Never-surface-as-available: ended EV credits (post-Sept-2025), residential solar federal credit for 2026+ primary-home installs, gambling losses presented as fully deductible (90% limit from 2026) | Presenting dead/limited provisions as live = direct user harm | Config suppression via TAX-09 status/effective dating; past-window amended-return facts only, date-gated |

---

## Hard Numbers → `config/tax-rules.php` (consolidated; each verified against Rev. Proc. 2025-32 / Notice 2025-67 before entry; all carry TAX-09 effective-dating metadata)

### Detection & engine (config/tax-detection sibling acceptable per spec §0.1/§13)
| Constant | Value | Used by |
|---|---|---|
| Materiality: single-txn auto-classify floor / recurring annual gate / single-txn interrogate | $100 / $500-yr / $1,000 | FLAG-08 |
| Confidence-band cutpoints (auto/conditional/specialist) | TBD by planner (mirror spendifiai.php) | INT-07, FLAG-06 |
| Onboarding retroactive history depth | 12–36 months | FLAG-12 |
| Withholding/estimated gap floor | $500 | FLAG-03 (exists) |
| Durable-fact reconfirm window | 12 months default (`facts.reconfirm_months`) | STORE-01 |
| Amended-return lookback | 3 years | FLAG-12 |

### OBBBA individual items (sunset-aware)
| Constant | Value | Used by |
|---|---|---|
| Tips deduction | $25,000/return; phaseout $150K/$300K; 2025–2028 | FLAG-17 |
| Overtime deduction | $12.5K–$25K; same phaseouts; 2025–2028; W-2 codes TP/TT | FLAG-17 |
| Senior deduction | $6,000 age 65+; MAGI $75K/$150K; 2025–2028 | FLAG-17 |
| Auto-loan interest | $10,000 cap; US-assembled; purchased 2025+; 2025–2028 | FLAG-10/17 |
| SALT cap | $40,000 through 2029; phases toward $10K above ~$500K MAGI | itemize probe |
| Charitable non-itemizer / itemizer floor | $1,000 / $2,000 MFJ; 0.5%-of-AGI floor (2026+) | FLAG-11, RPT-07 |
| Child Tax Credit | $2,200 | FLAG-23 |
| Adoption credit | ~$17K partially refundable | FLAG-23 |
| 529 K-12 limit / 529→Roth lifetime | $20K/yr / $35K (15-yr accounts) | FLAG-17 |
| Trump Account | $5,000/yr; employer $2,500 excluded; $1,000 seed 2025–2028; opens 2026-07-04 | FLAG-17/20 |
| Gambling-loss limit | 90% of losses from 2026 — NEVER surfaced as fully deductible | TAX-09 suppress; PERS-07 future |

### Retirement & benefits
| Constant | Value | Used by |
|---|---|---|
| IRA annual limit — SHARED across Roth + Traditional | $7,500 + $1,100 catch-up (2026) — headroom math MUST use combined Roth+Traditional contributions, never a type label alone (OWN Decision 2, correctness requirement) | TAX-04/TAX-08 headroom; STORE-01 retirement facts |
| 415(c) total DC limit | ~$72,000 | FLAG-20 mega backdoor |
| 457(b)/403(b) separate limits | $24,500 each (~$49K stacked); 3-yr pre-retirement catch-up doubles; governmental no-10%-penalty post-separation | FLAG-21 |
| Solo 401(k) employer share | 20% of net SE earnings | FLAG-17 |
| Cash-balance plan range | $150K–$350K/yr (age 45+) | FLAG-17 |
| §127 education/student-loan assistance | $5,250/yr (permanent) | FLAG-20; BIZ-01 future |
| Employer child-care credit | $500K / $600K small biz | RPT-07 |
| QCD annual limit | ~$108K; age 70½+ | FLAG-17 |
| Saver's Match arrival | 2027 (date-gate Saver's-Credit successor content) | FLAG-23 |
| IRA→HSA QFD | one-time lifetime, up to HSA limit; testing period | FLAG-24 |

### ACA / credits
| Constant | Value | Used by |
|---|---|---|
| ACA cliff | 400% FPL (~$62,600 single / ~$128,600 family-of-4, continental US); enhanced credits expired 2025-12-31; NO repayment cap post-2025 | FLAG-22 |
| EITC investment-income limit | per Rev. Proc. | FLAG-23 |
| AOTC | $2,500 | FLAG-23, FLAG-11 |
| Estimated-tax safe harbor | 100% prior-year / 110% if AGI > $150K | FLAG-18 |

### Business
| Constant | Value | Used by |
|---|---|---|
| §179 limit / phaseout | $2,560,000 / $4,090,000; capped at business income; listed property >50% use; mid-quarter >40% Q4 | FLAG-05/10; YEAR-01 future |
| Heavy-vehicle GVWR threshold | 6,000 lbs | FLAG-10 |
| De minimis safe harbor | $2,500/invoice | RPT-07 |
| §195 startup immediate | $5,000 | FLAG-17 |
| Standard mileage | 72.5¢/mi (2026) | FLAG-05, FLAG-09 |
| Home office simplified | $5/sq ft, $1,500 cap | FLAG-05, FLAG-09 |
| Cruise convention cap | $2,000 | RPT-07 |
| Per-diem M&IE rates | per GSA/IRS tables | FLAG-10 travel |
| Solar on rental MACRS / pool-landscape land improvement | 5-year + bonus / 15-year bonus-eligible | FLAG-10 |
| Guard-dog depreciation | 7-year | FLAG-10 |
| §48E ITC phase-down | construction-start / placed-in-service dates control (pro tier) | FLAG-10 |

### Credits/energy (retro windows — date-gated, past-window facts only)
| Constant | Value | Used by |
|---|---|---|
| §25D residential clean energy | 30%; expenditures through 2025-12-31 | FLAG-12 |
| §25D typical recovery range | $10K–$20K — educational range ONLY, uncertainty-framed | FLAG-12 |
| §30D EV window | purchases pre-Oct-2025 (retro only; never "available") | FLAG-12 |
| §25C window | work pre-2026 (retro only) | FLAG-12 |

### Investor / estate / losses (education gating)
| Constant | Value | Used by |
|---|---|---|
| LTCG 0% bracket top | ~$49K single / ~$98K MFJ (verify) — education gating; bracket-awareness glossary-line surfacing approved [default ruling — owner review pending] | cliff scan; RPT-07 glossary |
| NIIT | 3.8% above $200K/$250K MAGI | RPT-07 |
| Annual gift exclusion | ~$19,000 (verify) | RPT-07 |
| Estate/gift exemption | $15M / $30M (indexed 2027) | RPT-07 |
| QSBS cap / gross-asset test / holding tiers | $15M per taxpayer-issuer (or 10× basis) / $75M / 50-75-100% at 3-4-5 yrs | FLAG-16 |
| §1244 ordinary-loss cap | $50K / $100K MFJ | FLAG-16 |
| Kiddie-tax bound | under 24 if student | RPT-07 |
| §1341 threshold | > $3,000 | FLAG-17 |
| QOF mandatory recognition | end of 2026 (pre-2027 holders) | FLAG-16 |

### Medical / charitable / misc
| Constant | Value | Used by |
|---|---|---|
| Medical AGI floor | 7.5% (§213) | FLAG-10 |
| Medical lodging | $50/person/night | FLAG-10 |
| Charitable acknowledgment threshold / appraisal threshold | $250 / $5,000 non-cash | FLAG-10/11 |
| C-corp charitable | 10% of taxable income; 1% floor (OBBBA) | RPT-07 |
| Student-loan interest deduction | $2,500 | FLAG-11 |
| Educator expense | $300 | FLAG-17 |
| FEIE | ~$130K + housing | FLAG-17 (federal parts only) |
| Augusta / Masters day cap | ≤14 days | FLAG-10/17 |
| Medicare/HSA Part A lookback | 6 months | FLAG-26 |
| [ASSUMED] mandatory Roth catch-up FICA-wage threshold | > $150K prior-year (verify — P13 sign-off gate) | TAX-05 (shipped) |

### Future-module constants (enter config only when their module builds; recorded to prevent loss)
IRMAA breakpoints + 2-yr lookback · SS torpedo phase-in thresholds · RMD ages · §475(f) prior-April-15 · trucker 80% DOT meals · §1062 farmland installment · travel-worker 12-month hinge · year-end cadence dates (Oct 1 / Nov 15 / Dec 1 / Dec 20 / Jan 15).
