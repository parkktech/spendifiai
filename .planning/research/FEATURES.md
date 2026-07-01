# Feature Research

**Domain:** Personal Tax/Income-Optimization + Smart Financial Interview (v2.1 Optimize My Income)
**Researched:** 2026-07-01
**Confidence:** MEDIUM (cross-referenced Keeper Tax, TurboTax UX analysis, Betterment/Blooom retirement tools, Instead.com, Facet Wealth, IRS documentation, fintech compliance literature)

---

## Feature Landscape

### Table Stakes (Users Expect These)

Features users assume exist. Missing these = product feels incomplete or untrustworthy.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Document intake for new doc types | Users need to upload pay stubs, offer letters, 401k statements, insurance/mortgage docs — extends existing Vault pattern | LOW | Reuses `TaxDocumentExtractorService` + two-pass classify→extract. New `TaxDocumentCategory` enum values for: `pay_stub`, `offer_letter`, `retirement_statement`, `benefits_summary`, `stock_statement`, `insurance_statement`, `mortgage_statement`. |
| Cross-source data assembly before interview | Tool must read what it already knows (bank data, emails, prior docs) before asking questions | MEDIUM | Feeds the interview engine: pull from `Transaction`, `Subscription`, `EmailConnection/ParsedEmail`, existing vault docs, `UserFinancialProfile`. Prevents asking what can be inferred. |
| Guided interview — one question at a time | TurboTax, Keeper Tax, Cleo all established this as the standard; multi-question forms cause drop-off | MEDIUM | Each question rendered as its own card/screen. Conditional logic: skip questions answered by documents or bank data. Reuse `AIQuestion` pattern from existing categorization flow. |
| Filing status educational check | Most common source of tax mistakes; users expect any tax tool to surface this | MEDIUM | Compare what financial profile says vs what pay stub shows (W-4 box 3). Surface discrepancy as red flag. Educational framing only: "your pay stub suggests [X] — discuss with a tax professional." |
| Tax withholding check | Over/under withheld is a top-3 user pain point; every tax tool addresses this | MEDIUM | Compare federal withholding on pay stub to estimated tax liability using 2026 brackets. Flag if withholding gap is >$500. Suggest W-4 adjustment review (not assertion). |
| Standard vs itemized deduction comparison | Users need to know which is bigger before deciding anything else | LOW | Rules-first: pull standard deduction for filing status from deterministic 2026 tables. Sum known itemizable items from bank + docs (mortgage interest from 1098, charitable from transactions). Show both numbers side by side. |
| 401k/retirement contribution check | "Am I leaving employer match on the table?" is the highest-ROI question for salaried users | MEDIUM | Extract contribution rate from pay stub or retirement statement. Compare against employer match threshold (from offer letter or user answer). Flag uncaptured match as dollar value. |
| Optimization report with ranked action items | Users expect output — a concrete list of things to review, ranked by estimated impact | MEDIUM | Report sections: Filing Status, Withholding, Deductions, Retirement, Income Classification. Each item: description, estimated dollar range, confidence, "review with a tax professional" disclaimer. |
| "Educational only" disclaimer on every output | Without this, product creates liability. Users trained by TurboTax/H&R Block expect disclaimers. | LOW | Static disclaimer block on report + tooltips on individual suggestions. Cannot be dismissed globally — must appear adjacent to each suggestion. "This is educational information, not tax advice. Review with a licensed tax professional before making changes." |
| Progress indicator during interview | Users need to see how many questions remain; opaque interviews cause abandonment | LOW | Show step count (e.g., "3 of 8 questions") and a topic breadcrumb (Filing → Retirement → Deductions). Allow going back to revise answers. |
| Ability to skip questions | Some users won't have certain documents or situations; forcing answers produces bad data | LOW | Each question must have "Not applicable" or "Skip for now" option. Skipped questions reduce confidence of associated report sections. |
| Report persistence and refresh | Users return to check their report; a one-time-only report feels fragile | LOW | Persist `OptimizationSession` model. Allow "Refresh report" when new docs are uploaded or bank data changes. Show "last analyzed" timestamp. |

### Differentiators (Competitive Advantage)

