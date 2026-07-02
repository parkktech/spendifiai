# Tax Strategy Playbook — 2026 Edition (Post-OBBBA)
### Reference architecture for a bank-data-driven tax planning engine

> **Scope note:** Everything in this document is legal tax avoidance — using the code as written. Section 9 covers the bright line into evasion so the product can detect and warn, never recommend. All figures are 2026 tax year unless noted; verify inflation-adjusted numbers against current IRS revenue procedures before hardcoding.

---

## 1. The 2026 Landscape (What Changed)

The One Big Beautiful Bill Act (P.L. 119-21, signed July 4, 2025) made most TCJA provisions permanent and layered on new items:

**Permanent:**
- Seven brackets: 10/12/22/24/32/35/37%
- Standard deduction: $16,100 single / $32,200 MFJ / $24,150 HoH
- 20% QBI deduction (§199A) — permanent
- 100% bonus depreciation — permanent, for property acquired after Jan 19, 2025
- §179 expensing limit raised to $2.5M ($4M phaseout)
- Domestic R&D immediate expensing restored (§174A); small businesses may amend prior years
- Estate/gift/GST exemption: $15M per person / $30M per couple, indexed from 2027

**Temporary (2025–2028 unless noted):**
- Tip income deduction: up to $25,000 per return (phaseout begins $150K single / $300K MFJ)
- Overtime pay deduction (same phaseouts)
- Senior deduction: extra $6,000 for 65+ (MAGI phaseout $75K/$150K)
- Auto loan interest deduction up to $10,000 (US-assembled vehicles)
- SALT cap: $40,000 through 2029 (phases back toward $10K above ~$500K MAGI)
- Charitable deduction for non-itemizers ($1,000 single / $2,000 MFJ, starting 2026); itemizers face a new 0.5%-of-AGI floor

**New vehicles:**
- Trump Accounts: children under 18, $5,000/yr contribution cap, employer can contribute $2,500 excluded from wages; funding opens July 4, 2026; $1,000 federal seed for kids born 2025–2028
- Opportunity Zones 2.0: rolling designations; post-2026 investments get 5-year deferral + 10% basis bump (30% rural), full FMV step-up at 10 years
- QSBS overhaul (see §5)

**Killed or curtailed:** most clean energy / EV credits (EV credit ended Sept 2025), gambling losses limited to 90% of losses, various IRA-era green incentives.

---

## 2. W-2 Employees (No Business)

Ordered roughly by dollar impact per unit of effort:

### Tier 1 — The big levers
1. **401(k) max**: $24,500 (2026). Age 50–59: +$8,000. Age 60–63: +$11,250 "super catch-up." NEW: earners with prior-year FICA wages > $150K must make catch-ups as Roth.
2. **Mega backdoor Roth**: if the plan allows after-tax contributions + in-plan Roth conversion or in-service rollover, total 415(c) limit is ~$72K — meaning $40K+ of extra Roth space per year on top of the normal deferral. The single most underused strategy for high-earning employees. **Bank-data trigger:** high salary deposits + employer known to offer this (tech/finance).
3. **HSA as stealth IRA**: ~$4,400 self / $8,750 family (2026, verify). Triple tax-free. Advanced play: pay medical costs out of pocket, keep receipts forever, let the HSA compound invested, reimburse yourself decades later tax-free. Only account in the code better than a Roth.
4. **Backdoor Roth IRA**: $7,500 limit (2026, verify) nondeductible traditional contribution → immediate Roth conversion. Watch the pro-rata rule (existing pre-tax IRA balances poison it — roll them into the 401(k) first).
5. **Equity compensation timing**:
   - RSUs: the default 22% supplemental withholding under-withholds for anyone in the 32%+ bracket — flag estimated payment needs.
   - ISOs: exercise early in the year to preserve a disqualifying-disposition escape hatch before AMT locks in; model the AMT crossover point.
   - NSOs: exercise in low-income years.
   - **83(b) elections**: for early-stage restricted stock, file within 30 days of grant. Missing this window is one of the most expensive mistakes in tax. Non-recoverable.
   - ESPP: 15% discount + lookback is nearly free money; model qualifying vs disqualifying dispositions.

### Tier 2 — Bracket and timing games
6. **0% LTCG harvesting**: taxable income under ~$49K single / ~$98K MFJ (2026, verify) pays 0% on long-term gains. Sell winners, rebuy immediately (no wash sale on *gains*), reset basis for free. Huge for early-career, sabbatical, or between-jobs years.
7. **Roth conversions in low-income years**: job gap, grad school, early retirement — fill the 10/12/22% brackets with conversions.
8. **Tax-loss harvesting**: offset gains + $3,000/yr of ordinary income; losses carry forward indefinitely. Mind the 30-day wash sale rule (substantially identical securities).
9. **Charitable bunching + donor-advised fund**: stack 2–3 years of giving into one year to clear the standard deduction, grant it out over time. Donate appreciated stock, never cash — deduct FMV, never pay the embedded gain. Note the new 0.5%-of-AGI floor for itemizers from 2026.
10. **Deferred comp (NQDC/409A)** for executives: defer bonus into lower-income future years. Credit risk of the employer applies.

