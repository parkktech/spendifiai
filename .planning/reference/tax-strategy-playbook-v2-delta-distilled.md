# Tax Strategy Playbook v2 DELTA — Distilled Planning Reference

**Source:** `/home/spendifi/public_html/tax-strategy-playbook-2026 (1).md` — ONLY the sections added since v1 (diff hunks `71a72,128` and `309a367,410`): **§2B, §2C, §2D, §8B**.
**Do NOT use this file for v1 content** — that lives in `.planning/reference/tax-strategy-playbook-distilled.md` (unchanged by this delta).
**Sibling for dedupe:** `.planning/reference/tax-strategy-expansion-distilled.md` ("EXP") — several 2B items are condensed restatements of EXP modules M2–M14; overlap flags are marked `⟐ EXP-Mx` throughout so the merge step can dedupe (keep EXP's fuller question sets; keep THIS file's hard numbers and caveats, which EXP lacks).
**Consumers:** Phase 11 (detection + guided interview + AI-feed bridge), Phase 12 (report + doc intake + nav UI), Phase 13 (safety/legal hardening) planners.
**Distilled:** 2026-07-01

**Global boundary reminder (applies to every item):** educational-only framing ("may / could / consider"); never "you should / you qualify"; no refund computation; no filing-status assertion; no investment allocation advice; state tax optimization → STATE-01 (out of v2.1).

---

## §2B. Specialized Personas & the 2026 ACA Cliff

### 2B.1 The ACA subsidy cliff — "highest ROI-per-dollar in the code (2026+)"

Facts (verbatim-faithful):
- Enhanced premium credits **expired Dec 31, 2025**; the hard **400% FPL cliff is back** (~**$62,600 single / ~$128,600 family of four**, continental US).
- **One dollar over = zero credit for the whole year**, and post-2025 there is **no repayment cap** on excess advance credits.

Three sub-strategies:
1. **MAGI management**: traditional 401(k)/solo 401(k)/SEP, HSA, and SE deductions can pull a household back under the cliff — **$2K of deduction can restore $10–20K of subsidy**. **Model this before any Roth-vs-traditional recommendation for marketplace enrollees.** (Sequencing rule for TAX-05 Trad-vs-Roth band.)
2. **Real-time cliff monitor (product feature)**: for users paying marketplace premiums (**detectable in bank data**), track income trend vs the cliff mid-year; **a July warning leaves time to act, an April discovery is a five-figure clawback.**
3. **Interaction warnings**: Roth conversions, capital gains, and crypto sales all push MAGI over the cliff. **Sequence around it.**

`⟐ EXP-M9` (ACA subsidy planning as life-event unlock) and `⟐ EXP-M10.7` (Premium Tax Credit) — but the cliff mechanics, FPL dollar levels, no-repayment-cap change, mid-year monitor, and Roth-vs-traditional gating rule are NEW here. Keep this section as primary.
**Config:** FPL/400%-cliff thresholds belong in `config/tax-rules.php` (year-versioned). **Liability:** HIGH — a wrong cliff calc directly causes five-figure user harm; monitor output is P13 hardening territory.

### 2B.2 Public-sector / nonprofit / hospital employees — 457(b) stacking

- 403(b) and governmental 457(b) limits are **separate**: **$24,500 into each = ~$49K of deferral** for a teacher, nurse, firefighter, or government engineer who thought their cap was half that.
- Governmental 457(b) bonuses: **no 10% early-withdrawal penalty at any age after separation** ("the sleeper early-retirement vehicle"), and a **special 3-year pre-retirement catch-up that can double the annual limit**.
- **Caution (verbatim):** Non-governmental 457(b) = unfunded promise, **employer-creditor risk — flag before recommending**.
- **Trigger:** employer type ∈ {school, university, hospital, city/county/state, nonprofit}.

`⟐ EXP-M3` (Public-Sector Engine — has the fuller 5-question rule block). NEW here: the $24,500/$49K numbers, no-10%-penalty fact, catch-up-doubles fact, non-governmental creditor-risk caveat. Merge: EXP questions + these numbers/caveats.
**Config:** 457(b)/403(b) limits → `config/tax-rules.php` (EXP already flags this as ASK).

### 2B.3 Travel workers (nurses, pilots, consultants, oil field, defense contractors)

