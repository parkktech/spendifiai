# Requirements: SpendifiAI — v2.1 Optimize My Income

**Defined:** 2026-07-01
**Core Value:** Help users find every legal edge to maximize take-home income and savings — reviewing all their financial documents + linked email/bank data and interviewing them with only high-value questions and red flags — as **educational suggestions only**, never definitive tax advice.

## v2.1 Requirements

Requirements for this milestone. Each maps to a roadmap phase. All outputs are educational; deterministic math is computed by the rules engine, and Claude is used only to narrate/word findings.

### Tax Rules Engine (TAX)

- [x] **TAX-01**: System stores all 2026 IRS constants (brackets, standard deduction, 401k/IRA/HSA limits, SE tax, Roth phase-outs, QBI thresholds) in a year-versioned `config/tax-rules.php` — the single source of truth
- [x] **TAX-02**: `TaxRulesEngineService` computes marginal + effective federal tax rate from taxable income and filing status, reading only from config (zero Claude calls)
- [x] **TAX-03**: Engine computes standard-vs-itemized comparison and reports which is larger
- [x] **TAX-04**: Engine computes remaining 401(k), IRA, and HSA contribution headroom against annual limits (incl. age-based catch-up)
- [x] **TAX-05**: Engine produces a deterministic Traditional-vs-Roth recommendation band (≤12% → Roth lean, ≥32% → Traditional lean, middle → split), including the SECURE 2.0 mandatory-Roth-catch-up flag for high earners
- [x] **TAX-06**: Engine surfaces QBI deduction eligibility and self-employment-tax deduction where applicable
- [x] **TAX-07**: Rules-engine math is covered by Pest tests asserting exact matches to config values at bracket boundaries
- [x] **TAX-08**: `config/tax-rules.php` is extended (year-versioned, additive) with every constant the v2.1 detectors and education modules read — OBBBA caps with 2025–2028 sunsets (tips, overtime, senior, auto-loan interest), SALT cap + phase-down, 415(c) total DC limit, 457(b)/403(b) limits + catch-up facts, the shared Roth+Traditional IRA annual limit ($7,500 + $1,100 catch-up, 2026) with the correctness note that TaxRulesEngineService IRA-headroom math must consume COMBINED Roth+Traditional contributions (locked owner Decision 2), standard mileage rate, charitable non-itemizer amounts + 0.5% AGI itemizer floor + acknowledgment/appraisal thresholds, §179 limit/phaseout/GVWR/listed-property/mid-quarter rules, §195, de minimis safe harbor, home-office simplified rate/cap, solo-401(k) employer share, cash-balance range, CTC, adoption, AOTC, 529 limits, 529→Roth lifetime, Trump Account figures, gift/estate exclusions, QSBS caps/tiers, §1244 caps, §1341 threshold, kiddie-tax bound, QCD limit, NIIT thresholds, medical AGI floor + lodging rate, student-loan interest cap, educator expense, FEIE, cruise-convention cap, §127 amount, employer child-care credit maxima, estimated-tax safe-harbor percentages, ACA 400%-FPL cliff thresholds, EITC investment-income limit, Saver's-Match arrival date, §25D/§30D/§25C retro windows + educational recovery range, and detection materiality gates + confidence-band cutpoints — each verified against Rev. Proc. 2025-32 / Notice 2025-67 before entry, with effective-date/sunset behavior covered by boundary tests
- [x] **TAX-09**: Every detector, sweep, and probe rule carries per-rule provenance/expiration metadata (`rule_id`, `authority`, `effective_start`, `effective_end`, `phaseouts`, `inflation_adjusted`, `source_url`, `last_verified`, `status`, `band` incl. `suppress`/`hard_block`), and the engine automatically suppresses expired or never-available rules (ended EV credits, residential solar credit for 2026+ primary homes, gambling losses presented as fully deductible) and flags rules whose `last_verified` is stale — adopted for every Phase 11 rule from day one

### Cross-Source Context Engine (CTX)

- [x] **CTX-01**: `IncomeOptimizerDataAssemblerService` assembles a per-user financial snapshot from existing sources (UserFinancialProfile, transactions, income detection, vault-extracted documents) with no Claude calls
- [x] **CTX-02**: The snapshot is persisted in a new `IncomeOptimizationProfile` cache model and rebuilt via a background job
- [x] **CTX-03**: `CrossSourceReviewService` compares documents vs bank deposits vs email data and flags discrepancies deterministically (Claude only explains a detected discrepancy)
- [x] **CTX-04**: The interview and detectors skip anything already answerable from the snapshot ("know what you know before asking")

### Red-Flag Detection (FLAG)