### Tier 3 — The new OBBBA items (auto-detect from W-2 / bank data)
11. Tips deduction (up to $25K/return) — service industry.
12. Overtime deduction — hourly workers; new W-2 box 12 codes TP and TT in 2026.
13. Auto loan interest (up to $10K, US-assembled) — detectable as a recurring auto-loan payment in bank data.
14. Senior deduction $6,000 (65+).
15. Dependent care FSA + expanded Child & Dependent Care Credit (up to 50% of expenses now).
16. 529 plans: state deduction in most states; K-12 distribution limit now $20K/yr; OBBBA broadened qualified expenses.
17. Trump Account for kids: employer $2,500 contribution is excluded from income — ask HR.

---

## 3. Side Hustlers — The Highest-ROI Segment

The gap between "1099 income reported with zero deductions" and "properly structured micro-business" is routinely $5K–$25K/yr in tax. This is where your product prints value.

### Step 0: Legitimize it (hobby vs business)
Schedule C losses require **profit motive** (9-factor test, §183). The engine should score: separate bank account, books/records, time invested, expertise, history of profit. Rule of thumb: profit in 3 of 5 years creates a presumption. **Product feature:** detect commingled personal/business spending and prompt to open a separate account — this single behavior is the top defense in a hobby-loss audit.

### Core deductions (Schedule C, no entity required)
- Home office: exclusive-use space; simplified $5/sq ft (cap $1,500) or actual-expense method (usually bigger; unlocks partial utilities, insurance, depreciation).
- Vehicle: standard mileage (72.5¢/mi for 2026) or actual expenses + depreciation. Contemporaneous mileage log is the audit battleground — build a log feature.
- Equipment: §179 or 100% bonus — computers, cameras, tools, machinery, fully deductible year one.
- Phone/internet business-use percentage; software; education *maintaining* current skills (not qualifying for a new trade); business travel; 50% meals.
- Startup costs: $5K immediate + amortize remainder (§195).
- Self-employed health insurance: 100% above-the-line if not eligible for employer coverage.
- **Estimated tax safe harbor**: pay 100% of prior-year liability (110% if AGI > $150K) in quarterly estimates to avoid underpayment penalties regardless of current-year income. **Product feature:** compute rolling quarterly estimates from live bank inflows — underpayment penalties are the most common self-inflicted wound in this segment, and it's fully preventable with data you already have.

### The heavy artillery
- **Solo 401(k)**: employee deferral ($24,500) + employer contribution (20% of net SE earnings) — can shelter $70K+ of side income. Beats SEP-IRA because the employee deferral works even on modest profit, and it doesn't block the backdoor Roth. **Trigger:** Schedule C net > $10K and no solo 401(k) contributions detected.
- **QBI 20% deduction**: automatic on pass-through income; SSTB phaseouts start ~$197K single / ~$394K MFJ taxable income (verify 2026). Above that, W-2 wage / UBIA tests apply — a reason to elect S-corp (creates W-2 wages).
- **Hire your kids**: children under 18 employed by a parent's sole prop or spousal partnership: no FICA/FUTA, wages deductible to the business, and the child's ~$16,100 standard deduction makes the first $16K tax-free to them. Then fund their Roth IRA with earned income. Requirements: real work, market-rate pay, timesheets, actual payment. (S-corps lose the FICA exemption — use a family management company or keep the sole prop for this.)
- **Augusta rule (§280A(g))**: rent your home to your business ≤14 days/yr — income is tax-free to you, deductible to the business. Requires an entity (S/C-corp), board-meeting documentation, comparable local rental rates, invoices. Legal and safe *when documented*; a top audit-adjustment item when it's not.
- **Accountable plan** (once an entity exists): reimburse yourself for home office, mileage, phone — deductible to the company, tax-free to you.

### Entity decision tree (build this into the engine)

| Net profit | Recommendation | Why |
|---|---|---|
| < $10K | Sole prop, separate bank account | Entity overhead not worth it |
| $10K–$50K | LLC (default taxation) | Liability protection; taxes unchanged; ~$50–$800/yr state cost |
| $50K–$60K+ | **LLC + S-corp election (2553)** | Split into reasonable W-2 salary + distributions; distributions escape 15.3% SE tax. Break-even vs payroll/compliance cost (~$1.5–3K/yr) lands around $50–60K net |
| $250K+ or seeking investment | Model **C-corp** | 21% flat rate, QSBS potential, retained earnings, fringe benefits |

