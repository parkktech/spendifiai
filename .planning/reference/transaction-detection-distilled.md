# Transaction Detection & Interrogation Engine — Distilled Planning Reference

> Source: `/home/spendifi/public_html/transaction-detection-spec.md` (owner-authored, companion to `tax-strategy-playbook-2026.md`).
> Purpose: implementation-ready reference for Phase 11 (FLAG/INT/FEED) planning. Every detection rule, threshold, merchant pattern, and edge case from the original is preserved. Each block notes the existing SpendifiAI infra it maps to.
>
> **Safety boundary reminder (applies to EVERYTHING below):** all outputs are educational suggestions ("may / could / consider"). Detectors surface hypotheses and questions — the USER asserts facts; the engine never auto-claims a business purpose. Claude words questions and narrates numbers pre-computed by `TaxRulesEngineService` / `config/tax-rules.php` only (SAFE-01, SAFE-03). Gray-area items (guard dog, medical pool, sponsorship) are surfaced as questions + pro-review routing, never as deduction assertions (locked out-of-scope list in `.planning/REQUIREMENTS.md`).

---

## 0. Core Principle & Pipeline

**A transaction is a hypothesis generator, not an answer.** Every enriched transaction produces a set of candidate tax treatments with prior probabilities weighted by the user's profile. The question engine asks the minimum plain-language questions needed to collapse the hypothesis set, then routes to a strategy + documentation capture.

Pipeline stages:

```
Bank/card feed
  → Enrichment (merchant name, MCC, amount, recurrence, address-match, payee type)
  → Hypothesis generation (category detectors, §2 below)
  → Materiality gate
  → Question engine (ask-once profile graph)
  → Treatment assignment + confidence score
  → Documentation capture prompt (at moment of detection)
  → Strategy recommendation OR pro-review export OR "personal, tracked for basis"
```

**Infra mapping:**
- Enrichment: `Transaction` model (merchant, amount, `plaid_metadata` encrypted array incl. Plaid categories/location; `$hidden` plaid_categories), `MerchantAlias` table + merchant normalization (lowercase/trim/suffix-strip, payment-processor extraction) already used by `SubscriptionDetectorService`. NOTE: manual statement uploads have no MCC — merchant-name matching is the fallback signal path.
- Recurrence: `SubscriptionDetectorService` (charge_history JSON, frequency detection weekly/monthly/quarterly/annual) is the existing recurrence engine — the recurring-payee sweeps (§2.9) should reuse its normalized-merchant grouping, not reimplement.
- Hypothesis generation → **NEW** `RedFlagDetectorService` detectors (FLAG-01, FLAG-05).
- Question engine → **NEW** `InterviewOrchestratorService` + `InterviewSession` (INT-01..05) and `AIQuestion` with `QuestionType::Optimization` (FEED-01/02/03).
- Ask-once graph → extends `IncomeOptimizationProfile::answerableFields()` skip-logic (CTX-04); needs a durable-facts store (§0.2).
- Documentation capture → v2.0 Tax Document Vault (encrypted storage, hash-chain audit, two-pass extraction) + new `TaxDocumentCategory` cases (DOC-01/02/03) — Phase 12.
- Output contract → `OptimizationFinding` model (Phase 10, may need schema extension — §5).
- "Moment of detection" push notification → **GAP**: notifications are still on the v-next list; Phase 11 should surface via the AI Questions feed/badge (FEED-02, UI-01) and treat push as a later enhancement.

### 0.1 Materiality gates (don't interrogate noise)

| Rule | Threshold |
|---|---|
| Single transaction **< $100** | auto-classify, no questions (**unless recurring**) |
| Recurring pattern totaling **> $500/yr** | interrogate the *pattern* once (one conversation, applied retroactively) |
| Single transaction **> $1,000** | interrogate |
| Any transaction at a **known rental/business address** | interrogate regardless of size |
| **Loan-servicer payments** | always interrogate the *underlying asset* once |