- Tax-free stipends/per-diems require a **tax home**: permanent residence maintained, expenses duplicated, regular returns, and — "the hinge" — assignments **expected to last under one year**.
- **Cross 12 months (or expect to) and the work location becomes the tax home, making stipends taxable.**
- **Itinerant workers** (no permanent home) have **no tax home anywhere — everything taxable**.
- "Risk-band it green/yellow/red exactly as a recruiter never will; **this is a blow-up-prevention module, not a savings module**."

`⟐ EXP-M4` (Travel Worker Tax-Home Engine — has the 7 verbatim questions and the same G/Y/R banding; owner: "bad advice here can blow people up"). This delta adds only the expects-to-last / 12-month hinge phrasing and itinerant rule. Merge into M4; dedupe fully.
**Liability:** HIGH. Classification-only output; never assert stipend taxability (SAFE-01).

### 2B.4 Reimbursement beats deduction (all W-2 users)

- Unreimbursed employee expenses are **federally dead**. Survivors (verbatim list): **impairment-related work expenses** for disabled workers, **reservists**, **performing artists**, **fee-basis officials**.
- The move: shift costs to the employer — **accountable-plan reimbursement is deductible to them and tax-free to the worker**.
- **Product artifact:** an **Employer Reimbursement Request Packet** — receipts, mileage logs, business purpose, and **a one-page accountable-plan explainer for the HR team**.

`⟐ EXP-M5` (Employer Reimbursement Packet Generator — has the 10 detectable expense types and packet contents). NEW here: the four-category survivor list and the HR-team explainer page. Merge into M5.

### 2B.5 Second data plane: paystub + benefits guide

- "Bank feeds see spending; they can't see **unelected benefits**."
- Ingest paystubs and benefit guides to detect (verbatim list): **unclaimed match (free money first)** · **after-tax 401(k) availability (mega backdoor)** · **HSA/DCFSA gaps** · **ESPP non-participation** · **457(b) existence** · **employer student-loan repayment (§127)** · **commuter benefits** · **group legal** · **Trump Account employer contributions**.
- "For pure W-2 users **this plane finds more money than transaction analysis**."

`⟐ EXP-M2` (W-2 Benefit Arbitrage Engine — has the 13-item benefit list + 7 verbatim upload/interview questions; benefits guide is a NEW DOC-01 category, ASK). NEW here: **group legal** and **Trump Account employer contributions** (not in EXP's 13-item list). Merge into M2, adding those two items.
**Disposition:** P12 doc intake (paystub exists in DOC-01; benefits guide = new category, ASK).

### 2B.6 Smaller persona modules (six, verbatim-faithful)

1. **IRA→HSA qualified funding distribution**: one-time lifetime transfer up to the HSA limit — "moves taxable-someday IRA dollars into the never-taxed wrapper. **Testing period applies.** Niche, real." `⟐ EXP-M7` (identical strategy incl. testing-period caveat and 5 triggers — dedupe fully, keep M7).
2. **Caregivers/disability**: ABLE accounts, medical bunching, dependent care credit for a **disabled spouse/adult dependent**, **multiple-support agreements for shared elder care**, special-needs-trust **referral tier**. `⟐ EXP-M14` (fuller: 9 txn triggers + 8 strategies). NEW here: **multiple-support agreements** — add to M14 on merge.
3. **Students/young workers**: Roth from first earned dollar, 0% gain harvesting, scholarship/AOTC election, W-4 setup, 529→Roth tracking. "Simplified flow — **first-tax-year users need mistake prevention, not a corporate engine**." `⟐ EXP-M11` (9-item list is a superset — dedupe fully, keep M11).
4. **Clergy**: housing allowance, SECA treatment, **Form 4361 opt-out** — "legitimate and heavily abused territory; **pro-review band, and hard-block 'start a ministry' structures per §7**." `⟐ EXP-M13`. NEW here: Form 4361 named; explicit **hard-block on "start a ministry"** structures (P13 guard).
5. **Immigrants/visa/expat**: substantial presence, dual-status years, treaty positions, FBAR/FATCA, foreign pensions/corporations/trusts. "High-earner, underserved, and dangerous — **pure specialist-router, never auto-recommend**." `⟐ EXP-M12` (has the 7 questions + 8 warnings — dedupe fully, keep M12).
6. **Refundable credit scanner** (low/middle income): EITC (**mind the investment-income limit**), CTC/ACTC, Saver's Credit (**Saver's *Match* arrives 2027**), AOTC/LLC, premium tax credit, adoption credit, state credits. "For a working family this beats every advanced strategy in the book." `⟐ EXP-M10` (10-credit list). NEW here: EITC investment-income-limit caveat + **Saver's Match 2027 arrival date**. State credits → STATE-01 (both docs agree).