Features that set SpendifiAI apart. These are not expected but create clear competitive edge.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Cross-source red-flag detector (filing status mismatch) | Pay stub says "Married Filing Separately" but user thinks they're filing jointly — caught before tax prep begins | HIGH | Compare W-4 box 3 on pay stub (extracted) vs `UserFinancialProfile.tax_filing_status`. Flag discrepancy. Surface as high-priority red flag. No comparable tool does this automatically from documents + profile. |
| Deduction probe questions from transaction patterns | Electronics/drone purchases → "Used for work?" Pet food → "Guard/service animal?" Multiple UTV payments → "Motorsport business?" | HIGH | Rules engine scans `Transaction` records for merchant categories that have common deductible gray areas. For each matched pattern, generate a targeted interview question. Maps to existing `AIQuestion` feed infrastructure. |
| Traditional vs Roth 401k optimization engine | Current bracket + expected retirement income → concrete educational recommendation on which 401k type maximizes lifetime take-home | MEDIUM | Rules-first: if taxable income is in 22%+ bracket and retirement is distant, Traditional wins; if in 10-12% bracket or early career, Roth wins. Use deterministic 2026 brackets. Claude explains the tradeoff in plain English. |
| QBI deduction eligibility surface | 20% deduction on net business income is one of the most under-utilized freelancer deductions — most don't know it exists | MEDIUM | Rules-first: if has Schedule C income (1099-NEC in vault or bank deposits flagged as business) AND taxable income under threshold ($197,300 single / $394,600 MFJ for 2025), surface QBI opportunity. |
| Employer match gap calculator | Show the exact dollar amount of "free money" left on table if contribution rate is below match threshold | LOW | Requires: current contribution % (from pay stub or user answer) + employer match % (from offer letter extraction or user answer). `$annual_salary × (match_threshold_% − current_contribution_%)` = gap. High-impact, low-effort calculation. |
| Interview uses what it already knows | Unlike TurboTax which asks everything from scratch, SpendifiAI skips questions it can answer from bank/email/vault data | HIGH | Pre-populate interview context engine: scan transactions for side-business income, check vault for W-2/1099 already uploaded, check email for subscription patterns. Only ask what's genuinely unknown. Makes interview feel intelligent, not generic. |
| Ongoing red-flag questions in existing AI Questions feed | Optimization is not a one-time event — new transactions trigger new opportunities | MEDIUM | When new transactions arrive that match deduction probe patterns, create an `AIQuestion` of type `optimization_probe` routed to the Questions feed. Reuses existing infrastructure entirely. |
| Document-to-bank anomaly cross-check | "Your W-2 shows $85K wages but bank deposits total $92K — verify before filing" | HIGH | Extends existing `TaxDocumentIntelligenceService` cross-document anomaly detection. Adds comparison: sum of bank deposits by income category vs sum of reported income from W-2/1099s in vault. Surface as report finding, not error. |
| Offer letter benefit gap analysis | Compares current pay stub deductions to offer letter benefits to flag unclaimed benefits (HSA, FSA, commuter) | HIGH | Requires: offer letter extraction (new doc type) + pay stub extraction. Compare benefits available vs actually enrolled in. Educational framing: "your offer letter shows HSA eligibility — are you enrolled?" |

### Anti-Features (Deliberately NOT Building)