**Infra:** thresholds belong in `config/tax-rules.php` (or a `config/tax-detection.php` sibling) — never hard-coded in service classes. Address-match uses ask-once property graph + Plaid location metadata. Loan-servicer recognition can reuse `HousingDetectionService` loan-pattern logic and cost-of-living loan detection.

### 0.2 Ask-once profile graph (durable facts, never re-asked)

Answers persist as durable facts. New transactions match against the graph FIRST; questions fire only on unknowns (this is exactly CTX-04 / INT-03 "know what you know before asking").

- **Vehicles**: each vehicle → use (personal/business/mixed %), mileage method elected, GVWR, wrapped/branded?, financed (lender, US-assembled?)
- **Properties**: each address → primary/rental/STR/business, basis ledger, financing type (mortgage/HELOC/unsecured)
- **Entities**: type, election dates, payroll status, accountable plan?
- **People**: dependents, ages, student status, employed-by-business?
- **Methods elected**: standard vs actual mileage, simplified vs regular home office, cash vs accrual, lot-ID method for crypto

**Infra:** extends `IncomeOptimizationProfile` (per-user + tax-year, encrypted). Vehicles/properties/entities/people/methods are structured durable facts — plan either encrypted JSON columns on the profile or small child models. `UserFinancialProfile` already holds employment_type, business_type, has_home_office, housing_status — do not duplicate; the graph should read those via `IncomeOptimizerDataAssemblerService`. Answers arrive via `UserAnsweredQuestion` → `UpdateOptimizationFromAnswer` listener (FEED-03).

### 0.3 Method-conflict guards (HARD rules — deterministic suppression)

1. **Standard mileage elected** → suppress ALL parts/repair/insurance/depreciation suggestions for that vehicle (already included in the rate). Offer an annual "which method wins?" comparison instead.
2. **Simplified home office elected** → suppress actual-expense allocation prompts.
3. **§121 exclusion planning** → track depreciation recapture from any home-office/rental years.
4. **Accountable plan exists** → route reimbursable items through the plan, not Schedule C.