S-corp caveats the engine must enforce: **reasonable compensation** (IRS's #1 S-corp attack — salary should reflect market rate for the work, commonly 40–60% of profit as a starting heuristic), payroll filings, and the QBI interaction (salary reduces QBI; optimize jointly).

**Election lock warning:** once an entity changes its tax classification, it generally cannot re-elect for **60 months**. The engine should surface this before any classification change — these are five-year commitments, not experiments. Also note OBBBA's new **QBI minimum deduction** (a floor deduction for taxpayers with modest active QBI) — small side hustles get something even below the normal calculation.

**PTET election:** in ~36 states, the pass-through entity pays state tax at entity level → fully deductible federally → sidesteps the SALT cap. Still valuable even with the $40K cap for high earners above the phaseout.

---

## 4. Real Estate (Employees and Owners Both)

1. **The short-term rental loophole**: average guest stay ≤ 7 days + material participation (e.g., 100+ hours and more than anyone else) → losses are **non-passive** and offset W-2 income. Pair with:
2. **Cost segregation + 100% bonus depreciation**: engineering study reclassifies 20–35% of a building into 5/7/15-year property, all immediately deductible. A $500K STR can generate a $100–150K first-year paper loss against a tech salary. This is the single most powerful legal strategy for high-income W-2 earners — and the most audit-sensitive, so the hour log is everything.
3. **Real estate professional status (REPS)**: 750 hrs + more than half of working time in real estate (usually requires a non-working or RE-career spouse) → all rental losses non-passive.
4. **1031 exchanges**: defer gains indefinitely; 45-day identify / 180-day close; chain until death →
5. **Buy, borrow, die**: never sell; borrow against appreciated assets (loan proceeds aren't income); heirs get stepped-up basis wiping out the deferred gain. Legal under current law; the estate exemption at $15M/$30M makes this viable for nearly everyone below UHNW.
6. **Opportunity Zones 2.0**: roll capital gains into a QOF within 180 days; post-2026 investments: 5-year deferral, +10% basis (+30% rural), tax-free appreciation at 10 years (basis step-up now frozen at 30 years). Note: investors holding pre-2027 QOFs face mandatory gain recognition at the end of 2026 — plan loss harvesting or re-deferral now.
7. Primary residence §121 exclusion: $250K/$500K gain exclusion, 2-of-5-year rule; serial house-hacking is a legitimate wealth strategy.

---

## 5. Business Owners & Corporations

### Retirement as the biggest deduction on earth
- **Cash balance / defined benefit plan**: owners 45+ with strong profits can deduct $150K–$350K/yr on top of a 401(k)/profit-sharing combo. The elite move for high-income practices (doctors, lawyers, consultants, agencies). **Trigger:** owner age > 45, consistent profit > $300K.

### Depreciation & capex
- 100% bonus (permanent), §179 to $2.5M, and OBBBA's new 100% expensing for **qualified production property** (US factory/production buildings placed in service through 2030) — relevant to RAZOR-type hardware manufacturers.
- Heavy vehicles > 6,000 lbs GVWR: §179 up to ~$31K + bonus on the rest, at business-use percentage. Legit and popular; also a classic audit item when "100% business use" is claimed on the family SUV.

### R&D
- §174A: domestic R&D immediately expensible again; small businesses (< $31M gross receipts) can amend 2022–2024 to reclaim capitalized amounts.
- **§41 R&D credit**: dollar-for-dollar credit for developing products, firmware, software, prototypes — startups can apply up to $500K against payroll tax even with no income tax. Wildly underclaimed by small hardware/software shops. (Directly applicable to drone/RF development work.)

### QSBS (§1202) — the founder's holy grail, upgraded by OBBBA
- C-corp stock, gross assets < **$75M** at issuance (was $50M), original issuance, active business (not services like law/health/finance).
- Exclusion on sale: **50% at 3 years, 75% at 4 years, 100% at 5 years** (for stock issued after July 4, 2025). Cap raised to **$15M** per taxpayer per issuer (or 10× basis).
- **Stacking**: gift shares to non-grantor trusts for family members — each trust gets its own $15M exclusion. A $60M exit can be fully excluded across four trusts. This is the highest-stakes legal planning in the code; requires attorneys, but the engine should flag eligibility early (entity choice at formation determines it).
- §1045 rollover: sold before 5 years? Roll into new QSBS within 60 days and keep the clock.

### Compensation & fringe
- Accountable plans, HSA + HDHP, group health, QSEHRA/ICHRA for small employers, employer Trump Account contributions ($2,500 excluded), education assistance ($5,250), employer student-loan repayment (now permanent), Augusta rule board meetings.
- Income shifting: employ spouse (unlocks spousal retirement plan space) and kids; family limited partnerships for larger operations.
- **Employer-provided child care credit** (expanded 2026): up to $500,000 max credit, $600,000 for eligible small businesses. Massively underused; pairs with dependent-care FSA design.
- **Work Opportunity Tax Credit**: per-hire federal credit for targeted groups — most small employers never screen for it.

### Worker classification (audit landmine, product opportunity)
1099-vs-W-2 status turns on control and independence facts, not labels. Misclassification triggers back payroll taxes, penalties, and state actions. **Engine rule:** recurring payments to the same individuals, especially for services core to the business, should trigger a structured classification questionnaire — bank data makes this pattern trivially detectable, and catching it before the IRS or a state DOL does is a genuine safety feature.

### Timing & method
- Cash-method businesses: accelerate expenses / defer invoicing across year-end.
- Prepay up to 12 months of expenses (12-month rule).
- Charitable: C-corps deduct up to 10% of taxable income; OBBBA added a 1% floor — bunch corporate giving.

### Exit planning
- Installment sales (spread gain), ESOP sales (§1042 deferral for C-corps), opportunity zone rollover of the gain, CRTs for concentrated positions (income stream + deduction + no immediate gain), or QSBS if the runway was set up 5 years earlier — which is why entity selection is a *day-one* decision, not a year-five one.

---

## 6. Investors

- **Asset location**: bonds/REITs in tax-deferred, stocks in taxable (LTCG rates + step-up), highest-growth in Roth. Worth 0.2–0.5%/yr — compounds enormously.
- Direct indexing for continuous loss harvesting at scale.
- **Crypto**: as of early 2026 the wash-sale rule still does not apply to crypto by statute — losses can be harvested and repurchased immediately. Verify before shipping; Congress has repeatedly proposed closing this.
- Municipal bonds (federal-tax-free; double-exempt in-state), Treasuries (state-tax-free — meaningful in high-tax states, moot in AZ's flat 2.5%).
- NIIT 3.8% planning: applies above $200K/$250K MAGI; rental income under REPS and S-corp active distributions can escape it.
- Qualified dividends: mind the 61-day holding period.
- Gifting appreciated stock to adult kids in the 0% LTCG bracket (watch kiddie tax under 24 if a student).
- Estate layer: $19K/yr annual exclusion gifts per donee (verify 2026), 529 superfunding (5 years at once), SLATs/IDGTs/GRATs only above ~$15M/$30M net worth — below that, prioritize **basis step-up** (don't gift appreciated assets to elders' estates... actually do — "upstream gifting" to a trusted parent with unused exemption resets basis at their death; advanced, attorney-required).

---

## 6A. Crypto — Advanced Planning Stack

**Reporting reality first:** Form 1099-DA broker reporting began 2025 (gross proceeds; basis phasing in), and Rev. Proc. 2024-28 mandates **wallet-by-wallet basis tracking** (universal pooling ended Jan 1, 2025). Accuracy is now itself a savings strategy — unreconciled basis defaults toward zero. Build basis reconciliation as core infrastructure.

### Tier 1 — Structural (biggest impact)
1. **Trade inside a Roth solo 401(k) / self-directed IRA.** Contributions must be cash (in-kind crypto contributions are prohibited transactions), but the account can buy and actively trade crypto via a custodian. Every gain inside a Roth wrapper is permanently untaxed — for active/algorithmic traders this dominates all other strategies. Post-2025 DOL guidance rescission + EO on alternative assets made this mainstream. Guardrails: custodian must control keys (self-custody "checkbook LLC" lost in *McNulty v. Comm'r*); margin trading inside an IRA can trigger UBTI.
2. **Roth conversion on drawdowns.** Convert traditional-account crypto in-kind during crashes — tax on depressed value, recovery tax-free. **Product feature:** drawdown-triggered conversion alerts.
3. **§1256 futures for short-term traders.** CME-regulated crypto futures get 60/40 LTCG/STCG treatment regardless of holding period + mark-to-market loss recognition — a structural rate cut vs spot for high-frequency strategies.

### Tier 2 — Continuous optimization
4. **Loss harvesting, no wash sale.** Spot crypto is outside the wash-sale statute as of early 2026 — sell, book the loss, rebuy immediately. Harvest continuously. Tag as **legislation-risk** in the engine; closure is perennially proposed.
5. **Specific ID / HIFO lot selection** instead of FIFO — typically cuts realized gains 20–40% for active traders. Must be documented per-wallet at time of sale.
6. **0% LTCG bracket harvesting** in low-income years; **gift to adult kids** in 0% brackets (kiddie tax if under 24 and a student); estate **step-up** wipes embedded gains at death.
7. **Donate appreciated crypto** (DAF-friendly): FMV deduction, gain never taxed. Over $5K requires a **qualified appraisal** — exchange price screenshots are insufficient per IRS CCA 202302012. Build the appraisal checklist into the flow.
8. **Opportunity Zone rollover** of crypto gains — one of the only deferral tools available (1031 dead for crypto since 2018).
9. **Borrow against holdings** rather than sell (no realization). Warn: forced liquidation = deemed sale at the bottom.

### Tier 3 — Situational
10. **Miners/stakers**: ordinary income at receipt (staking: Rev. Rul. 2023-14). At scale, run as a business — bonus depreciation on rigs, electricity/hosting deductions, S-corp for SE tax once profitable. Hobby-level mining gets income with no deductions.
11. **Puerto Rico Act 60**: bona fide residents pay 0% on gains accruing *after* relocation (pre-move appreciation remains US-taxable). 183+ days, closer-connection facts, heavily audited. Specialist tier.
12. **PPLI (private placement life insurance)**: tax-free growth, tax-free policy loans, income-tax-free death benefit. Real but: $2–5M minimum premiums, §817(h) diversification, and the **investor control doctrine** (*Webber*) collapses it if the client directs the trading — which is exactly what crypto traders want to do. Specialist-referral only; never auto-recommend. **Hard-block** adjacent pitches: offshore "crypto IRA" wrappers, foreign annuity/insurance concealment structures (FBAR/FATCA exposure).

### Engine warnings to build
- **Spending crypto is a taxable disposal** — flag crypto-funded purchases (including "buying insurance with it") as realization events with gain calculation.
- Moving coins between own wallets ≠ taxable, but breaks naive basis tracking — reconcile transfers.
- Staking/airdrop/fork receipts = ordinary income at FMV on receipt date — detect inflows with no corresponding purchase.
- State exit planning: realize large gains *after* establishing residency in a no-tax state, with clean domicile facts (CA/NY pursue aggressively).

---

## 7. Nonprofits & 501(c)(3) — Mostly a Trap for This Use Case

**When a 501(c)(3) is right:** genuine charitable/educational/religious purpose, willing to give up ownership (nobody "owns" a charity), public support test or private foundation excise regime, annual 990 transparency.

**When it's wrong (and the engine should hard-block the suggestion):** any framing of "run my income through a nonprofit to avoid tax." Private inurement and excess-benefit transactions (§4958) trigger 25% excise taxes on the insider personally, 200% if uncorrected, plus exemption revocation. The IRS's "abusive trust/nonprofit" schemes list is essentially a prosecution roadmap.

**Legitimate charitable tax tools to offer instead:**
- Donor-advised fund — immediate deduction, invest and grant over time, ~zero admin. The right answer for 99% of people who ask about "starting a foundation."
- Donate appreciated long-term stock (FMV deduction, gain vanishes).
- QCDs: 70½+, up to ~$108K/yr directly from IRA to charity — excluded from income entirely, counts toward RMDs, beats itemizing.
- Private foundation only above ~$1–5M of committed philanthropy (1.39% excise on investment income, 5% payout, real admin).
- Charitable remainder trusts for exiting concentrated positions.

**UBIT trap:** even a legitimate 501(c)(3) pays corporate tax on **unrelated business income** (regular commercial activity not substantially related to the exempt purpose) and must file Form 990-T. A "nonprofit" running a de facto business doesn't escape tax on that business.

**Right-sizing the exempt category:** 501(c)(3) is for charitable/educational/religious public benefit. If the purpose is advocacy/social welfare → 501(c)(4) (no donor deduction, but lobbying allowed); trade association or industry group → 501(c)(6); member social club → 501(c)(7). The engine should route by stated purpose, not default everyone to (c)(3).

**Other entity confusions to catch:** "coops," multiple LLCs for no reason, Wyoming/Nevada LLC mythology (state of *operation* controls taxation — the Delaware/Wyoming LLC does nothing for a solo operator in Arizona except add fees), and "corporation sole" / "pure trust" packages, which are on the IRS Dirty Dozen and lead to criminal referrals.

---

## 8. Cross-Cutting Advanced Plays (Use with Guardrails)

| Strategy | Savings potential | Risk level | Guardrail |
|---|---|---|---|
| STR + cost seg vs W-2 income | Very high | Elevated audit | Hour logs, real STR operation |
| S-corp comp optimization | High | Moderate | Reasonable-comp study |
| QSBS stacking via trusts | Extreme | Low if papered | Attorneys, non-grantor status real |
| Cash balance plan | High | Low | Actuary, consistent funding |
| Augusta rule | Moderate | Elevated | Comps, minutes, invoices, ≤14 days |
| Hiring kids | Moderate | Moderate | Real work, timesheets, W-2s filed |
| PTET election | High (high-tax states) | Low | Timely state election |
| 831(b) micro-captive | High | **Listed transaction — avoid** | Don't ship this |
| Syndicated conservation easement | High | **Listed transaction — avoid** | Don't ship this |
| Offshore structures for US persons | — | **FBAR/FATCA criminal exposure** | Don't ship this |
| Malta pension / foreign trusts | — | **Dirty Dozen** | Don't ship this |

---

## 8A. The Minutiae Library — Edge-Case Deductions (Pro-Approval Tier)

Every item here is real law or decided case law. Engine behavior: surface the candidate **with its documentation checklist attached**, route to the user's tax professional for sign-off. Defensibility: 🟢 solid with docs / 🟡 fact-dependent / 🔴 frequently abused, extra care.

### Animals
| Play | Basis | Docs required | Rating |
|---|---|---|---|
| Guard dog for business premises | Ordinary & necessary security expense; dog depreciated (7-yr), food/vet/training at business % | Appropriate breed, kept at business site, security purpose memo, cost log | 🟡 |
| Pest-control cats | *Seawright v. Comm'r* (junkyard cats) | Genuine pest problem at business property | 🟡 |
| Foster pet costs for 501(c)(3) rescue | *Van Dusen v. Comm'r* — charitable deduction | Org letter for $250+, expense receipts, foster agreement | 🟢 |
| Pet as business (breeding, showing, pet-influencer revenue) | Schedule C with profit motive | Books, revenue, 9-factor hobby test | 🟡 |
| Service animal | §213 medical | Diagnosis, animal trained for the condition | 🟢 |

### Medical (§213, 7.5% AGI floor — or 100% via HSA)
- Pool/spa/home modification prescribed by physician: deductible to extent cost exceeds increase in home FMV; appraisal required. 🟡
- Weight-loss program for a *diagnosed* disease (obesity, hypertension); smoking cessation; wigs for medical hair loss; clarinet lessons for orthodontia (real ruling); special schools/tutoring for diagnosed learning disabilities; lead paint removal; fertility treatment. 🟢 with diagnosis documentation
- Medical travel: mileage + $50/person/night lodging. 🟢
- The *Hess* principle (business-asset body modification): court-approved only on extreme facts; effectively performer-only. 🔴
- Performer rule generally: stage makeup, costumes unsuitable for street wear 🟢; everyday grooming, gym, ordinary clothes — denied (*Hamper*, TV anchor). Logo/branded workwear = advertising, always safe. 🟢

### Business deep cuts
- **Home daycare exception**: home office **without exclusive use** — unique to daycare providers; can include pool/play areas by time-space formula. 🟢
- **Home office → commuting conversion**: principal-place-of-business home office makes all business driving from home deductible. Often worth more than the office itself. 🟢
- **Per diem M&IE** in lieu of meal receipts for business travel (self-employed: meals portion only). 🟢
- **12-month prepay rule**; **de minimis safe harbor** ($2,500/invoice expensing, election statement). 🟢
- **Antiques used in the trade are depreciable** (*Simon* — violin bows): antique desk, instrument, equipment actually used in business. 🟡
- **Racing/vehicle sponsorship as advertising**: wrapped/branded vehicle, documented marketing plan, leads/content/event log. Heavily litigated — promotion, not hobby-in-costume. 🟡
- **Free product as promotion** (*Sullivan* — gas station's free beer = advertising). 🟢
- **Fuel tax credit** for off-road business use (shop/farm/construction equipment): legit and valuable; also the IRS's top bogus-claim flag — log gallons and equipment. 🟡
- **FICA tip credit** (Form 8846) for food/beverage employers. 🟢
- **Disabled access credit** (small biz, up to $5K) for ADA improvements. 🟢
- **§1244 stock**: paper it at C-corp formation → losses become *ordinary* up to $50K/$100K MFJ. Free founder downside insurance; almost never claimed. 🟢
- **Section 105 HRA + employed spouse** (sole prop): family's entire medical out-of-pocket becomes a business deduction reducing income *and* SE tax. Written plan, real employment, reasonable comp. 🟢
- **Cruise-ship conventions**: up to $2,000, US-registry vessels, statements attached. 🟡
- **Domestic travel mixing business + vacation**: primarily-business trip = full airfare; weekend "sandwich days" between business days count as business. Document the primary purpose. 🟡
- **RV/boat as second home**: mortgage interest if it has sleeping, cooking, toilet facilities. 🟢
- **Farmers/fishermen income averaging** (Schedule J). 🟢

### Rentals & property
- **14-day rule (Masters/Super Bowl/WM Phoenix Open rule)**: rent your home ≤14 days/yr — income completely tax-free, unlimited amount. Scottsdale-corridor gold every February. Track the day count hard; day 15 makes it *all* taxable. 🟢
- **Basis-building tracker**: every improvement to a home adds to basis and shrinks future gain past the $250K/$500K exclusion — a passive product feature with real payoff decades later. 🟢
- **Partial §121 exclusion** even under 2 years for job move (50+ mi), health, or unforeseen circumstances — prorated. 🟢
- **Home-office-in-residence sale**: no gain allocation needed within same dwelling unit; only depreciation recapture. 🟢

### Losses people never claim
- **Nonbusiness bad debt**: documented loan to friend/family gone bad = short-term capital loss. Requires signed note, repayment terms, collection attempts. 🟡
- **Worthless securities** (§165(g)) and **abandonment losses**. 🟢
- **Ponzi/theft-loss safe harbor** (Rev. Proc. 2009-20): applies to investment fraud including crypto rug pulls with profit motive — deduct in year of discovery without the normal casualty limits. 🟢
- **§1341 claim-of-right**: repaid income from a prior year > $3K → credit at the original year's rate. 🟢

### Family & education sleepers
- **Scholarship election**: include some scholarship in the student's income (taxed near zero) to free up qualified expenses for the parents' **$2,500 AOTC**. Counterintuitive, IRS-blessed (Pub. 970). 🟢
- **529 → Roth IRA rollover**: $35K lifetime for 15-yr-old accounts (SECURE 2.0). 🟢
- **Summer day camp** counts for the dependent care credit (overnight camp doesn't). 🟢
- **Adoption credit** (~$17K, now partially refundable under OBBBA); **foster care payments excludable**; **ABLE accounts** for disabled family members. 🟢
- **Jury pay surrendered to employer** = deduction; **educator expenses** $300; **military reservist travel** above-the-line. 🟢
- **Employer education assistance**: $5,250/yr tax-free, now permanently including **student loan repayment** — S-corp owners can offer it to employee-family-members within nondiscrimination limits. 🟡

### Expat / geographic
- **Foreign earned income exclusion**: ~$130K excluded (330 days abroad or bona fide residence) + housing exclusion — the digital-nomad play. State domicile must also be severed. 🟡
- **Clergy housing allowance**; **combat zone exclusion**. 🟢

### State-specific layer (Arizona example — replicate per state)
- **AZ dollar-for-dollar credits**: public school activity, private school tuition organizations, qualifying charitable orgs, foster care orgs — several thousand dollars of state tax redirected to charity at **zero net cost**. The single easiest win for AZ filers; most have never heard of it. Build a per-state credit module — many states have equivalents. 🟢

**Engine rule for this whole section:** every surfaced item ships with (1) the legal basis, (2) the documentation checklist, (3) the defensibility rating, and (4) a "send to my tax pro" export that packages the fact pattern + substantiation. A pro shown the *Seawright* citation and a photo of the shop dog approves what they'd laugh at as a bare question.

---

## 9. The Bright Line (Forensic Accountant Section)

**Avoidance** = arranging real transactions to minimize tax under the code as written. Fully legal; the Supreme Court (Gregory v. Helvering, Learned Hand's famous language) blesses it.
**Evasion** = misrepresenting facts: unreported income, fabricated deductions, sham transactions with no economic substance, backdated documents. §7201, felonies, and the IRS Criminal Investigation conviction rate is ~90%.

The doctrines that kill "clever" schemes even when each step looks technically legal: **economic substance** (§7701(o)), **substance over form**, **step transaction**, **sham transaction**. If a structure only exists to create a deduction and has no business purpose, it fails — this is why "borderline" is not a viable product tier.

**What actually triggers audits (build into a risk score):**
- Schedule C losses year after year against high W-2 income (hobby loss)
- 100% business vehicle use
- Round numbers everywhere
- Cash-intensive income with lifestyle mismatch (bank deposits > reported income — you'll literally have this data; a deposit-vs-reported reconciliation is a killer feature)
- Large charitable deductions relative to income; non-cash donations without appraisals (> $5K requires qualified appraisal)
- Home office + meals + travel out of proportion to revenue
- Missing 1099s (IRS matching is automated)
- ERC claims (still an enforcement priority), fuel credits, unsupported R&D credits from credit mills

**The audit-winning behavior your product can enforce:** contemporaneous documentation. Mileage logs, hour logs for material participation, board minutes for Augusta, timesheets for kids, reasonable-comp studies. Taxpayers with records win; taxpayers reconstructing after the fact lose. A product that *generates the documentation trail* as the year unfolds is worth more than one that suggests strategies in April.

---

## 10. Product / Engine Architecture Notes

**Data signals → strategy triggers (from bank + Q&A):**

| Signal detected | Question to ask | Strategies unlocked |
|---|---|---|
| Payroll deposits only, no 401k at max | Employer plan features? | Deferral gap, mega backdoor, HSA |
| Stripe/PayPal/Venmo business-pattern inflows | Is this a business? Net profit? | Schedule C suite, Solo 401(k), entity tree |
| Schedule C proxy net > $50K | Comfort with payroll? | S-corp election analysis |
| Mortgage + property tax payments | Itemizing? State? | SALT $40K, bunching, PTET if owner |
| Rental income deposits | Avg stay? Hours? | STR loophole, cost seg, REPS |
| Auto loan payments | Vehicle US-assembled? Purchase year? | Auto-loan interest deduction |
| Tip-heavy or OT-coded income | Occupation | Tips/OT deductions 2025–28 |
| Brokerage transfers | Unrealized gains/losses? | TLH, asset location, 0% bracket |
| Age 65+ / dependents / childcare payments | — | Senior deduction, CTC $2,200, dependent care 50% |
| Charitable payments | — | DAF bunching, appreciated stock, QCD |
| High profit + owner age > 45 | — | Cash balance plan |
| C-corp formation or startup equity | Gross assets? Issue date? | QSBS clock, 83(b) alarm (30-day timer!) |
| Recurring payments to same individuals | Control/independence facts? | Worker classification review (1099 vs W-2) |
| Business inflows, no estimated payments detected | Prior-year liability? | Quarterly safe-harbor scheduler |
| Personal-type spend in business account | — | Commingling warning, hobby-loss risk score |
| Coinbase/Kraken/exchange transfers | Wallets? Basis records? Trading frequency? | Basis reconciliation, HIFO, loss harvesting, Roth solo 401(k) analysis, §1256 futures comparison |
| Crypto inflows with no purchase | Staking/mining/airdrop? | Ordinary income at receipt, mining-as-business analysis |
| Crypto-funded purchases detected | — | Realization-event warning + gain calc |

**Recommendation risk-banding (rank every output into one of three tiers):**
1. **Auto-recommend** — low-risk, high-certainty: withholding fixes, retirement/HSA maximization, accountable plans, SE health insurance, documented mileage/home office, clear §179/bonus items, estimated-tax scheduling.
2. **Conditional** — needs facts confirmed: S-corp election analysis, QBI optimization, 1031s, installment sales, passive-loss modeling, hiring/child-care credits, STR material participation, PTET elections.
3. **Specialist review required** — route to a CPA/attorney: multi-entity restructuring, QSBS/trust stacking, cash balance plan design, nonprofit formation, valuation-dependent charitable gifts, anything resting on appraisals or legal opinions.

**Output format:** four sections per user — (1) do now, (2) documents missing, (3) needs professional review, (4) **what the system refused to recommend and why**. That fourth section is a trust feature: software that can explain why it rejected an attractive-but-abusive position is more credible than software that always finds the biggest number.

**Tax-year versioning:** build effective-date awareness into every rule. Tips/OT/senior/auto-loan deductions sunset after 2028, SALT reverts after 2029, inflation adjustments change annually, and pre-2027 QOF holders hit mandatory recognition at the end of 2026. A strategy engine without a versioning layer will silently give wrong answers within 24 months.

**State layer (explicitly out of scope above, needs its own rules):** state income/franchise tax, PTET availability by state, sales-and-use nexus for e-commerce side hustles, local payroll taxes, and multi-state apportionment. Federal-only recommendations can be materially wrong in CA/NY/NJ; conversely AZ's 2.5% flat tax changes several trade-offs (munis matter less, PTET matters less).

**Compliance for the product itself (do not skip):**
- Personalized tax advice via software implicates preparer/adviser rules (Circular 230 if anyone practices before the IRS; state boards for "tax advice"). Frame outputs as *educational scenarios* with "consult a licensed professional to implement," and consider a CPA-review tier as the monetization path.
- Bank data: Plaid-style access brings GLBA data-safeguarding obligations; IRC §7216 imposes criminal penalties on tax-return preparers who misuse return information — architect data handling accordingly.
- Log every recommendation with its statutory basis and assumptions — your own audit trail.
- Hard-block list in the engine: micro-captives, syndicated easements, offshore concealment, nonprofit-as-personal-shelter, crypto non-reporting, cash structuring. Detect-and-warn beats detect-and-monetize.

---

## Appendix: 2026 Key Numbers (verify against Rev. Proc. before hardcoding)

| Item | 2026 |
|---|---|
| Standard deduction S / MFJ / HoH | $16,100 / $32,200 / $24,150 |
| 401(k) deferral / 50+ / 60–63 | $24,500 / +$8,000 / +$11,250 |
| Total 415(c) DC limit | ~$72,000 |
| IRA limit | $7,500 |
| HSA self / family | $4,400 / $8,750 |
| Standard business mileage | 72.5¢/mi |
| SALT cap | $40,000+ (phaseout > ~$500K MAGI) |
| QBI SSTB phaseout start | ~$197K S / ~$394K MFJ (verify) |
| Estate/gift exemption | $15M / $30M couple |
| Annual gift exclusion | ~$19,000 (verify) |
| §179 limit / phaseout | $2,560,000 / $4,090,000 |
| Employer child care credit max | $500K / $600K small biz |
| Child Tax Credit | $2,200 |
| QSBS cap / asset test | $15M / $75M |
| Tips / OT deduction caps | $25K / $12.5K–$25K (2025–2028) |