---

## §2C. Decumulation Module (Ages 55–75+) — "Sequencing Beats Deductions"

**Framing (verbatim):** "The most valuable ten-year planning arc in most lives, and the least-served persona in tax software."
**No EXP overlap** — EXP-M9 lists retirement/Medicare-enrollment/inheritance/parent-dies as bare life-event *triggers* and EXP-M15.8 has "missed RMD"; this module is otherwise entirely new. Keep in full.
**Liability note:** decumulation sequencing errors are irreversible and large (NUA destruction, IRMAA tiers, torpedo rates). Everything here is Conditional/Specialist band; the WARNINGS (NUA, Medicare/HSA, first-RMD doubling) are the safest v2.1-shippable pieces.

Ten strategies (all verbatim-faithful):

1. **Roth conversion window** (retirement → RMD age): fill low brackets annually with conversions before RMDs force income. **Model against IRMAA and the torpedo below — a naive "convert to top of 24%" plan can be wrong by thousands.**
2. **Social Security tax torpedo**: in the provisional-income phase-in zone, each additional IRA dollar drags **$0.50–$0.85 of SS benefits into taxation — effective marginal rates of 40–50% at middle incomes**. Withdrawal *ordering* (**taxable → traditional → Roth, with bracket-filling exceptions**) "is worth more than any deduction at this age."
3. **IRMAA cliffs**: Medicare premium surcharges at **hard MAGI breakpoints with a two-year lookback** — "the retiree's ACA cliff." **One dollar over a breakpoint = full surcharge tier for a year.** Model every conversion/gain against the brackets; **life-changing-event appeal (form SSA-44)** when income drops.
4. **NUA (net unrealized appreciation)**: appreciated employer stock inside a 401(k) can be distributed in-kind — ordinary tax on **basis only**, appreciation at **LTCG rates**. **"Rolling to an IRA destroys the option permanently."** **Engine rule: any detected 401(k) rollover at a public-company employer fires an NUA check *first*.** "One of the largest irreversible mistakes in the code."
5. **QCDs at 70½+** (~**$108K/yr**): the default charitable vehicle once eligible — **excluded from income entirely, satisfies RMDs, beats itemizing**.
6. **RMD compliance + timing**: missed-RMD penalty; **first-year doubling trap** — delaying the first RMD stacks **two RMDs into one year**.
7. **Widow(er)'s penalty**: the final joint-filing year is the **last access to MFJ brackets before single rates** — accelerate conversions/gains there. **"Handle with care in UX; this is grief-adjacent."** (P13 tone/UX guard.)
8. **Inherited IRA (10-year rule)**: **annual RMDs now required for most non-spouse beneficiaries**, and waiting for a **year-10 lump spikes brackets** — spread distributions against the beneficiary's income forecast.
9. **Early-access doors**: **rule of 55** · **72(t) SEPP** · governmental 457(b) penalty-free at any age after separation · **Roth contribution basis**.
10. **Medicare/HSA trap**: **HSA contributions must stop before Medicare enrollment** — **6-month retroactive Part A lookback** — "detect and warn."

**Config:** IRMAA breakpoints, QCD annual limit (~$108K), RMD ages, provisional-income thresholds → `config/tax-rules.php` when built.
**Detection hooks (P11-registerable signals):** SS deposits, RMD-age from DOB, 401(k) rollover events, Medicare premium payments, brokerage inheritance transfers.

---

## §2D. Additional Personas (bank-data detectable)

Eight personas, all verbatim-faithful. **EXP overlap is thin** — only divorce appears (as a bare EXP-M9 trigger word); everything else is new. Detection signals in **bold** are transaction-detection spec candidates (register in P11 even if the module ships later).