**Infra:** pure-PHP guard checks inside `RedFlagDetectorService` before any finding is emitted; method elections live in the ask-once graph. The annual method comparison is deterministic math → `TaxRulesEngineService` (see also Retroactive Scanner #4).

---

## 1. Detector: Vehicle / Powersports / Race Parts (§2.1)

**Signals:**
- MCC **5533 / 5571 / 5599**
- Merchants: **AutoZone, O'Reilly, Summit Racing, Rocky Mountain ATV/MC, RevZilla, Jegs**, dealer parts departments, tire shops
- Powersports dealers; trailer/toy-hauler purchases; track-day and race-entry fees; fuel at off-road recreation areas

**Hypotheses:** (a) personal hobby — no treatment; (b) business vehicle maintenance; (c) sponsorship/advertising; (d) resale inventory (flipper); (e) monetized content business; (f) farm/shop off-road equipment.

**Question tree (plain language → legal test; one legal test per question):**

1. "Is this for a vehicle you use in a business?" → if yes, which vehicle? → **check profile: mileage method.** Standard mileage → STOP, explain, offer method comparison (guard 0.3.1). Actual method → deduct at business-use %.
2. "Is this vehicle wrapped or branded with a business logo, or raced/shown where sponsors are visible?" → **Sponsorship module** (🟡 conditional — assemble the file, route to pro; legal test: *promotion, not hobby-in-costume*):
   - Written sponsorship agreement between entity and owner? (**required**)
   - Rate comparable to what an unrelated sponsor would pay? (**market-comp memo**)
   - Marketing output log: events, content posted, leads/impressions
   - Entity pays vendors directly (never reimburse personal card without accountable plan — cross-check guard 0.3.4)
3. "Do you buy parts or vehicles to fix and resell?" → **inventory/COGS treatment** (expense when SOLD, not when bought); hobby-vs-business **9-factor score**; **sales-tax/nexus flag** (note: state-adjacent → keep as informational flag only; state tax is deferred to STATE-01).
4. "Do you make money from content about this vehicle (YouTube, sponsorships, affiliate)?" → Schedule C with revenue check; expenses proportional to content activity; **hobby-loss watch** if perpetual losses.
5. "Is this equipment used off-road for work (shop, ranch, construction)?" → **§179/bonus** on the machine; **fuel tax credit** on logged off-road gallons — **require a gallons log; this is a top fraud flag when unsupported** (docs-missing must block pro_export_ready).
6. **GVWR > 6,000 lbs + business use** → heavy-vehicle **§179 module**.

**Sub-detector — Auto-loan interest deduction:** recurring payments to captive lenders/credit unions coded auto → ask "Was this vehicle assembled in the US, purchased 2025+?" → **auto loan interest deduction, up to $10,000, tax years 2025–2028**. ($10K cap and year window → `config/tax-rules.php`.)

**Infra:** recurring lender payments reuse `SubscriptionDetectorService` grouping; merchant lists → seeded detection tables (pattern: `CancellationProviderSeeder` with aliases JSON is the precedent for a merchant knowledge table). Retroactive: also fired by Missed-deduction scanner (§4.2).

---

## 2. Detector: Solar / Battery / Energy (§2.2)

**Signals:**
- Installers: **Tesla Energy, Sunrun, SunPower**, local EPCs
- **Loan servicers: GoodLeap, Mosaic, Dividend, Sunlight Financial, EnFin — recurring payments are the HIGHEST-RECALL signal**
- Permit fees; **APS/SRP interconnection charges** (AZ utilities)

**First question is always WHEN:** "When was the system paid for / turned on?"

| Timing / placement | Treatment |
|---|---|
| Expenditures **through Dec 31, 2025** | Residential credit (**§25D, 30%**). If a **2023–2025** system shows **no credit claimed** → fire the **AMENDED RETURN RECOVERY** flow (**3-year lookback**; **$10–20K recoveries are common** — headline feature; per out-of-scope rules, present as an educational range with uncertainty framing, never a guarantee) |
| **2026+ on a primary/second home** | Credit is dead. Add to **home basis** (feed the basis ledger); check state/utility incentives (**reduce basis, generally not income**) — state-credit specifics deferred to STATE-01 |
| **On a rental/STR property** | **5-year MACRS + bonus depreciation**; evaluate business **ITC §48E** (phasing down/out under OBBBA; **construction-start and placed-in-service dates control**; **pro-review tier**) |
| **On business property** (shop, commercial) | Depreciation + ITC analysis, **pro tier** |

**Edge cases / mirrors:**
- **Battery-only additions** follow the same tree.
- Detect and mirror for: **EV chargers, generators** (no credit; basis or business depreciation), and **roof work bundled with solar** — **roof ≠ credit-eligible even pre-2026; common overclaim to WARN on** (educational warning, fits the boundary perfectly).

**Infra:** loan-servicer recurring payments → `SubscriptionDetectorService` / cost-of-living loan detection. Amended-return recovery is also Retroactive Scanner #1. Credit %s, year cutoffs, lookback window → `config/tax-rules.php`. Basis ledger is a NEW persistent structure (per property, ask-once graph §0.2).

---

## 3. Detector: Pool / Spa (§2.3)

**Signals:** builders (**Presidential Pools, Shasta, California Pools**), pool loans, **Leslie's**/pool-supply recurrence, pool service ACH.

**Question tree:**
1. **Which property?** (address match) → **rental/STR** → **15-year land improvement, bonus-depreciation eligible**; amenity ROI note. 🟢 auto/high-confidence band.
2. **Primary home, default** → **basis ledger** (capital improvement; also block walls, decking, equipment replacements; **maintenance/chemicals EXCLUDED** from basis).
3. "Did a physician recommend this for a diagnosed medical condition (e.g., physical therapy)?" → **medical module**: prescription letter + **before/after home appraisal**; **deduction = cost minus FMV increase**; then **ongoing maintenance/utilities become recurring medical expenses at medical-use %**. 🟡 conditional, **pro tier**. (Gray-area — question + pro routing only, never an assertion.)
4. "Do you run a licensed home daycare?" → **time-space formula** inclusion. 🟢
5. **Financing nuance — ask BEFORE they sign if detected as a pending quote/deposit:** "How is it financed?"
   - Unsecured 'pool loan' → **interest nondeductible**
   - **HELOC/home-equity loan used to build it** → **interest deductible** (buy/build/substantially-improve rule)
   - *Same pool, same dollars — loan structure decides.* **Surface this proactively on any large contractor deposit.**
6. If user **rents home ≤14 days** for events → pool raises achievable **tax-free rent** (Augusta-style); **log amenity for comps**.

**Infra:** contractor-deposit detection = large one-off transaction to construction-category merchant (materiality gate: > $1,000). Property routing via ask-once graph. Financing question feeds property financing-type fact.

---

## 4. Detector: Landscaping / Hardscape (§2.4) — block wall, turf, DG, irrigation

**Signals:** landscape contractors, nurseries, rock/materials yards, **SiteOne**, recurring maintenance ACH.

**Question tree:**
1. Address match → **rental property**: repairs (deduct now) vs improvements (**15-yr land improvement, bonus eligible**) — ask **"repairing something that existed, or adding something new?"** 🟢
2. **Primary home**: improvements (wall, turf install, irrigation system, DG regrade) → **basis ledger**; recurring maintenance → personal, no treatment.
3. "Do clients or customers regularly come to your home office?" → **partial grounds deduction at business-use %** (*Langer* line of cases). 🟡, **pro tier**. (Gray-area → question + pro routing.)
4. **Utility/municipal xeriscape rebates** → **reduce basis, not income**.
5. **Pre-sale improvements** → basis; pair with **§121 exclusion projection** (cross-links guard 0.3.3 recapture tracking).

---

## 5. Detector: Home Improvement Stores (§2.5) — "the ambiguity king"

**Signals:** **Home Depot, Lowe's, Ace, Harbor Freight, Grainger, McMaster-Carr.**

Maximally ambiguous — personal house, shop/business, rental, home office. Resolution rule: **ask for the DESTINATION, not the item.**

> "Was this for: [my home] [my shop/business] [rental at ___] [home office area]?" — **one tap.**

**Edge case:** **Grainger/McMaster skew heavily business** (prototype/fab supplies → Schedule C or entity + possible **R&D credit supply costs** — **flag purchases coinciding with development projects**).

**Infra:** the one-tap destination question is a multiple-choice `AIQuestion` (existing options JSON pattern) with `QuestionType::Optimization`; answer writes a destination fact + feeds basis ledger or business allocation. Batch by merchant pattern per engine rule §6.4.

---

## 6. Detector: Animals & Security (§2.6)

**Signals:** vet clinics, **Chewy/Petco** recurrence, breeders, trainers; alarm companies, gun stores, camera systems.

1. **Pet spend + user has business premises** → "Does an animal guard your business property?" → **guard-dog module**: breed reasonableness, kept-at-site, security memo, cost log, **7-yr depreciation**, business-use %. 🟡. (Explicit out-of-scope: "your dog IS deductible" assertions — this module is question + file-assembly + pro routing ONLY. Maps to the FLAG-05 "pet" deduction probe.)
2. **Pet spend + charitable pattern** → "Do you foster for a rescue organization?" → ***Van Dusen* charitable flow**; **org letter required for $250+**.
3. **Security systems** routing: business premises → deduct/depreciate; home office → business-use %; home → **basis**.

---

## 7. Detector: Medical / Health (§2.7)

**Signals:** **MCC 8011–8099**, pharmacies, therapy, gyms, weight-loss programs, medical loan servicers (**CareCredit**).

Rules:
1. Route everything through **HSA-first logic** if eligible (**100% vs 7.5% AGI floor** comparison). HSA eligibility/limits already in `config/tax-rules.php` + `TaxRulesEngineService` headroom math (TAX-04).
2. Gym/program + "physician-diagnosed condition?" → **§213 candidate with documentation** (prescription letter).
3. **CareCredit/medical financing** → surface the underlying expense for HSA reimbursement or **deduction bunching** ("stack elective procedures into one calendar year to clear the AGI floor").
4. Track out-of-pocket **even when not deductible** → **HSA shoebox**: reimbursable **tax-free in ANY future year** with receipts.

**Infra:** HSA shoebox = persistent receipt log → Tax Document Vault (receipt uploads) + a tracked-expense list on the optimization profile. AGI floor % → `config/tax-rules.php`.

---

## 8. Detector: Travel Pattern Recognition (§2.8)

**Signal cluster:** **airline + hotel + (conference fee OR client-city match) within a date window** → "Business trip?"

On yes:
- **Per-diem vs actual** comparison (deterministic → `TaxRulesEngineService`; per-diem rates → config)
- **Sandwich-day optimization**
- **Airfare full-deduction check** (*primarily-business* test)
- **Spouse-travel warning**: not deductible **unless employee with business purpose** (educational warning)

**Infra:** multi-transaction correlation is NEW logic (no existing cluster detector); date-window join over categorized transactions. Client-city match needs a business-locations fact in the ask-once graph.

---

## 9. Detector: Recurring-Payee Sweeps (§2.9) — run MONTHLY

All reuse `SubscriptionDetectorService`-style normalized-merchant recurrence grouping:

| Sweep | Trigger | Routed module |
|---|---|---|
| Payments to **same individuals** | recurring personal payees | **worker-classification questionnaire** |
| **Childcare providers** | recurring childcare merchants | **dependent care credit / FSA** — **day camp YES, overnight camp NO** |
| **Tuition / loan servicers** | education payees | **AOTC/LLC**, **$2,500 student-loan-interest deduction**, **employer §127 check**, **scholarship election module** |
| **Charitable ACH/checks** | recurring donations | **bunching / DAF analysis**; **appreciated-asset substitution prompt** (⚠ keep strictly educational — "consider" framing; no securities/investment allocation advice, RIA boundary) |
| **Storage units, coworking** | recurring facility payments | **business allocation** |
| **Software/SaaS stack** | recurring software charges | **Schedule C sweep** (overlaps future FLAG-07 deductible-subscription detection — reuse `Subscription` records) |
| **Crypto exchange transfers** | exchange payees | **crypto module (playbook §6A)** — NOT in v2.1 requirements; park as backlog/future unless owner promotes it |
| **Insurance premiums** | recurring insurance | **SE health insurance / §105 HRA spouse-plan check** (SE health insurance also in Retroactive Scanner #2) |

**Cadence infra:** monthly scheduled task (add to `routes/console.php`; existing scheduler pattern), gated by user-activity like other AI jobs (28-day activity gate precedent).

---

## 10. Question Engine Design Rules (§3) — bind INT + FEED implementation

1. **Plain language, one legal test per question.** "Is the vehicle wrapped with your business logo?" not "Does this satisfy §162 ordinary-and-necessary advertising?" **Map each question to its legal test in metadata** for the pro-export. (Claude words the question — SAFE-01 framing; the legal-test mapping is static metadata, not Claude output.)
2. **Leading is fine; assuming is not.** Questions may surface the opportunity ("Shop purchases like this are often deductible — was this for the business?") but **the USER asserts the fact. The engine never auto-claims a business purpose. Log every assertion with timestamp — that log IS the audit defense.** (Assertions log → persist on `InterviewSession`/`OptimizationFinding.user_assertions`.)
3. **Ask at the moment of detection.** Fresh memory + push notification beats an April questionnaire. **Documentation capture in the SAME interaction**: photo of the wrapped vehicle, snap of the receipt, upload of the prescription/contract. (Push = notifications gap; v2.1 lands via AI Questions feed + nav badge. Doc capture in-interaction → Phase 12 DOC-01/02, vault upload from the question UI.)
4. **Batch by pattern, not transaction.** 40 AutoZone charges = ONE conversation about the vehicle, **applied retroactively** to all matched transactions. (`OptimizationFinding.transaction_ids[]` array; mirrors existing "propagate to matching merchants" behavior in `TransactionController::updateCategory`.)
5. **Confidence scoring bands:**
   - **High** (e.g., address-matched rental repair) → **auto-classify with undo**
   - **Medium** → **one-tap question**
   - **Low / high-stakes** (sponsorship, medical pool) → **full module + pro-review export**
   (Mirror the existing confidence-threshold pattern in `config/spendifiai.php`; band thresholds → config, FLAG-06 severity/priority.)
6. **Every "no" is also data.** "Personal" answers feed the **basis ledger**, **HSA shoebox**, and **commingling monitor** (personal spend inside business accounts → **risk flag**, playbook §9). Commingling detection is deterministic: `expense_type=personal` transactions on `AccountPurpose::Business` accounts.

---

## 11. Retroactive Scanners (§4) — run at onboarding on 12–36 months of history

1. **Missed-credit scanner:** solar/battery loans (**§25D**), **EV purchases pre-Oct 2025 (§30D)**, **energy-efficiency work pre-2026 (§25C)** → **amended-return candidates within the 3-year window**.
2. **Missed-deduction scanner:** **SE health insurance**, **home office never claimed**, **auto-loan interest 2025+**, unclaimed **AZ-style state credits** (state credits → deferred/STATE-01; keep the hook, suppress state output in v2.1).
3. **Basis reconstruction:** sweep contractor/improvement payments into the **property basis ledger**.
4. **Method-election review:** **mileage and home-office method comparisons on actuals** (deterministic → `TaxRulesEngineService`).
5. **Estimated-tax exposure:** **business inflows vs estimated payments made → safe-harbor gap** (companion to FLAG-03's $500 withholding-gap detector; safe-harbor %s → `config/tax-rules.php`).

**Infra:** run inside/alongside `BuildIncomeOptimizationProfile` job on `OptimizationProfileBuilt`; history depth may require `plaid:backfill`. Scanners emit `OptimizationFinding` records like live detectors.

---

## 12. Output Contract (§5) — per resolved detection

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

- Maps to `OptimizationFinding` (Phase 10). Verify/extend schema additively for: `transaction_ids[]`, `treatment`, `legal_basis`, `band`, `user_assertions[]` (timestamped), `docs_captured[]`, `docs_missing[]`, `estimated_value`, `pro_export_ready`.
- `estimated_value` MUST originate from `TaxRulesEngineService`/config (SAFE-03) and be presented as an educational range, never a guarantee.
- `band` implements FLAG-06 severity/priority.
- `docs_captured`/`docs_missing` link to Tax Document Vault records (Phase 12 DOC).

**Pro export** packages: fact-pattern narrative, assertions log, documents, legal basis + citations, defensibility rating, and **the specific question for the professional**. *"A CPA receiving a complete file approves in minutes what they'd reject as a bare question."* → Phase 12 (RPT-04 PDF via dompdf; reuse `TaxController::sendToAccountant` / `TaxPackageMail` email pattern).

---

## 13. Hard Numbers → `config/tax-rules.php` (year-versioned; NEVER in code, NEVER computed by Claude)

| Constant | Value | Used by |
|---|---|---|
| Materiality: single-txn auto-classify floor | $100 | gate §0.1 |
| Materiality: recurring-pattern annual gate | $500/yr | gate §0.1 |
| Materiality: single-txn interrogate threshold | $1,000 | gate §0.1 |
| Heavy-vehicle §179 GVWR threshold | 6,000 lbs | detector §1.6 |
| Auto-loan interest deduction cap | $10,000; tax years 2025–2028; US-assembled; purchased 2025+ | detector §1 sub |
| §25D residential clean-energy credit | 30%; expenditures through Dec 31, 2025 | detector §2 |
| Amended-return lookback | 3 years | scanner §11.1 |
| Typical §25D recovery range (marketing/educational range only) | $10K–$20K | amended-recovery flow |
| §30D EV credit window (retro) | purchases pre-Oct 2025 | scanner §11.1 |
| §25C energy-efficiency window (retro) | work pre-2026 | scanner §11.1 |
| Solar on rental: MACRS class | 5-year + bonus | detector §2 |
| Pool/landscape on rental: land improvement | 15-year, bonus eligible | detectors §3, §4 |
| Guard-dog depreciation | 7-year | detector §6 |
| Medical AGI floor | 7.5% | detector §7 |
| Charitable acknowledgment-letter threshold | $250 | detector §6.2, sweep §9 |
| Student-loan-interest deduction | $2,500 | sweep §9 |
| Tax-free short-rental day cap (Augusta) | ≤14 days | detector §3.6 |
| Onboarding history depth | 12–36 months | scanners §11 |
| Confidence-band cutpoints (auto / conditional / specialist) | TBD by planner (mirror spendifiai.php pattern) | engine §10.5 |
| Withholding/estimated-tax gap floor (existing FLAG-03) | $500 | scanner §11.5 |
| §48E ITC phase-down schedule (OBBBA) | dates control (construction-start / placed-in-service) — pro tier | detector §2 |

---

## 14. Document / Screenshot Requests (feed Phase 12 DOC intake design)

Detectors request these at the moment of detection (vault upload in-interaction):

1. Written sponsorship agreement (required for sponsorship module)
2. Market-rate comparable memo (sponsorship)
3. Marketing output log — events, content posted, leads/impressions (sponsorship)
4. Photo of wrapped/branded vehicle
5. Snap of receipt (general capture)
6. Physician prescription/recommendation letter (medical pool §3.3; gym/§213 §7.2)
7. Before/after home appraisal (medical pool FMV-increase offset)
8. Off-road fuel **gallons log** (fuel tax credit — required; top fraud flag when unsupported)
9. Rescue-organization letter for donations $250+ (*Van Dusen* foster flow)
10. Security memo + cost log (guard-dog module)
11. Loan/financing documents — HELOC vs unsecured (pool/improvement interest deductibility)
12. Contractor invoices / improvement receipts (basis reconstruction)
13. Mileage log (method-election comparison)
14. Daycare license (time-space formula)
15. Sponsorship vendor-payment evidence (entity pays directly / accountable plan)

---

## 15. Phase Disposition Summary

| Spec area | Phase | Requirement hooks |
|---|---|---|
| Materiality gates, ask-once graph, method-conflict guards | 11 | FLAG-01, CTX-04, INT-03 |
| Category detectors §§1–9 | 11 | FLAG-01, FLAG-05 (home office/vehicle/electronics/pet/meals probes are the required minimum; this spec is the superset), FLAG-06 (bands) |
| Question engine rules | 11 | INT-01..05, FEED-01..04 |
| Retroactive scanners | 11 | FLAG-01 (onboarding run of the same detectors); #5 pairs with FLAG-03 |
| Output contract / assertions log | 11 (schema) | OptimizationFinding extension; SAFE-03 for estimated_value |
| Doc-capture prompts + vault categories | 12 | DOC-01..03 |
| Pro export package | 12 | RPT-01..04 (dompdf + accountant-email pattern) |
| Push-at-detection | Post-v2.1 | notifications system not yet built; use AI Questions feed + badge in v2.1 |
| Crypto module, sales-tax/nexus, state credits | Deferred | playbook §6A / STATE-01 / backlog |
| Deductible SaaS sweep | Future | FLAG-07 |
| Educational framing of all questions/warnings/ranges | 13 | SAFE-01..05 |
