# Transaction Detection & Interrogation Engine — Product Spec
### Companion to tax-strategy-playbook-2026.md

---

## 1. Core Architecture

**Principle: a transaction is a hypothesis generator, not an answer.**
Every enriched transaction produces a set of candidate tax treatments with prior probabilities weighted by the user's profile. The question engine asks the minimum plain-language questions needed to collapse the hypothesis set, then routes to a strategy + documentation capture.

### Pipeline
```
Bank/card feed
  → Enrichment (merchant name, MCC, amount, recurrence, address-match, payee type)
  → Hypothesis generation (category tables below)
  → Materiality gate
  → Question engine (ask-once profile graph)
  → Treatment assignment + confidence score
  → Documentation capture prompt (moment of detection)
  → Strategy recommendation OR pro-review export OR "personal, tracked for basis"
```

### Materiality gates (don't interrogate noise)
- Single transaction < $100: auto-classify, no questions (unless recurring)
- Recurring pattern totaling > $500/yr: interrogate the *pattern* once
- Single transaction > $1,000: interrogate
- Any transaction at a known rental/business address: interrogate regardless of size
- Loan-servicer payments: always interrogate the *underlying asset* once

### Ask-once profile graph
Answers persist as durable facts, never re-asked:
- **Vehicles**: each vehicle → use (personal/business/mixed %), mileage method elected, GVWR, wrapped/branded?, financed (lender, US-assembled?)
- **Properties**: each address → primary/rental/STR/business, basis ledger, financing type (mortgage/HELOC/unsecured)
- **Entities**: type, election dates, payroll status, accountable plan?
- **People**: dependents, ages, student status, employed-by-business?
- **Methods elected**: standard vs actual mileage, simplified vs regular home office, cash vs accrual, lot-ID method for crypto
New transactions match against the graph first; questions fire only on unknowns.

### Method-conflict guards (hard rules)
- Standard mileage elected → suppress all parts/repair/insurance/depreciation suggestions for that vehicle (already included in the rate). Offer an annual "which method wins?" comparison instead.
- Simplified home office → suppress actual-expense allocation prompts.
- §121 exclusion planning → track depreciation recapture from any home-office/rental years.
- Accountable plan exists → route reimbursable items through the plan, not Schedule C.

---

## 2. Category Detection Tables