1. **Sports bettors** (**DraftKings/FanDuel/BetMGM transactions**): **OBBBA limits loss deduction to 90% of losses from 2026 — break-even bettors now owe tax on phantom income.** Session-method logging, W-2G reconciliation, year-end exposure warning, professional-gambler analysis (***Groetzinger***) at scale. Liability: HIGH (phantom-income surprise; pro-gambler status is fact-intensive).
2. **Active securities traders**: trader tax status + **§475(f) mark-to-market** — losses become ordinary, wash sales vanish, expenses deductible; **election due by the *prior* April 15 (calendar-driven prompt)**; entity structure unlocks retirement/health benefits. **Spot crypto generally outside §475 — flag as unsettled.** Liability: HIGH (TTS is a facts-and-circumstances status; irreversible election with a hard deadline).
3. **Military** (**DFAS deposits**): combat-zone exclusion, **moving expenses (still deductible for PCS)**, **SCRA/MSRRA spouse-domicile protection**, TSP, extended deadlines.
4. **Farmers/ranchers**: **Schedule J income averaging**, weather-forced livestock sale deferral, crop insurance proceeds deferral, prepaid inputs, **new §1062 installment treatment on qualified farmland sales to farmers (OBBBA)**.
5. **Divorcing users**: **Form 8332** dependency allocation, **QDRO (only penalty-free early retirement split)**, **§121 timing on the house**, **post-2018 alimony (nondeductible/nontaxable)**, filing-status math, legal-fee allocation (**fees for taxable alimony/business advice**). `⟐ EXP-M9` trigger "Divorce" only — this content is new. Liability: HIGH (filing-status math brushes the locked "asserting filing status" boundary; legal-adjacent).
6. **Household employers** (**recurring Zelle/Venmo to caregivers**): nanny-tax threshold, **Schedule H**, state registration — "compliance flag + unlocks the dependent care credit/FSA legitimately." (State registration piece → STATE-01-adjacent.)
7. **Truckers/DOT workers**: **special 80% meal deduction under hours-of-service rules**; per-diem method. (EXP-M4 lists truck drivers as travel-worker persona — different mechanic; keep both, cross-link.)
8. **Filing-status optimizer** (all married users): **MFS occasionally wins** — income-driven student-loan payments computed on separate income (**"often the dominant factor"**), medical AGI floor, liability separation. **Community-property states (incl. AZ) split income 50/50 on MFS — changes the math entirely; build state-aware.** ⚠ **CONFLICT FLAG:** "asserting filing status" is on the locked OUT-OF-SCOPE list and "build state-aware" collides with the STATE-01 deferral — this can only ever be an educational "MFS *may* be worth modeling with your preparer" surface, and needs an owner call (flag-for-user-decision).

---

## §8B. The Year-End Engine — Proactive Q4 Tax Planning

**Mission (verbatim):** "democratize the December CPA call. High earners get told how to time spending, income, and conversions before December 31; everyone else finds out what they owe in April, when nothing can be fixed. **Real-time bank data makes this the product's signature capability.**"

**PRIME DIRECTIVE (owner says "encode as a hard rule" — P13 guard):**
> *Never recommend spending solely to create a deduction.* A dollar spent saves only marginal-rate cents. The engine optimizes the **timing** of purchases the user already plans and needs — it asks **"what's on your purchase list for the next 6–12 months?"** and moves items across the year boundary; **it does not generate shopping ideas**. This is also the audit posture: **Q4 equipment with thin business use is a known exam pattern.**

EXP overlap: only the withholding/estimated-payment pieces (`⟐ EXP-M15` items 1–2, FLAG-03) and the low-year Roth-conversion/0%-harvesting bundle (`⟐ EXP-M9` worked example). The projector, equipment rules, solar rules, deadline wall, rescue kit, cliff layer, and cadence are all NEW.

### 8B.1 The bracket-trajectory projector ("the brain")

From YTD data, project full-year taxable income, marginal rate, and liability vs payments. Then **one comparison: this year's rate vs next year's expected rate.**
- **Higher this year** → accelerate deductions / defer income: buy planned equipment now, **prepay up to 12 months of expenses (rent, insurance, subscriptions — cash method)**, delay December invoicing into January, **pay PTET before Dec 31**, bunch charitable giving, pay accrued family-employee wages, write off bad debts and obsolete inventory.
- **Higher next year** → reverse everything: accelerate invoicing, defer purchases to January, and use the low year for **Roth conversions** and **0% gain harvesting** instead. `⟐ EXP-M9 worked example`
- **Same** → optimize around cliffs and cash flow only.

**Fits the deterministic pattern:** projection math = TaxRulesEngineService territory (Claude never computes dollars). No refund/liability *assertion* — projection is internal; outputs stay "may/could" (SAFE-01).

### 8B.2 Equipment timing rules (§179 / bonus)

