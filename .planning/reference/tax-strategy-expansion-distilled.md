# Tax Strategy Expansion — Distilled Planning Reference

**Source:** `/home/spendifi/public_html/tax-strategy-expansion-ideas.md` (owner's expansion on the tax-strategy playbook)
**Siblings:** `.planning/reference/tax-strategy-playbook-distilled.md`, `.planning/reference/transaction-detection-distilled.md`
**Consumers:** Phase 11 (detection + guided interview + AI-feed bridge) and Phase 12 (report + doc intake + nav UI) planners.
**Distilled:** 2026-07-01

## Core Thesis (verbatim intent)

The biggest addition is NOT more "random deductions" — it is **taxpayer personas and life-event engines**. Bank transactions alone miss too much: the product must also ingest **paystubs, W-2s, benefit guides, retirement-plan features, property facts, student-loan data, and state residency facts**. Bank data sees net deposits; it does not see what the employee *failed to elect*.

**Positioning shift (owner-mandated):**
> Not a "deduction finder." A **tax prevention, optimization, documentation, and professional-review engine.**

**Recommendation bands (owner's triage model — maps cleanly to FLAG-06 severity/priority):**

| Band | Contents |
|------|----------|
| **Auto-Recommend** | Low-risk, high-certainty: withholding fixes, benefit reminders, estimated-tax scheduling, documentation reminders |
| **Conditional** | Needs facts confirmed: S-corp analysis, travel-worker tax home, reimbursement strategy, dependent-care optimization |
| **Specialist Review** | Multi-state residency, immigration/expat, clergy, PPLI, large charitable gifts, estate structures, QSBS trusts, aggressive real estate losses |

---

# (a) Product Modules / Engines / Personas

The owner's recommended module list (all 16, verbatim from Implementation Notes):

1. Tax Savings Control Center
2. W-2 Benefit Arbitrage Engine
3. Public-Sector / Nonprofit Employee Engine
4. Travel Worker Tax-Home Engine
5. Employer Reimbursement Packet Generator
6. Small Business Benefit Design Engine
7. HSA Advanced Strategy Engine
8. Remote Worker / Multi-State Residency Engine
9. Life Event Tax Planner
10. Credit Maximization Scanner
11. Student / Young Worker Planner
12. Immigrant / Expat / Nonresident Specialist Router
13. Clergy / Ministry Worker Module
14. Caregiver / Disability Planning Module
15. Tax Penalty Prevention Engine
16. Current-Law Expiration Validator

## M1. Tax Savings Control Center ("The Biggest Missing Product Idea")

Does NOT start with deductions. Starts with the user's **12 "tax surfaces"**:

1. Payroll/paystub
2. Employer benefits
3. Bank/card transactions
4. Brokerage/crypto
5. Real estate/property
6. Business/entity/payroll
7. Family/dependents/education
8. State residency/location
9. Insurance/medical
10. Estate/gifting
11. IRS/state notices
12. Prior-year return

Then the system asks 7 orienting questions across every surface:

1. Where are they overpaying?
2. Where are they underpaying?
3. Where are they missing documentation?
4. Where are they exposed to audit?
5. Where is there a deadline?
6. Where is there an employer benefit they failed to elect?
7. Where is there a professional-review opportunity?

**Builds on:** the Optimize My Income page (UI-02) + `IncomeOptimizationProfile` snapshot — this is the organizing frame for the whole v2.1 surface, not a separate feature.
**Disposition:** Phase 12 framing input for UI-02; full 12-surface coverage is a future-milestone north star.

## M2. W-2 Employee Benefit Arbitrage Engine

For normal employees the biggest opportunity is usually **not deductions** — unreimbursed employee expenses are largely a dead end federally. Route W-2 users toward every available pre-tax/tax-favored employer benefit (13 items, verbatim):

- 401(k) / Roth 401(k)
- Employer match
- Mega backdoor Roth
- HSA / FSA / dependent care FSA
- ESPP
- NQDC
- 457(b)
- 403(b)
- Commuter benefits
- Education assistance
- Student-loan repayment assistance
- Employer-paid insurance
- Accountable-plan reimbursements

**Key product feature: upload paystub + employer benefits guide.**

Question set (7, verbatim):
1. Upload your latest paystub.
2. Upload your employer benefits guide.
3. Do you have access to 401(k), 403(b), 457(b), HSA, FSA, DCFSA, ESPP, NQDC, commuter benefits, or student-loan repayment?
4. Are you contributing enough to get the full match?
5. Are you in a high-deductible health plan?
6. Does your employer allow after-tax 401(k) contributions?
7. Does your employer allow in-plan Roth conversion or in-service rollover?

**Builds on:** FLAG-04 (match-gap detector), TAX-04 (contribution headroom), TAX-05 (Trad-vs-Roth band), DOC-01 (pay stub + benefits statement + 401(k) statement doc types).
**Disposition:** Core of Phase 11 (questions 3-7 → interview probes; match gap → FLAG-04) + Phase 12 (uploads → DOC-01; "employer benefits guide" is a NEW doc category beyond current DOC-01 list — flag for user decision).

## M3. Public-Sector / Nonprofit Employee Engine

Persona: teachers, police, firefighters, government workers, university staff, hospital employees, nonprofit employees. Big lever: **457(b)**, and **stacking 403(b) + 457(b)** for double deferral space.

Rule (verbatim):
```text
IF employer_type IN [school, university, hospital, nonprofit, city, county, state, police, fire]
THEN ask:
  Do you have a 403(b)?
  Do you have a governmental or nonprofit 457(b)?
  Are you maxing one but not the other?
  Are you within 3 years of normal retirement age?   ← 457(b) special catch-up signal
  Do you have pension income projections?
```

**Builds on:** interview skip-logic (INT-04 prerequisite gating), `IncomeOptimizationProfile.answerableFields()`, TAX-04 headroom (needs 457(b)/403(b) limits added to `config/tax-rules.php`).
**Disposition:** Phase 11 candidate (employer_type interview branch); 457(b)/403(b) limits in config are additive — flag for user decision on scope.

## M4. Travel Worker Tax-Home Engine

Persona: travel nurses, pilots, flight attendants, consultants, oil-field workers, truck drivers, construction workers, linemen, traveling salespeople, defense contractors. Owner's note: **"should be its own module because bad advice here can blow people up."**

Questions (7, verbatim):
1. Where is your main place of work?
2. Do you maintain a permanent home elsewhere?
3. Do you duplicate living expenses while traveling?
4. Are assignments expected to last less than one year?
5. Do you return home regularly?
6. Are stipends included in W-2 wages or treated as tax-free reimbursements?
7. Are you an itinerant worker?

**Risk classification output (never "stipends are tax free"):**
- **Green:** clear tax home + duplicated expenses + temporary assignment
- **Yellow:** weak home ties or long assignment
- **Red:** no tax home / itinerant pattern / same area > 1 year

**Builds on:** OptimizationFinding severity model (FLAG-06); educational framing (SAFE-01) is mandatory here.
**Disposition:** Future milestone (Conditional band per owner); risk-classification-only output fits the educational boundary. If pulled into v2.1, must never assert stipend taxability.

## M5. Employer Reimbursement Packet Generator ("Reimbursement Beats Deduction")

Product insight (verbatim):
```text
Do not tell W-2 employees: "Deduct this."
Tell them: "Ask your employer for an accountable-plan reimbursement."
```

Expense examples to detect and route (10, verbatim): field tech tools, sales mileage, home internet for remote employees, business phone, continuing education, uniforms, certifications, travel, client meals, professional dues.

**Deliverable:** an **Employer Reimbursement Request Packet** — receipts, business purpose, dates, mileage logs, accountable-plan explanation.

**Builds on:** transaction categorization + Order/OrderItem receipts + existing PDF export pipeline (dompdf); reframes FLAG-05 deduction probes for W-2 users.
**Disposition:** Phase 11 gets the *routing rule* (W-2 user + employee-expense detection → "ask employer" framing instead of "deduct"); the packet generator itself is future milestone / Phase 12+ flag-for-user-decision.

## M6. Small Business Benefit Design Engine (Employer-Side)

For owners with employees: "can we turn owner/family spending into compliant employer benefits?" (10 items, verbatim):

- Section 127 education assistance / student-loan repayment
- Dependent care assistance
- Accountable plan
- Group-term life
- HSA contributions
- QSEHRA / ICHRA
- Cell-phone policy
- Working-condition fringe benefits
- Employee discounts
- Retirement planning services

**Hard number:** employers may generally exclude up to **$5,250/year** of qualified educational assistance (including certain student-loan payments) if written-plan and nondiscrimination rules are met.

**Builds on:** `UserFinancialProfile.employment_type/business_type`; overlaps playbook §5 (Compensation & fringe).
**Disposition:** Future milestone (business-owner persona depth beyond v2.1's W-2-leaning scope); $5,250 constant belongs in `config/tax-rules.php` when built.

## M7. HSA Advanced Strategy Engine

Adds the lesser-known **IRA-to-HSA qualified funding distribution** (one-time, lifetime) on top of the playbook's HSA-as-stealth-IRA.

Product trigger (all 5, verbatim):
1. User has HSA eligibility
2. User has IRA balance
3. User is cash constrained
4. User has medical expenses or wants to fund HSA
5. User has not used lifetime qualified HSA funding distribution

Output framing (verbatim): "Possible one-time IRA-to-HSA transfer. This does not create an extra deduction, but can move IRA money into a triple-tax-advantaged HSA wrapper. **Testing-period rules apply.**"

**Builds on:** TAX-04 HSA headroom; INT-04 prerequisite gating (IRA-balance question already the model for the backdoor-Roth probe).
**Disposition:** Phase 11 candidate as one gated interview probe + finding; low build cost since the pattern (prereq-gated probe) already exists.

## M8. Remote Worker / Multi-State Residency Engine

Questions (6, verbatim):
1. Where is your employer located?
2. Where do you physically work each day?
3. Did you move states this year?
4. Did you keep your old home, license, voter registration, doctors, bank, church, or business contacts?
5. Did you work temporarily in another state?
6. Does your employer withhold for the correct state?

Not "move to a no-tax state" — build a **domicile evidence file** (10 items, verbatim): lease/deed, driver's license, voter registration, vehicle registration, utility bills, school enrollment, doctor/dentist, church/synagogue/community ties, calendar day count, old-state ties severed.

**CONFLICT FLAG:** State tax optimization is explicitly **Out of Scope for v2.1** (deferred to STATE-01). The domicile *evidence-file/documentation checklist* angle is arguably documentation (not state tax optimization), but this needs an owner call.
**Disposition:** Deferred to STATE-01 / future milestone. Owner is in Specialist Review band for multi-state residency. Do NOT build in Phases 11-12 without explicit approval.

## M9. Life Event Tax Planner

"A lot of tax savings are not occupation-based. They are event-based." All 21 triggers (verbatim):

Marriage · Divorce · Birth/adoption · Child starting college · Child turning 17 / 19 / 24 · New job · Job loss · Sabbatical · RSU vesting year · Moving states · Buying home · Selling home · Starting side hustle · Buying rental · Parent dies · Inheritance · Disability · Retirement · Medicare enrollment · Large crypto/stock gain · Large medical expense · Charitable year

**Worked example:** a job-loss or sabbatical year can unlock: Roth conversions, 0% long-term gain harvesting, ACA subsidy planning, tax-loss harvesting, and estimated-tax changes.

**Builds on:** transaction/income signals already detected (IncomeDetectorService can see job loss/new job; HousingDetectionService sees home purchase); child-age triggers derivable from dependents data.
**Disposition:** Future milestone as a full engine; Phase 11 can cheaply add 2-3 life-event interview questions ("Did you get married/divorced/have a child/change jobs this year?") since they gate many detectors.

## M10. Credit Maximization Scanner (Low/Middle-Income)

"For a working family it can be a bigger ROI than almost anything else." All 10 credits (verbatim):

1. Earned Income Credit
2. Child Tax Credit / Additional Child Tax Credit
3. Dependent care credit
4. American Opportunity Credit
5. Lifetime Learning Credit
6. Saver's Credit / Saver's Match
7. Premium Tax Credit
8. Adoption credit
9. ABLE account eligibility
10. State-level school/charity credits *(state layer → STATE-01, out of scope v2.1)*

**Builds on:** `TaxRulesEngineService` (all phase-outs/thresholds are deterministic config math — perfectly matches the TAX pattern); FLAG detectors.
**Disposition:** Strong Phase 11 candidate for federal credits (deterministic eligibility *surfacing*, never assertion — "may be eligible" framing per SAFE-01); item 10 deferred to STATE-01. Flag for user decision on whether to expand Phase 11 scope or slot as first post-v2.1 item.

## M11. Student / Young Worker Planner

Persona: identify **"first real tax year" users and simplify the flow** — they need to avoid mistakes and start tax-advantaged compounding early, not a full corporate tax engine.

Strategies (9, verbatim):
1. Roth IRA funding from earned income
2. 0% capital gain harvesting
3. Scholarship / 1098-T optimization
4. AOTC planning
5. Student-loan interest
6. Employer student-loan repayment
7. 529-to-Roth rollover tracking
8. First-job W-4 setup
9. Side-hustle Schedule C education

**Builds on:** IncomeDetectorService (low/first income signal), interview persona branch.
**Disposition:** Future milestone as persona; individual items (student-loan interest, W-4 setup) fit Phase 11 detectors cheaply.

## M12. Immigrant / Expat / Nonresident Specialist Router

"High earners and very underserved." **This is NOT something to auto-recommend. It should be a specialist-review funnel.**

Questions (7, verbatim):
1. Are you a U.S. citizen, green-card holder, visa holder, or nonresident?
2. What visa type?
3. How many days were you physically present in the U.S. this year and prior years?
4. Do you have foreign accounts?
5. Do you have foreign pension, crypto exchange, company ownership, or trust interests?
6. Are you eligible for a tax treaty position?
7. Did you move into or out of the U.S. this year?

Warnings to surface (8, verbatim): substantial presence test · dual-status return · treaty tie-breaker · FBAR / FATCA · foreign pension reporting · foreign corporation ownership · foreign trust danger · state domicile after entering/leaving U.S.

**Builds on:** NEW — a "specialist-review" finding disposition (route-out, no recommendation). Overlaps playbook §8A expat/geographic layer.
**Disposition:** Future milestone. High liability; only ever a warning + referral surface. Aligns with owner's Specialist Review band.

## M13. Clergy / Minister / Religious Worker Module

"Niche but powerful and often mishandled." **Needs guardrails because church/ministry structures are often abused.**

Areas (7, verbatim): housing allowance · self-employment tax treatment · accountable reimbursements · parsonage · opt-out rules for SECA, if applicable · charitable/religious worker travel · missionary foreign earned income issues.

**Disposition:** Future milestone, Specialist Review band. Abuse-prone area — any build must be question-and-refer only.

## M14. Caregiver / Disability / Medical-Family Planning Module

Broader than the playbook's medical deductions. **"High-trust area because users are often overwhelmed."**

Transaction/profile triggers (9, verbatim): recurring pharmacy/medical payments · home health aide · assisted living · wheelchair ramps · special school · therapy · dependent adult support · ABLE-eligible family member · disabled spouse or child.

Strategies (8, verbatim):
1. ABLE account
2. Medical expense bunching
3. HSA shoebox reimbursement
4. Dependent care credit for disabled spouse/dependent
5. Special needs trust referral
6. Employer FSA/HSA optimization
7. Home modifications with medical documentation
8. Impairment-related work expenses

**Builds on:** transaction detection (triggers are merchant/category patterns — feeds transaction-detection spec), FLAG-05-style prerequisite-verified probes.
**Disposition:** Future milestone as a module; the medical-transaction triggers should be registered in the transaction-detection spec now so signals accumulate.

## M15. Tax Penalty Prevention Engine ("Forensic Accountant" Layer)

"May be more valuable than exotic strategies." The pitch: **"We stop you from creating tax problems before they exist."**

Prevent (all 16, verbatim):
1. Under-withholding
2. Missed quarterly estimated payments
3. Late S-corp election
4. Late 83(b) election
5. Missed 1099 income
6. 1099-K mismatch
7. 1099-DA crypto mismatch
8. Missed RMD
9. Excess IRA/Roth/HSA contributions
10. Wash sales
11. Passive-loss misclassification
12. Home sale basis loss
13. Business/personal commingling
14. Payroll tax failure
15. State nexus failure *(state layer → STATE-01)*
16. Sales-tax failure *(state layer → STATE-01)*

**Builds on:** FLAG-03 already covers under-withholding (>$500 gap); CTX-03 CrossSourceReviewService already does document-vs-deposit mismatch (covers missed-1099/1099-K style mismatches); excess-contribution checks are TAX-04 headroom inverted (headroom < 0).
**Disposition:** Items 1, 5-6, 9 are Phase 11-adjacent (mostly existing REQs or trivial extensions). Items 2-4, 7-8, 10-14 → future milestone. Items 15-16 → STATE-01. The "penalty prevention" *framing* should appear in Phase 12 report sections.

## M16. Current-Law Expiration Validator

Every rule must carry effective-date metadata (schema verbatim):

```json
{
  "rule_id": "work_opportunity_tax_credit",
  "effective_start": "YYYY-MM-DD",
  "effective_end": "YYYY-MM-DD",
  "source_url": "official_source",
  "last_verified": "YYYY-MM-DD",
  "confidence": "verified | needs_review | expired_pending_extension",
  "recommendation_status": "auto | conditional | suppress"
}
```

Fields required: `expires_on`, `effective_start`, `effective_end`, `needs_verification`.

**Builds on:** `config/tax-rules.php` is already year-versioned (TAX-01) — this extends it with per-rule provenance/expiry metadata and a suppress switch.
**Disposition:** Flag for user decision — cheap to add to Phase 11 detector definitions now (each `OptimizationFinding` rule gets the metadata block); retrofitting later is more expensive. Strong liability-protection value (SAFE alignment).

---

# (b) Individual Strategies by Category

Legend — Risk: L(ow)/M(edium)/H(igh). Disposition: P11/P12/P13, FUT (future milestone), STATE-01, ASK (flag for user decision). "PB" = also in base playbook.

## Retirement & Employer Benefits (W-2)

| Strategy | Mechanics / Notes | Prereqs | Risk | Disposition |
|---|---|---|---|---|
| 401(k) / Roth 401(k) contributions | Max pre-tax or Roth deferral | Plan access; headroom (TAX-04) | L | P10 done (headroom) + P11 finding (PB) |
| Employer match capture | Contribute at least to full match | Paystub shows deferral % vs match formula | L | **P11 = FLAG-04** (PB) |
| Mega backdoor Roth | After-tax 401(k) → in-plan Roth conversion or in-service rollover | Plan allows after-tax contribs + conversion (interview Q6-Q7 of M2) | M | P11 gated probe (INT-04 pattern) (PB) |
| HSA / FSA / DCFSA election optimization | Elect and fill pre-tax health accounts | HDHP status (interview Q5); benefits guide | L | P11 probe + P12 doc intake (PB: HSA) |
| ESPP participation | Discounted employer stock purchase | Benefits guide shows ESPP | M (investment-adjacent; educational-only, never allocation advice) | FUT; mention-only |
| NQDC deferral | Nonqualified deferred comp election | High earner; plan access; forfeiture risk | M | FUT, Conditional band |
| 457(b) utilization | Separate deferral limit from 401(k)/403(b) | Governmental/nonprofit employer | L | P11 candidate (M3) |
| 403(b) utilization | Public-sector/nonprofit deferral | Employer type | L | P11 candidate (M3) |
| **403(b) + 457(b) stacking** | Both limits at once — double deferral space | Employer offers both; ask "maxing one but not the other?" | L | P11 candidate; config needs 457/403 limits (ASK) |
| 457(b) pre-retirement catch-up signal | "Within 3 years of normal retirement age" question | Age + plan data; pension projections doc | M | FUT |
| Commuter benefits | Pre-tax transit/parking | Benefits guide | L | P11 probe |
| Education assistance (as employee) | §127 exclusion via employer | Employer plan exists | L | P11 probe |
| Student-loan repayment assistance (employer) | §127 student-loan payment exclusion | Employer plan; student-loan txns detected | L | P11 probe |
| Employer-paid insurance optimization | Use employer-paid coverage vs personal spend | Benefits guide | L | FUT |
| Accountable-plan reimbursement (employee side) | Move employee expenses to employer reimbursement — "reimbursement beats deduction" | W-2 status + detected work expenses (M5 list) | L | P11 routing rule; packet generator FUT/ASK |

## HSA Advanced

| Strategy | Mechanics | Prereqs | Risk | Disposition |
|---|---|---|---|---|
| IRA-to-HSA qualified funding distribution | One-time lifetime transfer; no extra deduction but moves IRA money into triple-tax-advantaged wrapper; **testing-period rules apply** | All 5 M7 triggers; not previously used | M | P11 gated probe candidate |
| HSA shoebox reimbursement | Pay medical out-of-pocket now, reimburse tax-free years later from grown HSA | HSA + saved receipts (vault) | L | FUT (PB: HSA-as-stealth-IRA) |

## Employer-Side Benefit Design (Business Owners with Employees)

All FUT (M6), risk noted:

| Strategy | Hard numbers / notes | Risk |
|---|---|---|
| §127 education assistance / student-loan repayment plan | **Up to $5,250/yr excluded**; written-plan + nondiscrimination rules | L |
| Dependent care assistance program | Employer DCAP | L |
| Accountable plan (owner side) | Formalize reimbursements | L |
| Group-term life | Employer-provided exclusion | L |
| Employer HSA contributions | Through cafeteria plan | L |
| QSEHRA / ICHRA | Health reimbursement arrangements for small employers | M (compliance rules) |
| Cell-phone policy | Working-condition fringe | L |
| Working-condition fringe benefits | General category | L |
| Employee discounts | Qualified employee discount rules | L |
| Retirement planning services | Excludable fringe | L |

## Travel Worker

| Strategy | Mechanics | Risk | Disposition |
|---|---|---|---|
| Tax-home / stipend risk classification | Green/Yellow/Red classification (M4); never assert "stipends are tax free" | **H** ("can blow people up") | FUT, Conditional band; classification-only output |

## Multi-State / Residency (all → STATE-01 / deferred)

| Strategy | Mechanics | Risk | Disposition |
|---|---|---|---|
| Domicile evidence file | 10-item documentation checklist (M8); not "move to no-tax state" | H (Specialist Review band) | STATE-01 / FUT — out of scope v2.1 |
| Correct-state withholding check | "Does your employer withhold for the correct state?" | M | STATE-01 |

## Life-Event Unlocks (job-loss / sabbatical / low-income-year bundle)

| Strategy | Mechanics | Risk | Disposition |
|---|---|---|---|
| Roth conversion in low-income year | Convert while in low bracket | M | FUT (PB overlap: bracket/timing games) |
| 0% long-term gain harvesting | Realize LTCG within 0% bracket | M (investment-timing-adjacent; educational only) | FUT (PB overlap) |
| ACA subsidy (PTC) planning | Manage MAGI for Premium Tax Credit in gap year | M | FUT |
| Tax-loss harvesting | Realize losses (wash-sale interplay → M15 item 10) | M (securities-adjacent) | FUT (PB overlap: Investors) |
| Estimated-tax adjustment on income change | Recompute quarterlies after job loss/gain | L | P11-adjacent (FLAG-03 sibling) |

## Credits (Credit Maximization Scanner — federal items are P11/ASK candidates)

| Credit | Risk | Disposition |
|---|---|---|
| Earned Income Credit | L (surface "may be eligible" only) | P11/ASK |
| Child Tax Credit / Additional CTC | L | P11/ASK |
| Dependent care credit | L | P11/ASK |
| American Opportunity Credit | L | P11/ASK |
| Lifetime Learning Credit | L | P11/ASK |
| Saver's Credit / Saver's Match | L | P11/ASK |
| Premium Tax Credit | M (interacts with ACA enrollment facts) | P11/ASK |
| Adoption credit | L | P11/ASK (life-event trigger: adoption) |
| ABLE account eligibility | L | P11/ASK (also M14) |
| State-level school/charity credits | M | STATE-01 (PB has Arizona example) |

## Students / Young Workers (M11 — persona FUT; cheap items noted)

| Strategy | Notes | Risk | Disposition |
|---|---|---|---|
| Roth IRA funding from earned income | Start compounding early | L | FUT |
| 0% capital gain harvesting (student) | Same mechanic as life-event bundle | M | FUT |
| Scholarship / 1098-T optimization | Election nuances (taxable scholarship vs credit coordination) | M | FUT; 1098-T is a NEW doc type (ASK) |
| AOTC planning | Coordination with 1098-T/529 | L | P11/ASK (with credit scanner) |
| Student-loan interest deduction | Detectable from loan txns | L | P11 detector candidate |
| 529-to-Roth rollover tracking | Track lifetime limit/eligibility clock | L | FUT |
| First-job W-4 setup | Withholding setup guidance | L | P11-adjacent (FLAG-03 framing) |
| Side-hustle Schedule C education | Educational content for first-time 1099 | L | FUT (PB: Side Hustlers section) |

## Immigrant / Expat / Nonresident (all Specialist Review — warnings only, never recommendations)

| Item | Risk | Disposition |
|---|---|---|
| Tax treaty position evaluation | H | FUT, specialist router only |
| Substantial presence / dual-status / treaty tie-breaker awareness | H | FUT, warning surface |
| FBAR / FATCA / foreign pension / foreign corp / foreign trust compliance warnings | H | FUT, warning surface |
| Entry/exit-year state domicile warning | H | FUT/STATE-01 |

## Clergy (all Specialist Review band)

| Strategy | Risk | Disposition |
|---|---|---|
| Housing allowance / parsonage | H (abuse-prone area) | FUT |
| Clergy SE-tax (dual-status) treatment | H | FUT |
| SECA opt-out (if applicable) | H | FUT |
| Clergy accountable reimbursements | M | FUT |
| Charitable/religious worker travel | M | FUT |
| Missionary foreign earned income issues | H | FUT (routes to M12) |

## Caregiver / Disability / Medical

| Strategy | Notes | Risk | Disposition |
|---|---|---|---|
| ABLE account | For disability-eligible family member | L | FUT (also credit scanner item 9) |
| Medical expense bunching | Time expenses over 7.5% AGI floor (PB §213) | L | FUT (PB overlap) |
| HSA shoebox reimbursement | (see HSA Advanced above) | L | FUT |
| Dependent care credit for disabled spouse/dependent | Extension of dependent care credit | L | P11/ASK (with credit scanner) |
| Special needs trust referral | **Referral only** — never structure advice | L (as referral) | FUT, Specialist Review |
| Employer FSA/HSA optimization (caregiver context) | Same mechanics as M2 | L | P11 probe |
| Home modifications with medical documentation | Ramps etc. as medical expense with docs | M (documentation-dependent) | FUT (PB overlap: Medical minutiae) |
| Impairment-related work expenses | Rare surviving employee-expense deduction | M | FUT |

## Penalty Prevention (M15 — checks, not deductions)

| Check | Risk | Disposition |
|---|---|---|
| Under-withholding | L | **P11 = FLAG-03 (exists)** |
| Missed quarterly estimated payments | L | P11-adjacent / FUT |
| Late S-corp election | M (entity analysis = Conditional band) | FUT (PB: entity decision tree) |
| Late 83(b) election | M (deadline-critical) | FUT |
| Missed 1099 income | L | P10 CTX-03 partially covers; extend FUT |
| 1099-K mismatch | L | CTX-03-adjacent / FUT |
| 1099-DA crypto mismatch | M | FUT (PB: crypto stack) |
| Missed RMD | M | FUT |
| Excess IRA/Roth/HSA contributions | L (TAX-04 headroom inverted) | P11 cheap detector |
| Wash sales | M (securities-adjacent) | FUT (PB: engine warnings) |
| Passive-loss misclassification | M | FUT (PB: real estate) |
| Home sale basis loss | M | FUT |
| Business/personal commingling | L | FUT (PB: bright line; txn detection has signals) |
| Payroll tax failure | M | FUT |
| State nexus failure | M | STATE-01 |
| Sales-tax failure | M | STATE-01 |

---

# (c) Data-Ingestion & Document-Request Implications

## New data sources the doc demands (beyond bank/email)

1. **Paystubs** — deferral %, match, pre-tax elections, withholding (DOC-01 has "check/pay stub" already)
2. **W-2s** — v2.0 vault already extracts
3. **Employer benefits guides** — NEW doc category (not in DOC-01 list) — the single highest-leverage upload per M2 (ASK)
4. **Retirement-plan features/documents** — 401(k)/403(b)/457(b) statements (DOC-01 has "401(k)/retirement statement") + plan-feature facts (after-tax allowed? in-plan Roth? in-service rollover?) which come from SPD/benefits guide or interview
5. **Pension income projections** — public-sector persona (NEW, ASK)
6. **Property facts** — deeds, purchase docs (playbook real-estate overlap)
7. **Student-loan data** — servicer statements, interest paid (1098-E implied), employer repayment programs
8. **State residency facts** — domicile evidence file items (lease/deed, DL, voter reg, vehicle reg, utilities, school enrollment, day counts) — STATE-01
9. **1098-T / scholarship data** — student persona (ASK)
10. **Brokerage/crypto statements** — Control Center surface 4 (DOC-01 has "stock statement")
11. **Insurance/medical statements** — Control Center surface 9 (DOC-01 has "insurance statement")
12. **Prior-year return** — Control Center surface 12 (v2.0 vault handles 1040)
13. **IRS/state notices** — Control Center surface 11 (NEW category, ASK)
14. **Immigration/presence data** — visa type, day counts (interview-only, no doc type needed yet)
15. **Receipts + mileage logs** — for Employer Reimbursement Request Packet (email receipts pipeline already captures some)

## Concrete document-request prompts (verbatim from doc)

- "Upload your latest paystub."
- "Upload your employer benefits guide."
- Pension income projections (public-sector rule)
- Domicile evidence file items (10-item list, M8)
- Reimbursement packet inputs: receipts, business purpose, dates, mileage logs

## Rule-metadata ingestion requirement

Every detector/rule shipped in Phase 11 should carry the M16 metadata block (`effective_start`, `effective_end`, `source_url`, `last_verified`, `confidence`, `recommendation_status`) so expired law can be auto-suppressed. Cheap now, expensive to retrofit.

---

# (d) Cross-References to Base Playbook (tax-strategy-playbook-2026.md / its distilled sibling)

| Expansion item | Playbook overlap | Delta the expansion adds |
|---|---|---|
| W-2 Benefit Arbitrage (M2) | §2 W-2 Employees Tier 1 (401k, HSA, mega backdoor) | Paystub+benefits-guide *ingestion* and "failed to elect" detection — playbook detects from bank/W-2 data; expansion says bank data can't see non-elections |
| Reimbursement Beats Deduction (M5) | §2 (unreimbursed employee expenses dead end) | The reframe: generate an employer reimbursement packet instead of saying "no deduction" |
| Small Business Benefit Design (M6) | §5 Compensation & fringe | Employer-*side* design engine for owner/family spending; §127 $5,250 |
| HSA Advanced (M7) | §2/§8 HSA-as-stealth-IRA | IRA-to-HSA qualified funding distribution (new) |
| Life-event unlocks (M9) | §2 Tier 2 bracket/timing games; §6 Investors | Systematic 21-trigger event engine vs occupation-based framing |
| Credit scanner state items (M10.10) | §8A state-specific layer (Arizona example) | Same layer — both deferred to STATE-01 |
| Caregiver module (M14) | §8A Medical minutiae (§213, 7.5% floor) | Persona-level module with transaction triggers + ABLE/SNT |
| Penalty Prevention (M15) | §9 The Bright Line (forensic accountant); §6A crypto engine warnings | Elevates it to first-class product with the 16-item prevent list; "forensic accountant layer" naming matches playbook §9 |
| Current-Law Expiration Validator (M16) | §10 product/engine architecture notes; Appendix "verify against Rev. Proc." | Concrete per-rule JSON metadata schema |
| Recommendation bands | §10 engine architecture | Auto / Conditional / Specialist-Review triage — maps to FLAG-06 severity |
| Travel worker (M4), public-sector (M3), clergy (M13), immigrant/expat (M12), students (M11) | Not in playbook | Entirely new personas |
| Tax Savings Control Center (M1) | §10 architecture notes | 12-tax-surfaces framing; positions v2.1 UI |

## Boundary notes for planners (v2.1 guardrails applied)

- Anything state-related in this doc → STATE-01, out of v2.1 (M8, credit item 10, penalty items 15-16, expat state-domicile warning).
- ESPP/NQDC/tax-loss-harvesting/0%-gain-harvesting are investment-*adjacent*: educational timing/participation info only, never allocation advice (RIA boundary).
- Travel-worker, clergy, immigrant/expat, multi-state, SNT → Specialist Review band; output = classification/warning/referral, never recommendation.
- Credit scanner must surface "may be eligible," never "you qualify" (SAFE-01 / locked out-of-scope list).
- No refund/liability computation anywhere (penalty-prevention checks flag *gaps*, not dollar liabilities beyond the existing FLAG-03 $500 withholding-gap threshold).