### 2.1 Vehicle / Powersports / Race Parts
**Signals:** MCC 5533/5571/5599; merchants (AutoZone, O'Reilly, Summit Racing, Rocky Mountain ATV/MC, RevZilla, Jegs, dealer parts depts, tire shops); powersports dealers; trailer/toy-hauler purchases; track-day and race-entry fees; fuel at off-road recreation areas.

**Hypotheses:** (a) personal hobby — no treatment; (b) business vehicle maintenance; (c) sponsorship/advertising; (d) resale inventory (flipper); (e) monetized content business; (f) farm/shop off-road equipment.

**Question tree (plain language → legal test):**
1. "Is this for a vehicle you use in a business?" → if yes, which vehicle? → **check profile: mileage method.** Standard mileage → stop, explain, offer method comparison. Actual method → deduct at business-use %.
2. "Is this vehicle wrapped or branded with a business logo, or raced/shown where sponsors are visible?" → sponsorship module:
   - Written sponsorship agreement between entity and owner? (required)
   - Rate comparable to what an unrelated sponsor would pay? (market-comp memo)
   - Marketing output log: events, content posted, leads/impressions
   - Entity pays vendors directly (never reimburse personal card without accountable plan)
   - Rating: 🟡 — assemble the file, route to pro. Test: *promotion, not hobby-in-costume.*
3. "Do you buy parts or vehicles to fix and resell?" → inventory/COGS treatment (expense when sold, not when bought); hobby-vs-business 9-factor score; sales-tax/nexus flag.
4. "Do you make money from content about this vehicle (YouTube, sponsorships, affiliate)?" → Schedule C with revenue check; expenses proportional to content activity; hobby-loss watch if perpetual losses.
5. "Is this equipment used off-road for work (shop, ranch, construction)?" → §179/bonus on the machine; **fuel tax credit** on logged off-road gallons (require gallons log — top fraud flag when unsupported).
6. GVWR > 6,000 lbs + business use → heavy-vehicle §179 module.
Auto-loan detection (recurring payments to captive lenders/credit unions coded auto): "Was this vehicle assembled in the US, purchased 2025+?" → **auto loan interest deduction** (up to $10K, 2025–2028).

### 2.2 Solar / Battery / Energy
**Signals:** installers (Tesla Energy, Sunrun, SunPower, local EPCs); **loan servicers — GoodLeap, Mosaic, Dividend, Sunlight Financial, EnFin** (recurring payments are the highest-recall signal); permit fees; APS/SRP interconnection charges.

**First question is always WHEN:** "When was the system paid for / turned on?"
- Expenditures **through Dec 31, 2025** → residential credit (25D, 30%) — if a 2023–2025 system shows no credit claimed, fire the **AMENDED RETURN RECOVERY** flow (3-year lookback; $10–20K recoveries are common). This is a headline feature.
- **2026+ on a primary/second home** → credit is dead. Treatment: add to home basis (feed the basis ledger); check any state/utility incentives (reduce basis, generally not income).
- **On a rental/STR property** → 5-year MACRS + bonus depreciation; evaluate business ITC (§48E — phasing down/out under OBBBA; construction-start and placed-in-service dates control; pro-review tier).
- **On business property** (shop, commercial) → depreciation + ITC analysis, pro tier.
Battery-only additions follow the same tree. Detect and mirror for: EV chargers, generators (no credit; basis or business depreciation), roof work bundled with solar (roof ≠ credit-eligible even pre-2026 — common overclaim to warn on).

### 2.3 Pool / Spa
**Signals:** builders (Presidential Pools, Shasta, California Pools), pool loans, Leslie's/pool-supply recurrence, pool service ACH.

**Hypotheses & tree:**
1. Which property? (address match) → **rental/STR** → 15-year land improvement, **bonus-depreciation eligible**; amenity ROI note. 🟢
2. Primary home, default → **basis ledger** (capital improvement; also block walls, decking, equipment replacements... maintenance/chemicals excluded).
3. "Did a physician recommend this for a diagnosed medical condition (e.g., physical therapy)?" → medical module: prescription letter + before/after **home appraisal**; deduction = cost minus FMV increase; then **ongoing maintenance/utilities become recurring medical expenses** at medical-use %. 🟡, pro tier.
4. "Do you run a licensed home daycare?" → time-space formula inclusion. 🟢
5. **Financing nuance (ask BEFORE they sign if detected as a pending quote/deposit):** "How is it financed?" Unsecured 'pool loan' → interest nondeductible. **HELOC/home-equity loan** used to build it → interest deductible (buy/build/substantially-improve rule). Same pool, same dollars — loan structure decides. Surface this proactively on any large contractor deposit.
6. If user rents home ≤14 days for events → pool raises achievable tax-free rent; log amenity for comps.

### 2.4 Landscaping / Hardscape (block wall, turf, DG, irrigation)
**Signals:** landscape contractors, nurseries, rock/materials yards, SiteOne, recurring maintenance ACH.

**Tree:**
1. Address match → **rental property**: repairs (deduct now) vs improvements (15-yr land improvement, **bonus eligible**) — ask "repairing something that existed, or adding something new?" 🟢
2. Primary home: improvements (wall, turf install, irrigation system, DG regrade) → **basis ledger**; recurring maintenance → personal, no treatment.
3. "Do clients or customers regularly come to your home office?" → partial grounds deduction at business-use % (*Langer* line of cases). 🟡, pro tier.
4. Utility/municipal xeriscape rebates → reduce basis, not income.
5. Pre-sale improvements → basis; pair with §121 exclusion projection.

### 2.5 Home Improvement Stores (the ambiguity king)
**Signals:** Home Depot, Lowe's, Ace, Harbor Freight, Grainger, McMaster-Carr.
These are maximally ambiguous — personal house, shop/business, rental, home office. Resolution: **ask for the destination, not the item.** "Was this for: [my home] [my shop/business] [rental at ___] [home office area]?" One tap. Grainger/McMaster skew heavily business (prototype/fab supplies → Schedule C or entity + possible **R&D credit supply costs** — flag purchases coinciding with development projects).

### 2.6 Animals & Security
**Signals:** vet clinics, Chewy/Petco recurrence, breeders, trainers; alarm companies, gun stores, camera systems.
- Pet spend + user has business premises → "Does an animal guard your business property?" → guard-dog module: breed reasonableness, kept-at-site, security memo, cost log, 7-yr depreciation, business-use %. 🟡
- Pet spend + charitable pattern → "Do you foster for a rescue organization?" → *Van Dusen* charitable flow, org letter for $250+.
- Security systems: business premises → deduct/depreciate; home office → business-use %; home → basis.

### 2.7 Medical / Health
**Signals:** MCC 8011-8099, pharmacies, therapy, gyms, weight-loss programs, medical loan servicers (CareCredit).
- Route everything through **HSA-first** logic if eligible (100% vs 7.5% AGI floor).
- Gym/program + "physician-diagnosed condition?" → §213 candidate with documentation.
- CareCredit/medical financing → surface the underlying expense for HSA reimbursement or deduction bunching ("stack elective procedures into one calendar year to clear the AGI floor").
- Track out-of-pocket even when not deductible → **HSA shoebox**: reimbursable tax-free in any future year with receipts.

### 2.8 Travel Pattern Recognition
**Signal cluster:** airline + hotel + (conference fee OR client-city match) within a date window → "Business trip?" → per-diem vs actual comparison, sandwich-day optimization, airfare full-deduction check (primarily-business test), spouse-travel warning (not deductible unless employee with business purpose).

### 2.9 Recurring-Payee Sweeps (run monthly)
- Payments to same individuals → worker-classification questionnaire
- Childcare providers → dependent care credit/FSA (day camp yes, overnight no)
- Tuition/loan servicers → AOTC/LLC, $2,500 SLI deduction, employer §127 check, **scholarship election** module
- Charitable ACH/checks → bunching/DAF analysis; appreciated-asset substitution prompt
- Storage units, coworking → business allocation
- Software/SaaS stack → Schedule C sweep
- Crypto exchange transfers → crypto module (playbook §6A)
- Insurance premiums → SE health insurance / §105 HRA spouse plan check

---

## 3. Question Engine Design Rules

1. **Plain language, one legal test per question.** "Is the vehicle wrapped with your business logo?" not "Does this satisfy §162 ordinary-and-necessary advertising?" Map each question to its test in metadata for the pro-export.
2. **Leading is fine; assuming is not.** Questions may surface the opportunity ("Shop purchases like this are often deductible — was this for the business?") but the user asserts the fact. The engine never auto-claims a business purpose. Log every assertion with timestamp — that log *is* the audit defense.
3. **Ask at the moment of detection.** Fresh memory + push notification beats an April questionnaire. Documentation capture in the same interaction: photo of the wrapped vehicle, snap of the receipt, upload of the prescription/contract.
4. **Batch by pattern, not transaction.** 40 AutoZone charges = one conversation about the vehicle, applied retroactively.
5. **Confidence scoring.** High confidence (address-matched rental repair) → auto-classify with undo. Medium → one-tap question. Low/high-stakes (sponsorship, medical pool) → full module + pro-review export.
6. **Every "no" is also data.** "Personal" answers feed the basis ledger, HSA shoebox, and commingling monitor (personal spend inside business accounts → risk flag per playbook §9).

---

## 4. Retroactive Scanners (run at onboarding on 12–36 months of history)
1. **Missed-credit scanner**: solar/battery loans (25D), EV purchases pre-Oct 2025 (30D), energy-efficiency work pre-2026 (25C) → amended-return candidates within the 3-year window.
2. **Missed-deduction scanner**: SE health insurance, home office never claimed, auto-loan interest 2025+, unclaimed AZ-style state credits.
3. **Basis reconstruction**: sweep contractor/improvement payments into the property basis ledger.
4. **Method-election review**: mileage and home-office method comparisons on actuals.
5. **Estimated-tax exposure**: business inflows vs estimated payments made → safe-harbor gap.

---

## 5. Output Contract (per detection)
Every resolved detection emits:
```json
{
  "transaction_ids": [],
  "treatment": "sponsorship_advertising",
  "legal_basis": "IRC §162; see litigated factors memo",
  "confidence": 0.72,
  "band": "conditional | auto | specialist",
  "user_assertions": [{"q": "...", "a": "...", "ts": "..."}],
  "docs_captured": ["sponsorship_agreement.pdf", "vehicle_wrap.jpg"],
  "docs_missing": ["market-rate comp memo"],
  "estimated_value": 4200,
  "pro_export_ready": false
}
```
The **pro export** packages: fact pattern narrative, assertions log, documents, legal basis + citations, defensibility rating, and the specific question for the professional. A CPA receiving a complete file approves in minutes what they'd reject as a bare question.

---

## 6. Second Data Plane: Paystub & Benefits Ingestion
Bank data sees spending, not **unelected benefits**. At onboarding and each open-enrollment season, request paystub + benefits guide upload. Parse for: 401(k)/403(b)/457(b) availability and current deferral %, employer match formula vs actual contribution (unclaimed match = alert), after-tax 401(k) + in-plan conversion (mega backdoor gate), HDHP/HSA status, FSA/DCFSA elections, ESPP terms and participation, NQDC eligibility, §127 education/student-loan benefit, commuter benefits, employer Trump Account contributions. Cross-check paystub state withholding against detected residence (multi-state flag). For pure W-2 users, this plane outperforms transaction analysis.

## 7. Life-Event Trigger Engine
Events unlock strategy windows that transaction categories never surface. Detect from data where possible (payroll deposit stops = job loss/sabbatical → Roth conversion + 0% gain harvesting + ACA window; new mortgage = home purchase; escrow/title inflow = home sale → §121 + basis ledger settlement; marketplace premium payments = ACA cliff monitor) and ask annually for the rest: marriage/divorce, birth/adoption, child turning 17/19/24 (credit cliffs, kiddie tax), college start, state move (domicile evidence file: license, voter reg, vehicle reg, lease/deed, day count, old-ties severed), inheritance/death of parent (step-up documentation NOW — date-of-death valuations are easy today and expensive to reconstruct later), Medicare enrollment (HSA contribution stop — 6-month lookback trap), RSU vest years, large gains.

## 8. Penalty-Prevention Sweeps (the forensic layer — run continuously)
Under-withholding / missed estimates vs safe harbor · missed or late 83(b) (30-day hard clock from any equity grant detected) · late S-corp election (2553 window) · 1099-K / 1099-DA / 1099-NEC mismatch vs detected deposits · missed RMDs · excess IRA/Roth/HSA contributions (income phaseout crossings) · Roth income-limit breach (recharacterize before deadline) · HSA contributions after Medicare enrollment · wash sales across accounts · commingling score · payroll deposit failures (S-corps) · state nexus / sales-tax exposure for e-commerce sellers · ACA advance-credit overrun (no repayment cap post-2025). Positioning: *"We stop tax problems before they exist."* This layer justifies the subscription even in years with no new strategies.

## 9. Rule Schema — Expiration Validator
Every rule in the engine carries effective dating; the engine suppresses or flags stale rules automatically:
```json
{
  "rule_id": "tips_deduction",
  "authority": "IRC §224 (OBBBA)",
  "effective_start": "2025-01-01",
  "effective_end": "2028-12-31",
  "phaseouts": {"magi_single": 150000, "magi_mfj": 300000},
  "inflation_adjusted": false,
  "source_url": "irs.gov/...",
  "last_verified": "2026-07-01",
  "status": "verified | needs_review | expired | expired_pending_extension",
  "band": "auto | conditional | specialist | suppress | hard_block"
}
```
Known sunset calendar to seed: tips/OT/senior/auto-loan deductions (2028), SALT $40K (2029), QOF mandatory recognition (end of 2026), enhanced ACA credits (already expired — cliff active), residential energy credits (expired 2025 — amendment scanner only), annual inflation adjustments (every rule with dollar figures).

## 10. Module Roadmap (build order by user value ÷ effort)
1. Retroactive scanners + basis ledger (onboarding wow)
2. Penalty-prevention sweeps (retention)
3. Transaction hypothesis engine, top 10 categories (§2)
4. Paystub/benefits plane + W-2 benefit arbitrage
5. ACA cliff monitor (small persona, extreme per-user value)
6. Entity decision tree + S-corp analyzer
7. Life-event engine
8. Persona packs: public-sector 457 stacking, travel-worker tax home, students, caregivers
9. Documentation vault + pro-export
10. Specialist routers: expat/immigrant, clergy, estate, PPLI/QSBS

---

## 11. Third Data Plane: Prior-Year Return Ingestion
Parse the uploaded 1040 + schedules at onboarding. Outputs:
- **Carryforward tracker** (headline feature): capital loss, passive loss (per-activity, Form 8582), NOL, charitable, foreign tax credit, and AMT credit carryforwards are routinely *lost* when users switch preparers/software. Recovering a forgotten carryforward = found money on par with the solar scanner.
- Missed-item diff: SE health insurance, QBI, credits vs detected facts.
- Method elections on record (mileage, home office, lot ID) → seed the profile graph.
- Depreciation schedules → continue, don't restart.
- Prior AGI/liability → estimated-tax safe harbor computation.

## 12. High-Stakes Irreversible-Moment Guards (interrupt-class alerts)
Some detections warrant interrupting the user, not queuing a suggestion:
- **401(k) rollover initiated at public-company employer → NUA check BEFORE rollover completes** (rolling to IRA permanently destroys the option)
- Equity grant detected → **83(b) 30-day clock**
- Large contractor deposit → financing-structure question (HELOC vs unsecured) before signing
- Marketplace enrollee trending over the **ACA cliff** mid-year
- Roth conversion queued in an **IRMAA lookback** year
- HSA contributions continuing within 6 months of Medicare enrollment
- Crypto/stock sale queued that crosses a cliff (ACA, IRMAA, EITC investment-income limit)
- Entity classification change → 60-month lock confirmation

## 13. Gambling Detection Module
Signals: DraftKings, FanDuel, BetMGM, Caesars, casino ATM patterns. From 2026, only **90% of losses** are deductible — break-even bettors owe tax on phantom income. Features: running session log (the IRS-preferred method for slots/apps), W-2G reconciliation vs deposits, year-end phantom-income exposure estimate, professional-status analysis at scale, and the honest warning most apps won't show: heavy recurring gambling spend also feeds the wellbeing/risk lens, not just the tax lens.

## 14. IRS Notice-Response Module
Users photograph any IRS/state letter. Classify (CP2000 matching, balance due, ID verify, audit), rebut matching errors from held data (basis records defeat most CP2000s), generate the response letter + evidence packet, or route to the pro network. "We handle the scary letter" is likely the strongest retention feature in the product.

## 15. Business-Model / Liability Layer
The **vetted CPA/EA network** is simultaneously monetization, Circular 230 posture, and liability firewall: the product sells *prepared fact patterns with documentation* to professionals who approve and implement — it does not sell advice. Revenue: subscription (scanners, monitors, vault) + per-packet or revenue-share on pro referrals + premium tiers for entity owners. Every recommendation surface carries the educational-scenario disclaimer; every implementation path terminates at a licensed human. Data posture: GLBA safeguards, §7216 handling, SOC 2 before scale — bank data + tax data is the most sensitive combination a consumer app can hold.

---

## 16. Year-End Proactive Engine (Q4 Module) — Flagship
The feature the accumulated profile graph exists to power. Full strategy content: playbook §8B.

**Pipeline:** YTD income projection (payroll + business inflows + realized gains + crypto) → liability-vs-payments gap → marginal-rate trajectory (this year vs next) → cliff-proximity scan (ACA, IRMAA, QBI, tips/OT, 0% LTCG, EITC) → **purchase-list interview** → ranked timing recommendations → hard-deadline calendar → executed-action log for the filing packet.

**Purchase-list interview (the anti-waste guard):** "What equipment, vehicles, or big expenses are you planning in the next 6–12 months anyway?" The engine only ever **re-times planned spending** across the year boundary. Hard rule: no card may present spending as net savings — every accelerate-purchase card renders `tax_saved` AND `net_cash_cost` side by side, and if the user hasn't asserted a pre-existing need, the card doesn't render at all.

**Equipment gates:** placed-in-service ≤ Dec 31 feasibility (lead-time field; alerts fire Oct 1) · §179-vs-bonus selection (mid-quarter check: Q4 asset ratio > 40% → prefer bonus) · business-use assertion + logging starts at delivery · listed-property >50% rule · GVWR branch · §179 business-income cap vs bonus-NOL branch.

**Solar/energy gate:** primary home 2026+ → economics-only card, explicitly "no federal credit" · rental/STR/business property → depreciation/ITC path, pro tier.

**December rescue sub-flow:** underpayment detected after Oct 1 → **withholding time-machine calculator** (exact W-4/bonus-withholding dollar target to reach safe harbor — withholding is deemed paid evenly across the year) → employer payroll-cutoff warning → fallback Jan 15 estimate.

**Cadence:** Oct 1 projection + interview · Nov 15 withholding/deferral last call · Dec 1 charitable transfers + conversion/harvest decisions · Dec 20 last-call checklist (placed-in-service, RMD/QCD, FSA, gifts) · Jan 15 Q4 estimate. Every alert deep-links to a one-tap action or pro-export.

**Output contract additions per year-end card:** `deadline`, `lead_time_days`, `net_cash_cost`, `tax_saved`, `cliff_bonus_value` (value from cliff restoration — often exceeds tax_saved), `reversible: true|false`.