- **"Placed in service by Dec 31" — delivered and ready for use, not ordered or paid.** Lead times mean **the alert fires in October**.
- **§179 capped at business income** (bonus is not, and can create a loss/NOL); **listed property (UTV, camera gear) needs >50% documented business use or depreciation recaptures**; vehicles **< 6,000 lbs GVWR hit luxury-auto caps**; **> 6,000 lbs unlocks the heavy-vehicle path at business-use %**.
- **Mid-quarter convention**: **>40% of the year's assets placed in service in Q4 slashes regular MACRS first-year depreciation** — **bonus depreciation is immune, making bonus the default tool for December purchases**.
- **Business-use substantiation begins day one: mileage/hour log starts at delivery.**

Liability: HIGH (audit-pattern territory; the prime directive is the mitigation).

### 8B.3 Solar and energy, honestly ⚠ liability-sensitive

- **Primary home, 2026+: NO federal credit. Recommend on utility economics only — never imply a write-off.** (**2023–2025 installs → amendment scanner.**)
- **Rental / STR / business property: still a real year-end lever** — **5-yr MACRS, bonus-eligible, possible business ITC (dates control; pro tier)**. "Same panels, different roof, different answer."

P13 guard candidate: hard-block any output implying a residential solar federal credit for 2026+ installs. (Solar-industry misinformation makes this a top liability surface.)

### 8B.4 December 31 hard-deadline wall ("calendar as product")

Dec-31 items (verbatim list): **Roth conversions** · **tax-loss and gain harvesting (securities T+1 settlement — last trade date matters; crypto too)** · **DAF and appreciated-stock gifts (custodian transfers take weeks — start early December)** · **solo 401(k) *establishment* (funding can wait)** · **401(k) deferral changes via final payrolls (bump % in Nov)** · **529 state deductions (most states)** [state layer → STATE-01] · **annual-exclusion gifts ($19K/donee)** · **QCDs and RMDs** · **FSA spend-down** · **PTET payments** · **equipment placed in service** · **family-employee wages actually paid**.

**April window ("breathing room"):** HSA, IRA, SEP contributions; **Q4 estimate Jan 15**.

### 8B.5 The December rescue kit (behind on payments)

- **Withholding time machine**: W-2 withholding is **deemed paid *evenly through the year*** — a **December W-4 spike or bonus-withholding election retroactively cures a full year of estimated-payment underpayment**. "The single best late fix; **estimated payments can't do this**."
- **Safe-harbor true-up math (100%/110% prior year) with exact dollar target.**
- Retirement/HSA contributions that still count; **loss harvesting to shrink the gain side**.

`⟐ EXP-M15.1–2 / FLAG-03` (under-withholding detection exists in P10) — the *December-timing remedy* and evenly-deemed-paid rule are NEW; merge as the remediation arm of FLAG-03.

### 8B.6 Cliff-aware December ("surgical layer")

**Every projected move is checked against thresholds already in the engine:**
- **ACA cliff** — "a **$2K SEP contribution near the line can be worth $10K+ of subsidy**" (ties to §2B.1)
- **IRMAA lookback years** (ties to §2C.3)
- **QBI/SSTB phaseouts** — "a **cash-balance or 401(k) contribution can restore the 20% deduction on *all* QBI**"
- **tips/OT deduction phaseouts ($150K/$300K)**
- **0% LTCG ceiling**
- **EITC investment-income limit**
- **child credit phaseouts**

"Near a cliff, **timing dollars are worth multiples of their face value** — this is where the engine visibly outperforms a generic 'buy a truck' heuristic."

### 8B.7 Cadence (calendar spec — verbatim dates)

| Date | Action |
|---|---|
| **Oct 1** | Full-year projection + **purchase-list interview** + equipment lead-time alerts |
| **Nov 15** | Withholding/deferral final adjustments (**payroll cutoffs**) |
| **Dec 1** | Charitable transfers initiated; conversion/harvesting decisions |
| **Dec 20** | Last-call checklist (**placed-in-service, RMD/QCD, FSA, gifts**) |
| **Jan 15** | Q4 estimate; **log everything executed for the filing packet** |

**Foundation dependency (verbatim):** "the profile graph, purchase-list interview, and YTD projection are why the 'getting to know the person' groundwork throughout the year exists — **the year-end engine is where that accumulated context pays out**." (→ argues for P11 interview capturing the purchase-list question and P10 profile snapshot feeding a future Year-End milestone.)