Features that seem good but create legal/ethical/technical problems. These are explicitly out of scope.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Asserting the "correct" filing status | Users want to be told what to do, not just what to consider | Telling a user "you should file jointly" is tax advice requiring a CPA/EA/JD. SpendifiAI has no such license. IRS audit liability shifts to the platform. | Surface the discrepancy and estimated difference ("filing jointly could reduce your liability by ~$X based on these numbers — review with a tax professional"). Present both scenarios. Never assert which to choose. |
| Calculating actual net tax owed / refund amount | Users want to know their refund | This is tax preparation (requires PTIN for paid preparers). Calculating an actual refund figure creates expectation that could expose the platform to liability if wrong. | Show effective vs marginal rate and withholding gap (educational). Do not compute Form 1040 lines. |
| Investment allocation or portfolio advice | 401k optimization naturally leads users to ask "what should I invest in?" | Investment advice on specific asset allocations requires RIA registration with SEC or FINRA. SpendifiAI is not registered. | Recommend contribution level only. "Consider maximizing contributions and reviewing allocation with a financial advisor." |
| Guaranteeing dollar savings amounts | Users want certainty; "you'll save $X" is compelling | Guarantees create direct liability if the user's actual tax outcome differs. Amounts depend on factors the app cannot fully know. | Frame all estimates as ranges with explicit uncertainty: "based on the information provided, this deduction could be worth $200–$800 — actual impact depends on your full tax picture." |
| Auto-filing or tax return generation | The logical next step after optimization feels natural | Requires PTIN (Preparer Tax Identification Number) for paid preparation. Also out of scope per PROJECT.md. | Export findings to a downloadable PDF report the user can share with their accountant. Integrate with existing tax export infrastructure. |
| Legal advice on gray-area deductions | "Is my guard dog deductible?" — users want a yes/no | Definitive rulings on gray-area deductions (guard dog, mixed-use vehicle, home office shared with family) vary by situation and could be wrong, causing IRS penalties the user attributes to the app. | Surface the rule ("a guard/service animal used primarily for business may qualify") and the relevant IRS publication, then probe with a question. Never assert deductibility. |
| Global "dismiss all disclaimers" toggle | Power users find disclaimers annoying | Eliminates the legal protection the disclaimers provide. A user who dismissed disclaimers and then acts on advice in a harmful way creates greater liability than one who saw disclaimers repeatedly. | Reduce visual noise with collapsible disclaimer details (click "What does this mean?"), but the high-level "review with a tax professional" must always be visible adjacent to each suggestion. |
| Full SSN/TIN extraction and storage | Pay stubs and W-2s contain full SSNs; the AI will see them | Catastrophic PII liability. A breach of stored SSNs would be a regulatory and reputational disaster. Already explicitly prohibited in PROJECT.md. | Strip SSNs before storing extracted data. Store only last 4 digits. Log that stripping occurred. Display as "***-**-1234". |
| Personalized score ("Your Optimization Score: 62/100") | Makes results gamified and easy to share | A single score encourages over-trust and gaming. Users would chase the score rather than acting on the actual findings. Score thresholds would need calibration that's inherently arbitrary. | Surface individual findings with priority (High/Medium/Low impact). Let the user see the full picture, not a reduction. |
| State tax optimization | Federal optimization is complex enough; state rules vary dramatically | 50-state tax rules are an entire separate domain. Supporting all states would require maintaining a large, frequently-changing rules library that's out of scope for v2.1. | Focus exclusively on federal tax optimization in v2.1. Add state-specific rules only after federal system is validated. Note to user: "state tax rules vary — consult a local tax professional." |
| Retirement distribution / withdrawal advice | "When should I take from my 401k?" is a natural question | Withdrawal timing and sequence is investment/retirement advice requiring fiduciary responsibility. Not in SpendifiAI's scope. | Surface contribution optimization only. Out-of-scope questions should be deflected: "retirement distribution strategy is a great question for a financial advisor." |
| Competitive comparison ("You're leaving 40% more than average users") | Seems motivating | Social comparison in financial contexts is manipulative and may not be legally permissible under FTC guidance. Comparisons also require a large user data pool SpendifiAI does not yet have. | Personal impact framing only: "at your current salary, the uncaptured employer match is $X/year." Absolute numbers, not relative comparisons. |

---

## Feature Dependencies