- [x] **FLAG-01**: `RedFlagDetectorService` runs deterministic detectors and produces `OptimizationFinding` records (Claude only writes each flag's description)
- [x] **FLAG-02**: Filing-status mismatch detector surfaces (never asserts) a possible mismatch between stated status and document/expected status
- [x] **FLAG-03**: Tax-withholding detector flags an estimated withholding gap greater than $500
- [x] **FLAG-04**: 401(k) employer-match-gap detector flags unclaimed employer match from pay-stub data
- [x] **FLAG-05**: Deduction-probe detectors (home office, vehicle, electronics, pet, meals) fire ONLY when their data prerequisites are verified from real user data
- [x] **FLAG-06**: Every finding carries a severity/priority so high-value items can be surfaced first
- [ ] **FLAG-07**: Deductible-subscription detection surfaced as optimization findings
- [x] **FLAG-08**: Detectors respect config-driven materiality gates: single transactions under the auto-classify floor generate no questions unless recurring, recurring patterns over the annual gate are interrogated once as a pattern, single transactions over the interrogate threshold and any transaction at a known rental/business address or to a loan servicer are always interrogated — all thresholds ($100/$500/$1,000) live in config, never in service code, verified by tests
- [x] **FLAG-09**: Deterministic method-conflict guards suppress contradictory findings before emission: a standard-mileage election suppresses vehicle actual-expense suggestions (offering an engine-computed annual method comparison instead), a simplified home-office election suppresses actual-expense allocation prompts, §121 planning tracks depreciation recapture from home-office/rental years, and an existing accountable plan routes reimbursable items through the plan rather than Schedule C
- [ ] **FLAG-10**: A merchant-pattern category detector library (seeded knowledge table with aliases, following the CancellationProviderSeeder precedent) covers the detection-spec superset beyond FLAG-05: vehicle/powersports (incl. GVWR §179 branch and auto-loan-interest sub-detector), solar/battery/energy loan servicers, pool/spa, landscaping/hardscape, home-improvement one-tap destination question, animals/security, medical/health with HSA-first routing (incl. diagnosed-condition cluster and registered caregiver/disability triggers), the multi-transaction travel-cluster correlator (incl. per-diem-vs-actual), RV/boat-as-second-home loan detection, the Masters/14-day standalone detector, and gambling merchant signal registration — every gray-area module (sponsorship, guard dog, medical pool, grounds, fuel credit) surfaces ONLY as (1) a question the user answers, (2) a documentation checklist, (3) a static config-sourced defensibility rating, and (4) pro-review-export routing — never as a deduction assertion
- [ ] **FLAG-11**: A monthly scheduled recurring-payee sweep (activity-gated like existing AI jobs) reuses subscription-detection grouping to route recurring payments into modules: same-individual payments → worker-classification questionnaire (warn-and-educate only), childcare → dependent-care credit/FSA (day camp yes, overnight no), tuition/loan servicers → AOTC/LLC/student-loan-interest/§127/scholarship-election (scholarship module flagged narrate-carefully), charitable → bunching/DAF analysis with the reframed appreciated-asset education ("some donors give appreciated holdings…" — mechanics only, no directive; >$5K non-cash always pairs the qualified-appraisal checklist), storage/coworking → business allocation, insurance → SE-health/§105-HRA question-only check
- [ ] **FLAG-12**: Retroactive scanners run at onboarding over 12–36 months of history (alongside BuildIncomeOptimizationProfile, backfilling via plaid:backfill where needed): a missed-credit scan (§25D solar 3-year amended-return recovery presented ONLY as a config-sourced educational range with uncertainty framing — "recoveries have commonly ranged from $A to $B; a tax professional could evaluate whether an amended return applies"; §30D EV strictly date-gated as a past-window fact never presented as currently available; §25C pre-2026), a missed-deduction scan (SE health insurance, unclaimed home office, auto-loan interest 2025+), basis reconstruction of contractor/improvement payments, method-election comparison on actuals, and estimated-tax safe-harbor exposure — all emitting standard OptimizationFinding records
- [x] **FLAG-13**: OptimizationFinding is extended additively to carry the full output contract: `transaction_ids[]`, `treatment`, `legal_basis` + `assumptions` (static config-sourced statutory citations — never Claude output), confidence `band` (cutpoints in config), timestamped `user_assertions[]` (the audit-defense log of user-asserted facts), `docs_captured[]`/`docs_missing[]` linked to Vault records, `estimated_value_cents` (integer cents; the detection spec's `estimated_value` field) written only by TaxRulesEngineService, `pro_export_ready` blocked while required docs are missing (e.g. fuel-credit gallons log), plus year-end forward-compat fields (`deadline`, `lead_time_days`, `net_cash_cost`, `tax_saved`, `cliff_bonus_value`, `reversible`)
- [x] **FLAG-14**: A deterministic commingling monitor flags personal-type spend inside business-purpose accounts (expense_type=personal on AccountPurpose::Business) and educates with locked wording — "business owners commonly keep a separate account for business activity; it is the single most effective record in a hobby-loss review" — warn-and-educate only, never "you qualify as a business"
- [x] **FLAG-15**: A deterministic audit-risk score feeds finding severity from: perpetual Schedule C losses against W-2 income (9-factor hobby-loss score), 100% business vehicle use, round-number patterns, deposit-vs-reported mismatch, outsized charitable deductions / non-cash >$5K without appraisal, home-office+meals+travel disproportionate to revenue, missing 1099s, and mill-flag credits — surfaced with locked protective framing ("returns with patterns like [X] commonly receive additional IRS scrutiny — here is the documentation that typically resolves it"), never as accusations, never implying wrongdoing, never a numeric audit probability
- [x] **FLAG-16**: Time-critical deadline alarms are detected and routed to the feed at highest severity: the 83(b) 30-day window on restricted-stock grant signals, pre-2027 QOF holders' mandatory gain recognition at end of 2026, and a QSBS early-eligibility flag at C-corp formation (paired with a §1244 note) — urgency stated as fact with consider-a-professional framing
- [x] **FLAG-17**: The signal→question→strategy matrix ships as gated income-signal probes (each firing only on verified prerequisites per the FLAG-05 pattern): deferral gap/mega-backdoor plan-feature questions ("if your plan allows" framing), the income-drop Roth-conversion trigger (keyed off payroll/income signals ONLY, never asset values), tips/overtime/senior/auto-loan OBBBA detectors, solo 401(k) on Schedule-C-proxy net > $10K (employee gate mandatory), SE health insurance, dependent care, 529/Trump-Account family probes (529 federal parts ONLY), the QCD age-70½ probe, cash-balance-plan referral (age > 45 + profit > $300K), §174A/§41 R&D questions with the credit-mill audit warning, the entity-analysis question at net > $50K (60-month-lock warning surfaces BEFORE any classification content; "commonly considered at this level" is the ceiling; never a recommendation), Augusta/hire-kids/accountable-plan/§105-HRA entity-gated question-only modules with doc checklists, occupation-gated items (educator, jury-pay-surrendered, reservist travel, FEIE federal parts only, Sch. J; clergy detection routes to specialist referral only), NSO timing education (mechanic stated, directive stripped), and loss probes (bad debt, worthless securities, Ponzi safe harbor, §1341)
- [ ] **FLAG-18**: A quarterly estimated-tax safe-harbor scheduler surfaces a "safe-harbor benchmark" — never "your estimated taxes": "to meet the 100%/110% prior-year safe harbor, total payments of $X would be needed; detected so far: $Y; remaining gap: $Z — a penalty-avoidance benchmark, not your tax bill." All arithmetic uses ONLY the user-supplied or vault-extracted prior-year liability (1040 line 22) and detected IRS payment outflows; detected business inflows are a surfacing trigger only and never an input to any computation. Boundary wording approved as written here [default ruling — owner review pending]
- [x] **FLAG-20**: A W-2 benefit-arbitrage detector surfaces unelected-benefit gaps from interview answers and (when available) extracted paystub/benefits-guide facts: unclaimed employer match, after-tax 401(k) + in-plan-conversion availability (mega-backdoor gate, "if your plan allows"), HSA/FSA/DCFSA gaps, ESPP non-participation (participation education only — "free money" and guaranteed-return language banned; no disposition modeling), NQDC existence (routes to the education module with its employer-credit-risk warning), commuter benefits, §127 education/student-loan assistance, group legal, and employer Trump Account contributions
- [x] **FLAG-21**: A public-sector/nonprofit employer-type branch probes 457(b)/403(b) stacking (separate limits, educational "many public-sector employees can defer into both"), asks the 3-year pre-retirement catch-up question and the pension-income-projection question (EXP M3 Q5 — the answer persists as a durable fact; the pension-projection document type itself is DOC-09, future), and ALWAYS surfaces the non-governmental-457(b) employer-creditor-risk caveat before any 457(b) content — limits in config
- [x] **FLAG-22**: An ACA subsidy-cliff awareness monitor detects marketplace premium payments in bank data, computes cliff proximity purely in the rules engine against config FPL thresholds, and surfaces a mid-year educational warning ("your income trend may be approaching the 400%-FPL threshold where premium credits end — a professional could review options such as pre-tax contributions") — MAGI-management education is sequenced before any Traditional-vs-Roth narration for marketplace enrollees, and the output never presents a computed subsidy or clawback amount as the user's amount
- [x] **FLAG-23**: A federal refundable-credit scanner surfaces "may be eligible" findings (never "you qualify") from deterministic config math: EITC (with the investment-income limit), CTC/ACTC, dependent care credit, AOTC/LLC, Saver's Credit (Saver's Match content date-gated to 2027), Premium Tax Credit, adoption credit, and ABLE eligibility — state-level credits deferred to STATE-01
- [x] **FLAG-24**: A one-time IRA-to-HSA qualified-funding-distribution probe fires only when all prerequisite gates pass (HSA eligibility, IRA balance, cash constraint, medical expenses or funding intent, QFD not previously used) and its wording always includes the testing-period caveat — "a one-time IRA-to-HSA transfer may be possible; it does not create an extra deduction, and testing-period rules apply"
- [x] **FLAG-25**: A reimbursement-beats-deduction routing rule reframes detected employee-type expenses for W-2 users as "consider asking your employer about an accountable-plan reimbursement" instead of deduction framing, while the surviving above-the-line categories (impairment-related work expenses, reservists, performing artists, fee-basis officials) still route as deduction education — the Employer Reimbursement Request Packet generator itself is deferred
- [ ] **FLAG-26**: A penalty-prevention sweep runs continuously over the transaction-observable subset: excess IRA/Roth/HSA contributions (contribution headroom inverted), Roth income-limit breach awareness (recharacterization education), HSA-contributions-near-Medicare heuristic warning (6-month Part A lookback fact), and 1099-K/deposit mismatch surfacing via the cross-source review — warn-and-educate framing only
- [ ] **FLAG-27**: Life-event triggers fire from existing signals — payroll deposits stopping (low-income-year education window), a new mortgage appearing (basis-ledger start), an escrow/title inflow (§121 education), and marketplace premiums (activates FLAG-22) — plus a small annual interview battery (marriage/divorce, birth/adoption, job change, inheritance → step-up-documentation-now prompt, Medicare enrollment) whose answers persist as durable facts
- [x] **FLAG-28**: Profile-vs-reality conformance detectors (locked owner Decision 3, `enhanced-profile-integration-notes.md`) surface mismatches between the user's Enhanced Tax Profile and observed evidence, in BOTH directions, and NEVER assert: (1) stated filing status vs paystub W-4/withholding evidence — this extends FLAG-02 with the paystub as its evidence plane (e.g. profile says head-of-household while withholding evidence suggests married-filing-jointly); (2) stated IRA/HSA facts vs detected bank transfers and payroll deductions (profile says no HSA but the paystub shows an HSA deduction; profile says Roth-only but transactions show Traditional IRA contributions); (3) checkbox facts (rental property, childcare, student loans) vs transaction patterns (rent-income deposits, daycare merchants, loan-servicer payments) — both "profile says X but no evidence" and "evidence says X but profile unchecked"; every mismatch emits an OptimizationFinding plus an educational question in the flow ("Your paystub appears to show X while your profile says Y — want to update your profile or tell us more?") and profile updates only happen through user confirmation, never automatically

### Guided Interview (INT)

- [x] **INT-01**: `InterviewOrchestratorService` runs a persisted `InterviewSession` state machine (Claude used only to word each question)
- [x] **INT-02**: The interview presents one high-value question at a time with skip and back navigation
- [x] **INT-03**: The interview asks only high-signal questions/red flags — it does not re-ask anything derivable from the snapshot, and caps the initial pass to a small number of questions
- [x] **INT-04**: Deduction/eligibility probes are gated behind explicit prerequisite answers (e.g. backdoor-Roth probe gated behind an IRA-balance question to respect the pro-rata rule)
- [x] **INT-05**: A user can resume an in-progress interview and re-answer a prior question
- [x] **INT-06**: Detector questions are batched by merchant pattern, not per transaction: 40 charges at one merchant produce ONE conversation, and the answer is applied retroactively to all matched transactions (recorded in the finding's `transaction_ids[]`), mirroring the existing propagate-to-matching-merchants behavior
- [x] **INT-07**: Confidence bands drive response mode: high-confidence findings PRE-FILL a suggested treatment marked "suggested — confirm" with one-tap confirm/undo (until the user confirms, the treatment is excluded from `user_assertions[]`, excluded from `estimated_value` aggregation, and `pro_export_ready` stays false); medium-confidence produces a one-tap multiple-choice question; low-confidence/high-stakes items open the full module with documentation checklist and pro-review routing — auto-classification without confirmation is permitted only for bookkeeping category (existing ≥0.85 behavior), never for deduction/tax-treatment fields; band cutpoints live in config

### AI Questions Feed Integration (FEED)

- [x] **FEED-01**: A new additive `QuestionType::Optimization` enum case is added without changing existing question behavior
- [x] **FEED-02**: A `SurfaceHighPriorityRedFlags` listener creates standard `AIQuestion` records for high-priority findings so they appear in the existing AI Questions feed
- [x] **FEED-03**: An `UpdateOptimizationFromAnswer` listener on the existing `UserAnsweredQuestion` event feeds answers back into the optimization profile
- [x] **FEED-04**: The existing `UpdateTransactionCategory` listener is guarded to ignore optimization questions, so transaction-categorization behavior and tests are unaffected

### Durable Tax Facts Store (STORE)

- [x] **STORE-01**: An ask-once durable-facts graph persists structured facts that are never re-asked — an append-only `UserTaxFact` store (namespaced fact keys; encrypted values; volatility tiers permanent/stable/annual with a config reconfirm window and one-tap re-confirmation; supersession chain with full provenance: source type/id + timestamps) plus a `TaxProfileEntity` store for vehicles, properties, and business entities (people stay on the existing Dependent/Household models) — new transactions match the graph FIRST, questions fire only on unknowns, answers arrive via the FEED-03 listener, `IncomeOptimizationProfile::answerableFields()` is extended (additively) to consult the store, and the data assembler is extended additively to also read `business_type` and `housing_status` from UserFinancialProfile. **ANCHORING (locked owner Decision 1):** the store EXTENDS the existing Enhanced Tax Profile system (`UserFinancialProfile` + `EnhancedProfileSection.tsx` on Settings) — it is NOT a disconnected parallel store: profile checkbox fields seed facts with `source_type=profile_field`, and every learned fact (interview- or document-sourced) surfaces in the user's Settings for review/correction via an additive UI hook alongside EnhancedProfileSection (additive columns/models + additive UI section only; no changes to existing fields' API shape or EnhancedProfileSection behavior). **RETIREMENT MULTI-ACCOUNT (locked owner Decision 2, correctness requirement):** the store represents simultaneous retirement account types (Roth IRA + Traditional IRA + employer 401(k) types) with per-type contribution amounts where known — the single legacy `ira_type` column stays untouched — because the IRA annual limit is SHARED across Roth+Traditional ($7,500 for 2026, +$1,100 catch-up): TaxRulesEngineService IRA-headroom inputs MUST use combined Roth+Traditional contributions, never a type label alone. **CONFIRMATION GATE (locked owner Decision 4):** facts with `source_type=document_extraction` enter as PROPOSED (with per-field confidence) and never become current, never feed skip-logic, and never supersede user-entered values until the USER CONFIRMS them
- [x] **STORE-02**: A per-property basis ledger accumulates capital improvements (contractor deposits, pool/landscape/solar 2026+, pre-sale work) toward future §121 gain reduction — rebates reduce basis not income, maintenance/chemicals excluded, depreciation-recapture years tracked — fed by detectors, retroactive basis reconstruction, and every "personal" answer on improvement-category spend, with each ledger entry referencing its Vault receipt
- [x] **STORE-03**: An HSA shoebox tracks out-of-pocket medical expenses even when not currently deductible (receipts in the Tax Document Vault + a tracked-expense list on the optimization profile) with accurate education copy: "expenses incurred after your HSA was opened may be reimbursable tax-free in any future year, with receipts" — never implying pre-establishment expenses qualify

### Optimization Report (RPT)

- [x] **RPT-01**: `OptimizationReportGeneratorService` assembles a ranked, sectioned report (deductions / taxes / filings / 401k) where all numbers come from `TaxRulesEngineService` and Claude writes only the narrative prose
- [x] **RPT-02**: The report is generated by a background job and stored in a new `OptimizationReport` model, and marked stale on new document upload or bank sync
- [x] **RPT-03**: The report renders persistent, non-globally-dismissable educational disclaimers on every section
- [x] **RPT-04**: The report is exportable (PDF via the existing dompdf pipeline)
- [x] **RPT-05**: A pro-review export packages a finding for the user's tax professional — fact-pattern narrative, timestamped assertions log, captured documents, static config-sourced legal basis + citations, static defensibility rating (solid / fact-dependent / frequently-abused), and the specific question for the professional — via dompdf + the existing sendToAccountant/TaxPackageMail pattern; the export is blocked while required docs are missing, and every exported PDF carries the persistent educational disclaimer plus "prepared from user-asserted, unverified facts; educational material, not tax advice"
- [x] **RPT-06**: The optimization report adds three wrapper sections around RPT-01's topical content (which becomes the "do now (educational)" section): "documents missing" aggregated from findings' `docs_missing`, "needs professional review" (specialist-band findings), and "what the system refused to recommend and why" rendering the hard-block list and owner-rejected items as a trust feature — describing WHAT was refused and WHY at a high level only, never HOW any blocked scheme works
- [x] **RPT-07**: An educational strategy library ships risk-rated, referral-routed education modules viewable without a detector firing: NQDC (employer-credit-risk warning), ESPP/NSO participation education (reframed wording), 1031 exchange, cost-segregation and STR/REPS specialist referrals, NIIT, qualified-dividend 61-day fact, the approved RIA-reframe glossary lines exactly as the liability screen reframed them — 0% LTCG bracket-awareness line, tax-loss-harvesting glossary facts ($3K offset, wash-sale rule), asset-location account-type-taxation facts (no asset classes named), stepped-up-basis glossary line [default ruling — owner review pending; the fifth approved item, the *McNulty* crypto-in-retirement warning form, stays parked with FLAG-19] — the "MFS may be worth modeling with your preparer" ceiling line (persona otherwise blocked) [default ruling — owner review pending], estate/gifting glossary (annual exclusion, superfunding, kiddie tax; trusts = specialist referral; upstream gifting EXCLUDED pending owner approval), DAF/QCD/private-foundation/CRT alternatives, UBIT trap, WOTC, exempt-category routing + Wyoming/Nevada-LLC mythology debunk, compensation & fringe stack (QSEHRA/ICHRA, group health, employ-spouse; FLPs = specialist referral), employer child-care credit, FICA tip credit, disabled access credit, cruise conventions, antiques-in-trade, free-product-as-promotion, timing-and-method (12-month prepay, de minimis), the exit-planning menu (specialist-referral always), and an avoidance-vs-evasion explainer (PB-v1 §9, verbatim-worthy): avoidance = real transactions under the code as written (*Gregory v. Helvering*); evasion = misrepresented facts (§7201 felonies); plus the killer doctrines that defeat even legal-looking steps — economic substance (§7701(o)), substance-over-form, step transaction, sham transaction — closing with "borderline is not a viable product tier" (this module also feeds Phase 13's framing review as reference copy) — every module with legal basis, doc checklist, static defensibility rating, and consult-a-professional framing
- [x] **RPT-08**: The report includes a time-sensitive section driven by findings' deadline metadata — Dec-31 hard-deadline items and Jan-15/April-window items relevant to the user's detected facts (Roth-conversion window facts, DAF/appreciated-gift transfer lead times, solo-401(k) establishment, FSA spend-down, QCD/RMD, annual-exclusion gifts, placed-in-service) — educational framing only, state-layer items suppressed to STATE-01

### Document Intake (DOC)

- [x] **DOC-01**: New document types (check/pay stub, employer offer letter, 401(k)/retirement statement, benefits statement, stock statement, insurance statement) are added as `TaxDocumentCategory` cases reusing the v2.0 Vault two-pass extraction pipeline
- [x] **DOC-02**: Image-based uploads (screenshots) are extracted via the existing Claude vision pattern (base64, no new library)
- [x] **DOC-03**: New document extraction feeds the optimization snapshot and marks the report stale
- [x] **DOC-05**: During the interview/question flow a finding can proactively request specific documents or screenshots at the moment of detection (photo of a wrapped vehicle, receipt snap, prescription letter, sponsorship agreement, loan docs), and the user can upload to the Vault without leaving the interaction — reusing the v2.0 DocumentRequest + auto-fulfillment infrastructure and updating `docs_captured`/`docs_missing` on the finding
- [x] **DOC-06**: Substantiation document categories are added as TaxDocumentCategory cases beyond DOC-01's financial-statement types: sponsorship agreement, market-comp memo, marketing-output log, physician prescription/recommendation letter, before/after appraisal, gallons log, rescue-org letter, security memo, loan/financing documents, contractor invoices, mileage log, daycare license, and sponsorship vendor-payment evidence — each linkable from a finding's docs checklist
- [x] **DOC-07**: The paystub/benefits data plane ships: "employer benefits guide" is added as a new TaxDocumentCategory with an extraction schema covering plan availability + deferral %, match formula, after-tax 401(k) + in-plan conversion availability, HDHP/HSA status, FSA/DCFSA elections, ESPP terms, NQDC eligibility, §127 benefits, commuter benefits, group legal, and employer Trump Account contributions (the paystub state-withholding-vs-residence cross-check is recorded but its output is suppressed to STATE-01) — **CONFIRMATION GATE (locked owner Decision 4):** extracted facts NEVER write directly to the profile, snapshot, or durable-fact store; extraction produces PROPOSED profile/fact updates with per-field confidence which the USER CONFIRMS before anything is written (user-entered values are never silently overwritten); only confirmed facts land in the snapshot and durable-fact store (with per-fact provenance: source document, extraction date, tax year) so skip-logic suppresses already-answered questions — this is the "AI onboarding" path: a new user's fastest route to a complete Enhanced Tax Profile is uploading a paystub, with upload prompts at onboarding and open-enrollment season

### Feature Surface & UX (UI)

- [x] **UI-01**: An "Optimize My Income" nav item is added to AuthenticatedLayout (with a badge for pending red-flag questions)
- [x] **UI-02**: A dedicated Optimize My Income page presents the flow: findings → guided interview → optimization report
- [x] **UI-03**: Every user-facing optimization surface uses educational modal framing ("may," "could," "consider") and shows an inline disclaimer

### Safety & Liability Boundary (SAFE)

- [ ] **SAFE-01**: All Claude system prompts hard-code educational framing and ban assertive language ("you should," "you must," "you qualify," filing-status assertions)
- [ ] **SAFE-02**: Uploaded-document content is passed to Claude only inside `<document_content>` delimiters with a structured JSON output schema and output validation (prompt-injection defense)
- [ ] **SAFE-03**: Claude never computes tax dollar amounts — a test asserts all report/finding numbers originate from `TaxRulesEngineService`/config
- [ ] **SAFE-04**: Sensitive PII from stubs/offer letters (SSN, wages) follows existing encryption + SSN-last-4 rules; document access stays within the existing audit trail
- [ ] **SAFE-05**: A security + legal hardening pass (prompt-injection penetration test, disclaimer/framing review, SSN-masking audit) is completed before the milestone is considered done
- [ ] **SAFE-06**: A hard-block refusal list is enforced in code (detect, refuse-and-educate, never monetize): 831(b) micro-captives, syndicated conservation easements, offshore structures / FBAR-FATCA concealment, Malta pension arrangements / abusive foreign trusts (Dirty Dozen — PB-v1 §8 P13 HARD-BLOCK row), nonprofit-as-personal-shelter (§4958 education), corporation sole / pure trust packages, "start a ministry" structures, crypto non-reporting, cash structuring, PPLI / offshore-crypto-IRA auto-pitches, and Hess-style body-mod probes (never probed — refuse-and-explain material only) — plus a never-surface-as-available config list (ended EV credits, residential solar federal credit for 2026+ primary homes, gambling losses presented as fully deductible) and the anti-waste principle (no output may present spending solely to create a deduction as savings); any matching user prompt triggers refuse-and-educate, the list feeds the RPT-06 refusal section (what/why only, never how), and guard-style warnings carry best-effort (not monitoring-guarantee) disclaimers
- [ ] **SAFE-07**: The prompt-injection defense explicitly covers every new content path: DOC-02 screenshot/vision extraction (including injection via text inside images), DOC-05 in-flow uploads, DOC-06 substantiation documents, and DOC-07 benefits guides — all passed inside `<document_content>` delimiters with structured JSON output schemas and output validation; and the SAFE-05 framing review enumerates by name every liability-reframed item and every gray-area module wording as testable assertions, pinning SAFE-01 system prompts to the exact reframe phrasings

### Action Center (ACT)

- [ ] **ACT-01**: A lifecycle-adaptive, persistent to-do surface (Dashboard widget + Optimize page; nav badge showing open item count) aggregates every actionable item as a large-checkmark to-do; per-item done-state is persisted in the durable-facts/action store with timestamps
- [ ] **ACT-02**: Stage-0 onboarding items are generated deterministically from connection/profile state for first-run users — ① Link your bank account ② Link your credit cards ③ Link your emails ④ Do the onboarding interview ⑤ Upload a pay stub — each disappears when completed and is replaced by what it unlocks
- [ ] **ACT-03**: Every Action Center item carries a quantified benefit line (deterministic engine arithmetic for short-horizon figures; D9.7 illustration rules with stated config assumptions for long-horizon projections) and a due date where real (bonus election cutoffs, Dec 31 year-end items)
- [ ] **ACT-04**: Checking an item done enters claimed state in the ChangeMonitor's 2-4-week observation window and transitions to verified when the expected change materializes in transaction/deposit data, reusing the SavingsLedger claimed→verified pattern
- [ ] **ACT-05**: The empty-list state renders as an achievement moment ("You're fully optimized for now — we're watching for changes"), not a blank or dead-end view

### Scenarios (SCN)

- [ ] **SCN-01**: `config/optimization-objectives.php` encodes the full fact-requirements map for objectives `take_home`, `tax_burden`, and `retirement`; `ObjectiveReadinessService` computes per-objective readiness (`blocking_missing`, `confirm_needed`, `optional_missing`, `questions_to_unlock`) in two tiers: `known` (sufficient for scenario math) and `confirmed` (required for fact-gated directives)
- [ ] **SCN-02**: `ScenarioFactResolverService` resolves every required fact through a per-fact source-priority chain (fact → snapshot → profile → derive → ask) with alias fallback; a citable `ScenarioFactSet` row (HMAC-SHA256 hash, encrypted `resolved_facts`, GDPR cascade) is persisted via an additive migration at choose-time
- [ ] **SCN-03**: A `POST /optimizer/objectives/{year}/{objective}/enqueue` endpoint front-inserts blocking-missing gap questions into the interview session using deterministic config-driven question templates with typed answer conversion — zero Claude calls in this path
- [ ] **SCN-04**: `TaxRulesEngineService` gains SCN-01 through SCN-07 pure computation methods (W-4 withholding math, FICA/§125 split, match-capture arithmetic, FV-range illustration, MAGI headroom, full-vector outcome, benefit aggregation); the ACA-cliff guard is arithmetic inside `computeScenarioOutcome()` — no emitted scenario can push a marketplace enrollee over the 400%-FPL cliff; the ACA invariant is covered by a 200-baseline property test
- [ ] **SCN-05**: `ScenarioSolverService` runs the six-knob solver (W-4 alignment, trad/Roth 401k split, 401k level vs match formula, HSA election, IRA type/amount within the shared limit, auto-transfer to savings) over all three objectives and attributes per-paycheck and per-year benefit figures entirely from TaxRulesEngineService
- [ ] **SCN-06**: For objectives where knobs diverge the API emits three named options (A=`take_home`, B=`retirement`, `balanced`); when objectives agree a single merged plan is emitted; the frontend renders a side-by-side comparison with knob-diff highlights, trade-off one-liners, and an Illustration badge on long-horizon figures
- [ ] **SCN-07**: Choosing a scenario re-computes server-side, snapshots the `ScenarioFactSet`, persists `scenario.chosen_option` and `scenario.chosen_knobs` in `UserTaxFact`, materializes or re-materializes the checklist, and marks the optimization report stale; re-choosing supersedes the previous choice
- [ ] **SCN-08**: Materialized checklist items render as fact-gated imperatives — confirmed-fact steps show the directive ("Contact your payroll department and change your W-4 filing status to Married Filing Jointly"), unconfirmed-fact steps render as the confirmation ask; every step carries an engine-computed benefit line; the checklist header aggregates total unlocked benefit across all confirmed steps

### Monitors (MON)

- [ ] **MON-01**: `ChangeMonitor` unifies verification watches and change detection in one service: the verification side watches for EXPECTED changes (checklist items checked done → 2-4-week observation window → verified outcome surfaced when the projected change lands in transaction/deposit data, reusing SavingsLedger claimed→verified); the detection side fires on UNEXPECTED changes (income shift ≥2 pay cycles, CrossSourceReview discrepancy, life-event triggers) and creates an `OptimizationFinding` + AIQuestion + DOC-05 document request with educational, benefit-forward copy ("We noticed [specific change] — send an updated [doc] and we'll check whether your [withholding/401k/transfers] are still optimized"); cadence guard ensures one prompt per detected change per freshness window with ≥2 pay-cycle persistence requirement
- [ ] **MON-02**: Predictive calendar watchers extend ChangeMonitor with expected-event scheduling: bonus lead-time alerts (sourced from prior-year pattern, interview fact, or offer-letter extraction) fire with config lead time before the expected payroll cutoff, presenting a bonus scenario set (Option A: 0% deferral/max cash; Option B: max deferral/bracket management; Option C: standing election); year-end window items are gated on the user's confirmed business/personal context and confirmed business type, and every purchase-timing item carries the net-cost honesty statement ("a $10,000 purchase in the 24% bracket saves ~$2,400 in tax and costs ~$7,600 net cash — only if you needed it anyway")

### Design Elevation (ELEV)

- [ ] **ELEV-01**: DESIGN-ELEVATION-SPEC.md Wave 1 is applied: the 41 additive `sw-*` elevation tokens (shadow scale, motion tokens, display type scale, gradient recipes, surface colors) are added to `resources/css/app.css`; `AuthenticatedLayout.tsx` receives the spring-cubic-bezier sidebar transition, premium top header (backdrop-blur, 2-layer shadow, h-14), pill-indicator active nav state, and the `btn-press` / `card-lift` CSS utilities; Wave 1 preservation audit (§6 template) passes
- [ ] **ELEV-02**: DESIGN-ELEVATION-SPEC.md Wave 2 is applied: `StatCard` gains the double-bezel anatomy (outer gradient frame, inner core, value at `text-[28px] font-[800]`, sentence-case label, semantic icon container variants); `SubscriptionCard`, `Badge`, and generic content cards gain `ring-1`/gradient/`shadow-sw-1` treatment and `card-lift` hover; Dashboard stat grid uses `stagger-children`; transaction row hover uses the gradient-fade pattern; Wave 2 preservation + tests audit passes
- [ ] **ELEV-03**: All new Phase-14 UI components (Action Center widget, scenario comparison cards, checklist items, ChangeMonitor doc-request cards) are born to DESIGN-ELEVATION-SPEC canonical recipes (§3.11 scenario/checklist action card, §3.9 premium empty state, §3.10 skeleton) from their initial commit — no retrofitting required

## Future Requirements

Deferred beyond v2.1. Tracked but not in this roadmap.

### Post-Validation Enhancements

- **CTX-05**: Cross-source income anomaly detection (W-2 wages vs deposits) beyond the basic discrepancy scan
- **DOC-04**: Offer-letter benefit-gap analysis (variable-format extraction with its own confidence threshold)
- **STATE-01**: State-level tax optimization and state-specific educational guidance (California TCJA non-conformity, etc.)

### Detector & Store Extensions

- **FLAG-19**: Protective crypto warnings package — KEPT FUTURE: the detection spec's parking wins over the playbook's P11 promotion [default ruling — owner review pending]: crypto-funded purchases flagged as generally-taxable disposal events, wallet-transfer basis-continuity warnings, staking/airdrop/mining inflows ("generally treated as ordinary income at FMV on receipt (Rev. Rul. 2023-14) — this may apply to you; a professional could confirm"), wallet-by-wallet basis-records education, appreciated-crypto donation appraisal checklist (>$5K, CCA 202302012), HIFO/specific-ID accounting-method education — warnings only, no transaction instructions
- **STORE-04**: Contemporaneous log features (mileage log, STR hour log, Augusta day-count + minutes tracker, kids-on-payroll timesheets, off-road gallons log) generating the documentation trail as the year unfolds — v2.1 ships doc-checklist exports only
- **NOTIF-01**: Push notification at the moment of detection — deferred until a notification system exists; v2.1 substitutes the AI Questions feed + UI-01 badge
- **DOC-08**: Prior-year-return plane: extraction coverage for schedules/Form 8582/depreciation schedules, the carryforward tracker (capital-loss/passive/NOL/charitable/FTC/AMT — headline feature), missed-item diff, and method-elections-on-record seeding — v2.1 uses existing 1040 extraction or user-supplied prior-year liability for FLAG-18
- **DOC-09**: Pension-income-projection document type (EXP data-source item 5, flagged "NEW, ASK"; public-sector persona) — extraction schema and vault category deferred; v2.1 ships only the ASK-level interview question inside FLAG-21's EXP-M3 battery, with the answer persisted as a durable fact (ASK-level item resolved by default disposition — owner review pending)
- **CRYP-01**: Crypto basis reconciliation infrastructure (wallet-by-wallet per Rev. Proc. 2024-28)
- **PKT-01**: Employer Reimbursement Request Packet generator (receipts, business purpose, mileage logs, one-page accountable-plan HR explainer) — the FLAG-25 routing rule ships in v2.1; the packet artifact is deferred
- **BIZ-01**: Small-business employer-side benefit design engine (§127 plans, DCAP, QSEHRA/ICHRA, group-term life, fringe policies)

### Engines

- **LIFE-01**: Full 21-trigger life-event tax planner (v2.1 ships the 4 data-detectable triggers + small annual battery via FLAG-27)
- **YEAR-01**: Year-End Q4 Engine — bracket-trajectory projector, purchase-list interview with anti-waste hard rules (tax_saved + net_cash_cost always paired; no asserted need → no card), equipment/placed-in-service gates, cliff-aware December, withholding time-machine rescue kit, Oct 1→Jan 15 cadence — flagship of a later milestone; v2.1 lays FLAG-13 fields + TAX-08 cliff constants + SAFE-06 anti-waste principle
- **GUARD-01**: Irreversible-moment interrupt-class guards (NUA-before-rollover, conversion-in-IRMAA-lookback, sale-crossing-cliff, true interrupts) — v2.1 ships only the transaction-inferable subsets inside FLAG-10/16/17/22/26
- **NOTICE-01**: IRS notice-response module — defensible shape is classify + evidence packet + route to licensed pro; auto-generated rebuttal letters require legal review (Circular 230) and remain blocked until then
- **CTRL-01**: Tax Savings Control Center full 12-tax-surfaces coverage (v2.1: framing input to UI-02 only)

### Persona Packs (all question/classification/warning/referral only — never recommendations)

- **PERS-01**: Travel-worker tax-home engine (green/yellow/red classification; 12-month hinge; itinerant rule; never asserts stipend taxability)
- **PERS-02**: Student/young-worker planner (first-tax-year simplified flow; 1098-T is a new doc type)
- **PERS-03**: Immigrant/expat/nonresident specialist router (warnings + referral only)
- **PERS-04**: Clergy module (housing allowance, SECA, Form 4361; question-and-refer only; "start a ministry" hard-block lives in SAFE-06)
- **PERS-05**: Caregiver/disability module (ABLE, bunching, multiple-support agreements, SNT referral; transaction triggers registered in FLAG-10 now)
- **PERS-06**: Decumulation module ages 55–75+ (Roth window vs IRMAA/torpedo, withdrawal-ordering education, NUA, RMD traps, widow(er) UX-guarded, inherited-IRA, early-access doors)
- **PERS-07**: Gambling module (session log, W-2G reconciliation, phantom-income exposure via rules engine; wellbeing lens = owner decision; merchant signals seeded in FLAG-10 now)
- **PERS-08**: Active-trader §475(f) module · **PERS-09**: Military/DFAS · **PERS-10**: Farmers/ranchers (§1062, Sch J extras) · **PERS-11**: Divorce module (Form 8332, QDRO, §121 timing — filing-status adjacency makes this HIGH) · **PERS-12**: Household-employer nanny-tax module · **PERS-13**: Truckers/DOT 80% meals

## Out of Scope

Explicitly excluded to hold the educational-only boundary and control scope. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Asserting a filing status ("you should file jointly") | Crosses education → advice; regulatory liability (IRS OPR Alert 2026-19) |
| Computing an actual refund/liability amount | Would constitute tax preparation (PTIN territory) |
| Auto-filing or tax-return generation | Requires PTIN; out of product scope |
| Investment allocation / securities advice | Requires RIA registration |
| Guaranteeing specific dollar savings | Use ranges + uncertainty framing; guarantees create liability |
| Gray-area deduction assertions ("your dog IS deductible") | Audit risk for users; maintain a hard block list, surface as questions only |
| State tax optimization (v2.1) | Federal-only for this milestone; deferred to STATE-01 |
| Global "dismiss all disclaimers" toggle | Disclaimers must remain persistent for liability protection |
| Non-Anthropic AI providers | Whole platform is built on Claude; no new provider |
| New Composer/npm packages for the rules engine | Plain PHP bracket math is faster/cleaner; third-party rule engines mismatch static annual constants |
| Detector- or alert-driven securities/crypto transaction instructions (0% LTCG sell/rebuy, tax-loss-harvest alerts, drawdown-triggered conversions, §1256 comparisons, direct indexing, muni/Treasury selection, asset location) | RIA registration; educational reframes exist for some but require explicit owner sign-off before any planner touches them (five glossary/awareness lines approved for RPT-07 under [default ruling — owner review pending]) |
| Borrow-against-holdings strategies (crypto leverage, buy-borrow-die) | RIA-adjacent leverage advice; only the protective forced-liquidation warning may ever ship (future FLAG-19) |
| Upstream gifting (basis step-up via parent) | Attorney-required estate strategy; excluded from the estate glossary unless owner approves a referral-mention |
| AMT crossover modeling (ISO exercises) | ≈ computing a liability amount (PTIN territory); only the safe one-liner ships pending owner decision |
| Entity-election recommendations ("you should elect S-corp") | SAFE-01 banned assertive language; "commonly considered at this level" education with the 60-month-lock warning first is the ceiling |
| Puerto Rico Act 60 residency mechanics | STATE-01 deferral + heavily-audited specialist territory |
| MFS filing-status optimizer beyond "may be worth modeling with your preparer" | Locked filing-status-assertion ban + community-property math requires state awareness (STATE-01); RESOLVED: only the educational ceiling line is allowed, persona otherwise blocked [default ruling — owner review pending] |
| Auto-generated IRS rebuttal/response letters | Circular 230 representation territory; classify + evidence packet + route-to-pro is the only permitted shape (future, after legal review) |
| Abusive-scheme content beyond refuse-and-educate (831(b), easements, offshore concealment, nonprofit shelters, corporation sole/pure trusts, "start a ministry", structuring, PPLI pitches, Hess probes) | Listed transactions / Dirty Dozen / criminal exposure; SAFE-06 enforces in code |
| Surfacing dead or not-yet-effective provisions as available (ended EV credits, residential solar credit 2026+, gambling losses as fully deductible) | Direct user harm; TAX-09 effective-dating suppression |
| Recommending spending solely to create a deduction | A dollar spent saves marginal-rate cents; owner hard rule (SAFE-06 anti-waste principle) |
| Auto-classifying a tax treatment without user confirmation | The engine may pre-fill "suggested — confirm" only (INT-07); auto-classification is reserved for bookkeeping categories |
| Writing document-extracted facts to the profile or durable-fact store without user confirmation | Locked owner Decision 4: extraction produces proposed updates with per-field confidence; the user confirms before anything is written; user-entered values are never silently overwritten |

## Traceability

Each requirement maps to exactly one phase. Phases continue the global numbering from v2.0 (Phases 10-13).

| Requirement | Phase | Status |
|-------------|-------|--------|
| TAX-01 | Phase 10 | Complete |
| TAX-02 | Phase 10 | Complete |
| TAX-03 | Phase 10 | Complete |
| TAX-04 | Phase 10 | Complete |
| TAX-05 | Phase 10 | Complete |
| TAX-06 | Phase 10 | Complete |
| TAX-07 | Phase 10 | Complete |
| CTX-01 | Phase 10 | Complete |
| CTX-02 | Phase 10 | Complete |
| CTX-03 | Phase 10 | Complete |
| CTX-04 | Phase 10 | Complete |
| FLAG-01 | Phase 11 | Complete |
| FLAG-02 | Phase 11 | Complete |
| FLAG-03 | Phase 11 | Complete |
| FLAG-04 | Phase 11 | Complete |
| FLAG-05 | Phase 11 | Complete |
| FLAG-06 | Phase 11 | Complete |
| INT-01 | Phase 11 | Complete |
| INT-02 | Phase 11 | Complete |
| INT-03 | Phase 11 | Complete |
| INT-04 | Phase 11 | Complete |
| INT-05 | Phase 11 | Complete |
| FEED-01 | Phase 11 | Complete |
| FEED-02 | Phase 11 | Complete |
| FEED-03 | Phase 11 | Complete |
| FEED-04 | Phase 11 | Complete |
| RPT-01 | Phase 12 | Complete |
| RPT-02 | Phase 12 | Complete |
| RPT-03 | Phase 12 | Complete |
| RPT-04 | Phase 12 | Complete |
| DOC-01 | Phase 12 | Complete |
| DOC-02 | Phase 12 | Complete |
| DOC-03 | Phase 12 | Complete |
| UI-01 | Phase 12 | Complete |
| UI-02 | Phase 12 | Complete |
| UI-03 | Phase 12 | Complete |
| SAFE-01 | Phase 13 | Pending |
| SAFE-02 | Phase 13 | Pending |
| SAFE-03 | Phase 13 | Pending |
| SAFE-04 | Phase 13 | Pending |
| SAFE-05 | Phase 13 | Pending |
| FLAG-07 | Phase 11 | Pending |
| TAX-08 | Phase 11 | Complete |
| TAX-09 | Phase 11 | Complete |
| FLAG-08 | Phase 11 | Complete |
| FLAG-09 | Phase 11 | Complete |
| FLAG-10 | Phase 11 | Pending |
| FLAG-11 | Phase 11 | Pending |
| FLAG-12 | Phase 11 | Pending |
| FLAG-13 | Phase 11 | Complete |
| FLAG-14 | Phase 11 | Complete |
| FLAG-15 | Phase 11 | Complete |
| FLAG-16 | Phase 11 | Complete |
| FLAG-17 | Phase 11 | Complete |
| FLAG-18 | Phase 11 | Pending |
| FLAG-20 | Phase 11 | Complete |
| FLAG-21 | Phase 11 | Complete |
| FLAG-22 | Phase 11 | Complete |
| FLAG-23 | Phase 11 | Complete |
| FLAG-24 | Phase 11 | Complete |
| FLAG-25 | Phase 11 | Complete |
| FLAG-26 | Phase 11 | Pending |
| FLAG-27 | Phase 11 | Pending |
| FLAG-28 | Phase 11 | Complete |
| INT-06 | Phase 11 | Complete |
| INT-07 | Phase 11 | Complete |
| STORE-01 | Phase 11 | Complete |
| STORE-02 | Phase 11 | Complete |
| STORE-03 | Phase 12 | Complete |
| RPT-05 | Phase 12 | Complete |
| RPT-06 | Phase 12 | Complete |
| RPT-07 | Phase 12 | Complete |
| RPT-08 | Phase 12 | Complete |
| DOC-05 | Phase 12 | Complete |
| DOC-06 | Phase 12 | Complete |
| DOC-07 | Phase 12 | Complete |
| SAFE-06 | Phase 13 | Pending |
| SAFE-07 | Phase 13 | Pending |
| ACT-01 | Phase 14 | Pending |
| ACT-02 | Phase 14 | Pending |
| ACT-03 | Phase 14 | Pending |
| ACT-04 | Phase 14 | Pending |
| ACT-05 | Phase 14 | Pending |
| SCN-01 | Phase 14 | Pending |
| SCN-02 | Phase 14 | Pending |
| SCN-03 | Phase 14 | Pending |
| SCN-04 | Phase 14 | Pending |
| SCN-05 | Phase 14 | Pending |
| SCN-06 | Phase 14 | Pending |
| SCN-07 | Phase 14 | Pending |
| SCN-08 | Phase 14 | Pending |
| MON-01 | Phase 14 | Pending |
| MON-02 | Phase 14 | Pending |
| ELEV-01 | Phase 14 | Pending |
| ELEV-02 | Phase 14 | Pending |
| ELEV-03 | Phase 14 | Pending |

**Coverage:**

- v2.1 requirements: 96 total (78 existing + 18 new for Phase 14)
- Mapped to phases: 96 / 96 (100%)
- Unmapped: 0

**Per-phase counts:**

- Phase 10 — Foundation (Tax Rules Engine & Cross-Source Snapshot): 11 (TAX ×7, CTX ×4)
- Phase 11 — Detection, Interview & AI Feed Integration: 42 (FLAG ×27, INT ×7, FEED ×4, TAX ×2, STORE ×2)
- Phase 12 — Report, Document Intake & Feature Surface: 18 (RPT ×8, DOC ×6, UI ×3, STORE ×1)
- Phase 13 — Safety, Validation & Hardening: 7 (SAFE ×7)
- Phase 14 — Action Center, Scenarios & Design Elevation: 18 (ACT ×5, SCN ×8, MON ×2, ELEV ×3)

---
*Requirements defined: 2026-07-01*
*Last updated: 2026-07-02 after Phase 14 definition (Action Center, Scenarios, Monitors, Design Elevation)*