---

## Hard numbers introduced by this delta (config/tax-rules.php candidates)

| Constant | Value | Section |
|---|---|---|
| ACA cliff | 400% FPL; ~$62,600 single / ~$128,600 family-of-4 (continental US); enhanced credits expired 2025-12-31; NO repayment cap post-2025 | 2B.1 |
| 457(b)/403(b) separate limits | $24,500 each (~$49K stacked); 3-yr pre-retirement catch-up can double limit; no 10% penalty post-separation (governmental) | 2B.2 |
| Travel-worker tax-home hinge | assignment expected < 1 year; 12-month crossover | 2B.3 |
| Saver's Match | arrives 2027 | 2B.6 |
| SS tax torpedo | $0.50–$0.85 per IRA dollar; 40–50% effective marginal | 2C.2 |
| IRMAA | hard MAGI breakpoints, 2-year lookback; SSA-44 appeal | 2C.3 |
| QCD | age 70½+; ~$108K/yr | 2C.5 |
| Medicare/HSA | 6-month retroactive Part A lookback | 2C.10 |
| Gambling losses | deduction limited to 90% of losses from 2026 (OBBBA) | 2D.1 |
| §475(f) election | due prior April 15 | 2D.2 |
| Trucker meals | 80% under DOT hours-of-service | 2D.7 |
| §179/listed property | §179 capped at business income; >50% business use; 6,000 lbs GVWR line; mid-quarter >40% Q4 | 8B.2 |
| Solar | primary home 2026+: $0 federal credit; rental/business: 5-yr MACRS + bonus + possible ITC; 2023–2025 → amendment scanner | 8B.3 |
| Annual-exclusion gift | $19K/donee | 8B.4 |
| Safe harbor | 100% / 110% prior-year | 8B.5 |
| Tips/OT phaseouts | $150K / $300K | 8B.6 |
| Cadence | Oct 1 / Nov 15 / Dec 1 / Dec 20 / Jan 15; equipment alert October; Q4 estimate Jan 15 | 8B.7 |
| Farmland | §1062 installment treatment (OBBBA) | 2D.4 |

## New document/data requests this delta implies (beyond EXP list)

- Marketplace (healthcare.gov) premium detection from bank data + prior-year Form 1095-A (ACA monitor)
- SSA benefit statements / Medicare premium detection (decumulation)
- 401(k) rollover event detection + employer-stock basis facts (NUA check)
- W-2G forms (sports bettors); brokerage 1099-B trade counts (trader status)
- DFAS paystub/LES (military); Schedule F prior returns (farmers)
- Divorce decree / Form 8332 (divorcing users)
- **Purchase-list interview** ("what's on your purchase list for the next 6–12 months?") — new interview instrument, not a document
- Equipment delivery/placed-in-service dates + mileage/hour logs from day one

## Dedupe map (for the merge step)

| Delta item | EXP module | Keep on merge |
|---|---|---|
| 2B.1 ACA cliff | M9/M10.7 (thin) | DELTA primary |
| 2B.2 457(b) stacking | M3 | EXP questions + DELTA numbers/caveats |
| 2B.3 Travel workers | M4 | EXP primary (delta adds 12-mo hinge phrasing) |
| 2B.4 Reimbursement beats deduction | M5 | EXP primary + DELTA survivor list & HR explainer |
| 2B.5 Second data plane | M2 | EXP primary + DELTA adds group legal, Trump Accounts |
| 2B.6.1 IRA→HSA QFD | M7 | EXP primary (identical) |
| 2B.6.2 Caregivers | M14 | EXP primary + DELTA multiple-support agreements |
| 2B.6.3 Students | M11 | EXP primary (superset) |
| 2B.6.4 Clergy | M13 | EXP primary + DELTA Form 4361 + hard-block rule |
| 2B.6.5 Immigrants/expat | M12 | EXP primary (identical stance) |
| 2B.6.6 Credit scanner | M10 | EXP primary + DELTA Saver's-Match-2027 & EITC caveat |
| 2C (all 10) | none (M15.8 missed-RMD only) | DELTA — entirely new |
| 2D (all 8) | M9 "Divorce" trigger word only | DELTA — entirely new |
| 8B rescue kit | M15.1–2 / FLAG-03 | FLAG-03 detection + DELTA remedy |
| 8B everything else | none | DELTA — entirely new |