```
Document Intake (new doc types: pay stub, offer letter, retirement stmt)
  └──requires──> Existing TaxDocumentExtractorService (already built in v2.0)
     └──requires──> Two-pass classify→extract pipeline (already built in v2.0)

Cross-Source Context Engine
  └──requires──> Document Intake (new types)
  └──requires──> Transaction data (already in DB)
  └──requires──> UserFinancialProfile (already built)
  └──requires──> ParsedEmail/Order data (already built)
  └──feeds──> Interview Engine (nothing to ask if already known from data)

Interview Engine (AIQuestion-pattern)
  └──requires──> Cross-Source Context Engine (to skip known answers)
  └──requires──> Existing AIQuestion infrastructure (type + status enums)
  └──produces──> OptimizationSession answers

Rules-First Optimization Engine
  └──requires──> 2026 deterministic tax tables (brackets, limits, thresholds)
  └──requires──> OptimizationSession answers + Cross-Source Context
  └──feeds──> Optimization Report

Optimization Report
  └──requires──> Rules-First Engine output
  └──requires──> Claude AI (plain-English explanations per finding)
  └──displays──> Action items ranked by estimated impact

Ongoing Red-Flag Questions (AI Questions feed integration)
  └──requires──> Existing AIQuestion + TransactionCategorized event pipeline
  └──requires──> Deduction probe rules (from Rules-First Engine)
  └──feeds INTO──> Existing Questions page (no new page needed)

Filing Status Red-Flag Detector
  └──requires──> Pay stub extraction (W-4 box 3 or withholding amounts)
  └──requires──> UserFinancialProfile.tax_filing_status
  └──feeds──> Optimization Report (high-priority finding)

Employer Match Gap Calculator
  └──requires──> Pay stub extraction (contribution % or contribution amount)
  └──optionally──> Offer letter extraction (match % and threshold)
  └──fallback──> Interview question if not extractable
  └──feeds──> Optimization Report (retirement section)

Document-to-Bank Anomaly Cross-Check
  └──requires──> W-2/1099 extraction (already built in v2.0)
  └──requires──> Transaction income aggregation (existing DashboardController logic)
  └──enhances──> Existing TaxDocumentIntelligenceService cross-document detection
  └──feeds──> Optimization Report (income consistency section)
```

### Dependency Notes

- **Cross-Source Context Engine requires ALL data sources assembled first:** The interview should never ask about information visible in uploaded documents or bank transactions. Building this "known facts" layer is the highest-leverage architectural piece.
- **Interview Engine reuses AIQuestion infrastructure:** The existing `AIQuestion` model with its `question_type`, `status`, `options` fields and the `/api/v1/questions` endpoint can be extended with a new `question_type` of `optimization_probe`. New UI overlay for the sequential interview flow, but the backend persistence pattern is identical.
- **Optimization Report requires Rules-First Engine THEN Claude:** Rules determine what findings exist; Claude generates the plain-English explanation for each finding. Do not use Claude to determine whether a rule applies — that is deterministic and must be deterministic.
- **Ongoing red-flag questions do NOT require a new page:** They flow into the existing AI Questions feed automatically when `CategorizePendingTransactions` or a new event fires, reusing the existing Questions page.

---

## MVP Definition

### Launch With (v2.1 core)

Minimum viable feature set to deliver value and validate the concept.

- [x] New "Optimize My Income" nav item and dedicated page/flow entry point
- [x] Document intake for 4 new doc types: pay stub, offer letter, 401k/retirement statement, benefits summary — via existing Vault
- [x] Cross-Source Context Engine (assemble known facts before asking questions)
- [x] Guided interview flow: one question at a time, conditional logic, skip/back capability
- [x] Filing status check (profile vs extracted pay stub — red flag if mismatch)
- [x] Tax withholding check (withholding gap against 2026 brackets)
- [x] Standard vs itemized comparison (from known deductibles)
- [x] 401k contribution check: employer match gap calculator
- [x] Traditional vs Roth educational recommendation (rules-first, plain-English Claude explanation)
- [x] QBI deduction eligibility surface (for self-employed users)
- [x] Deduction probe questions for top 5 transaction patterns (home office, vehicle/gas, electronics, pet food, meals/entertainment)
- [x] Optimization report with ranked action items, confidence levels, and disclaimers
- [x] Ongoing red-flag questions in existing AI Questions feed

### Add After Validation (v2.1.x)

- [ ] Offer letter benefit gap analysis — requires offer letter extraction to be validated first; add when extraction confidence is high enough
- [ ] Document-to-bank income anomaly cross-check — extends existing `TaxDocumentIntelligenceService`; valuable but not blocking
- [ ] Insurance statement extraction (additional doc type) — useful for HSA/FSA analysis
- [ ] Mortgage statement extraction for itemized deduction pre-population

### Future Consideration (v2.2+)

