# Tax Strategy Playbook — Distilled Planning Reference (v2.1 Optimize My Income)

> **Source:** `/home/spendifi/public_html/tax-strategy-playbook-2026.md` (owner's playbook, post-OBBBA 2026 edition).
> **Audience:** Phase 11 (FLAG/INT/FEED) and Phase 12 (RPT/DOC/UI) planners. Fed verbatim to planning agents.
> **Prime directive:** everything here is EDUCATIONAL-ONLY material. All outputs use "may / could / consider" framing. Claude words questions and narrates numbers pre-computed by `TaxRulesEngineService` / `config/tax-rules.php`. Claude NEVER computes dollars (SAFE-03). Nothing here overrides `.planning/REQUIREMENTS.md` Out-of-Scope table.

---

## 0. How to read this document

Each strategy block carries:

- **Risk:** `LOW` (plainly educational, safe to auto-surface) / `MEDIUM` (needs careful framing, prerequisite gating, docs checklist, or "surface as question only") / `HIGH` (crosses the advice line, collides with a locked out-of-scope item, is specialist-tier, or is on the hard-block list).
- **Disposition:** `P11` (detector / interview question / AI-feed), `P12` (report section / document intake / UI), `P13` (safety hardening, refusal list), `config` (constant belongs in `config/tax-rules.php`), `FLAG-FOR-USER-DECISION` (collides with locked scope — project owner must resolve before any planner touches it).
- **Mechanics / Numbers / Prerequisites / Interview Qs / Docs** — implementation-ready detail preserved from the source.

**Number precedence rule:** where this playbook and `config/tax-rules.php` disagree, **config wins** (it carries IRS citations). Known conflicts noted inline. All playbook figures marked "(verify)" must be confirmed against Rev. Proc. 2025-32 / Notice 2025-67 before entering config.

**Scope-collision register (resolve before planning):**

| Playbook item | Locked out-of-scope line it hits | Action |
|---|---|---|
| Asset location, direct indexing, muni/Treasury selection, §1256 vs spot comparison | "Investment allocation / securities advice (RIA registration)" | FLAG-FOR-USER-DECISION |
| 0% LTCG harvesting, tax-loss harvesting, crypto sell/rebuy ("sell winners, rebuy immediately") | Same — instructing security transactions is allocation advice | FLAG-FOR-USER-DECISION (educational bracket-headroom framing may survive; transaction instructions may not) |
| Playbook's entity table "Recommendation" column ("LLC + S-corp election") | "you should / you qualify" ban (SAFE-01) | Reframe as thresholds-to-consider, never a recommendation |
| Gray-area deductions (pets, Augusta, body-mod, racing) | "Gray-area deduction assertions" out-of-scope | Surface as QUESTIONS with doc checklists only — never assertions |
| PTET, AZ state credits, state exit planning, state domicile | "State tax optimization (v2.1)" — deferred to STATE-01 | Record in backlog; do NOT build in v2.1 |
| Refund/liability computation implied by "model the AMT crossover", "compute rolling quarterly estimates" | "Computing an actual refund/liability amount" | Quarterly safe-harbor math from prior-year liability is arithmetic on user-supplied numbers, not return prep — treat as allowed rules-engine math, but confirm with owner (FLAG-FOR-USER-DECISION for AMT modeling) |

---

## 1. 2026 Landscape Constants (→ `config/tax-rules.php` additions)

Already in config: brackets, standard deductions (+senior addition), 401(k)/IRA/HSA limits, SE tax, QBI ($201,750/$403,500 phaseout starts — supersedes playbook's ~$197K/~$394K), Roth bands.

**Missing constants the playbook requires (all "verify against Rev. Proc." before hardcoding):**

| Constant | Playbook value (2026) | Needed by |
|---|---|---|
| 415(c) total DC limit | ~$72,000 | Mega backdoor headroom |
| LTCG 0% bracket top | ~$49K single / ~$98K MFJ | 0% LTCG education (if in scope) |
| Standard mileage rate | 72.5¢/mi | Vehicle deduction probe |
| SALT cap | $40,000 through 2029; phases toward $10K above ~$500K MAGI | Itemize probe |
| Tips deduction cap | $25,000/return; phaseout from $150K single / $300K MFJ; 2025–2028 sunset | OBBBA detectors |
| Overtime deduction cap | $12.5K–$25K; same phaseouts; 2025–2028; W-2 box 12 codes TP/TT | OBBBA detectors |
| Senior deduction (OBBBA bonus) | $6,000 age 65+; MAGI phaseout $75K/$150K; 2025–2028 | OBBBA detectors |
| Auto loan interest deduction | up to $10,000; US-assembled vehicles; 2025–2028 | Loan detector |
| Charitable non-itemizer deduction | $1,000 single / $2,000 MFJ (2026+); itemizers: new 0.5%-of-AGI floor | Charitable probe |
| §179 limit / phaseout | $2,560,000 / $4,090,000 | Business capex |
| Child Tax Credit | $2,200 | Dependent probe |
| Annual gift exclusion | ~$19,000 (verify) | Estate education |
| Estate/gift exemption | $15M / $30M couple (indexed from 2027) | Estate education |
| QSBS cap / gross-asset test | $15M per taxpayer per issuer (or 10× basis) / $75M | Founder detector |
| Employer child-care credit max | $500K / $600K small biz | Owner report |
| Employer education assistance | $5,250/yr (incl. student-loan repayment, now permanent) | Benefits probe |
| QCD annual limit | ~$108K, age 70½+ | Charitable/senior probe |
| Educator expense | $300 | Occupation probe |
| Adoption credit | ~$17K, partially refundable (OBBBA) | Family probe |
| AOTC | $2,500 | Scholarship-election education |
| 529 K-12 distribution limit | $20K/yr (OBBBA) | 529 probe |
| 529→Roth lifetime rollover | $35K (15-yr-old accounts, SECURE 2.0) | 529 probe |
| Trump Account | $5,000/yr cap; employer $2,500 excluded; $1,000 seed births 2025–2028; funding opens July 4, 2026 | Family probe |
| NIIT | 3.8% above $200K/$250K MAGI | Investor education |
| Medical AGI floor | 7.5% (§213) | Medical probes |
| §1244 ordinary-loss cap | $50K / $100K MFJ | Founder education |
| FEIE | ~$130K + housing exclusion | Expat probe |
| Cruise convention cap | $2,000 | Travel edge case |
| De minimis safe harbor | $2,500/invoice | Business expensing |
| Home office simplified | $5/sq ft, $1,500 cap | Home-office probe |
| Startup cost immediate | $5,000 (§195) | New-business probe |
| Medical lodging | $50/person/night | Medical travel |
| Estimated-tax safe harbor | 100% prior-year liability / 110% if AGI > $150K | Quarterly scheduler |
| Solo 401(k) employer share | 20% of net SE earnings | Solo-401k headroom |
| Cash balance plan deduction range | $150K–$350K/yr (age 45+, actuarial) | Owner detector |
| Kiddie tax age bound | under 24 if student | Gifting education |
| Nonbusiness bad debt / worthless securities | STCL treatment; §165(g) | Loss probes |
| §1341 claim-of-right threshold | > $3,000 | Loss probes |

**Killed/curtailed (never surface as available):** most clean-energy/EV credits (EV credit ended Sept 2025); gambling losses limited to 90% of losses.

**Tax-year versioning is mandatory:** tips/OT/senior/auto-loan sunset after 2028; SALT reverts after 2029; pre-2027 QOF holders hit mandatory gain recognition end of 2026; inflation adjustments change annually. Every rule needs effective-date awareness (config is already year-keyed — keep it that way).

---

## 2. W-2 Employee Strategies

### W2-1. 401(k) maximization — Risk: LOW — P11 (FLAG-04 adjacent) + config (done)
- **Mechanics:** max employee deferral; catch-ups by age band; SECURE 2.0 mandatory Roth catch-up for high earners (engine already computes: TAX-04/05).
- **Numbers:** $24,500 deferral; +$8,000 age 50–59/64+; +$11,250 age 60–63 super catch-up; prior-year FICA wages > $150K (config: needs professional confirmation) → catch-ups MUST be Roth.
- **Prerequisites:** payroll deposits detected; age; pay-stub YTD deferral data.
- **Interview Qs:** "Does your employer offer a 401(k)? Do you know your current contribution percentage? Does your employer match — at what rate?"
- **Docs:** recent pay stub (screenshot OK — DOC-02), 401(k) plan statement.
- **Trigger:** payroll deposits + no/low deferral evidence → deferral-gap finding; unclaimed match → FLAG-04.

### W2-2. Mega backdoor Roth — Risk: MEDIUM — P11 detector + P12 report
- **Mechanics:** if plan allows after-tax contributions + in-plan Roth conversion or in-service rollover, total 415(c) limit ~$72K gives ~$40K+ extra Roth space above the normal deferral. Playbook calls it "the single most underused strategy for high-earning employees."
- **Prerequisites:** high salary deposits; plan features UNKNOWN from bank data — must ask.
- **Interview Qs:** "Does your 401(k) plan allow after-tax (non-Roth) contributions? Does it allow in-plan Roth conversion or in-service rollovers?" (both must be yes).
- **Docs:** 401(k) plan summary / SPD screenshot; pay stub showing contribution types.
- **Bank trigger:** high salary deposits + employer known to commonly offer this (tech/finance payroll names).
- **Framing risk:** plan-feature dependent; never state "you can contribute $40K" — narrate computed headroom with "if your plan allows."

### W2-3. HSA as stealth IRA — Risk: LOW (contribution) / MEDIUM (receipt-hoarding play) — P11 + config (done)
- **Mechanics:** triple tax-free. Advanced play: pay medical out of pocket, keep receipts indefinitely, let HSA compound invested, reimburse decades later tax-free.
- **Numbers:** $4,400 self / $8,750 family (config, cited); +$1,000 age 55+.
- **Prerequisites:** HDHP enrollment (min deductible $1,700/$3,400 — in config).
- **Interview Qs:** "Is your health plan a high-deductible plan (HDHP)? Are you contributing to an HSA? Through payroll (saves FICA too)?"
- **Docs:** benefits statement, insurance card/summary screenshot.
- **Note:** "let the HSA compound invested" brushes investment advice — keep to "HSA funds may be investable; consider discussing with a professional."

### W2-4. Backdoor Roth IRA — Risk: MEDIUM — P11 (explicitly INT-04 example) + config (done)
- **Mechanics:** nondeductible traditional IRA contribution → immediate Roth conversion when over Roth MAGI phaseout. **Pro-rata rule:** existing pre-tax IRA balances poison it — consider rolling them into the 401(k) first.
- **Numbers:** $7,500 IRA limit (config); Roth phaseout $153K–$168K single / $242K–$252K MFJ (config).
- **Prerequisites (INT-04 gate):** MUST ask IRA balance question before surfacing — "Do you have any existing pre-tax traditional/SEP/SIMPLE IRA balances?" Only probe backdoor if answer known.
- **Interview Qs:** IRA balances; MAGI band; whether workplace plan accepts roll-ins.
- **Docs:** IRA statement(s).

### W2-5. Equity compensation timing (RSU / ISO / NSO / ESPP) — Risk: MEDIUM–HIGH — P11 detectors; AMT modeling FLAG-FOR-USER-DECISION
- **RSU under-withholding:** default 22% supplemental withholding under-withholds for 32%+ bracket earners → estimated-payment-gap flag (ties to FLAG-03). Risk LOW-MEDIUM; purely arithmetic.
- **ISO timing:** exercise early in the year preserves disqualifying-disposition escape hatch before AMT locks in; "model the AMT crossover point" — AMT modeling is close to liability computation → FLAG-FOR-USER-DECISION; safe version: "ISO exercises may have AMT implications; consider professional review before exercising."
- **NSO:** exercise in low-income years. Educational timing note only.
- **ESPP:** 15% discount + lookback ≈ free money; qualifying vs disqualifying disposition education. Risk LOW as participation education; modeling dispositions = MEDIUM.
- **Interview Qs:** "Do you receive RSUs/options/ESPP? Vesting this year? Have you checked withholding on vests?"
- **Docs:** equity grant summary, brokerage vest statement, pay stub showing supplemental withholding.
- **Bank trigger:** brokerage transfers + large irregular deposits from employer/broker.

### W2-6. 83(b) election alarm — Risk: MEDIUM (time-critical) — P11 high-priority detector
- **Mechanics:** early-stage restricted stock — file 83(b) within **30 days of grant**. Non-recoverable if missed; "one of the most expensive mistakes in tax."
- **Trigger:** startup equity / C-corp formation signals.
- **Interview Qs:** "Have you recently received restricted stock (not RSUs) in a startup? Grant date?" If within 30 days → highest-severity finding (FLAG-06) routed to feed immediately.
- **Docs:** grant agreement.
- **Framing:** "an 83(b) election has a strict 30-day deadline — consider contacting a professional immediately" — urgency is fine; the deadline is fact, not advice.

### W2-7. 0% LTCG gain harvesting — Risk: HIGH — FLAG-FOR-USER-DECISION
- **Mechanics:** taxable income under ~$49K single / ~$98K MFJ (verify) → 0% LTCG. Sell winners, rebuy immediately (no wash sale on gains), reset basis free. Big in sabbatical/between-jobs/early-career years.
- **Collision:** "sell winners, rebuy" is a securities transaction instruction → RIA out-of-scope. Possible surviving form: "your income may fall in the 0% long-term capital gains bracket this year — a professional could review whether that's relevant to any investments you hold." Owner must approve any form of this.

### W2-8. Roth conversions in low-income years — Risk: MEDIUM — P11 + P12
- **Mechanics:** job gap / grad school / early retirement → fill 10/12/22% brackets with traditional→Roth conversions. Engine already computes bracket headroom (TAX-02) and Roth band (TAX-05).
- **Trigger:** payroll deposits stop / income drop detected vs prior months.
- **Interview Qs:** "Has your income dropped this year (job change, sabbatical)? Do you hold pre-tax retirement balances?"
- **Docs:** retirement account statement.
- **Framing:** narrate computed bracket headroom in dollars from the engine; "converting may fill lower brackets" — never a directive.

### W2-9. Tax-loss harvesting — Risk: HIGH — FLAG-FOR-USER-DECISION
- **Mechanics:** losses offset gains + $3,000/yr ordinary income; indefinite carryforward; 30-day wash-sale rule (substantially identical securities).
- **Collision:** instructing sales = securities advice. Educational glossary-level content may be acceptable; detector-driven "harvest now" is not.

### W2-10. Charitable bunching + donor-advised fund — Risk: LOW-MEDIUM — P11 + P12
- **Mechanics:** stack 2–3 years of giving into one year to clear the standard deduction; grant out over time via DAF. Donate appreciated stock, not cash — FMV deduction, embedded gain never taxed. New 0.5%-of-AGI floor for itemizers from 2026; non-itemizers get $1,000/$2,000.
- **Trigger:** recurring charitable payments in bank data.
- **Interview Qs:** "Roughly how much do you give annually? Do you itemize? Do you hold appreciated investments?" (last question — careful: appreciated-stock donation is charitable strategy, not allocation; generally accepted as tax education).
- **Docs:** donation receipts; >$5K non-cash requires **qualified appraisal**.
- **Engine already computes standard-vs-itemized (TAX-03)** — bunching narrative hangs off that.

### W2-11. Deferred comp (NQDC/409A) — Risk: MEDIUM — P12 report education only
- **Mechanics:** executives defer bonus into lower-income future years. **Credit risk of employer applies** — must be stated.
- **Interview Qs:** "Does your employer offer a deferred-compensation plan?"
- **Docs:** NQDC plan document.

### W2-12. Tips deduction (OBBBA) — Risk: LOW — P11 + config
- **Numbers:** up to $25,000/return; phaseout begins $150K single / $300K MFJ; 2025–2028.
- **Trigger:** tip-heavy income patterns; occupation answer.
- **Interview Qs:** "Does your work include tip income?"
- **Docs:** W-2 (box codes), pay stubs.

### W2-13. Overtime deduction (OBBBA) — Risk: LOW — P11 + config
- **Numbers:** cap $12.5K–$25K; same phaseouts; 2025–2028; W-2 box 12 codes **TP and TT** in 2026 (detector can read these from vault-extracted W-2s).
- **Trigger:** hourly-pattern payroll with variable amounts; W-2 codes.
- **Docs:** W-2, pay stubs.

### W2-14. Auto loan interest deduction (OBBBA) — Risk: LOW — P11 + config
- **Numbers:** up to $10,000 interest; US-assembled vehicles; 2025–2028.
- **Trigger:** recurring auto-loan payment in bank data (detectable today via loan categories).
- **Interview Qs:** "Was the vehicle assembled in the US? Purchase year? Loan or lease?"
- **Docs:** loan statement showing interest paid; VIN (assembly plant decode is possible — nice-to-have).

### W2-15. Senior deduction (OBBBA) — Risk: LOW — P11 + config
- **Numbers:** extra $6,000 for 65+; MAGI phaseout $75K/$150K; 2025–2028. (Distinct from the age-65 standard-deduction addition already in config — don't conflate.)
- **Trigger:** age from profile; Social Security deposits.

### W2-16. Dependent care FSA + Child & Dependent Care Credit — Risk: LOW — P11 + config
- **Mechanics:** expanded credit up to 50% of expenses (OBBBA). Summer **day** camp qualifies; overnight camp does not.
- **Trigger:** childcare/daycare merchant payments in bank data.
- **Interview Qs:** "Do you pay for childcare? Does your employer offer a dependent-care FSA?"
- **Docs:** provider receipts, provider EIN.

### W2-17. 529 plans — Risk: LOW (federal parts) — P11 + config; state deduction → STATE-01
- **Mechanics:** state deduction in most states (OUT OF SCOPE v2.1 — note only); K-12 distributions now $20K/yr; OBBBA broadened qualified expenses; superfunding = 5 years of gift exclusion at once (~$95K); 529→Roth $35K lifetime for 15-yr-old accounts.
- **Trigger:** 529 provider transfers; tuition/school payments.
- **Interview Qs:** "Do you have children? Existing 529 accounts? Private K-12 tuition?"

### W2-18. Trump Account (kids) — Risk: LOW — P11 + config
- **Numbers:** children under 18; $5,000/yr cap; employer can contribute $2,500 excluded from wages; funding opens July 4, 2026; $1,000 federal seed for kids born 2025–2028.
- **Interview Qs:** "Do you have children under 18? Does your employer offer Trump Account contributions? (worth asking HR)."

---

## 3. Side Hustlers (highest-ROI segment per playbook: $5K–$25K/yr gap)

### SH-0. Hobby-vs-business legitimization + commingling detector — Risk: MEDIUM — P11 (core detector)
- **Mechanics:** Schedule C losses require profit motive (§183 9-factor test). Presumption: profit 3 of 5 years. Engine scores: separate bank account, books/records, time invested, expertise, profit history.
- **Product feature (owner-specified):** detect commingled personal/business spending → prompt to open a separate account. "This single behavior is the top defense in a hobby-loss audit." AccountPurpose enum + expense_type data already exist.
- **Interview Qs:** "Is this activity intended to make a profit? Separate bank account? Hours per week? Profitable years so far?"
- **Framing:** commingling warning is a safety feature — LOW risk to surface; hobby-loss scoring language must avoid "you qualify as a business."

### SH-1. Home office deduction — Risk: MEDIUM (FLAG-05 listed probe) — P11
- **Mechanics:** exclusive-use space. Simplified: $5/sq ft, cap $1,500. Actual-expense method usually bigger (partial utilities, insurance, depreciation).
- **Prerequisites (FLAG-05):** verified self-employment income + housing data before probing.
- **Interview Qs:** "Do you have a space used exclusively and regularly for the business? Square footage? Rent or own?"
- **Docs:** floor plan / photo, utility bills, rent/mortgage statements.
- **Bonus (8A):** principal-place-of-business home office converts commuting → deductible business miles ("often worth more than the office itself").

### SH-2. Vehicle / mileage — Risk: MEDIUM (FLAG-05 listed probe) — P11 + config
- **Mechanics:** standard mileage 72.5¢/mi (2026) or actual + depreciation. **Contemporaneous mileage log is the audit battleground — playbook explicitly says build a log feature** (product opportunity, likely post-v2.1; note in backlog).
- **Interview Qs:** "Do you drive for the business? Log kept? Roughly what % business use?" 100% business use is an audit flag — never encourage.
- **Docs:** mileage log, vehicle purchase docs.

### SH-3. Equipment expensing (§179 / bonus) — Risk: LOW — P11 + P12
- **Mechanics:** computers, cameras, tools, machinery fully deductible year one via §179 (to $2.5M) or 100% bonus (permanent, property acquired after Jan 19, 2025).
- **Trigger:** electronics/equipment purchases in business-purpose accounts (FLAG-05 electronics probe).
- **Docs:** purchase receipts/invoices.

### SH-4. Ordinary business expense cluster — Risk: LOW — P11 + P12
- Phone/internet business-use %, software, education *maintaining current skills* (NOT qualifying for a new trade — the distinction matters and should appear in the question), business travel, **50% meals** (FLAG-05 meals probe), startup costs $5K immediate + amortization (§195).
- **Trigger:** recognizable merchant categories in business account.
- **Docs:** receipts; for education — course description showing skill maintenance.

### SH-5. Self-employed health insurance — Risk: LOW — P11
- **Mechanics:** 100% above-the-line if not eligible for employer coverage (incl. spouse's).
- **Trigger:** health-insurance premium payments + Schedule C proxy income.
- **Interview Qs:** "Are you eligible for coverage through an employer or spouse's employer?"

### SH-6. Estimated tax safe harbor / quarterly scheduler — Risk: LOW — P11 (ties to FLAG-03) + P12
- **Mechanics:** pay 100% of prior-year liability (110% if AGI > $150K) quarterly → no underpayment penalty regardless of current income. **Owner-specified product feature: compute rolling quarterly estimates from live bank inflows** — "the most common self-inflicted wound in this segment, fully preventable."
- **Prerequisites:** prior-year liability (user-supplied or from vault-extracted 1040); business inflows with no estimated-payment outflows to IRS detected.
- **Interview Qs:** "What was your total tax liability last year (line 22 of your 1040)? Have you made quarterly estimated payments this year?"
- **Docs:** prior-year 1040 (vault), IRS payment confirmations.
- **Scope note:** this is arithmetic on a user-supplied prior-year number, not computing a current-year refund — but confirm with owner it clears the "computing liability" boundary.

### SH-7. Solo 401(k) — Risk: LOW-MEDIUM — P11 detector + config
- **Mechanics:** employee deferral ($24,500) + employer contribution (20% of net SE earnings) — can shelter $70K+. Beats SEP-IRA because (a) employee deferral works on modest profit, (b) doesn't block backdoor Roth (SEP balances hit pro-rata).
- **Owner trigger:** Schedule C proxy net > $10K and no solo-401(k) contributions detected.
- **Interview Qs:** "Do you have a solo 401(k) or SEP-IRA for this income? Any employees besides a spouse?" (employees kill solo-401k eligibility — must gate).
- **Docs:** plan statement.

### SH-8. QBI 20% deduction — Risk: LOW — done in P10 (TAX-06) + P12 narrative
- **Numbers (config wins):** phaseout starts $201,750 single / $403,500 joint, windows $75K/$150K; SSTB elimination $276,750/$553,500; OBBBA $400 minimum deduction when QBI ≥ $1,000 + material participation. Above threshold: W-2 wage / UBIA tests — one educational reason an S-corp election *creates* W-2 wages (cross-link SH-11).
- **Interview Qs:** "Is the business a specified service business (health, law, consulting, financial services)?"

### SH-9. Hire your kids — Risk: MEDIUM — P11 question-only + P12 doc checklist
- **Mechanics:** children under 18 employed by parent's **sole prop or spousal partnership**: no FICA/FUTA, wages deductible, child's ~$16,100 standard deduction makes first ~$16K tax-free to them; then fund child's Roth IRA with earned income. **S-corps LOSE the FICA exemption** — playbook suggests family management company or keeping the sole prop for this.
- **Requirements (documentation checklist):** real work, market-rate pay, timesheets, actual payment, W-2s filed.
- **Interview Qs:** "Do you have children who could do genuine work for the business (age, tasks)?"
- **Docs:** timesheets, job description, payroll records.
- **Framing:** surface as "some business owners employ their children — this has strict requirements; here's the documentation a professional would want." Never "hire your kids to save $X."

### SH-10. Augusta rule (§280A(g)) — Risk: MEDIUM-HIGH — P11 question-only + P12 doc checklist
- **Mechanics:** rent your home to your business ≤14 days/yr — income tax-free to you, deductible to the business. Requires an entity (S/C-corp), board-meeting documentation, comparable local rental rate comps, invoices. "Legal and safe when documented; a top audit-adjustment item when it's not."
- **Prerequisites:** entity exists (S/C-corp) — gate hard.
- **Docs checklist (ship with the finding):** rental comps, board minutes, invoices, day-count ≤14.
- **Cross-ref:** personal 14-day rental rule (RE-8) is the no-entity cousin.

### SH-11. Entity decision tree — Risk: MEDIUM-HIGH — P12 educational module; NEVER a recommendation
- **Playbook table (reframe every "Recommendation" as "commonly considered at this level"):**
  - < $10K net: sole prop + separate bank account (entity overhead not worth it)
  - $10K–$50K: LLC default taxation (liability protection, taxes unchanged, ~$50–$800/yr state cost)
  - $50K–$60K+: LLC + S-corp election (Form 2553) — reasonable W-2 salary + distributions; distributions escape 15.3% SE tax; break-even vs $1.5–3K/yr payroll/compliance cost lands ~$50–60K net
  - $250K+ or seeking investment: model C-corp (21% flat, QSBS potential, retained earnings, fringe)
- **S-corp caveats the engine must state:** reasonable compensation (IRS's #1 S-corp attack; 40–60% of profit is a *starting heuristic* only), payroll filing burden, QBI interaction (salary reduces QBI — optimize jointly).
- **Election lock warning (must surface before any classification talk):** classification changes generally locked for **60 months** — five-year commitments.
- **Owner trigger:** Schedule C proxy net > $50K → "comfort with payroll?" interview question → S-corp election *analysis* (conditional tier).
- **Framing:** "you should elect S-corp" is banned. "At this profit level, many owners have a professional model an S-corp election; the trade-offs are…" is the ceiling.

### SH-12. PTET election — Risk: MEDIUM — OUT OF SCOPE v2.1 (STATE-01 backlog)
- ~36 states; entity pays state tax → federally deductible → sidesteps SALT cap. Record only.

### SH-13. Accountable plan — Risk: LOW — P11 + P12 (playbook "auto-recommend" tier)
- **Mechanics:** once an entity exists, reimburse yourself for home office, mileage, phone — deductible to company, tax-free to you.
- **Prerequisites:** entity exists.
- **Docs:** written plan document, expense reports.

---

## 4. Real Estate

### RE-1. Short-term rental loophole — Risk: HIGH — P11 question-only, specialist-referral output
- **Mechanics:** average guest stay ≤ 7 days + material participation (e.g., 100+ hours and more than anyone else) → losses NON-passive, offset W-2 income.
- **Trigger:** rental income deposits (Airbnb/VRBO payouts).
- **Interview Qs:** "Average guest stay? Who manages it? Hours you personally spend?"
- **Docs:** hour log (the audit battleground), platform statements.

### RE-2. Cost segregation + 100% bonus — Risk: HIGH — P12 specialist-referral education
- **Mechanics:** engineering study reclassifies 20–35% of building into 5/7/15-year property, immediately deductible. $500K STR → $100–150K first-year paper loss against W-2 salary. Playbook: "single most powerful legal strategy for high-income W-2 earners — and the most audit-sensitive; the hour log is everything."
- **Route:** specialist-review tier ALWAYS (needs engineering study).

### RE-3. Real estate professional status (REPS) — Risk: HIGH — P11 question-only
- **Mechanics:** 750 hrs + more than half of working time in real estate (usually needs non-working or RE-career spouse) → all rental losses non-passive. Also escapes NIIT on rental income.
- **Docs:** contemporaneous hour log.

### RE-4. 1031 exchange — Risk: MEDIUM — P12 education (playbook "conditional" tier)
- **Numbers:** 45-day identification / 180-day close; chain until death. Dead for crypto since 2018.
- **Trigger:** property-sale-sized deposits + rental history.

### RE-5. Buy, borrow, die — Risk: HIGH — FLAG-FOR-USER-DECISION (P12 glossary at most)
- **Mechanics:** never sell; borrow against appreciated assets (loan proceeds ≠ income); heirs get stepped-up basis erasing deferred gain. $15M/$30M exemption makes it broadly viable.
- **Risk:** leveraging-against-securities education borders investment advice; also estate strategy. Glossary-level only if owner approves.

### RE-6. Opportunity Zones 2.0 — Risk: MEDIUM-HIGH — P11 time-critical detector + P12
- **Mechanics:** roll capital gains into QOF within 180 days. Post-2026 investments: 5-yr deferral, +10% basis (+30% rural), tax-free appreciation at 10 years (basis step-up frozen at 30 years).
- **TIME-CRITICAL:** pre-2027 QOF holders face **mandatory gain recognition at end of 2026** — detector should ask existing-QOF question this year and flag for professional planning ("loss harvesting or re-deferral" is the pro's call, not ours).
- **Interview Qs:** "Do you hold any Opportunity Zone fund investments? Invested before 2027?"

### RE-7. §121 primary-residence exclusion — Risk: LOW — P11 + P12
- **Numbers:** $250K/$500K gain exclusion; 2-of-5-year rule; serial house-hacking is legitimate. Partial exclusion under 2 years for job move (50+ mi), health, or unforeseen circumstances — prorated. Home-office-in-same-dwelling: no gain allocation, only depreciation recapture.
- **Product feature (8A):** **basis-building tracker** — log every improvement to shrink future gain past the exclusion. Passive, LOW risk, good P12/backlog candidate.

### RE-8. Personal 14-day rental rule ("Masters rule") — Risk: LOW — P11 + P12
- **Mechanics:** rent your home ≤14 days/yr → income completely tax-free, unlimited amount. "Scottsdale-corridor gold every February." **Day 15 makes ALL of it taxable — track the day count hard.**
- **Trigger:** Airbnb-style deposits without ongoing rental pattern.
- **Interview Qs:** "How many total days did you rent your home this year?"

---

## 5. Business Owners & Corporations

### BO-1. Cash balance / defined benefit plan — Risk: MEDIUM — P11 detector, specialist-referral output
- **Numbers:** owners 45+ with strong profits can deduct $150K–$350K/yr on top of 401(k)/profit-sharing.
- **Owner trigger:** age > 45 AND consistent profit > $300K.
- **Route:** specialist tier (requires actuary, consistent funding commitment).

### BO-2. Depreciation & capex stack — Risk: LOW-MEDIUM — P12 + config
- 100% bonus (permanent); §179 to $2.56M/$4.09M phaseout; OBBBA 100% expensing for **qualified production property** (US factory/production buildings in service through 2030).
- **Heavy vehicles > 6,000 lbs GVWR:** §179 up to ~$31K + bonus on rest, at business-use % — "classic audit item when 100% business use is claimed on the family SUV" → never encourage 100%.

### BO-3. §174A R&D expensing + amend-back — Risk: MEDIUM — P11 question + specialist referral
- Domestic R&D immediately expensible again; small businesses (< $31M gross receipts) can **amend 2022–2024** to reclaim capitalized amounts.
- **Interview Qs:** "Did you capitalize R&D costs 2022–2024? Gross receipts under $31M?"

### BO-4. §41 R&D credit — Risk: MEDIUM — P11 detector + specialist referral
- Dollar-for-dollar credit for developing products, firmware, software, prototypes; startups can apply up to **$500K against payroll tax** with no income tax. "Wildly underclaimed by small hardware/software shops." Caution (from §9): unsupported R&D credits from credit mills are an audit trigger — pair the finding with the warning.
- **Trigger:** payroll + dev-tool/component spending patterns; occupation answers.

### BO-5. QSBS (§1202) — Risk: HIGH — P11 early-eligibility flag; specialist-referral ALWAYS
- **Numbers (post-OBBBA, stock issued after July 4, 2025):** C-corp stock, gross assets < **$75M** at issuance, original issuance, active business (not law/health/finance services). Exclusion **50% at 3 yrs / 75% at 4 yrs / 100% at 5 yrs**; cap **$15M** per taxpayer per issuer (or 10× basis).
- **Stacking:** gift shares to non-grantor trusts — each trust gets its own $15M ($60M exit fully excluded across four trusts). "Highest-stakes legal planning in the code; requires attorneys — but the engine should flag eligibility early (entity choice at formation determines it)."
- **§1045 rollover:** sold before 5 years → roll into new QSBS within 60 days, keep the clock.
- **Trigger:** C-corp formation / startup equity signals → ask gross assets + issue date.

### BO-6. Compensation & fringe stack — Risk: LOW-MEDIUM — P12
- Accountable plans; HSA+HDHP; group health; QSEHRA/ICHRA for small employers; employer Trump Account $2,500 (excluded from wages); education assistance $5,250/yr incl. **student-loan repayment (now permanent)**; Augusta-rule board meetings.
- **Income shifting:** employ spouse (unlocks spousal retirement plan space) + kids (SH-9); family limited partnerships for larger operations (specialist).
- **8A angle:** S-corp owners can offer education assistance to employee-family-members within nondiscrimination limits (🟡 — MEDIUM).

### BO-7. Employer-provided child care credit — Risk: LOW — P12 + config
- Up to $500,000 max credit ($600,000 eligible small businesses), expanded 2026. "Massively underused; pairs with dependent-care FSA design."

### BO-8. Work Opportunity Tax Credit — Risk: LOW — P12
- Per-hire federal credit for targeted groups; "most small employers never screen." Interview Q: "Do you screen new hires for WOTC eligibility (Form 8850)?"

### BO-9. Worker classification review — Risk: MEDIUM (safety feature) — P11 detector
- 1099-vs-W-2 turns on control/independence facts, not labels. Misclassification = back payroll taxes + penalties + state actions.
- **Owner engine rule:** recurring payments to the same individuals for services core to the business → structured classification questionnaire. "Catching it before the IRS or a state DOL does is a genuine safety feature."
- **Framing:** pure warn-and-educate. Never "reclassify them."

### BO-10. Timing & method — Risk: LOW — P12
- Cash-method: accelerate expenses / defer invoicing across year-end; **12-month prepay rule**; de minimis safe harbor $2,500/invoice (election statement required).
- Corporate charitable: C-corps deduct up to 10% of taxable income; OBBBA 1% floor → bunch corporate giving.

### BO-11. Exit planning menu — Risk: HIGH — P12 education, specialist-referral ALWAYS
- Installment sales (spread gain); ESOP sales (§1042 deferral, C-corps); OZ rollover of gain; CRTs for concentrated positions (income stream + deduction + no immediate gain); QSBS if runway set up 5 years earlier. Core message: "entity selection is a day-one decision, not a year-five one."

---

## 6. Investors — ⚠️ HEAVILY COLLIDES WITH LOCKED OUT-OF-SCOPE (RIA)

Every item below except NIIT education and the 61-day dividend fact involves telling users where/what to buy, sell, or hold. **Default: FLAG-FOR-USER-DECISION; do not plan features on these without owner sign-off.**

| # | Strategy | Mechanics/numbers | Risk | Disposition |
|---|---|---|---|---|
| IV-1 | Asset location | Bonds/REITs in tax-deferred, stocks in taxable, highest-growth in Roth; worth 0.2–0.5%/yr | HIGH | FLAG-FOR-USER-DECISION |
| IV-2 | Direct indexing | Continuous loss harvesting at scale | HIGH | FLAG-FOR-USER-DECISION |
| IV-3 | Municipal bonds / Treasuries | Fed-tax-free munis; state-tax-free Treasuries (state layer out of scope anyway) | HIGH | FLAG-FOR-USER-DECISION |
| IV-4 | NIIT 3.8% education | Applies above $200K/$250K MAGI; REPS rental income and S-corp active distributions can escape it | MEDIUM | P12 education + config |
| IV-5 | Qualified dividends 61-day holding | Fact-level education | LOW | P12 glossary |
| IV-6 | Gift appreciated stock to adult kids in 0% LTCG bracket | Kiddie tax if under 24 and a student | MEDIUM-HIGH | FLAG-FOR-USER-DECISION (gifting mechanics OK; "to realize gains at 0%" is transaction advice) |
| IV-7 | Estate layer | $19K/yr annual exclusion per donee (verify); 529 superfunding (5 yrs at once); SLATs/IDGTs/GRATs only above ~$15M/$30M net worth; below that prioritize basis step-up | MEDIUM | P12 glossary education; trusts = specialist referral |
| IV-8 | Upstream gifting | Gift appreciated assets to trusted parent with unused exemption → basis reset at their death. "Advanced, attorney-required" | HIGH | P12 mention-with-referral at most |

---

## 6A. Crypto — Advanced Stack

**Foundation (LOW risk, genuinely protective):** Form 1099-DA broker reporting began 2025 (gross proceeds; basis phasing in); Rev. Proc. 2024-28 mandates **wallet-by-wallet basis tracking** (universal pooling ended Jan 1, 2025). "Unreconciled basis defaults toward zero — accuracy is itself a savings strategy." → **CR-0 Basis reconciliation** is core-infrastructure-grade; realistically a post-v2.1 feature, record in backlog; the *warning* that basis records matter is P11-safe.

| # | Strategy | Mechanics / numbers | Risk | Disposition |
|---|---|---|---|---|
| CR-1 | Crypto inside Roth solo 401(k)/SDIRA | Cash contributions only (in-kind = prohibited transaction); custodian must control keys (*McNulty v. Comm'r* killed checkbook-LLC self-custody); margin inside IRA can trigger UBTI; gains permanently untaxed | HIGH | FLAG-FOR-USER-DECISION (account-type education vs trading advice line is thin) |
| CR-2 | Roth conversion on drawdowns | Convert traditional-account crypto in-kind during crashes — tax on depressed value, recovery tax-free. Playbook wants drawdown-triggered alerts | HIGH | FLAG-FOR-USER-DECISION (market-timing alerts ≈ investment advice) |
| CR-3 | §1256 crypto futures | CME futures: 60/40 LTCG/STCG + mark-to-market, regardless of holding period | HIGH | FLAG-FOR-USER-DECISION (instrument selection = securities advice) |
| CR-4 | Loss harvesting, no wash sale | Spot crypto outside wash-sale statute as of early 2026 — sell, book loss, rebuy immediately. **Tag legislation-risk in engine; closure perennially proposed** | HIGH | FLAG-FOR-USER-DECISION |
| CR-5 | Specific ID / HIFO lot selection | Cuts realized gains 20–40% for active traders vs FIFO; must document per-wallet at time of sale | MEDIUM | P12 education (accounting-method education, not allocation) |
| CR-6 | 0% bracket / gift / step-up (crypto) | Same as W2-7 / IV-6 | HIGH | FLAG-FOR-USER-DECISION |
| CR-7 | Donate appreciated crypto | FMV deduction, gain never taxed; DAF-friendly; **>$5K requires qualified appraisal — exchange screenshots insufficient (IRS CCA 202302012)**; build appraisal checklist into flow | MEDIUM | P11 question + P12 doc checklist |
| CR-8 | OZ rollover of crypto gains | One of the only crypto deferral tools (1031 dead since 2018); 180-day window | MEDIUM | P12 education + referral |
| CR-9 | Borrow against holdings | No realization; warn: forced liquidation = deemed sale at the bottom | HIGH | FLAG-FOR-USER-DECISION |
| CR-10 | Mining/staking as business | Ordinary income at receipt (staking: Rev. Rul. 2023-14); at scale: business — bonus depreciation on rigs, electricity/hosting deductions, S-corp for SE tax once profitable; hobby-level = income with no deductions | MEDIUM | P11 question + P12 |
| CR-11 | Puerto Rico Act 60 | 0% on gains accruing after relocation; pre-move appreciation stays US-taxable; 183+ days + closer-connection facts; heavily audited | HIGH | P12 mention-with-specialist-referral at most |
| CR-12 | PPLI | $2–5M minimum premiums; §817(h) diversification; **investor control doctrine (*Webber*) collapses it if client directs trading**. "Specialist-referral only; NEVER auto-recommend." **Hard-block adjacent pitches:** offshore crypto-IRA wrappers, foreign annuity/insurance concealment (FBAR/FATCA) | HIGH | P13 (never auto-recommend + hard-block list) |

**Crypto engine warnings (LOW risk, ship as P11 detectors — protective):**
- Spending crypto is a taxable disposal — flag crypto-funded purchases as realization events (gain calc = rules-engine arithmetic on user-supplied basis).
- Wallet-to-wallet moves ≠ taxable but break naive basis tracking — reconcile transfers.
- Staking/airdrop/fork receipts = ordinary income at FMV on receipt date — detect inflows with no corresponding purchase.
- State exit planning (realize gains after establishing no-tax-state residency; CA/NY pursue aggressively) — **STATE-01, out of scope v2.1.**

**Bank triggers:** Coinbase/Kraken/exchange transfers → ask wallets/basis records/trading frequency; crypto inflows with no purchase → staking/mining/airdrop question.

---

## 7. Nonprofits & 501(c)(3) — Mostly detect-and-block

### NP-0. Nonprofit-as-personal-shelter — Risk: HIGH — P13 HARD-BLOCK
- Any "run my income through a nonprofit" framing → hard block + educate: private inurement / excess-benefit (§4958) = 25% excise on the insider personally, 200% if uncorrected, + revocation. IRS "abusive trust/nonprofit" schemes list ≈ prosecution roadmap.

### Legitimate alternatives to offer instead (P12 education):
| # | Tool | Numbers / mechanics | Risk |
|---|---|---|---|
| NP-1 | Donor-advised fund | Immediate deduction, invest + grant over time, ~zero admin. "Right answer for 99% who ask about starting a foundation" | LOW |
| NP-2 | Donate appreciated long-term stock | FMV deduction, gain vanishes | LOW-MEDIUM (see W2-10) |
| NP-3 | QCDs | Age 70½+, up to ~$108K/yr IRA→charity direct; excluded from income; counts toward RMDs; beats itemizing | LOW |
| NP-4 | Private foundation | Only above ~$1–5M committed philanthropy; 1.39% excise, 5% payout, real admin | MEDIUM (referral) |
| NP-5 | Charitable remainder trust | Exit concentrated positions: income stream + deduction + no immediate gain | HIGH (specialist) |

### NP-6. UBIT trap warning — Risk: LOW (protective) — P12
- Even a legit 501(c)(3) pays corporate tax on unrelated business income + Form 990-T.

### NP-7. Exempt-category routing + entity-confusion catches — Risk: LOW-MEDIUM — P11/P12
- Route by purpose: charitable/educational/religious → (c)(3); advocacy → (c)(4) (no donor deduction, lobbying OK); trade association → (c)(6); social club → (c)(7).
- Catch: "coops," multiple pointless LLCs, **Wyoming/Nevada LLC mythology** (state of operation controls taxation — does nothing for a solo operator elsewhere except fees), **"corporation sole" / "pure trust" packages → IRS Dirty Dozen, criminal referrals → P13 HARD-BLOCK.**

---

## 8. Cross-Cutting Risk Table (owner's own banding — reuse for FLAG-06 severity)

| Strategy | Savings potential | Risk | Guardrail |
|---|---|---|---|
| STR + cost seg vs W-2 | Very high | Elevated audit | Hour logs, real STR operation |
| S-corp comp optimization | High | Moderate | Reasonable-comp study |
| QSBS stacking via trusts | Extreme | Low if papered | Attorneys, non-grantor status real |
| Cash balance plan | High | Low | Actuary, consistent funding |
| Augusta rule | Moderate | Elevated | Comps, minutes, invoices, ≤14 days |
| Hiring kids | Moderate | Moderate | Real work, timesheets, W-2s filed |
| PTET election | High (high-tax states) | Low | Timely state election (STATE-01) |
| **831(b) micro-captive** | — | **Listed transaction** | **P13 HARD-BLOCK** |
| **Syndicated conservation easement** | — | **Listed transaction** | **P13 HARD-BLOCK** |
| **Offshore structures for US persons** | — | **FBAR/FATCA criminal exposure** | **P13 HARD-BLOCK** |
| **Malta pension / foreign trusts** | — | **Dirty Dozen** | **P13 HARD-BLOCK** |

---

## 8A. Minutiae Library — Edge-Case Deductions (Pro-Approval Tier)

**Owner's engine rule for this ENTIRE section:** every surfaced item ships with (1) legal basis, (2) documentation checklist, (3) defensibility rating (🟢 solid with docs / 🟡 fact-dependent / 🔴 frequently abused), (4) a **"send to my tax pro" export** packaging fact pattern + substantiation. All items = surface-as-question-only (out-of-scope table bans gray-area *assertions*). P12 owns the pro-export; P11 owns the probes.

### Animals (all MEDIUM risk, question-only)
| Play | Legal basis | Docs | Rating |
|---|---|---|---|
| Guard dog for business premises | Ordinary & necessary security expense; dog depreciated 7-yr; food/vet/training at business % | Appropriate breed, kept at business site, security-purpose memo, cost log | 🟡 |
| Pest-control cats | *Seawright v. Comm'r* (junkyard cats) | Genuine pest problem at business property | 🟡 |
| Foster pet costs (501(c)(3) rescue) | *Van Dusen v. Comm'r* — charitable | Org letter for $250+, receipts, foster agreement | 🟢 |
| Pet as business (breeding/showing/influencer) | Schedule C w/ profit motive | Books, revenue, 9-factor test | 🟡 |
| Service animal | §213 medical | Diagnosis + animal trained for the condition | 🟢 |

FLAG-05 explicitly lists a "pet" deduction probe — these are its content. Prereq gate: recurring vet/pet-store spend + business or charitable context verified.

### Medical (§213, 7.5% AGI floor — or 100% via HSA) — MEDIUM
- Pool/spa/home modification **prescribed by physician**: deductible only to extent cost exceeds increase in home FMV; **appraisal required**. 🟡
- Diagnosed-condition cluster 🟢 (with diagnosis docs): weight-loss program for diagnosed disease (obesity/hypertension); smoking cessation; wigs for medical hair loss; clarinet lessons for orthodontia (real ruling); special schools/tutoring for diagnosed learning disabilities; lead paint removal; fertility treatment.
- Medical travel: mileage + $50/person/night lodging. 🟢
- *Hess* body-modification principle: performer-only, extreme facts. 🔴 — do not probe; refuse-and-explain material (P13).
- Performer rule: stage makeup/costumes unsuitable for street wear 🟢; everyday grooming/gym/ordinary clothes DENIED (*Hamper*, TV anchor); logo/branded workwear = advertising, always safe 🟢.

### Business deep cuts
| Play | Mechanics / basis | Rating / risk | Notes |
|---|---|---|---|
| Home daycare exception | Home office WITHOUT exclusive use; pool/play areas by time-space formula | 🟢 LOW | Occupation-gated |
| Home office → commuting conversion | Principal-place-of-business home office makes all business driving from home deductible; "often worth more than the office itself" | 🟢 LOW-MED | Pairs with SH-1 |
| Per diem M&IE | In lieu of meal receipts for travel; self-employed: meals portion only | 🟢 LOW | |
| 12-month prepay + de minimis safe harbor | Prepay ≤12 months; $2,500/invoice expensing w/ election statement | 🟢 LOW | |
| Antiques used in trade are depreciable | *Simon* (violin bows) — antique desk/instrument/equipment actually used | 🟡 MED | |
| Racing/vehicle sponsorship as advertising | Wrapped/branded vehicle, documented marketing plan, leads/content/event log; heavily litigated | 🟡 MED-HIGH | "Promotion, not hobby-in-costume" |
| Free product as promotion | *Sullivan* (gas station's free beer = advertising) | 🟢 LOW | |
| Fuel tax credit (off-road business use) | Shop/farm/construction equipment; ALSO the IRS's top bogus-claim flag | 🟡 MED-HIGH | Log gallons + equipment; pair with audit warning |
| FICA tip credit (Form 8846) | Food/beverage employers | 🟢 LOW | |
| Disabled access credit | Small biz, up to $5K, ADA improvements | 🟢 LOW | |
| §1244 stock | Paper at C-corp formation → losses ORDINARY up to $50K/$100K MFJ; "free founder downside insurance; almost never claimed" | 🟢 LOW-MED | Formation-time alarm, pairs with QSBS/83(b) |
| Section 105 HRA + employed spouse | Sole prop: family's entire medical out-of-pocket → business deduction reducing income AND SE tax; written plan, real employment, reasonable comp | 🟢 MED | Question-only w/ doc checklist |
| Cruise-ship conventions | Up to $2,000, US-registry vessels, statements attached | 🟡 MED | |
| Domestic mixed business+vacation travel | Primarily-business trip = full airfare; weekend "sandwich days" count as business; document primary purpose | 🟡 MED | |
| RV/boat as second home | Mortgage interest if sleeping + cooking + toilet facilities | 🟢 LOW | Detectable: RV/boat loan payments |
| Farmers/fishermen income averaging (Sch. J) | Occupation-gated | 🟢 LOW | |

### Rentals & property — see RE-7/RE-8 (14-day rule, basis tracker, partial §121, home-office sale)

### Losses people never claim (P11 probes, mostly protective)
| Play | Mechanics | Docs | Risk |
|---|---|---|---|
| Nonbusiness bad debt | Documented loan to friend/family gone bad = STCL | Signed note, repayment terms, collection attempts | 🟡 MED |
| Worthless securities (§165(g)) / abandonment | Fact-level | Brokerage statement | 🟢 LOW |
| Ponzi/theft-loss safe harbor (Rev. Proc. 2009-20) | Investment fraud incl. **crypto rug pulls** with profit motive; deduct year of discovery, no normal casualty limits | Fraud evidence | 🟢 LOW-MED |
| §1341 claim of right | Repaid prior-year income > $3K → credit at original year's rate | Repayment records | 🟢 LOW |

### Family & education sleepers (P11 + config)
| Play | Numbers | Risk |
|---|---|---|
| Scholarship election → AOTC | Include some scholarship in student's income (taxed ~0) to free qualified expenses for parents' $2,500 AOTC; IRS-blessed (Pub. 970) | 🟢 LOW-MED (counterintuitive — narrate carefully) |
| 529 → Roth rollover | $35K lifetime, 15-yr-old accounts (SECURE 2.0) | 🟢 LOW |
| Summer day camp = dependent care credit | Overnight camp doesn't count | 🟢 LOW |
| Adoption credit / foster payments / ABLE | ~$17K partially refundable; foster payments excludable; ABLE for disabled family | 🟢 LOW |
| Jury pay surrendered to employer; educator $300; reservist travel | Above-the-line items | 🟢 LOW |
| Employer education assistance | $5,250/yr incl. student-loan repayment (permanent); S-corp family-member angle 🟡 | LOW / MED |

### Expat / geographic
| Play | Numbers | Risk |
|---|---|---|
| Foreign earned income exclusion | ~$130K + housing exclusion; 330 days abroad or bona fide residence; state domicile must be severed (state part → STATE-01) | 🟡 MED |
| Clergy housing allowance; combat zone exclusion | Occupation-gated facts | 🟢 LOW |

### State-specific (AZ credits etc.) — OUT OF SCOPE v2.1 → STATE-01 backlog
- AZ dollar-for-dollar credits (public school, private-school tuition orgs, qualifying charities, foster orgs) — "several thousand dollars redirected at zero net cost; the single easiest win for AZ filers." Build a per-state credit module in STATE-01.

---

## 9. Bright Line & Audit-Risk Score (P11 detector content + P13 refusal framework)

**Avoidance vs evasion (educate, verbatim-worthy):** avoidance = real transactions under the code as written (Gregory v. Helvering). Evasion = misrepresented facts — unreported income, fabricated deductions, sham transactions, backdated docs; §7201 felonies; IRS CI conviction rate ~90%. Killer doctrines even when steps look legal: **economic substance (§7701(o)), substance over form, step transaction, sham transaction.** "Borderline is not a viable product tier."

**Audit-risk score inputs (deterministic detectors — build into FLAG severity model):**
1. Schedule C losses year after year against high W-2 income (hobby loss)
2. 100% business vehicle use
3. Round numbers everywhere
4. **Cash-intensive income with lifestyle mismatch — bank deposits > reported income. Owner: "you'll literally have this data; a deposit-vs-reported reconciliation is a killer feature"** (CTX-03/CrossSourceReviewService is the natural home; CTX-05 backlog extends it)
5. Large charitable deductions relative to income; non-cash > $5K without qualified appraisal
6. Home office + meals + travel out of proportion to revenue
7. Missing 1099s (IRS matching automated — cross-check vault 1099s vs deposits)
8. ERC claims, fuel credits, unsupported R&D credits from mills

**The audit-winning product behavior (owner's thesis):** *generate the documentation trail as the year unfolds* — mileage logs, hour logs (material participation), board minutes (Augusta), timesheets (kids), reasonable-comp studies. "Taxpayers with records win. A product that generates the documentation trail is worth more than one that suggests strategies in April." → P12 doc-checklist exports now; live log features = post-v2.1 backlog.

---

## 10. Engine Architecture Notes (owner's spec — maps to phases)

### Signal → question → strategy matrix (P11 detector/interview seed table)

| Signal detected | Question to ask | Strategies unlocked |
|---|---|---|
| Payroll deposits only, no 401k at max | Employer plan features? | Deferral gap, mega backdoor, HSA |
| Stripe/PayPal/Venmo business-pattern inflows | Is this a business? Net profit? | Schedule C suite, Solo 401(k), entity tree |
| Schedule C proxy net > $50K | Comfort with payroll? | S-corp election analysis |
| Mortgage + property tax payments | Itemizing? State? | SALT $40K, bunching, (PTET → STATE-01) |
| Rental income deposits | Avg stay? Hours? | STR loophole, cost seg, REPS |
| Auto loan payments | Vehicle US-assembled? Purchase year? | Auto-loan interest deduction |
| Tip-heavy or OT-coded income | Occupation | Tips/OT deductions 2025–28 |
| Brokerage transfers | Unrealized gains/losses? | (TLH, asset location, 0% bracket — FLAG-FOR-USER-DECISION) |
| Age 65+ / dependents / childcare payments | — | Senior deduction, CTC $2,200, dependent care 50% |
| Charitable payments | — | DAF bunching, appreciated stock, QCD |
| High profit + owner age > 45 | — | Cash balance plan |
| C-corp formation or startup equity | Gross assets? Issue date? | QSBS clock, 83(b) alarm (30-day timer!), §1244 |
| Recurring payments to same individuals | Control/independence facts? | Worker classification review |
| Business inflows, no estimated payments detected | Prior-year liability? | Quarterly safe-harbor scheduler |
| Personal-type spend in business account | — | Commingling warning, hobby-loss risk score |
| Coinbase/Kraken/exchange transfers | Wallets? Basis records? Trading frequency? | Basis reconciliation, HIFO; (Roth solo 401k / §1256 — FLAG-FOR-USER-DECISION) |
| Crypto inflows with no purchase | Staking/mining/airdrop? | Ordinary income at receipt, mining-as-business |
| Crypto-funded purchases | — | Realization-event warning + gain calc |

### Recommendation risk-banding (adopt as FLAG-06 severity/tier model)
1. **Auto-surface (educational):** withholding fixes, retirement/HSA maximization, accountable plans, SE health insurance, documented mileage/home office, clear §179/bonus, estimated-tax scheduling.
2. **Conditional (facts-gated questions):** S-corp election analysis, QBI optimization, 1031s, installment sales, passive-loss topics, hiring/child-care credits, STR material participation, (PTET → STATE-01).
3. **Specialist-review required:** multi-entity restructuring, QSBS/trust stacking, cash-balance design, nonprofit formation, valuation-dependent charitable gifts, anything resting on appraisals or legal opinions.

### Report output format (owner spec → RPT-01 sectioning)
Four sections per user: **(1) do now (educational), (2) documents missing, (3) needs professional review, (4) what the system refused to recommend and why.** Section 4 is a deliberate trust feature — implement the refusal list (P13 hard-blocks + FLAG-FOR-USER-DECISION rejects) as report content.

### Compliance for the product itself (P13 / SAFE requirements, owner-specified)
- Circular 230 / state-board exposure → frame everything as *educational scenarios* + "consult a licensed professional to implement"; CPA-review tier is the monetization path.
- GLBA data-safeguarding (Plaid-style bank access); **IRC §7216 criminal penalties** for misuse of return information — architect data handling accordingly (vault + encryption already aligned).
- **Log every recommendation with statutory basis and assumptions** — the product's own audit trail (extend OptimizationFinding with `legal_basis` + `assumptions` fields — P11 schema decision).
- **Hard-block list (P13):** micro-captives (831(b)), syndicated easements, offshore concealment, nonprofit-as-personal-shelter, corporation sole / pure trusts, crypto non-reporting, cash structuring, PPLI auto-pitches / offshore crypto-IRA wrappers, *Hess*-style body-mod probes. "Detect-and-warn beats detect-and-monetize."

---

## Appendix — Owner's 2026 key-number table (verify vs Rev. Proc. before entering config)

| Item | 2026 |
|---|---|
| Standard deduction S / MFJ / HoH | $16,100 / $32,200 / $24,150 ✅ in config |
| 401(k) deferral / 50+ / 60–63 | $24,500 / +$8,000 / +$11,250 ✅ in config |
| Total 415(c) DC limit | ~$72,000 ❌ add |
| IRA limit | $7,500 ✅ in config |
| HSA self / family | $4,400 / $8,750 ✅ in config |
| Standard business mileage | 72.5¢/mi ❌ add |
| SALT cap | $40,000+ (phaseout > ~$500K MAGI) ❌ add |
| QBI SSTB phaseout start | config wins: $201,750 / $403,500 ✅ |
| Estate/gift exemption | $15M / $30M ❌ add |
| Annual gift exclusion | ~$19,000 (verify) ❌ add |
| §179 limit / phaseout | $2,560,000 / $4,090,000 ❌ add |
| Employer child care credit | $500K / $600K small biz ❌ add |
| Child Tax Credit | $2,200 ❌ add |
| QSBS cap / asset test | $15M / $75M ❌ add |
| Tips / OT deduction caps | $25K / $12.5K–$25K (2025–2028) ❌ add |