- [ ] Expanded deduction probe library (beyond top 5 transaction patterns)
- [ ] Multi-year optimization comparison ("your 401k gap grew by $X vs last year")
- [ ] State-specific optimization (requires maintaining per-state rules)
- [ ] Accountant portal integration: accountant can see optimization report alongside client's vault

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Document intake (pay stub, offer letter, 401k) | HIGH | LOW (reuses Vault) | P1 |
| Cross-Source Context Engine | HIGH | MEDIUM | P1 |
| Guided interview — one Q at a time | HIGH | MEDIUM | P1 |
| Filing status mismatch red flag | HIGH | MEDIUM | P1 |
| 401k employer match gap calculator | HIGH | LOW | P1 |
| Traditional vs Roth educational recommendation | HIGH | LOW (rules-first + Claude) | P1 |
| Optimization report with disclaimers | HIGH | MEDIUM | P1 |
| Standard vs itemized comparison | MEDIUM | LOW | P1 |
| Tax withholding check | MEDIUM | LOW | P1 |
| QBI deduction eligibility surface | MEDIUM | LOW | P1 |
| Deduction probe questions (top 5 patterns) | HIGH | MEDIUM | P1 |
| Ongoing red-flag questions in AI feed | MEDIUM | LOW (reuses AIQuestion) | P1 |
| Document-to-bank income anomaly check | HIGH | HIGH (aggregation complexity) | P2 |
| Offer letter benefit gap analysis | HIGH | HIGH (new extraction schema) | P2 |
| Expanded deduction probe library | MEDIUM | MEDIUM | P3 |
| Multi-year optimization comparison | LOW | MEDIUM | P3 |
| State-specific tax optimization | HIGH | VERY HIGH (50-state rules) | P3 |

**Priority key:**
- P1: Must have for v2.1 launch
- P2: Add after core validated
- P3: Future milestone

---

## Competitor Feature Analysis

| Feature | Keeper Tax | TurboTax | Instead.com | SpendifiAI Approach |
|---------|------------|----------|-------------|---------------------|
| Transaction-based deduction detection | Yes — tap-to-confirm on bank scan | Manual entry only | No (advisor-focused) | Yes — transaction pattern → probe question |
| Document extraction | No | Limited OCR | Yes — 1040s, paystubs, K-1s | Yes — full two-pass AI extraction (v2.0 Vault) |
| Cross-source (docs + bank + email) | Partial (bank only) | No | Yes (advisor reads all) | Yes — unique differentiator |
| Filing status educational check | No | Yes — interview flow | Yes | Yes — automated from pay stub + profile |
| 401k contribution optimization | No | Partial | Yes | Yes — match gap + Traditional vs Roth |
| Interview format | Tap-to-confirm | Progressive form | Advisor conversation | One question at a time, conditional logic |
| Educational disclaimers | Minimal | Prominent | N/A (advisor is fiduciary) | Prominent, adjacent to every finding |
| Ongoing optimization (between tax seasons) | Year-round expense tracking | Annual only | Advisor-driven | Yes — ongoing red-flag questions in AI feed |
| Cost to user | $16/month | $0–$219/year | $1,800+/year advisor fee | Included in SpendifiAI (no separate charge) |

---

## Educational-Only Boundary (Liability Framework)

This table defines the hard line between what SpendifiAI can say vs what requires a licensed professional.

| SpendifiAI CAN say (educational) | SpendifiAI CANNOT say (advice) |
|-----------------------------------|-------------------------------|
| "Your pay stub shows withholding of $X; for your income level the 2026 standard withholding would be approximately $Y" | "You are under-withheld and will owe $Z at filing" |
| "Married filing jointly generally results in a lower combined tax than married filing separately for most couples" | "You should file jointly" |
| "A home office used exclusively and regularly for business may qualify as a deductible expense" | "Your home office qualifies; you can deduct $X" |
| "Your employer matches up to 4% — contributing 4% would maximize free money; you are currently at 2%" | "Increase your contribution to 4%" |
| "Traditional 401k contributions reduce taxable income now; Roth contributions are tax-free in retirement. At your current marginal rate, consider discussing Traditional with your advisor." | "You should use Traditional 401k" |
| "Your pet food purchases are not typically deductible, but if your pet is a certified guard or service animal used for business, a portion may be deductible — discuss with your tax professional" | "Your dog is deductible" |
| "Based on these numbers, you may qualify for the QBI deduction — review this with your tax professional" | "You qualify for the QBI deduction" |

**Rule:** Use "may," "could," "consider," "discuss with," "typically," and "based on information provided." Never use "will," "should," "you qualify," "you owe," or any definitive assertion.

---

## Existing SpendifiAI Assets to Leverage

| Existing Asset | How It Helps v2.1 |
|----------------|-------------------|
| `TaxDocumentExtractorService` + two-pass pipeline | Extend with new doc types (pay stub, offer letter, retirement stmt) — classification patterns + extraction schemas |
| `TaxDocumentIntelligenceService` | Extend cross-document anomaly detection to include bank deposit vs W-2/1099 comparison |
| `AIQuestion` model + `/api/v1/questions` endpoint | Reuse for optimization probe questions; add `optimization_probe` to `QuestionType` enum |
| `UserFinancialProfile` | Provides filing status, employment type, housing status — cross-referenced against extracted docs |
| `IncomeDetectorService` | Already classifies primary vs extra income from bank data — feeds cross-source context engine |
| `TransactionCategorizerService` patterns | Merchant pattern matching logic for deduction probe detection |
| `TransactionCategorized` event + listeners | Hook for triggering ongoing red-flag question generation from new transactions |
| `SavingsAnalyzerService` pattern | Template for "gather data → send to Claude → structured output" service pattern |
| `DashboardCacheService` invalidation patterns | Optimization report cache should invalidate when new docs uploaded or new transactions arrive |
| `TaxController` + `TaxExportService` | Optimization report can export via existing export infrastructure |
| Redis queue infrastructure | Background job for running cross-source analysis when report is first generated or refreshed |
| Existing AI Questions page | No new page needed for ongoing red-flag questions — they surface here automatically |

---

## Sources

- [Keeper Tax — Write-Off Detection](https://www.keepertax.com/feature/write-off-detection)
- [Keeper Tax Review — FinanceBuzz](https://financebuzz.com/keeper-tax-review)
- [Ask an AI Accountant, Version 2.0 — Keeper Tax](https://www.keepertax.com/ask-an-ai-accountant-2-0)
- [How TurboTax Turns a Dreadful UX Into a Delightful One — Appcues](https://www.appcues.com/blog/how-turbotax-makes-a-dreadful-user-experience-a-delightful-one)
- [Designing Onboarding Questionnaire for a Finance App — Medium](https://medium.com/@harshiknayak/designing-onboarding-questionnaire-for-a-personal-finance-strategy-app-ux-case-study-0dcbfeb6b2bc)
- [Top 10 Fintech UX Design Practices 2026 — Onething Design](https://www.onething.design/post/top-10-fintech-ux-design-practices-2026)
- [Betterment — Traditional and Roth 401(k)s](https://www.betterment.com/employees/resources/traditional-and-roth-401ks)
- [Blooom Review 2025 — Millennial Money Man](https://millennialmoneyman.com/blooom-review/)
- [Instead AI Review 2026 — Uncle Kam](https://unclekam.com/tax-pro-tools/ai-tax-tools/instead-ai-review/)
- [Altruist Hazel AI Tax Planning](https://altruist.com/news/hazel-ai-tax-planning/)
- [Facet Tax Planning](https://facet.com/tax-planning/)
- [Fintech Regulation Guide — Innreg](https://www.innreg.com/blog/fintech-regulation-guide-for-startups)
- [Tax Compliance in FinTech — RegTech Analyst](https://regtechanalyst.com/tax-compliance-in-fintech-balancing-user-experience-and-regulatory-requirements/)
- [What Are Tax Pros Asking AI Chatbots? — Intuit Tax Pro Center](https://accountants.intuit.com/taxprocenter/practice-management/what-are-tax-pros-asking-ai-chatbots-during-tax-season/)
- [Risks of Using AI for Tax Preparation — davidovcpa.com](https://www.davidovcpa.com/uncategorized/risks-of-using-ai-for-tax-preparation-what-taxpayers-must-know/)
- [IRS — Self-Employed Retirement Plan Contribution Deduction](https://www.irs.gov/retirement-plans/self-employed-individuals-calculating-your-own-retirement-plan-contribution-and-deduction)
- [A Freelancer's Guide to Taxes — TurboTax](https://turbotax.intuit.com/tax-tips/self-employment-taxes/a-freelancers-guide-to-taxes/L6ACNfKVW)

---

*Feature research for: Optimize My Income (v2.1) — Personal Tax/Income Optimization + Smart Financial Interview*
*Researched: 2026-07-01*
