# SCENARIOS-SPEC — Optimization Scenarios: Data Acquisition, Engine, Choice-to-Checklist (FINAL, merged)

> Implementation-ready design for owner Decisions 9 + 10
> (`.planning/reference/enhanced-profile-integration-notes.md` — BINDING).
> Merged from `SCENARIOS-SPEC-part1-data.md` + `SCENARIOS-SPEC-part2-engine.md` after adversarial
> verification against shipped code on `release/v2.0.0` (2026-07-02). Every referenced
> class/method/field/config key/fact key below was verified in the actual codebase; every
> inconsistency between the two parts is resolved here (see §M, "Merge fixes applied" — §M is
> normative where the original parts disagree).
>
> Educational-only frame (D10.6) and SAFE-01/SAFE-03 (no dollar figures in Claude payloads) apply
> to every section of this document.

---

## 0. Non-negotiables

1. **All dollar math in `TaxRulesEngineService`** (or the sibling pure `ScenarioSolverService`
   that only calls it). Zero Claude/HTTP in any computation path. Claude words prose only; dollar
   figures never enter Claude payloads (same discipline as
   `InterviewOrchestratorService::wordQuestion()` / `OptimizationReportNarratorService::narrateSection()`).
2. **Integer cents everywhere** in PHP; `config/tax-rules.php` stores whole dollars; the engine
   converts (shipped convention).
3. **Additive only**: new config files/keys, new services, new controllers, new routes, new page
   stage, two forward-only migrations. No changes to existing engine method signatures,
   `OptimizationFinding` shape, report section response shape, `UserTaxFact` semantics,
   `UserFinancialProfile` API, or any existing endpoint.
4. **Educational frame (D10.6)**: scenarios are "approaches to consider"; the user's pick IS the
   election. No "should", no guarantees. Long-horizon numbers follow D9.7 illustration rules
   (config growth assumptions, RANGE output, labeled "illustration").
5. **Cliff-before-Roth (2B.1, verbatim binding)**: for marketplace enrollees, MAGI management is
   modeled BEFORE any Roth allocation — a **hard guard inside scenario computation** (§B.5), not
   narration ordering. Subsidy/clawback dollars are NEVER computed (FLAG-22 rail).
6. **Fact-gated directives (D9.2)**: a checklist step that depends on an unconfirmed fact renders
   as the confirmation ask, not the directive.
7. **Blocked-list compliance** (INTEGRATION-MAP.md Blocked section is authoritative): no
   filing-status assertion (K1 only operationalizes the status the user confirmed); MFS
   optimization is NOT modeled (only the RPT-07 ceiling line exists elsewhere); backdoor Roth is
   NOT a scenario knob (stays a specialist-band finding); no silent writes of document-extracted
   facts (D4 confirm gate untouched); no spending-to-deduct, no guaranteed-dollar-savings language.
8. **Data safety**: money values live only in encrypted columns (`UserTaxFact.value`,
   `IncomeOptimizationProfile` money columns, `ScenarioFactSet.resolved_facts`,
   `InterviewSession.assertions`) or transient authed API responses. `AIQuestion.options` is
   unencrypted JSON — it must never carry a dollar value (pointer pattern, §A.5).

## 0.1 Verified grounding (shipped code this design builds on)

| Machinery | File | Verified surface used here |
|---|---|---|
| `TaxRulesEngineService` | `app/Services/TaxRulesEngineService.php` | `computeTax`, `marginalRate`, `standardDeductionCents(status, ?age)`, `compareStandardVsItemized`, `remaining401kRoomCents(ytd, ?age)`, `remainingIraRoomCents(ytd, ?age)` (shared trad+Roth limit), `remainingHsaRoomCents(ytd, coverageType, ?age)`, `rothVsTraditionalBand`, `rothIraEligibility(magi, status)` → `{eligible, limit_cents, phase_out_pct}`, `requiresMandatoryRothCatchup(priorYearFicaWagesCents)`, `traditionalIraDeductibility(magi, status, covered, spouseCovered)` → `{deductible, partial_limit_cents}`, `selfEmploymentTax`, private `computeBracketTax` — all cents-in/cents-out from `config/tax-rules.php` |
| `config/tax-rules.php` | — | 2026 brackets (4 statuses), `standard_deduction` + `standard_deduction_senior_addition`, `401k.employee_deferral` 24,500 + `catchup_age_50_plus` 8,000 + `catchup_age_60_to_63` 11,250 + `mandatory_roth_catchup_threshold` 150,000, `ira.annual_limit`/`catchup_age_50_plus` 1,100 + Roth/trad phase-outs (covered + spouse-covered), `hsa.self_only` 4,400/`family` 8,750/`catchup_age_55_plus`, `se_tax.ss_rate/medicare_rate/ss_wage_base/additional_medicare_rate`, `detection.ira_shared_limit`, `detection.section_415c_limit` [ASSUMED], `detection.ctc_amount` 2,200, `detection.medicare_hsa_lookback_months` 6, `detection.aca_fpl_pct`/`aca_fpl_threshold_single`/`aca_fpl_threshold_family4` [ASSUMED] |
| `IncomeOptimizationProfile` | `app/Models/IncomeOptimizationProfile.php` | 14 encrypted cent columns (`w2_wages`, `self_employment_income`, `bank_deposit_total`, `traditional_401k_ytd`, `roth_401k_ytd`, `ira_ytd`, `hsa_ytd`, `mortgage_interest`, `property_tax_paid`, `charitable_contributions`, `student_loan_interest`, …), flags (`filing_status`, `has_hsa_eligible_plan`, `has_ira`, `ira_type`, `has_self_employment`, `employment_type`, `estimated_age` — **column exists, never populated: verified, only fillable**), `answerableFields(?UserTaxFact $factsProxy = null)`, `profile_hash`, `$hidden` on all money columns |
| `IncomeOptimizerDataAssemblerService` | `app/Services/IncomeOptimizerDataAssemblerService.php` | `buildProfile(User, int $taxYear)` upsert; sources: profile flags (`normaliseFilingStatus`, `has_self_employment` from `employment_type`) → Ready TaxDocuments (incl. PayStub nested-`fields` arm summing `gross_pay`, `traditional_401k_deduction`, `roth_401k_deduction`, `hsa_deduction`) → calendar-year bank deposits; `isStale()`/`computeProfileHash` staleness pattern; `dollarsToCents()` |
| `UserTaxFact` | `app/Models/UserTaxFact.php` | `recordFact()` (named args: userId, factKey, value, sourceType, label, volatility, reconfirmAfter, taxYear, entityId, sourceId, metadata; append-only, SELECT FOR UPDATE, proposals for `document_extraction`), `confirmProposal()` (D4 gate), `currentFact($userId, $key, $entityId = null, $taxYear = null)` (confirm-gated), `currentFactKeys()`, `isDueForReconfirmation()`, encrypted `value` |
| `InterviewOrchestratorService` | `app/Services/InterviewOrchestratorService.php` | `startOrResume()` (idempotent, stale-queue self-heal), `buildInitialQueue()` (auto → conditional → battery; capped by `config('tax-detection.interview.initial_cap', 10)`), `nextQuestion()` (skip-logic `isAlreadyAnswered`, private const `GATED_PROBES` INT-04, `isPrerequisiteUnsatisfied`), `createOptimizationQuestion()` (private const `BAND_CONFIDENCE`, auto=1.0), `wordQuestion()` (the Claude wording path), `recordAnswer(session, factKey, value, ?questionText, ?questionId)` — currently hardcodes `volatility: 'stable'`, `taxYear: null` (the §A.5.3 additive change) |
| `InterviewSession` | `app/Models/InterviewSession.php` | `queue`/`asked` string arrays; `markAsked`, `dequeueKey`, `appendTranscript`; `assertions` encrypted TEXT + `$hidden`; one in_progress per (user, tax_year) partial unique index |
| `InterviewController` | `app/Http/Controllers/Api/InterviewController.php` | routes under `optimizer/interview/*` (auth:sanctum, NO bank.connected); `next()` response shape (`question.{id, question, question_type, options, ai_confidence, ai_best_guess, band, suggested_treatment, transaction_count}`); `answer()` via orchestrator; `AnswerOptimizationQuestionRequest` (`answer: required|string|max:500`) |
| `TaxDocumentExtractorService` | `app/Services/AI/TaxDocumentExtractorService.php` | `PAY_STUB_FIELDS` (21 incl. `pay_period_start/end`, `pay_date`, `gross_pay`, `federal_tax_withheld`, `ytd_gross`, `ytd_federal_tax`, pretax deduction fields), `BENEFITS_GUIDE_FIELDS` (17 incl. `employer_match_formula`, `hdhp_hsa_available`), `RETIREMENT_STATEMENT_FIELDS` (`account_balance`, `ytd_contributions`, `ytd_employer_contributions`, `account_type`, …) |
| `PaystubFactExtractorService` | `app/Services/AI/PaystubFactExtractorService.php` | `PAYSTUB_FACT_MAP` (4 money facts → `retirement.traditional_401k_ytd_cents`, `retirement.roth_401k_ytd_cents`, `retirement.hsa_ytd_cents`, `benefits.fsa_ytd_cents`), `BENEFITS_FACT_MAP` (`employer.*`), `proposeFacts()` via `source_type='document_extraction'` (confirm-gated), yes/no boolean convention |
| Detectors/Scanners/Sweeps | `app/Services/Detectors/*`, `Scanners/SafeHarborBenchmark.php`, `Sweeps/PenaltyPreventionSweep.php` | `AcaCliffMonitor` (`aca_magi_management` finding, cliff-before-Roth), `EmployerMatchGapDetector` (`employer.match_pct/match_threshold_pct/contribution_pct`), `WithholdingGapDetector` (`employer.federal_withholding`), `ProfileConformanceDetector` (compares `profile.tax_filing_status` vs `w4.filing_status` — proof the two filing-status keys are semantically DISTINCT), `SafeHarborBenchmark` (`prior_year.federal_liability_cents`), `PenaltyPreventionSweep` (`profile.filing_status`), `RefundableCreditScanner` (`family.dependents_count`, `family.qualifying_children_under_17`) |
| Report pipeline | `app/Services/OptimizationReportGeneratorService.php`, `OptimizationReportNarratorService.php`, `config/optimization-report.php` | 9 sections (4 topical + 3 RPT-06 wrappers incl. `documents_missing` + year_end + glossary); `narrateSection()`/`narrateExecutiveSummary()` are the sanctioned Claude call sites (with `NarrationService`); `MarkOptimizationReportStale` listener exists and is wired |
| `DurableFactsController` | `app/Http/Controllers/Api/DurableFactsController.php` | `index/confirm/supersede` under `optimizer/facts/*`; `value` `$hidden` |
| Frontend | `resources/js/Pages/Optimize/Index.tsx` | `type ViewMode = 'findings' \| 'interview' \| 'report'`; `StageIndicator`; interview loop against `/{interview}/next` |
| Dashboard | `app/Http/Controllers/Api/DashboardController.php` | `monthly_surplus` + `budget_waterfall` figures (reused for K6 cap, never re-derived) |

Shipped fact keys this spec reads (all grep-verified in `app/`):
`profile.filing_status`, `w4.filing_status`, `family.dependents_count`,
`family.qualifying_children_under_17`, `employer.match_pct`, `employer.match_threshold_pct`,
`employer.contribution_pct`, `employer.match_formula`, `employer.has_401k`,
`employer.federal_withholding`, `employer.hdhp_hsa_available`, `employer.hsa_enrolled`,
`employer.fsa_available`, `employer.dependent_care_fsa_available`, `employer.after_tax_401k_available`,
`employer.in_plan_roth_conversion_available`, `employer.hsa_deduction_ytd`, `employer.has_457b`,
`health.hsa_eligible`, `hsa.ytd_contribution_cents`, `retirement.traditional_401k_ytd_cents`,
`retirement.roth_401k_ytd_cents`, `retirement.hsa_ytd_cents`, `retirement.k401_contribution_ytd_cents`,
`ira.traditional_ytd_contribution_cents`, `ira.traditional_contribution_ytd` (legacy variant),
`ira.roth_ytd_contribution_cents`, `ira.balance_range`, `ira.backdoor_roth_eligible`,
`retirement.has_ira_balance`, `profile.estimated_magi_cents`, `prior_year.agi_cents`,
`prior_year.federal_liability_cents`, `marketplace.pays_marketplace_premiums`,
`medicare.enrollment_date`, `finance.is_cash_constrained`, `benefits.fsa_ytd_cents`, `life_event.*`.

---

# PART A — Objective-driven data acquisition & readiness (Decision 10.1 + D9.2 substrate)

## A.1 Canonical fact registry

### A.1.1 Value conventions (unchanged from shipped code)

- **Money**: integer-cents-as-string in `UserTaxFact.value` (`'150000'` = $1,500.00). Interview
  answers typed in dollars are converted server-side: `(string)(int) round((float)$dollars * 100)`.
- **Booleans**: `'yes'` / `'no'` (PaystubFactExtractorService convention).
- **Enums**: snake_case strings matching `config/tax-rules.php` keys (`married_joint`, not
  `married_jointly` — the assembler's `normaliseFilingStatus()` is the reference normalizer).
- **Volatility**: `permanent` (never re-asked), `stable` (12-month reconfirm via
  `isDueForReconfirmation()`), `annual` (per-tax-year; YTD money facts always carry `tax_year`).

### A.1.2 NEW fact keys introduced by this spec (canonical, final — supersedes both parts)

| Fact key | Type | Volatility | tax_year? | Meaning |
|---|---|---|---|---|
| `pay.frequency` | enum: `weekly\|biweekly\|semimonthly\|monthly` | stable | null | Paycheck cadence. Periods/yr map in config: 52/26/24/12. **[Merge fix M1: Part 2's `income.pay_frequency` is renamed to this]** |
| `pay.gross_per_period_cents` | money | annual | yes | Gross pay per paycheck |
| `pay.federal_withholding_per_period_cents` | money | annual | yes | Federal income tax withheld per paycheck |
| `w4.dependents_claimed` | integer string | stable | null | Dependents claimed on the W-4 on file at work (NOT `family.dependents_count` — the delta between them drives the owner's "update your dependents from 0 to 3" directive) |
| `w4.extra_withholding_per_period_cents` | money | stable | null | W-4 Step 4(c) extra withholding |
| `person.birth_year` | year string (`'1985'`) | permanent | null | Age math: catch-up limits, years-to-target. Resolver derives `age = tax_year − birth_year`; assembler backfills the never-populated `estimated_age` snapshot column (§A.6.4) |
| `retirement.target_age` | integer string | stable | null | User's stated target retirement age |
| `retirement.statement_balance_cents` | money | annual | yes | Total balance from RETIREMENT_STATEMENT doc extraction (§A.5.4) |
| `retirement.statement_ytd_contributions_cents` | money | annual | yes | Statement YTD contributions — `known` cross-check only, never the canonical 401(k) YTD (metadata carries `account_type`) |
| `retirement.elected_roth_share_pct` | integer string | stable | null | Written when the user checks off the K2/K3 checklist step (reality fact, §D.5) |
| `hsa.coverage_type` | enum: `self_only\|family` | stable | null | Selects `config/tax-rules.php` `hsa.self_only` vs `hsa.family`. **[Merge fix M2: Part 2's `health.hsa_coverage_type` is renamed to this]** |
| `income.annual_gross_cents` | money | annual | yes | User-stated expected gross (interview fallback when no W-2/paystub/bank signal) |
| `spouse.annual_income_cents` | money | annual | yes | Spouse gross income (MFJ dual-earner accuracy). Seeded from `UserFinancialProfile.spouse_income` (monthly, encrypted) × 12 as `derived`; interview ask only if absent |
| `spouse.covered_by_retirement_plan` | yes/no | stable | null | **[Merge addition M13]** Input to `traditionalIraDeductibility(..., $spouseCoveredByPlan)`. Optional; default `'no'` documented as the conservative assumption (yields the wider deduction phase-out only via the covered-spouse table when `'yes'`) |
| `scenario.chosen_option` | enum: `take_home\|tax_burden\|retirement\|balanced\|merged\|custom` | stable | yes | Choice persistence (§D.6) |
| `scenario.chosen_knobs` | JSON string (encrypted value column) | stable | yes | The clamped knob vector chosen; metadata carries `fact_set_id` (§D.6) |

All new money facts fit the existing encrypted `value` column — no schema change to `user_tax_facts`.

### A.1.3 Alias map (resolver-level — detectors are NOT rewritten; scope rule 8)

The shipped detector fleet uses divergent keys for the same physical fact. The resolver treats the
first column as canonical and falls back through aliases (most recent `asserted_at` current row wins):

| Canonical | Aliases (read-only fallback) |
|---|---|
| `retirement.traditional_401k_ytd_cents` | `retirement.k401_contribution_ytd_cents` |
| `hsa.ytd_contribution_cents` | `retirement.hsa_ytd_cents`, `employer.hsa_deduction_ytd` |
| `ira.traditional_ytd_contribution_cents` | `ira.traditional_contribution_ytd` |

**[Merge fix M3 — REMOVED alias]** Part 1 aliased `w4.filing_status` ↔ `profile.filing_status`.
That is wrong and is deleted: the shipped `ProfileConformanceDetector` COMPARES these two keys to
detect W-4-vs-profile mismatch (its whole purpose), and K1's divergence math (§B.1) needs both
sides independently. They are semantically distinct facts:
- `profile.filing_status` — the user's actual/confirmed filing status (what the return will say);
  read by `PenaltyPreventionSweep`. This is the canonical status for all annual-tax math.
- `w4.filing_status` — evidence of what the W-4 on file at the employer claims (paystub
  conformance / interview). This is a withholding-side input only.
Collapsing them would both break K1 and create a liability problem (treating payroll-form evidence
as the user's confirmed election).

New writes always use canonical keys. Aliases live in `config/optimization-objectives.php`
(`'fact_aliases' => [...]`) so future consolidation is config-only (deferred; owner sign-off
required before touching shipped detector writes).

## A.2 FACT-REQUIREMENTS MAP (Decision 10.1)

Three objectives — canonical ids used EVERYWHERE (config, services, API, frontend, option keys):
**`take_home`**, **`tax_burden`**, **`retirement`**.
**[Merge fix M4: Part 2's `income`/`tax` ids are renamed to `take_home`/`tax_burden`.]**

Each required fact carries a **source-priority chain**; the resolver walks it in order and stops at
the first hit. Chain order is per-fact (declared in config), following two principles:
- **Money YTD facts**: snapshot first (doc-summed values are fresher/more complete than stated ones).
- **Identity/enum facts** (filing status, coverage type): user-confirmed fact first — a user's
  interview answer must beat a stale profile-derived snapshot. **[Merge fix M14: Part 1's C1 put
  snapshot first; fixed to fact-first.]**

Chain steps: `snapshot:{column}` (IncomeOptimizationProfile) · `fact` (confirmed UserTaxFact,
canonical then aliases, year-scoped then unscoped: `currentFact($u,$k,null,$year) ?? currentFact($u,$k)`) ·
`profile:{field}` (UserFinancialProfile) · `derive:{rule}` (§A.6.3) · `ask` (template, §A.4).

Rule: **every blocking fact has an interview template** — documents/bank data are accelerators,
never the only path. `blocking` = scenario math is impossible or misleading without it.
`optional` = improves precision; the documented default applies when absent (defaults are config
constants read by the engine, never invented by narration).

### A.2.1 Shared core (all three objectives)

| # | Fact | Blocking | Source chain | Default if optional-missing |
|---|---|---|---|---|
| C1 | Confirmed filing status (`profile.filing_status`) | BLOCKING | fact `profile.filing_status` → snapshot `filing_status` → profile `tax_filing_status` (normalized) → ask | — |
| C2 | Annual gross income (cents) | BLOCKING | snapshot `w2_wages` + `self_employment_income` → derive: paystub `ytd_gross` annualized → snapshot `bank_deposit_total` (flagged `low_precision`) → fact `income.annual_gross_cents` → ask | — |
| C3 | `pay.frequency` | BLOCKING (take_home, retirement); optional (tax_burden) | derive: paystub `pay_period_start/end` span (§A.6.3) → fact → ask | tax_burden default: `biweekly` |
| C4 | `person.birth_year` | BLOCKING (retirement); optional (take_home, tax_burden) | fact → ask | catch-ups assumed unavailable (conservative: no catch-up headroom shown) |

### A.2.2 Objective `take_home` — "more money in your paycheck now"

Levers fed (Part B): W-4 alignment, withholding right-sizing with safe-harbor guardrail, pre-tax
election trims where over-funded, per-paycheck surplus routing.

| # | Fact | Blocking | Source chain |
|---|---|---|---|
| T1 | C1–C3 | BLOCKING | — |
| T2 | `pay.gross_per_period_cents` | BLOCKING | derive: paystub `gross_pay` (per-stub field) → derive: C2 ÷ periods/yr → ask |
| T3 | Federal withholding, annualized (`employer.federal_withholding`, per-tax-year, cents) | BLOCKING | fact (written by paystub/interview flows; read by `WithholdingGapDetector`) → derive: paystub `ytd_federal_tax` annualized → derive: `pay.federal_withholding_per_period_cents` × periods/yr → ask (per-paycheck phrasing) |
| T4 | `w4.filing_status` + `w4.dependents_claimed` (W-4 on file) | **optional-with-suppression** — when absent, the K1 delta is suppressed (`w4_delta_included=false`, §B.4 step 4) and the K1 checklist group renders as the confirm-ask (D9.2). **[Merge fix M12: Part 1 had T4 BLOCKING; Part 2's graceful suppression wins — scenarios stay computable, the confirm-ask acquires the fact]** | fact → ask |
| T5 | `family.dependents_count` (+ `family.qualifying_children_under_17` when > 0) | BLOCKING | facts (existing; `RefundableCreditScanner` reads) → ask |
| T6 | Current pre-tax elections YTD: `retirement.traditional_401k_ytd_cents`, `retirement.roth_401k_ytd_cents`, `hsa.ytd_contribution_cents`, `benefits.fsa_ytd_cents` | BLOCKING (confirmed-zero allowed: `'0'`) | snapshot `traditional_401k_ytd`/`roth_401k_ytd`/`hsa_ytd` → fact (confirmed paystub proposals, aliases) → ask ("none" stores `'0'`) |
| T7 | `w4.extra_withholding_per_period_cents` | optional (default `'0'`) | fact → ask |
| T8 | `prior_year.federal_liability_cents` | optional — **safe-harbor guardrail**: without it the engine must not propose reducing withholding below current-year computed tax (conservative floor) | fact (existing, `SafeHarborBenchmark`) → ask |
| T9 | `spouse.annual_income_cents` | optional; surfaced only when C1 = `married_joint` | derive: profile `spouse_income` × 12 → fact → ask |

### A.2.3 Objective `tax_burden` — "lower this year's tax bill"

| # | Fact | Blocking | Source chain |
|---|---|---|---|
| B1 | C1, C2 | BLOCKING | — |
| B2 | `has_self_employment` | BLOCKING (boolean; both values valid) | snapshot flag → profile `employment_type` derivation (already in assembler) → ask |
| B3 | T6 set (pre-tax YTD, confirmed-zero allowed) | BLOCKING | as T6 |
| B4 | `ira.traditional_ytd_contribution_cents` + `ira.roth_ytd_contribution_cents` | BLOCKING (shared-limit math — `remainingIraRoomCents` needs the COMBINED total, per D2) | snapshot `ira_ytd` (undifferentiated sum; counts as `known`, not type-split — triggers a split-confirm question when `has_ira` and `ira_type` ∈ {null, both-suspected}) → facts → ask |
| B5 | `health.hsa_eligible` | conditionally BLOCKING — iff HSA lever in play (`employer.hdhp_hsa_available`='yes' OR profile `has_hsa` OR `hsa.ytd_contribution_cents` > 0) | fact (existing) → profile `has_hsa` (proxy; `known` not `confirmed`) → ask |
| B6 | `hsa.coverage_type` | conditionally BLOCKING — iff B5 = yes | fact → ask (gated, §A.4.3) |
| B7 | `person.birth_year` | optional (catch-up precision, senior std-deduction) | as C4 |
| B8 | Itemization signals: snapshot `mortgage_interest`, `property_tax_paid`, `charitable_contributions`, `student_loan_interest` | optional (default: standard deduction; `compareStandardVsItemized` runs only when present) | snapshot only — never interview-asked (doc-upload affordance; `config('tax-detection.doc_request_labels')` already covers these) |
| B9 | `profile.estimated_magi_cents` | optional (IRA deductibility/Roth phase-out precision; default: MAGI ≈ engine-projected, §B.2 SCN-05) | fact (existing) → derive |
| B10 | `family.dependents_count`, `family.qualifying_children_under_17` | optional (credit framing) | facts → profile `has_childcare_expenses` signal → ask |
| B11 | `prior_year.agi_cents` | optional | fact (existing) → ask |
| B12 | `spouse.covered_by_retirement_plan` | optional (default `'no'`) — only surfaced when C1 = `married_joint` and the IRA lever is in play | fact → ask |

### A.2.4 Objective `retirement` — "more toward retirement"

| # | Fact | Blocking | Source chain |
|---|---|---|---|
| R1 | C1–C4 (birth year BLOCKING here) | BLOCKING | — |
| R2 | `retirement.target_age` | BLOCKING (interview) — a config default (`default_retirement_age` 65) exists but readiness still asks; the default applies only to the FV horizon when the user declines ("not sure" choice stores nothing, engine uses default, illustration states the assumption) | fact → ask |
| R3 | T6 401(k) pair (trad + Roth YTD, confirmed-zero allowed) | BLOCKING | as T6 |
| R4 | B4 IRA pair | BLOCKING | as B4 |
| R5 | `employer.has_401k` | BLOCKING (boolean; `'no'` short-circuits R6/R7 to `not_applicable`) | fact (benefits-guide proposal → confirmed) → ask |
| R6 | Match formula: `employer.match_pct` + `employer.match_threshold_pct` (structured pair) OR `employer.match_formula` (free text; counts as `known` — structured pair required for `confirmed` directive math) | BLOCKING iff R5 = yes | facts (existing `EmployerMatchGapDetector` keys) → benefits-guide `employer.match_formula` → ask (two-part battery, prerequisite-chained) |
| R7 | `employer.contribution_pct` | BLOCKING iff R5 = yes ("not sure" → paystub-upload affordance + falls back to derive: T6 ÷ YTD gross) | fact (existing) → derive → ask |
| R8 | `retirement.statement_balance_cents` | optional — without it, projections are contributions-only illustrations (assumption stated per D9.7) | fact (new doc mapping §A.5.4) → fact `ira.balance_range` (coarse; `known`) → ask (range choices) |
| R9 | `employer.after_tax_401k_available`, `employer.in_plan_roth_conversion_available` | optional (mega-backdoor lever gate — surfaces a specialist-band note only, never a knob) | facts (benefits-guide) → ask only in specialist follow-up (stays out of gap queue) |
| R10 | `finance.is_cash_constrained` | optional (solver floor input, §B.7) | fact (existing) → ask |

**Out of scope for v2.1 scenarios (explicit, stated as assumptions in the UI):** state income tax
(config is federal-only), Social Security projection, pension/DB plans, 457(b)/403(b) coordination
(specialist-band note when `employer.has_457b` exists), spousal IRA scenarios, solo-401k/SEP knobs
for SE users (SE income is treated as ordinary income in the tax stack; the existing SE findings
continue to fire), FSA computed scenarios (awareness-only, §B.1 K4), backdoor/mega-backdoor Roth
(specialist-band findings only), MFS-vs-MFJ comparison (Blocked list), Additional Medicare surtax in
per-paycheck estimates (documented assumption; high earners get a caution line).

## A.3 Where the map lives: `config/optimization-objectives.php` (NEW)

Follows the D2 config-not-code precedent. Shape:

```php
return [
    'objectives' => [
        'take_home' => [
            'label' => 'Take-home income',
            'facts' => [
                // requirement_id => spec
                'filing_status' => [
                    'canonical_key' => 'profile.filing_status',   // [M3] confirmed status, NOT w4.*
                    'blocking' => true,
                    'chain' => ['fact', 'snapshot:filing_status', 'profile:tax_filing_status', 'ask'],
                ],
                'federal_withholding_annual' => [
                    'canonical_key' => 'employer.federal_withholding',
                    'blocking' => true,
                    'chain' => ['fact', 'derive:annualize_ytd_federal_tax',
                                'derive:per_period_times_frequency', 'ask:pay.federal_withholding_per_period_cents'],
                ],
                'w4_on_file' => [
                    'canonical_key' => 'w4.dependents_claimed',   // + w4.filing_status sibling entry
                    'blocking' => false,
                    'suppresses' => 'k1_delta',                   // [M12] optional-with-suppression
                    'chain' => ['fact', 'ask'],
                ],
                // ... one entry per A.2 row ...
            ],
        ],
        'tax_burden' => [ /* ... */ ],
        'retirement' => [ /* ... */ ],
    ],

    'fact_aliases' => [
        'retirement.traditional_401k_ytd_cents' => ['retirement.k401_contribution_ytd_cents'],
        'hsa.ytd_contribution_cents' => ['retirement.hsa_ytd_cents', 'employer.hsa_deduction_ytd'],
        'ira.traditional_ytd_contribution_cents' => ['ira.traditional_contribution_ytd'],
        // [M3] w4.filing_status ↔ profile.filing_status alias DELETED — distinct facts
    ],

    // [M8] single home for the periods map — optimizer-scenarios.php does NOT duplicate it
    'pay_periods_per_year' => ['weekly' => 52, 'biweekly' => 26, 'semimonthly' => 24, 'monthly' => 12],

    'question_templates' => [
        'pay.frequency' => [
            'question' => 'How often are you paid?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'weekly', 'label' => 'Every week'],
                ['value' => 'biweekly', 'label' => 'Every 2 weeks'],
                ['value' => 'semimonthly', 'label' => 'Twice a month (e.g. 1st & 15th)'],
                ['value' => 'monthly', 'label' => 'Once a month'],
            ],
            'volatility' => 'stable',
            'label' => 'Pay frequency',
        ],
        'w4.dependents_claimed' => [
            'question' => 'How many dependents do you currently claim on the W-4 form you filed with your employer? (This is what payroll uses — it may differ from your actual dependents.)',
            'answer_type' => 'integer', 'min' => 0, 'max' => 15,
            'volatility' => 'stable',
            'label' => 'Dependents claimed on W-4',
        ],
        'person.birth_year' => [
            'question' => 'What year were you born? We use this only to compute contribution limits and retirement timelines.',
            'answer_type' => 'year', 'min' => 1920,
            'volatility' => 'permanent',
            'label' => 'Birth year',
        ],
        'retirement.target_age' => [
            'question' => 'At what age would you like to be able to retire?',
            'answer_type' => 'integer', 'min' => 40, 'max' => 80,
            'volatility' => 'stable',
            'label' => 'Target retirement age',
        ],
        'pay.federal_withholding_per_period_cents' => [
            'question' => 'How much federal income tax is withheld from each paycheck? (Look for "Federal Income Tax" or "Fed W/H" on your pay stub — or upload a pay stub and we\'ll read it for you.)',
            'answer_type' => 'money_dollars',
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Federal withholding per paycheck',
            'doc_affordance' => 'pay_stub',   // renders "upload instead" CTA
        ],
        'hsa.coverage_type' => [
            'question' => 'Is your health plan coverage for just you, or does it cover your family?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'self_only', 'label' => 'Just me'],
                ['value' => 'family', 'label' => 'My family'],
            ],
            'volatility' => 'stable',
            'label' => 'HSA coverage type',
            'prerequisite' => 'health.hsa_eligible',   // merged into gate map at runtime (§A.4.3)
        ],
        'retirement.traditional_401k_ytd_cents' => [
            'question' => 'So far this year, roughly how much have you put into your Traditional (pre-tax) 401(k)? Enter 0 if none.',
            'answer_type' => 'money_dollars', 'allow_zero' => true,
            'volatility' => 'annual', 'tax_year_scoped' => true,
            'label' => 'Traditional 401(k) YTD (stated)',
            'doc_affordance' => 'pay_stub',
        ],
        // ... one template per askable fact in §A.2 (full list is an implementation task) ...
    ],
];
```

Key properties:

- **Deterministic templates — NO Claude call for gap questions.** These are profile-data asks with
  fixed phrasing and typed answers. `createOptimizationQuestion()` gets an additive early branch:
  if `$factKey` exists in `config('optimization-objectives.question_templates')`, build the
  `AIQuestion` from the template (question text; options carry `choices`/`answer_type`/
  `objective_tags`) and skip `wordQuestion()` entirely. Cheaper, safe by construction, immune to
  SAFE-03 drift. Finding-driven questions keep the existing Claude path.
- **Objective tagging** lives in `AIQuestion.options.objective_tags` (all objectives whose map
  contains the fact) and in config. `InterviewSession.queue` stays a plain string array — no schema
  change; back-compat total.
- **`answer_type` drives server-side conversion** in `recordAnswer()` (template lookup by fact key):
  `money_dollars` → cents-string; `integer`/`year` → digit-string validation; `choice` → must match
  a template choice value. `AnswerOptimizationQuestionRequest::rules()` stays
  `answer: required|string|max:500` (unchanged); the orchestrator does typed validation against the
  template and 422s on mismatch.

## A.4 GAP → QUESTION mechanics

### A.4.1 `ObjectiveReadinessService::enqueueGaps()` (the ONLY gap-enqueue path)

**[Merge fix M6: Part 2's proposed `InterviewOrchestratorService::queueFactKeys()` is DROPPED —
this method is the single mechanism, and its front-insert semantics win.]**

```
enqueueGaps(User $user, int $taxYear, string $objective): array
  1. $session = orchestrator->startOrResume($userId, $taxYear)          // shipped, idempotent
  2. $missing = readiness(...)[$objective]['blocking_missing']
                (+ 'confirm_needed' suggested-confirms, §A.5)
  3. $keys = fact keys with templates, minus ($session->queue ∪ $session->asked ∪ answered facts)
  4. FRONT-INSERT: $session->update(['queue' => array_values(array_unique(
         array_merge($keys, $session->queue ?? [])))])
     — gap keys go BEFORE pending finding keys so "answer 2 more questions" is immediate.
     Battery questions remain last by construction (they were already at the tail).
  5. return ['enqueued' => $keys, 'queue_size' => count($session->fresh()->queue)]
```

- Gap enqueues are **not** subject to `interview.initial_cap` (that cap governs the auto-finding
  seed in `buildInitialQueue()` only; the user explicitly requested these questions).
- Multi-part asks (match formula = `employer.match_pct` + `employer.match_threshold_pct`) enqueue
  as consecutive keys; the second is prerequisite-gated on the first so ordering survives skips.
- Re-entrancy safe: answered keys are filtered by the shipped `isAlreadyAnswered()` at pop time
  even if a race sneaks one into the queue.

### A.4.3 Prerequisite gating (extends INT-04)

`GATED_PROBES` is a private const today. Additive change: the orchestrator merges the const with
`config('optimization-objectives.question_templates.*.prerequisite')` pairs at construction
(`array_merge` — const entries win on collision). New gates:

| Gated key | Prerequisite |
|---|---|
| `hsa.coverage_type` | `health.hsa_eligible` |
| `employer.match_pct` | `employer.has_401k` |
| `employer.match_threshold_pct` | `employer.match_pct` |
| `employer.contribution_pct` | `employer.has_401k` |

Semantics note: the shipped `isPrerequisiteUnsatisfied()` drops the key when the prerequisite is
unmet. For gap flows that's wrong for `employer.has_401k`='no' (dependent keys should be resolved
as not-applicable, not re-asked forever). Additive rule in the READINESS computation (orchestrator
untouched): when a prerequisite fact is answered `'no'`, dependent blocking facts flip to state
`not_applicable` and count as resolved.

## A.5 Accelerators & suggested-confirm

### A.5.3 Interview answers (unchanged write path, typed conversion added)

`InterviewOrchestratorService::recordAnswer()` remains the single write path. Additive behavior:
before `recordFact()`, look up the template for `$factKey`; if `answer_type='money_dollars'`,
convert to cents-string; set `volatility` and `taxYear` from the template (`tax_year_scoped: true`
→ session's tax year, else null — replacing today's hardcoded `'stable'`/`null` ONLY when a
template exists; non-template keys keep exact current behavior). Label from template. Transcript
append unchanged (encrypted `assertions`).

### A.5.4 Doc-extraction accelerators (additive `PaystubFactExtractorService` maps)

- NEW `RETIREMENT_STATEMENT_FACT_MAP` (same shape as `PAYSTUB_FACT_MAP`):
  `account_balance` → `retirement.statement_balance_cents`;
  `ytd_contributions` → `retirement.statement_ytd_contributions_cents` (cross-check fact only —
  statement contributions are ambiguous across account types; never the canonical 401(k) YTD).
- `PAYSTUB_FACT_MAP` additive entries: `federal_tax_withheld` →
  `pay.federal_withholding_per_period_cents`, `gross_pay` → `pay.gross_per_period_cents`
  (money, annual, tax_year-scoped).
- **[Merge fix M7]** Pay-frequency derivation lives in the RESOLVER (§A.6.3), NOT the extractor —
  it needs cross-field date math, not a 1:1 field map. (Part 2's "propose frequency via the
  extractor" is dropped.)
- All extractor writes remain **proposals** (`source_type='document_extraction'`, D4 confirm gate).
  Nothing about the proposal/confirm pipeline changes.

### A.5.5 Suggested-confirm for known-but-unconfirmed values

When the resolver finds a value from the **snapshot** or a **profile field** (math-capable but not
user-confirmed — §A.7 two-tier model), the gap queue can include a one-tap confirm question:

- Created via the template branch with `band='auto'`, `ai_confidence=1.0` (shipped `BAND_CONFIDENCE`
  pattern) and `options.prefill_source = 'snapshot:w2_wages'` — a POINTER, never the value
  (`AIQuestion.options` is unencrypted JSON; dollar values must not persist there).
- `InterviewController::next()` resolves the pointer at read time and adds transient response
  fields `prefill_display` (formatted, e.g. `"$72,500"`) and `prefill_value` (raw string) —
  computed per-request over the authed API, never stored.
- Answering `confirm` makes the orchestrator resolve the pointer server-side at answer time and
  `recordFact()` the resolved value with `source_type='interview_answer'` (user-confirmed
  provenance). A replacement value records that instead. Either way the fact graduates to `confirmed`.

## A.6 Resolution engine — `App\Services\ScenarioFactResolverService` (NEW)

### A.6.1 Placement: new read-side service, not an assembler extension

The assembler is a write-side snapshot builder (pure DB aggregation into
`IncomeOptimizationProfile` rows on job cadence). Resolution/readiness is a read-side, query-time
join across snapshot + facts + profile that must never mutate the snapshot and must reflect a fact
confirmed seconds ago. The resolver *consumes* the assembler's product and mirrors its conventions
(cents-as-string, `normaliseFilingStatus`, `dollarsToCents`).

### A.6.2 Public API

```php
final class ScenarioFactResolverService
{
    /** Resolve every fact in the union of all objective requirement maps. */
    public function resolveAll(User $user, int $taxYear): array;          // fact_key => ResolvedFact|null

    /** Resolve one canonical fact through its source chain (incl. aliases + derivations). */
    public function resolve(User $user, int $taxYear, string $canonicalKey): ?array;

    /** Freeze the current resolution into a versioned, citable ScenarioFactSet row (§A.8.2). */
    public function snapshotFactSet(User $user, int $taxYear): ScenarioFactSet;

    /** Staleness check mirroring IncomeOptimizerDataAssemblerService::isStale(). */
    public function isStale(ScenarioFactSet $set): bool;
}
```

`ResolvedFact` shape (plain array; encrypted only when persisted in a fact set):

```php
[
    'fact_key'   => 'employer.federal_withholding',
    'value'      => '842000',                    // cents-string / enum / yes-no / digit-string
    'value_type' => 'money_cents',
    'source'     => 'derived',                   // snapshot | fact | profile | derived
    'source_ref' => 'derived:annualize_ytd_federal_tax(doc:512)',
    'confirmed'  => false,                       // §A.7 two-tier flag
    'resolved_at'=> '2026-07-02T14:03:11Z',
]
```

`source_ref` grammar: `fact:{id}` · `snapshot:{profile_id}:{column}` · `profile:{profile_id}:{field}`
· `derived:{rule}({inputs})`. This is what scenarios cite (D10.6 "grounded in user-confirmed
facts") and what D9.2 fact-gating reads.

### A.6.3 Derivation rules (deterministic, config-parameterized)

| Rule id | Computation | Inputs |
|---|---|---|
| `annualize_ytd_gross` | `ytd_gross ÷ elapsed_year_fraction(pay_date)` | latest Ready PayStub doc `fields.ytd_gross.value`, `fields.pay_date.value` |
| `annualize_ytd_federal_tax` | same, over `ytd_federal_tax` | latest Ready PayStub |
| `per_period_times_frequency` | `per_period_cents × periods_per_year[pay.frequency]` | resolved `pay.*` facts |
| `frequency_from_paystub` | pay-period span days → 6–8: weekly · 12–15: biweekly · 15–16 w/ 1st/15th anchors: semimonthly · 27–32: monthly; two+ stubs: median gap between `pay_date`s wins; ambiguous spans (13–16 days without anchors) → `null` → falls through to ask | PayStub `pay_period_start/end`, `pay_date` |
| `age_from_birth_year` | `tax_year − birth_year` | `person.birth_year` |
| `spouse_annual_from_profile` | `monthly spouse_income × 12` | `UserFinancialProfile.spouse_income` |
| `contribution_pct_from_ytd` | `401k_ytd ÷ ytd_gross` | resolved facts |

All derivations produce `source='derived'`, `confirmed=false`, and record input refs. Dollar math
here is plain deterministic arithmetic (assembler-style); anything touching brackets/limits stays
in `TaxRulesEngineService`.

### A.6.4 What the resolver never does / assembler touch

- Never calls Claude. Never writes `UserTaxFact` rows. Never returns decrypted values through any
  API endpoint (§E responses are keys/labels/states only).
- Additive assembler touch: `buildProfile()` backfills `estimated_age` from
  `UserTaxFact::currentFact($userId, 'person.birth_year')` when present (`taxYear − birth_year`).
  No column/API change; code ignoring the column keeps ignoring it.

## A.7 Two-tier resolution: `known` vs `confirmed`

- **`known`** — resolvable from snapshot / unconfirmed doc-sum / profile / derivation. Sufficient
  for **scenario math** (detectors already compute on snapshot values; same standard).
- **`confirmed`** — traced to `fact:` provenance with user assent (`interview_answer`, `user_edit`,
  or confirmed `document_extraction`). Required for **D9.2 fact-gated directives**: a checklist
  step renders as an imperative ONLY when every fact it anchors to is `confirmed`; otherwise the
  step renders as the confirmation ask.

Objective readiness gates on `known`. Directive rendering gates on `confirmed`. The readiness API
exposes both so the UI can show "2 to answer · 3 to confirm".

**No duplication into the facts store**: profile fields and snapshot columns are NOT copied into
`UserTaxFact` during resolution. A value enters the store only through interview answer (incl.
suggested-confirm), user edit (`DurableFactsController::supersede`), or confirmed doc proposal.
This keeps `answerableFields()` semantics intact and avoids circular provenance.

## A.8 Readiness model & versioned fact sets

### A.8.1 `App\Services\ObjectiveReadinessService` (NEW, thin — consumes the resolver)

```php
final class ObjectiveReadinessService
{
    public function __construct(
        private readonly ScenarioFactResolverService $resolver,
        private readonly InterviewOrchestratorService $orchestrator,
    ) {}

    /** Per-objective readiness DTO for the UI + the scenario gate. THE single readiness source. */
    public function readiness(User $user, int $taxYear): array;

    /** §A.4.1 — front-inserts gap questions for one objective into the interview session. */
    public function enqueueGaps(User $user, int $taxYear, string $objective): array;
}
```

**[Merge fix M5]** This service is the ONLY readiness computer. Part 2's separate
`optimizer-scenarios.readiness` config block and its looser fact sets ("at least one of {401k,
HSA, IRA}") are DELETED — `ScenarioController` consumes `readiness()` output, so the fact map in
`config/optimization-objectives.php` is the single source of truth.

Per-objective computation over the §A.2 map + `resolveAll()`:

- `blocking_missing` — blocking facts with no resolution (and not `not_applicable` per §A.4.3).
- `confirm_needed` — blocking facts resolved but `confirmed=false` (drives suggested-confirms;
  does NOT block readiness).
- `optional_missing` — unresolved optional facts (each with its documented default).
- `ready` = `count(blocking_missing) === 0`.
- `questions_to_unlock` = count of distinct question templates covering `blocking_missing`
  (multi-part asks count each part).
- `completeness_pct` = `round(100 × (2·resolved_blocking + resolved_optional) ÷ (2·total_blocking + total_optional))`
  (blocking double-weighted; `not_applicable` counts as resolved). Purely presentational.

**No caching** in v1 (a handful of indexed lookups; readiness must reflect an answer given one
second ago — the interview loop re-fetches per answer). If profiling demands it later: 60s keyed
`objective-readiness:{user}:{year}`, invalidated where `MarkOptimizationReportStale` already hooks.

### A.8.2 Versioned fact set — `ScenarioFactSet` model + migration (NEW, additive)

Scenario results must cite exactly the facts they used (D10.6, D9.2). Pattern mirrors the
assembler's `profile_hash`.

```php
// database/migrations/2026_07_XX_XXXXXX_create_scenario_fact_sets_table.php  (forward-only, additive)
Schema::create('scenario_fact_sets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // GDPR cascade
    $table->integer('tax_year');
    $table->string('fact_set_hash', 64);      // HMAC-SHA256, see below
    $table->text('resolved_facts');           // encrypted JSON: fact_key => ResolvedFact
    $table->timestamps();
    $table->index(['user_id', 'tax_year']);
});
```

Model: `resolved_facts` cast `'encrypted'` (TEXT + manual json_encode/decode — the
`InterviewSession.assertions` precedent, avoiding `encrypted:array` double-encode), `$hidden` on
`resolved_facts`, `scopeForUser()`.

- `fact_set_hash = hash_hmac('sha256', canonical_json(fact_key => [source_ref, value]), config('app.key'))`.
  HMAC (not bare SHA-256): money values have a small search space — a bare hash of `'842000'`-style
  inputs is brute-forceable from the unencrypted hash column.
- `isStale($set)`: recompute current hash, compare — same contract as the assembler's `isStale()`.
- **[Merge fix M9 — citation linkage]** `snapshotFactSet()` is called at CHOOSE time (§D.6): the
  new fact set's `id` is stored in the `scenario.chosen_knobs` fact's `metadata` (non-PII integer)
  and in every materialized checklist row (`fact_set_id` column, §D.5). The scenario GET response
  carries a `fact_set` citation summary (id, fact count, labels + source types — never values).
  Fact supersession flips `isStale()` → dependent scenario/checklist surfaces show the
  "facts changed — recompute" affordance and the report-stale listener fires.
- Citation surface: report/checklist render "Based on N facts you provided" with fact labels +
  source types (all non-hidden on `UserTaxFact`) — never values.

---

# PART B — Scenario engine (Decision 10.2/10.3 + D9.7 benefit math)

## B.1 THE KNOB SET

Six knobs. A **knob vector** is the canonical input to scenario computation:

```php
// Canonical knob vector (all keys always present; engine clamps a COPY, never trusts input)
[
    'w4' => [
        'filing_status'       => 'married_joint',  // W-4 Step 1(c): 'single_or_mfs'|'married_joint'|'head_of_household'
        'dependents_under_17' => 3,                // W-4 Step 3 qualifying children
        'other_dependents'    => 0,                // W-4 Step 3 other dependents
    ],
    'k401' => [
        'deferral_pct'   => 10.0,   // % of gross, employee deferral (trad + Roth combined)
        'roth_share_pct' => 50,     // % of deferral that is Roth; grid {0,25,50,75,100}
    ],
    'hsa' => [
        'annual_election_cents' => 440000,  // total year election (payroll §125 assumed)
    ],
    'ira' => [
        'traditional_cents' => 400000,      // additional-this-year, on top of YTD
        'roth_cents'        => 350000,
    ],
    'transfer' => [
        'per_period_cents' => 50000,        // automatic bank transfer per pay period
    ],
]
```

**K1 — W-4 alignment (filing status + dependents).**
Never searched by solvers: in every scenario K1 is "align the W-4 with the user's *confirmed*
facts" (D9.4: operationalize what the user confirmed; never assert an election in the abstract).
W-4 Step 1(c) has three boxes; the user's `married_separate` confirmed status maps to
`single_or_mfs`, computed with the `single` withholding tables — this mirrors the Pub 15-T
"Single or Married filing separately" column convention. **[Merge fix M11: Part 2 claimed the
config single/MFS tables are identical — they are NOT at the top (37% starts at $640,600 single vs
$384,350 MFS). The mapping stands because SCN-01 approximates the Pub 15-T W-4 column, which uses
the single-status parameters; the divergence is documented in code and immaterial to withholding
estimates at the wage levels where this feature operates. Annual-tax math (Step 3) always uses the
true confirmed status with its own bracket table, so MFS filers are computed correctly there.]**
- Data: `profile.filing_status` (confirmed fact → snapshot fallback), `w4.filing_status` +
  `w4.dependents_claimed` (W-4-on-file evidence — distinct facts, §A.1.3), `family.dependents_count`,
  `family.qualifying_children_under_17`, gross + pay frequency (§B.2).
- Constraints (config): brackets + `standard_deduction` per W-4 status; `detection.ctc_amount`
  (2,200) per qualifying child; **NEW config** `detection.odc_amount` (500, [CITED: IRC §24(h)(4)])
  per other dependent. K1 changes withholding/take-home only — never computed annual tax (the
  engine reports both so the UI can say "this moves money from your refund into paychecks").
- Fact gate: the directive ("Contact your payroll department and change your W-4 filing status
  from head of household to married filing jointly") renders only when `profile.filing_status` is
  confirmed AND (`w4.filing_status` known-divergent OR `w4.dependents_claimed` known-divergent).
  Otherwise the step is "Confirm what filing status and dependents your current W-4 claims — check
  your latest paystub or payroll portal" (once answered, the directive unlocks).

**K2 — Traditional-vs-Roth 401(k) split.**
- Domain: `roth_share_pct` ∈ config grid `{0, 25, 50, 75, 100}`.
- Data: `employer.has_401k`, `retirement.traditional_401k_ytd_cents`,
  `retirement.roth_401k_ytd_cents` (facts via aliases; snapshot columns fallback), marginal rate
  (engine, baseline taxable), age (resolver `age_from_birth_year` → snapshot `estimated_age`
  **[M10]**), prior-year FICA wages (`prior_year.agi_cents` as proxy; ask when absent),
  `marketplace.pays_marketplace_premiums`.
- Constraints:
  - Combined trad+Roth deferral ≤ `401k.employee_deferral` + catch-up (`catchup_age_50_plus` for
    50–59/64+, `catchup_age_60_to_63` for 60–63) via `remaining401kRoomCents()` semantics.
  - `requiresMandatoryRothCatchup()` (SECURE 2.0 §603): when true, the catch-up *portion* is forced
    Roth regardless of the knob; engine flags `guards.mandatory_roth_catchup_applied`.
  - `rothVsTraditionalBand()` is the solver *prior* (§B.7), never a hard constraint.
  - **ACA CLIFF GUARD (hard, §B.5)**: for marketplace enrollees near the cliff, Roth share is
    forced down (Roth → Traditional) until projected MAGI clears the cliff buffer — inside
    `computeScenarioOutcome()`, so no emitted scenario can push a marketplace enrollee over the
    cliff via Roth allocation. Cliff-before-Roth made arithmetic.

**K3 — 401(k) contribution level vs match formula.**
- Domain: `deferral_pct` ∈ [floor, ceil] in 1-point steps; floor = current pct (never propose a
  reduction) unless `finance.is_cash_constrained`='yes', in which case floor =
  `employer.match_threshold_pct` (never below full-match capture); ceil = pct exhausting the
  full-year 401(k) limit.
- Data: `employer.match_pct`, `employer.match_threshold_pct`, `employer.contribution_pct` (the
  `EmployerMatchGapDetector` trio), `employer.match_formula` (display text), gross,
  401(k) YTD facts.
- Constraints: `remaining401kRoomCents`; §415(c) awareness only (`detection.section_415c_limit`) —
  never modeled, one glossary line when after-tax facts exist. Match capture math §B.3 SCN-03.
  "If your plan allows" framing mandatory in all K3 copy (FLAG-04 rail).

**K4 — HSA / FSA election.**
- Domain: HSA `annual_election_cents` ∈ [current YTD, coverage limit]. FSA is **awareness-only** in
  v2.1 (no FSA limit exists in `config/tax-rules.php`; a real FSA constant is P13-gated — until
  sign-off the FSA knob renders as an educational note, never a computed dimension).
- Data: `employer.hdhp_hsa_available`, `health.hsa_eligible`, `employer.hsa_enrolled`,
  `hsa.ytd_contribution_cents` (aliases; snapshot `hsa_ytd`), `hsa.coverage_type` **[M2]**,
  age (55+ catch-up), `medicare.enrollment_date`.
- Constraints: `remainingHsaRoomCents(ytd, coverageType, age)`; HDHP eligibility fact-gated (no
  confirmed HDHP fact → the HSA step is a confirm-ask, not a directive); **Medicare lookback hard
  stop**: if `medicare.enrollment_date` within `detection.medicare_hsa_lookback_months` (6) of now
  (or age 65+ with enrollment unknown), the HSA knob pins to current YTD + caution line (IRS Notice
  2004-2 Q&A 28 rail).
- Take-home nuance the engine MUST model: payroll HSA is §125 cafeteria money — exempt from income
  tax AND employee FICA. 401(k) deferrals reduce income-tax wages only (still FICA-taxed). §B.4.

**K5 — IRA type/amount within the shared limit.**
- Domain: `(traditional_cents, roth_cents)` on a quarter-grid of remaining shared room:
  each ∈ {0, ¼, ½, ¾, 1} × remainingRoom, with trad + roth ≤ remainingRoom.
- Data: `ira.traditional_ytd_contribution_cents` + `ira.roth_ytd_contribution_cents` (COMBINED YTD
  → `remainingIraRoomCents()` per the D2 shared-limit rule), MAGI (`profile.estimated_magi_cents`
  fact, else SCN-05 projection), filing status, covered-by-plan (true when any 401k YTD > 0 or
  `employer.has_401k` confirmed), `spouse.covered_by_retirement_plan` (default `'no'` **[M13]**),
  `retirement.has_ira_balance` + `ira.balance_range` (pro-rata awareness),
  `ira.backdoor_roth_eligible` (stays interview-gated; never a knob).
- Constraints: `remainingIraRoomCents(combinedYtd, age)`; `rothIraEligibility(magi, status)` caps
  `roth_cents` at the phased-out limit (0 when fully phased out — backdoor Roth is NOT a scenario
  knob; specialist-band finding); `traditionalIraDeductibility(magi, status, covered, spouseCovered)`
  caps the deduction AND the proposed `traditional_cents` at the deductible amount
  (nondeductible-basis tracking is specialist territory); ACA guard prefers `traditional_cents`
  for marketplace enrollees (MAGI reducer).

**K6 — Auto-transfer to savings.**
- Domain: `per_period_cents` ∈ [0, cap], cap = `scenario_assumptions.auto_transfer_max_surplus_share`
  × monthly surplus converted to per-period. Monthly surplus comes from the existing dashboard
  budget-waterfall math (`DashboardController` figures) — reuse, never re-derive.
- Constraints: pure annualization, zero tax math. RETIREMENT objective: K6 sized to fund the K5
  IRA amounts per period (IRA contributions are bank transfers — the transfer IS the mechanism).
  TAKE_HOME objective: K6 routes `income_objective_transfer_share` (default 0.50) of the
  per-paycheck take-home gain ("set up direct deposit to savings of $X every 2 weeks" — the
  owner's D9 verbatim). Benefit line = per_period × periods/yr, exact arithmetic.

## B.2 Common baseline inputs — `ScenarioSolverService::assembleBaseline()`

**[Merge fix M15]** The baseline is built FROM `ScenarioFactResolverService::resolveAll()` output
(not ad-hoc fact reads) so provenance, aliases, chain order, and the citation fact set stay
consistent between readiness, computation, and checklist gating.

```php
[
    'annual_gross_cents'        => int,      // C2 chain
    'se_income_cents'           => int,      // snapshot self_employment_income
    'pay_periods_per_year'      => int,      // pay.frequency → optimization-objectives map [M1][M8]
    'filing_status'             => string,   // CONFIRMED status (C1) — annual-tax math input
    'w4_on_file'                => ['filing_status' => ?string, 'dependents_claimed' => ?int], // T4
    'family'                    => ['dependents_under_17' => int, 'other_dependents' => int],
    'age'                       => ?int,     // derive:age_from_birth_year → snapshot estimated_age [M10]
    'target_retirement_age'     => int,      // R2 fact or config default (assumption stated)
    'prior_year_fica_wages_cents' => ?int,   // prior_year.agi_cents proxy
    'current' => [                            // annualized run-rates from YTD facts:
        'trad_401k_cents' => int, 'roth_401k_cents' => int,
        'hsa_cents' => int, 'ira_trad_ytd_cents' => int, 'ira_roth_ytd_cents' => int,
        'deferral_pct' => ?float,             // employer.contribution_pct or derived
    ],
    'employer' => ['match_pct' => ?float, 'match_threshold_pct' => ?float, 'has_401k' => bool],
    'hsa_coverage_type'         => ?string,
    'medicare_enrollment_date'  => ?string,
    'is_marketplace_enrollee'   => bool,      // marketplace.pays_marketplace_premiums (AcaCliffMonitor gate)
    'is_cash_constrained'       => bool,
    'spouse_covered_by_plan'    => bool,      // [M13] default false
    'monthly_surplus_cents'     => int,       // dashboard waterfall figure
    'annual_withholding_cents'  => ?int,      // employer.federal_withholding
    'fact_set_hash'             => string,    // resolver hash for cache keying + choose-time snapshot
]
```

YTD annualization: `annual = ytd / elapsed_pay_periods × periods` when pay dates known; fallback
`ytd / months_elapsed × 12`. Deterministic; documented in code; happens in `assembleBaseline()`
(never inside `solve()` — no `now()`-dependent branching in solvers).

## B.3 New engine methods (all in `TaxRulesEngineService`; pure, cents, config, zero HTTP)

```php
// ── SCN-01: Per-paycheck federal withholding approximation ──────────────────
// Pub 15-T percentage-method shape, standard schedule (W-4 Step 2 unchecked, no extra
// withholding beyond the explicit param handled by callers). DECISION-SUPPORT ESTIMATE,
// labeled as such in UI copy.
public function estimatePeriodWithholdingCents(
    int $periodGrossCents,
    int $periodPreTaxCents,        // trad 401k + HSA + §125 premiums (federal-wage reducers)
    string $w4FilingStatus,        // 'single_or_mfs' | 'married_joint' | 'head_of_household'
    int $dependentsUnder17,
    int $otherDependents,
    int $payPeriodsPerYear,
    int $year = 2026,
): int
// 1. Map single_or_mfs → 'single' tables (Pub 15-T column convention, see K1 [M11]); validate.
// 2. annualWages = (periodGross − periodPreTax) × periods.
// 3. adjusted = max(0, annualWages − standardDeductionCents(status)).
// 4. tentative = computeBracketTax(adjusted, brackets[status])   // reuse private helper
// 5. credits = deps17 × detection.ctc_amount × 100 + depsOther × detection.odc_amount × 100.
// 6. return max(0, (int) round((tentative − credits) / periods)).

// ── SCN-02: Employee-share FICA (HSA §125 is FICA-exempt; 401k is NOT) ───────
// se_tax.ss_rate/2 and se_tax.medicare_rate/2 with ss_wage_base. Additional Medicare
// surtax intentionally excluded from per-paycheck estimates (documented assumption;
// high earners get a caution line, never silent wrongness).
/** @return array{social_security_cents:int, medicare_cents:int, total_cents:int} */
public function employeeFicaCents(int $annualFicaWagesCents, int $year = 2026): array

// ── SCN-03: Employer match capture ────────────────────────────────────────────
// match = gross × min(contribPct, thresholdPct) × matchPct   (pcts as 0–100 floats)
public function matchCaptureCents(
    int $annualGrossCents, float $contributionPct, float $matchPct, float $matchThresholdPct,
): int

// ── SCN-04: Long-horizon illustration (D9.7: config rates, RANGE output) ──────
// Annuity FV = P × ((1+g)^n − 1) / g at growth_low and growth_high. NEVER a single point.
// 'assumptions' strings feed the UI tooltip + report verbatim.
/** @return array{low_cents:int, high_cents:int, horizon_years:int,
 *                growth_rate_low:float, growth_rate_high:float, assumptions:string[]} */
public function futureValueRangeCents(int $annualContribCents, int $horizonYears, int $year = 2026): array
// Reads optimizer-scenarios.assumptions.illustrative_growth_rate_low/high.
// Zero/negative horizon → zeros with horizon_years=0 (UI omits the illustration block).

// ── SCN-05: Projected MAGI under a knob vector (educational approximation) ────
// MAGI ≈ gross + SE income − trad401k − HSA − deductible trad IRA (partial-limit-capped).
public function projectedMagiCents(array $baseline, array $knobs, int $year = 2026): int

// ── SCN-06: ACA cliff headroom ────────────────────────────────────────────────
// Threshold selection mirrors AcaCliffMonitor: married_joint → family4 threshold, else
// single ([ASSUMED] household granularity — same P13 caveat as the config keys).
// Positive = MAGI room below the cliff; negative = over.
public function acaCliffHeadroomCents(int $projectedMagiCents, string $filingStatus, int $year = 2026): int

// ── SCN-07: The core — one knob vector → three outcome metrics ────────────────
/** @param array $baseline §B.2   @param array $knobs §B.1 (engine clamps a COPY)
 *  @return array §B.6 Outcome shape — includes the clamped/guarded vector */
public function computeScenarioOutcome(array $baseline, array $knobs, int $year = 2026): array
```

## B.4 `computeScenarioOutcome()` algorithm

**Step 1 — Clamp (ordered; every clamp appends to `guards.clamps[]`):**
1. `k401`: `deferralCents = gross × deferral_pct`; clamp so `deferralCents` ≤ the FULL-YEAR limit
   incl. catch-ups (the knob expresses the full-year election; YTD only annualizes the baseline).
2. Mandatory Roth catch-up: if `prior_year_fica_wages_cents` known and
   `requiresMandatoryRothCatchup()` true and deferral exceeds the base `employee_deferral` limit,
   the excess is forced Roth (added to the Roth side after the split); flag
   `mandatory_roth_catchup_applied`.
3. `hsa`: clamp to coverage-type limit + 55+ catch-up (full-year equivalent). Medicare lookback:
   enrollment within `detection.medicare_hsa_lookback_months` or (age ≥ 65, enrollment unknown) →
   pin to `current.hsa_cents`, flag `medicare_hsa_guard`.
4. `ira`: combined additional ≤ `remainingIraRoomCents(ira_trad_ytd + ira_roth_ytd, age)` (COMBINED
   YTD — the D2 shared-limit rule). `roth_cents` further capped by
   `rothIraEligibility(magi, status)['limit_cents']` minus Roth YTD. `traditional_cents` capped at
   the `traditionalIraDeductibility(...)` deductible amount.
5. `transfer`: clamp to the surplus cap (K6).

**Step 2 — ACA cliff guard (AFTER limits, BEFORE tax math — cliff-before-Roth):**
```
if baseline.is_marketplace_enrollee:
    magi   = projectedMagiCents(baseline, knobs)
    buffer = optimizer-scenarios.assumptions.aca_cliff_buffer_cents
    need   = buffer − acaCliffHeadroomCents(magi, filing_status)
    if need > 0:
        # (a) Roth 401k share → Traditional (dollar-for-dollar MAGI reducer)
        shift = min(need, roth_401k_annual_cents); move to trad; need -= shift
        # (b) Roth IRA → Traditional IRA, up to the deductible partial limit
        shift = min(need, ira.roth_cents, deductible_headroom); move; need -= shift
        # (c) still short → flag only; solvers MAY raise trad-401k/HSA within the take-home
        #     floor (§B.7); the scenario card carries the ACA caution line and links the
        #     shipped aca_magi_management finding. NEVER compute subsidy/clawback $ (FLAG-22).
        guards.aca_cliff_applied = true (with aca_need_remaining_cents when (c))
```

**Step 3 — Annual federal tax (baseline vs scenario):**
```
pretax(v)  = trad401k(v) + hsa(v) + deductible_trad_ira(v)
taxable(v) = max(0, gross + se_income − pretax(v) − standardDeductionCents(confirmed_status, age))
tax(v)     = computeTax(taxable(v), confirmed_status)
federal_tax.annual_delta_cents = tax(scenario) − tax(current)     # negative = savings
```
Uses the CONFIRMED filing status (`profile.filing_status`) — annual tax is what the return will
say; the W-4 only shapes withholding. SE tax is invariant across these knobs (no solo-401k knob in
v1) so it cancels in deltas; documented assumption.

**Step 4 — Per-paycheck take-home (baseline vs scenario):**
```
periodGross       = gross / periods
periodPreTaxWH(v) = (trad401k(v) + hsa(v)) / periods              # withholding-wage reducers
withholding(v)    = estimatePeriodWithholdingCents(periodGross, periodPreTaxWH(v),
                                                   w4_status(v), deps17(v), depsOther(v), periods)
fica(v)           = employeeFicaCents(gross − hsa(v)).total / periods   # HSA §125 FICA-exempt
takeHome(v)       = periodGross − (trad401k(v)+roth401k(v)+hsa(v))/periods − withholding(v) − fica(v)
take_home.per_paycheck_delta_cents = takeHome(scenario) − takeHome(current)
take_home.annual_delta_cents       = per_paycheck_delta × periods
```
Baseline uses `w4_on_file` when known (K1 alignment shows a real delta). When the W-4 on file is
unknown, baseline withholding uses `annual_withholding_cents / periods` (observed) and the K1
delta is suppressed (`w4_delta_included=false`) until the fact is confirmed (D9.2 + [M12]).
K6 transfers are reported separately ("moved to savings"), never subtracted as "lost" take-home.
Safe-harbor guardrail (T8): when `prior_year.federal_liability_cents` is absent, no scenario may
propose withholding below current-year computed tax (conservative floor; guard chip shown).

**Step 5 — Retirement:**
```
annual_contributions_delta = Δ(trad401k + roth401k + ira_trad + ira_roth)   # vs current run-rate
employer_match_delta       = matchCaptureCents(gross, deferral_pct(scn), match_pct, threshold)
                             − matchCaptureCents(gross, deferral_pct(cur), ...)
illustration               = futureValueRangeCents(annual_contributions_delta + employer_match_delta,
                                                   target_retirement_age − age)
```
HSA increases are their own line ("+$X/yr into your HSA") — never folded into the retirement FV
(conservative; HSA-as-retirement is a glossary note). Roth/Trad after-tax equivalence is
deliberately NOT modeled (specialist territory); fixed template line on the card: "Roth dollars are
contributed after tax; traditional dollars are taxed later" (no Claude).

## B.5 The ACA sequencing invariant (testable)

> For any baseline with `is_marketplace_enrollee = true`, any outcome emitted by
> `computeScenarioOutcome()` satisfies: `projectedMagiCents(baseline, outcome.knobs)` ≤
> cliff − buffer, OR `guards.aca_cliff_applied = true` with `aca_need_remaining_cents > 0` and the
> Roth knobs at 0. Pest property test over randomized baselines (§T).

## B.6 Outcome shape (returned by SCN-07, serialized by the API)

```php
[
    'knobs'   => [...],                      // clamped + guarded vector actually computed
    'take_home' => ['per_paycheck_delta_cents' => int, 'annual_delta_cents' => int,
                    'w4_delta_included' => bool],
    'federal_tax' => ['annual_delta_cents' => int],
    'retirement' => [
        'annual_contributions_delta_cents' => int,
        'employer_match_delta_cents'       => int,
        'hsa_annual_delta_cents'           => int,
        'illustration' => null | [          // null when horizon/age unknown
            'low_cents' => int, 'high_cents' => int, 'horizon_years' => int,
            'growth_rate_low' => float, 'growth_rate_high' => float,
            'assumptions' => string[],       // rendered verbatim in tooltip + report
        ],
    ],
    'guards' => [
        'aca_cliff_applied' => bool, 'aca_need_remaining_cents' => int,
        'mandatory_roth_catchup_applied' => bool, 'medicare_hsa_guard' => bool,
        'safe_harbor_floor_applied' => bool,
        'clamps' => string[],                // e.g. '401k_annual_limit', 'roth_ira_phaseout'
    ],
]
```

## B.7 Objective solvers (deterministic heuristics — no LLM, no randomness)

`ScenarioSolverService::solve(array $baseline, string $objective): array` returns a knob vector.
Objective ids: `take_home` · `tax_burden` · `retirement` **[M4]**. Common: K1 =
align-to-confirmed-facts; every candidate evaluated through SCN-07 (clamps/guards apply);
take-home floor guard `takeHome(candidate) ≥ min_take_home_ratio × takeHome(current)` with
`min_take_home_ratio` 0.90 (config), tightened to 0.97 when `finance.is_cash_constrained`.

**TAKE_HOME (maximize per-paycheck take-home now):**
1. K3 deferral_pct = max(current_pct, match_threshold_pct) — never leave match money (dominant
   free-return rule); if cash-constrained and current > threshold, propose threshold as the floor
   option but never auto-select below current (both shown in the knob detail).
2. K2 roth_share = 0 (traditional lowers withholding → raises take-home), subject to
   mandatory-Roth-catch-up clamp.
3. K4 HSA = current. K5 IRA additional = 0.
4. K6 = `income_objective_transfer_share` × per-paycheck take-home gain (routes the gain to
   savings — the owner's "direct deposit to savings of $500 every 2 weeks" pattern).

**TAX_BURDEN (minimize current-year federal tax):** greedy fill in config priority order
`tax_objective_priority = [match_capture, hsa, trad_401k, trad_ira]`:
1. K3 to match threshold (as TAKE_HOME).
2. K4 HSA → largest election on the `hsa_grid_step_cents` ($250) grid keeping the take-home floor
   satisfied (HSA first: income-tax + FICA exempt).
3. K3 continue: raise deferral_pct in 1-pt steps (roth_share = 0) while the floor holds and 401k
   room remains.
4. K5 traditional IRA → largest quarter-grid amount within the deductible partial limit while the
   floor holds. Roth IRA = 0.
5. K6 = 0. ACA guard naturally satisfied (all-traditional is MAGI-minimizing).

**RETIREMENT (maximize retirement delta):**
1. K3: raise deferral_pct to the max the floor allows (up to annual-limit pct).
2. K2: roth_share from `rothVsTraditionalBand(marginalRate(taxable(current), status))`:
   'roth' → 100, 'split' → 50, 'traditional' → 0; ACA guard may force down.
3. K4 HSA → fill room within the floor (after K3, same grid).
4. K5: fill remaining shared IRA room (quarter grid); type split by the same band + Roth
   phase-out cap.
5. K6 sized to fund the K5 amounts per pay period (the transfer is the execution mechanism).

**BALANCED (synthesis, §C):** per-knob midpoints between the TAKE_HOME and RETIREMENT vectors,
snapped to each knob's grid, then re-run through SCN-07 (re-clamped, re-guarded). Balanced is
always *computed*, never averaged outcomes.

Determinism: fixed grids, fixed iteration order, integer math, no `now()`-dependent branching in
`solve()` (annualization lives in `assembleBaseline()` and is part of the cached input).

## B.8 Per-knob benefit attribution (feeds Decision-9 benefit lines)

`ScenarioSolverService::attributeBenefits(array $baseline, array $chosenKnobs): array`
- For each knob dimension d: `computeScenarioOutcome(baseline, current_vector_with_only_d_changed)`;
  record its three deltas as knob d's attributed benefit. ≤ 6 extra engine calls, pure.
- Interaction remainder (chosen-total minus sum of singles) is attributed to the checklist header
  aggregate only (D9.7 header line), never to an individual step.
- Deterministic arithmetic → exact figures on checklist steps ("increases your take-home by
  ~$X/paycheck ($Y/yr)"); long-horizon figures → range framing with the "Illustration" label
  (D9.7 split honored).

---

# PART C — Conflict model (Decision 10.3/10.5)

## C.1 Knob-level divergence detection

`ScenarioSolverService::diffKnobs(array $a, array $b): array` — a dimension diverges when the
difference exceeds its epsilon (config `optimizer-scenarios.divergence`):

| Dimension | Epsilon |
|---|---|
| `k401.deferral_pct` | ≥ 1.0 pt |
| `k401.roth_share_pct` | ≥ 25 (one grid step) |
| `hsa.annual_election_cents` | ≥ 25_000 ($250) |
| `ira.traditional_cents` / `ira.roth_cents` | ≥ ¼ of remaining room or ≥ 50_000, whichever larger |
| `transfer.per_period_cents` | ≥ 2_500 ($25) |
| `w4.*` | never diverges (identical in all scenarios by construction) |

## C.2 Agree vs conflict

Compute the three objective vectors (take_home TH, tax_burden TB, retirement R).
- **AGREEMENT** — `diffKnobs(TH, R)` empty AND `diffKnobs(TH, TB)` empty: emit a SINGLE merged
  plan (`agreement = true`, one option `merged`). UI: one card + "Build my action list".
- **CONFLICT** — otherwise, exactly three options (D10.5):
  - **Option A — "More take-home now"** = TH (option key `take_home`)
  - **Option B — "More toward retirement"** = R (option key `retirement`)
  - **Balanced (default highlight)** = midpoint synthesis (§B.7), EXCEPT: when `diffKnobs(TB, TH)`
    and `diffKnobs(TB, R)` are both non-empty (the tax vector matches neither pole), Balanced is
    seeded from TB instead of the midpoint and labeled "Balanced — also lowest 2026 tax"
    (deterministic rule, documented in code: TB is inherently the middle path — max pre-tax gives
    strong current-year tax AND strong retirement).
  - The option with minimum `federal_tax.annual_delta_cents` gets the "Lowest 2026 tax" badge
    (computed, never asserted).

## C.3 Trade-off one-liners (template-based — zero Claude for figures)

Each diverging knob carries a `tradeoff` built from `optimizer-scenarios.tradeoff_templates`,
tokens filled server-side from engine outputs:

```php
'tradeoff_templates' => [
    'k401.roth_share_pct' =>
        'Option A: {a_pct}% Roth — Option B: {b_pct}% Roth. A keeps about {a_take_home}/paycheck more now; '
        .'B could mean roughly {b_fv_low}–{b_fv_high} more by age {age} under these assumptions (illustration).',
    'k401.deferral_pct' =>
        'Option A defers {a_pct}% — Option B defers {b_pct}%. The difference is about {delta_paycheck}/paycheck '
        .'now versus {delta_annual}/yr more invested (plus {match_delta}/yr employer match, if your plan allows).',
    'hsa.annual_election_cents' => '...',
    'ira.*' => '...',
    'transfer.per_period_cents' => '...',
],
```

Claude's ONLY role on this surface: `OptimizationReportNarratorService::narrateScenarioComparison()`
(new method on the existing narrator — keeps the two-sanctioned-call-sites invariant) produces the
2–3 sentence intro prose above the cards from a payload of knob names, objective ids, and guard
flags — **no cents fields, ever** (same exclusion discipline as `narrateSection()`).

---

# PART D — UX: objectives panel, comparison, pick, checklist, persistence

Design regime for ALL frontend work in this part: Decisions 6/7/12 — `frontend-design` +
`ui-ux-pro-max` + soft-skill/taste-skill/redesign-skill, DESIGN-ELEVATION-SPEC.md token extensions,
sw-* system, Preserve-brand-elevate-premium mode, blocking audits per D7.4.

## D.1 Placement in `Optimize/Index.tsx`

Fourth stage added: `type ViewMode = 'findings' | 'interview' | 'scenarios' | 'report'`.
`StageIndicator` gains a "Choices" step between Interview and Report. Flow: findings → interview →
**choices (scenarios)** → report. Interview completion ("queue exhausted") surfaces a "See your
options" CTA that jumps to the scenarios stage. The report stage renders the chosen plan section
once a choice exists (§D.7).

## D.2 Objectives readiness panel (D10.1 — the acquisition engine)

Top of the scenarios stage (and mirrored as an Objectives panel on the page): three cards computed
server-side by `ObjectiveReadinessService` **[M5 — single readiness source]**:

1. Card per objective: label, state chip — `Ready` (sw-success) · `N questions to unlock`
   (accent CTA) · `Confirm M values` (secondary chip when `ready && confirm_needed > 0`).
2. Copy pattern (owner verbatim target): *"Take-home optimization ready · answer 2 more questions
   to unlock Retirement optimization."* Rendered from `ready` + `questions_to_unlock` only — the
   frontend never computes readiness.
3. CTA tap → `POST /api/v1/optimizer/objectives/{year}/{objective}/enqueue` **[M6 — the single
   enqueue path]** → route into the existing `InterviewCard` flow (`/{interview}/next` loop). On
   each recorded answer the panel re-fetches readiness; the count visibly ticks down (2 → 1 →
   Ready).
4. All three ready → panel collapses into the scenario comparison entry point.
5. Doc affordance: templates with `doc_affordance` render an "upload a pay stub instead" secondary
   action linking the existing vault upload flow; on doc Ready + proposal confirm, the question
   auto-skips via shipped `isAlreadyAnswered()`.

## D.3 Comparison rendering

- **Agreement**: one full-width card "Your objectives point the same way" + merged plan + primary
  CTA "Build my action list".
- **Conflict**: 3-up grid (`grid md:grid-cols-3 gap-4`, stacked mobile), Balanced pre-selected
  (ring-sw-accent). Card anatomy (rounded-2xl border-sw-border bg-sw-card shadow-sm — matches
  FindingSummaryCard):
  1. Header: option label + one-line objective, badges ("Lowest 2026 tax", "Default").
  2. **Three-metric outcome block** (always all three, D10.2): Take-home Δ (per paycheck AND /yr,
     exact) · Federal tax Δ (/yr, exact, signed) · Retirement (+$/yr contributions + match; FV
     range "≈ $X–$Y by 65" with an Info tooltip listing `illustration.assumptions` verbatim and a
     persistent sw-info "Illustration" Badge — never plain text that could read as a promise).
  3. **Knob rows**: only DIVERGING knobs shown highlighted (bg-sw-accent-light, per-option value
     bolded) with the §C.3 trade-off line beneath (text-sw-muted). Agreeing knobs collapse under
     "Same in every option (N)".
  4. Guard chips when set: "Marketplace-plan guard applied" (links the shipped
     `aca_magi_management` finding narration), "Catch-up must be Roth", "HSA held (Medicare
     timing)", "Safe-harbor floor applied".
  5. CTA: "Choose this approach".
- **Mix-your-own (D10.4)**: "Customize" expands a panel seeded from the selected card: segmented
  control per diverging knob (grid values only). Every change fires `POST .../compute`
  (deterministic, fast) and live-updates a fourth "Your mix" outcome strip. "Choose my mix"
  persists the custom vector.
- Educational rail: the existing page-level disclaimer block plus one scenario-specific static
  line above the cards: "These are approaches to consider based on facts you confirmed and current
  IRS limits. Whichever you choose, the elections are yours to make with your employer and
  institutions." (static copy, not Claude).
- Citation line under the cards: "Based on {N} facts you provided" → expands to fact labels +
  source types (from the fact-set summary; never values).

## D.4 Pick-an-option flow

"Choose this approach" opens `ConfirmDialog` (existing component):
- Restates the option's three metrics + knob changes as plain rows.
- Copy: "Choosing builds your personal action checklist. You can change your mind anytime —
  choosing again replaces the checklist." The user's pick IS the confirmation (D10.6) — no extra
  attestation step.
- Confirm → `POST /optimizer/scenarios/{year}/choose` → routes to the checklist view (below the
  cards in the Choices stage, mirrored in the report).

## D.5 Generated checklist (Decision 9 contract) + checklist store

The choose endpoint materializes items from `optimizer-scenarios.checklist_templates` — one group
per knob whose chosen value differs from current; each step numbered, imperative, one action,
employer/portal/form named (D9.5), with an engine-computed benefit line (§B.8):

- K1: "Contact your payroll department (or your payroll portal's W-4 form) and update your filing
  status to {confirmed_status_label} and your dependents from {w4_deps} to {family_deps}." →
  "≈ +{per_paycheck}/paycheck ({annual}/yr) in take-home." **Fact-gated** (§B.1 K1 gate);
  otherwise the step IS the confirm-ask and the directive shows as locked-next.
- K2/K3: "Log into your 401(k) portal and set your contribution to {pct}%, split {trad_pct}%
  traditional / {roth_pct}% Roth (if your plan allows both)." → exact take-home/tax deltas +
  "captures ≈ {match}/yr in employer match" + FV range line labeled illustration.
- K4: HSA election step (open-enrollment / qualifying-event caveat as fixed copy) — gated on the
  HDHP fact.
- K5: "Contribute {trad}/{roth} to your Traditional/Roth IRA — as {n} transfers of {amount}" —
  pairs with K6.
- K6: "Set up an automatic transfer of {amount} every {period_label} from checking to savings." →
  "This automatic transfer sets aside {annual}/yr."
- Header aggregate (D9.7): "Completing these {n} actions ≈ {take_home}/mo take-home · {tax}/yr in
  2026 federal tax · roughly {fv_low}–{fv_high} more by {age} (illustration)."

**Checklist store** — the Decision-9 durable action store, built in this unit (one additive
migration `create_optimization_checklist_items_table`):

```
optimization_checklist_items:
  id, user_id (FK cascade), tax_year, source_type ('scenario_choice'), source_id (chosen fact id),
  fact_set_id (FK scenario_fact_sets — [M9] citation linkage), knob, step_key,
  kind ('directive'|'confirm_ask'), benefit_line_params (json — token values, integer cents),
  position, done_at (nullable), timestamps
  index (user_id, tax_year)
```

`benefit_line_params` carries cents integers inside a JSON column on a user-scoped row returned
only over authed API — matches the treatment of `OptimizationFinding.estimated_value_cents`
(engine-computed figures on non-encrypted finding rows, shipped precedent).

**Completion writes reality facts**: checking off the K3 step supersedes
`employer.contribution_pct` (and K2 → new `retirement.elected_roth_share_pct` fact) via
`UserTaxFact::recordFact(source_type: 'user_edit')` — the CHOICE writes intent (§D.6); the
CHECKBOX writes reality. Detectors (`EmployerMatchGapDetector` etc.) then naturally stop firing.

## D.6 Persistence of the chosen scenario (facts store, D10.4)

On choose:
1. `ScenarioFactResolverService::snapshotFactSet()` freezes the fact set used (id → citations).
2. Two `UserTaxFact::recordFact()` writes (source_type `user_edit`, `tax_year` set, volatility
   `stable`):
   - `scenario.chosen_option` → `'take_home'|'tax_burden'|'retirement'|'balanced'|'merged'|'custom'` **[M4]**
   - `scenario.chosen_knobs` → JSON-encoded clamped knob vector (value column is encrypted TEXT —
     cents inside are fine; `$hidden` keeps it out of serialization). `metadata.fact_set_id` set **[M9]**.
3. Checklist materialization (§D.5).
4. Fire the existing report-stale path (`MarkOptimizationReportStale` pattern) so the report
   regenerates with the chosen-plan section.

Re-choosing supersedes (append-only chain preserves history) and re-materializes the checklist
(old rows deleted for that user+year+source_type — scoped delete of rows this feature owns, not
user data).

## D.7 Report integration

New section injected by `OptimizationReportGeneratorService` when `scenario.chosen_option` exists:
`section_key: 'chosen_plan'`, title "Your Chosen Approach — Action Plan",
`section_type: 'topical'`, inserted before the `documents_missing` wrapper. Contents: option
label, three-metric summary (figures from a fresh SCN-07 run — never stored prose), and the
aggregated unlocked checklist steps (D9.6 "User Actions Needed" aggregation). Narrator writes the
section prose from a no-dollars payload as usual. Additive to `config/optimization-report.php`.

---

# PART E — API surface (all NEW routes additive; auth:sanctum; NO bank.connected — mirrors the shipped optimizer groups so statement-upload-only users participate)

```php
// Objectives / readiness (controller: OptimizationObjectiveController)
Route::prefix('optimizer/objectives')->group(function () {
    Route::get('/{year}', 'show')->middleware('throttle:60,1');
    Route::post('/{year}/{objective}/enqueue', 'enqueue')->middleware('throttle:10,1');
});

// Scenarios (controller: ScenarioController)
Route::prefix('optimizer/scenarios')->group(function () {
    Route::get('/{year}', 'show')->middleware('throttle:30,1');
    Route::post('/{year}/compute', 'compute')->middleware('throttle:60,1');
    Route::post('/{year}/choose', 'choose')->middleware('throttle:10,1');
});

// Checklist (controller: OptimizationChecklistController) — [merge addition M16: D9.1 requires
// per-item done-state persistence; Part 2 described the store but defined no endpoints]
Route::prefix('optimizer/checklist')->group(function () {
    Route::get('/{year}', 'show')->middleware('throttle:60,1');
    Route::patch('/items/{item}', 'update')->middleware('throttle:30,1');  // done/undone toggle
});
```

`{objective}` validated against `config('optimization-objectives.objectives')` keys → 404
otherwise. `{item}` uses route-model binding + a policy (`user_id` ownership). `choose` validates
`year` ∈ {currentYear, currentYear−1} like the report controller. All endpoints operate on
`auth()->id()` only.

**GET /optimizer/objectives/{year}** — keys, labels, states only; **never values** (safe to log):

```json
{
  "tax_year": 2026,
  "objectives": {
    "take_home": {
      "label": "Take-home income",
      "ready": true,
      "completeness_pct": 88,
      "questions_to_unlock": 0,
      "blocking": [
        {"fact_key": "profile.filing_status", "label": "Filing status", "state": "confirmed", "source": "fact"},
        {"fact_key": "employer.federal_withholding", "label": "Federal withholding", "state": "known", "source": "derived"}
      ],
      "confirm_needed": [{"fact_key": "employer.federal_withholding", "label": "Federal withholding"}],
      "optional_missing": [
        {"fact_key": "prior_year.federal_liability_cents", "label": "Last year's federal tax",
         "default_note": "safe-harbor guardrail applied conservatively"}
      ]
    },
    "tax_burden":  { "ready": false, "questions_to_unlock": 1, "...": "..." },
    "retirement":  { "ready": false, "questions_to_unlock": 2, "...": "..." }
  }
}
```

**POST /optimizer/objectives/{year}/{objective}/enqueue** →
`{ "session": {"id": 41, "tax_year": 2026, "status": "in_progress"}, "enqueued": ["person.birth_year", "retirement.target_age"], "queue_size": 5, "message": "2 questions added to your interview." }`

**GET /optimizer/scenarios/{year}** — assemble baseline (from resolver), run solvers, return the
set. Cached 60s per `scenarios:{user_id}:{year}:{fact_set_hash}` (DashboardCacheService pattern);
the hash covers relevant current-fact ids + snapshot `profile_hash`, so any interview answer
invalidates. `readiness` block = `ObjectiveReadinessService::readiness()` projection
(`ready` + `blocking_missing` keys) **[M5]** — only ready objectives produce option cards.

```jsonc
{
  "tax_year": 2026,
  "readiness": {
    "take_home":  { "ready": true,  "missing_fact_keys": [] },
    "tax_burden": { "ready": true,  "missing_fact_keys": [] },
    "retirement": { "ready": false, "missing_fact_keys": ["pay.frequency", "retirement.target_age"] }
  },
  "agreement": false,
  "options": [
    { "key": "take_home",  "label": "More take-home now",      "badges": [],
      "knobs": { /* §B.1 clamped */ }, "outcome": { /* §B.6 */ } },
    { "key": "retirement", "label": "More toward retirement",  "badges": [], "...": "..." },
    { "key": "balanced",   "label": "Balanced",                "badges": ["default", "lowest_tax"], "...": "..." }
  ],
  "knob_diffs": [
    { "knob": "k401.roth_share_pct",
      "values": { "take_home": 0, "retirement": 100, "balanced": 50 },
      "tradeoff_line": "Option A: 0% Roth — Option B: 100% Roth. A keeps about $120/mo more now; ..." }
  ],
  "same_knobs": ["w4", "hsa.annual_election_cents"],
  "fact_set": { "id": 12, "fact_count": 14, "facts": [{"label": "Filing status", "source": "fact"}] },
  "intro_prose": null,          // filled from the report narrator cache when available
  "chosen": { "option_key": "balanced", "chosen_at": "...", "knobs": { "...": "..." } },
  "disclaimer": "These are approaches to consider ... elections are yours to make."
}
```

All money fields are integer cents with `_cents` suffix (frontend divides by 100 — no
decimal-string pitfalls).

**POST /optimizer/scenarios/{year}/compute** — body `{ "knobs": {...} }` (`ComputeScenarioRequest`:
numeric bounds, grid membership for roth_share, non-negative cents). Returns
`{ "outcome": { §B.6 } }` for the mix panel. Engine clamps regardless — validation is UX nicety,
clamping is the security boundary.

**POST /optimizer/scenarios/{year}/choose** — body
`{ "option_key": "take_home"|"tax_burden"|"retirement"|"balanced"|"merged"|"custom", "knobs": {...}? }`
(`knobs` required iff `custom`; `ChooseScenarioRequest`). Server recomputes from its OWN solver
output (never trusts client figures), snapshots the fact set, writes the two facts, materializes
the checklist, fires report-stale. Returns
`{ "chosen": {...}, "checklist": { "header_aggregate": {...}, "items": [...] } }`.

**PATCH /optimizer/checklist/items/{item}** — body `{ "done": true|false }`. Setting done on
fact-writing steps triggers the §D.5 reality-fact writes; response includes the refreshed item.

`InterviewController::next()` — additive response fields only (existing keys untouched):
`objective_tags`, `answer_type`, `choices` (from template), `prefill_display`/`prefill_value`
(transient, §A.5.5), `doc_affordance`.

---

# PART F — Config additions (all additive)

**`config/tax-rules.php`** — one key appended inside the existing `2026 → detection` block:
```php
// ── Credit for Other Dependents (W-4 Step 3 second line) ────────────────
// [CITED: IRC §24(h)(4); $500 non-refundable, not inflation-indexed]
'odc_amount' => 500,
```
(FSA health limit deliberately NOT added in v2.1 — FSA stays awareness-only until the P13
sign-off gate confirms the 2026 indexed amount; adding it later upgrades K4 without redesign.)

**NEW `config/optimization-objectives.php`** — §A.3 (objectives fact map, aliases, templates,
`pay_periods_per_year`, prerequisites).

**NEW `config/optimizer-scenarios.php`** (assumptions and knob metadata — NOT IRS constants):
```php
return [
    'assumptions' => [
        'illustrative_growth_rate_low'  => 0.04,   // D9.7 range floor
        'illustrative_growth_rate_high' => 0.07,   // range ceiling — NEVER shown as a single number
        'default_retirement_age'        => 65,
        'aca_cliff_buffer_cents'        => 200_000, // $2,000 safety margin below the 400% FPL cliff
        'min_take_home_ratio'           => 0.90,
        'min_take_home_ratio_cash_constrained' => 0.97,
        'auto_transfer_max_surplus_share' => 0.80,
        'income_objective_transfer_share' => 0.50,
        'hsa_grid_step_cents'           => 25_000,
    ],
    'grids' => ['roth_share_pct' => [0, 25, 50, 75, 100], 'ira_room_fractions' => [0, 0.25, 0.5, 0.75, 1.0]],
    'divergence' => [ /* §C.1 epsilons */ ],
    'tax_objective_priority' => ['match_capture', 'hsa', 'trad_401k', 'trad_ira'],
    'tradeoff_templates' => [ /* §C.3 */ ],
    'checklist_templates' => [ /* §D.5 step + benefit-line templates with token slots */ ],
    // [M5] NO 'readiness' key — ObjectiveReadinessService over optimization-objectives.php is
    //      the single readiness source.
    // [M8] NO 'pay_frequency_periods' key — lives once in optimization-objectives.php.
];
```
A Pest guard test greps `ScenarioSolverService` + the new engine methods for raw threshold
literals (FLAG-08 materiality-test pattern) — every number must trace to config.

---

# §M — Merge fixes applied (Part 1 vs Part 2 reconciliation — normative)

| # | Conflict | Resolution |
|---|---|---|
| M1 | Pay-frequency fact key: Part 1 `pay.frequency` vs Part 2 `income.pay_frequency` | `pay.frequency` everywhere |
| M2 | HSA coverage key: Part 1 `hsa.coverage_type` vs Part 2 `health.hsa_coverage_type` | `hsa.coverage_type` everywhere |
| M3 | Part 1 aliased `w4.filing_status` ↔ `profile.filing_status` | Alias DELETED — shipped `ProfileConformanceDetector` compares these two keys to detect mismatch; K1 needs both sides. `profile.filing_status` = confirmed status (annual-tax math); `w4.filing_status` = W-4-on-file evidence (withholding side). Part 1's C1 canonical key + config sketch corrected accordingly |
| M4 | Objective ids: Part 1 `take_home/tax_burden/retirement` vs Part 2 `income/tax/retirement` | Part 1 ids everywhere (config, services, API, option keys, chosen_option enum) |
| M5 | Duplicate readiness: Part 2's `optimizer-scenarios.readiness` config + looser fact sets vs Part 1's requirement map + `ObjectiveReadinessService` | Single source: `ObjectiveReadinessService` over `config/optimization-objectives.php`. Part 2's readiness block deleted; `ScenarioController::show` consumes `readiness()` |
| M6 | Duplicate enqueue: Part 2's proposed `InterviewOrchestratorService::queueFactKeys()` (append) vs Part 1's `enqueueGaps()` (front-insert + endpoint) | `queueFactKeys()` dropped; `enqueueGaps()` + `POST /optimizer/objectives/{year}/{objective}/enqueue` is the only path; front-insert semantics win (user-requested questions surface immediately) |
| M7 | Pay-frequency derivation location: Part 2 put it in `PaystubFactExtractorService`; Part 1 in the resolver | Resolver (`frequency_from_paystub` rule) — cross-field date math doesn't fit the 1:1 extractor map; extractor stays proposals-only for mapped fields |
| M8 | `pay_periods_per_year` map duplicated in both configs | Lives once in `config/optimization-objectives.php` |
| M9 | Part 1 required scenario rows to carry `fact_set_id`; Part 2 persists no scenario rows ("no migrations required") | Choose-time `snapshotFactSet()`; `fact_set_id` stored in `scenario.chosen_knobs` metadata + on every checklist row; GET carries a fact-set citation summary. Corrected migration count: exactly TWO additive migrations in this unit (`scenario_fact_sets`, `optimization_checklist_items`) |
| M10 | Baseline age source: Part 2 `estimated_age` snapshot; Part 1 `person.birth_year` fact + backfill | Resolver `age_from_birth_year` primary; backfilled `estimated_age` snapshot as fallback |
| M11 | Part 2 claimed single/MFS bracket tables are identical in config | False at the 37% threshold ($640,600 vs $384,350). The `single_or_mfs → single` mapping stands (Pub 15-T W-4 column convention, documented in code); annual-tax math always uses the true confirmed status |
| M12 | `w4.dependents_claimed`/`w4.filing_status`: Part 1 T4 = BLOCKING; Part 2 suppresses the K1 delta when unknown | Optional-with-suppression: scenarios stay computable, `w4_delta_included=false`, K1 checklist group renders as confirm-ask (D9.2). Readiness surfaces them under confirm/ask, never blocks |
| M13 | `traditionalIraDeductibility()` needs `$spouseCoveredByPlan`; neither part defined a source | NEW optional fact `spouse.covered_by_retirement_plan` (default `'no'`, documented conservative assumption; asked only when MFJ + IRA lever in play) |
| M14 | Part 1's global "snapshot beats fact" chain vs identity facts | Chain order is per-fact config: money YTD = snapshot-first (doc sums fresher); identity/enum = fact-first (user confirmation beats profile-derived snapshot). C1 corrected to fact-first |
| M15 | Part 2's `assembleBaseline()` read facts directly | Baseline is built from `ScenarioFactResolverService::resolveAll()` so provenance/aliases/chains/citations are identical across readiness, computation, and gating |
| M16 | D9.1 requires persisted per-item done-state; Part 2 described the store but no endpoints | `optimization_checklist_items` migration + `GET /optimizer/checklist/{year}` + `PATCH /optimizer/checklist/items/{item}` added |

---

# §T — Tests (Pest, additive; `php artisan test --compact` + `vendor/bin/pint --dirty` per house rules)

**Data layer**
1. Resolver source-priority per-fact chain (identity fact-first, money snapshot-first); alias
   fallback; year-scoped-then-unscoped lookup; `w4.filing_status` and `profile.filing_status`
   resolve independently (regression for M3).
2. Derivations: frequency-from-paystub span table (incl. ambiguous → null); YTD annualization;
   confirmed-zero handling; `age_from_birth_year`.
3. Readiness: blocking vs optional; `not_applicable` via prerequisite='no'; `questions_to_unlock`;
   conditional-blocking (B5/B6, R6/R7); T4 suppression not blocking.
4. Enqueue: front-insert order; dedupe vs queue/asked/answered; battery stays last; initial_cap
   not applied; idempotent double-POST.
5. Template questions: zero HTTP to Anthropic (`Http::fake` + assertNothingSent); typed conversion
   (money→cents, choice validation 422); volatility/tax_year from template; non-template keys keep
   legacy recordAnswer behavior.
6. Suggested-confirm: `prefill_source` pointer never persists a value in `options` (regression:
   assert no `/\d{4,}/` in stored options); confirm answer records resolved value as
   `interview_answer`.
7. FactSet: HMAC hash stability; `isStale()` flips on fact supersession; `resolved_facts` hidden +
   encrypted; GDPR cascade.
8. Readiness API: response contains no money values (regression: no `/\d{4,}/` besides year/ids).

**Engine**
9. SCN-01 vs hand-computed values per W-4 status; single_or_mfs → single mapping; SCN-02 FICA incl.
   wage-base cap; SCN-03 match over/under threshold; SCN-04 FV range monotonicity + zero-horizon;
   SCN-05/06 MAGI + headroom; SCN-07 full-vector cases: (a) MFJ 3-kids W-4-misaligned baseline
   reproduces the owner's D9 example shape, (b) age-61 super-catch-up clamp, (c) mandatory-Roth
   catch-up reassignment, (d) Roth IRA phase-out cap, (e) shared-IRA-limit clamp with combined YTD,
   (f) Medicare HSA guard, (g) safe-harbor floor when prior-year liability absent.
10. **ACA invariant property test** (§B.5): 200 randomized marketplace baselines near the cliff —
    every emitted outcome clears cliff−buffer or flags with Roth at 0.
11. Solver determinism (same baseline twice → identical vectors); objective dominance sanity
    (take_home option take-home ≥ others; retirement option retirement-delta ≥ others; tax_burden
    option federal-tax ≤ others, epsilon ties allowed).
12. Agreement rule: converged baseline → single merged option; divergent → exactly 3 options +
    non-empty knob_diffs; Balanced-seeded-from-TB rule.
13. No-literal grep guard over solver + new engine methods (§F).

**API/UX**
14. Scenarios API: readiness gating (missing facts → no option card), compute clamps hostile
    input, choose recomputes server-side + writes both facts + fact-set snapshot + supersession on
    re-choose, cross-user 403, throttles.
15. Checklist: materialization from templates; fact-gated steps render confirm-ask when facts
    unconfirmed and directive after confirmation; done-toggle writes reality facts
    (`employer.contribution_pct` supersession, `retirement.elected_roth_share_pct`); re-choose
    re-materializes.
16. No-Claude guard: zero HTTP in objectives/scenarios/checklist endpoints.
17. Report: `chosen_plan` section injected only when a choice exists; narrator payload contains no
    cents fields (regression grep on the payload builder).

---

# §I — Implementation plan (executor batch; additive-only; runs AFTER the in-flight review-fixes push lands, per D9/D10 sequencing)

Ground rules for every task: additive-only (no existing API/response/schema changes; forward-only
migrations); `php artisan test --compact` green + `vendor/bin/pint --dirty` before each commit;
frontend tasks carry the D6/D7/D12 skill + blocking-audit regime and the DESIGN-ELEVATION-SPEC
token system; no task touches detectors, `UserTaxFact` semantics, `DurableFactsController`,
`buildInitialQueue()` ordering, or existing migrations.

**Wave 1 — pure foundations (parallel-safe, zero product risk)**
- T1. `config/tax-rules.php` += `detection.odc_amount`; NEW `config/optimizer-scenarios.php`
  (assumptions/grids/divergence/priority/templates skeleton); NEW
  `config/optimization-objectives.php` (full §A.2 fact map, aliases, `pay_periods_per_year`, all
  question templates). Tests: config-shape guards.
- T2. `TaxRulesEngineService` += SCN-01…SCN-07 + engine unit tests (§T.9) + no-literal guard.
  Pure PHP; no service/controller wiring yet.

**Wave 2 — data substrate (depends on T1)**
- T3. `ScenarioFactResolverService` (resolveAll/resolve/derivations/two-tier) + tests §T.1–2.
- T4. Migration `create_scenario_fact_sets_table` + `ScenarioFactSet` model +
  `snapshotFactSet()`/`isStale()` + tests §T.7. Forward-only; run `php artisan migrate` only.
- T5. `ObjectiveReadinessService` (readiness + enqueueGaps) + orchestrator ADDITIVE changes
  (template branch in `createOptimizationQuestion()`, config-merged gate map alongside
  `GATED_PROBES`, typed conversion + template volatility/taxYear in `recordAnswer()`, prefill
  pointer resolution) + `PaystubFactExtractorService` additive map entries + NEW
  `RETIREMENT_STATEMENT_FACT_MAP` + assembler `estimated_age` backfill. Tests §T.3–6.
- T6. `OptimizationObjectiveController` + 2 routes + `InterviewController::next()` additive
  fields. Tests §T.8.

**Wave 3 — engine orchestration (depends on Waves 1–2)**
- T7. `ScenarioSolverService` (assembleBaseline-from-resolver, solve ×3, Balanced synthesis,
  diffKnobs, attributeBenefits) + tests §T.10–12.
- T8. Migration `create_optimization_checklist_items_table` + model + policy + materialization
  service (templates → items, fact-gating, benefit params) + done-toggle reality-fact writes.
  Tests §T.15.
- T9. `ScenarioController` + `OptimizationChecklistController` + FormRequests
  (`ComputeScenarioRequest`, `ChooseScenarioRequest`) + routes + 60s cache + choose flow
  (recompute, snapshot, facts, checklist, report-stale). Tests §T.14, §T.16.
- T10. Report `chosen_plan` section in `OptimizationReportGeneratorService` +
  `config/optimization-report.php` additive entry + narrator `narrateScenarioComparison()`
  (no-dollars payload). Tests §T.17.

**Wave 4 — frontend (depends on Wave 3 API; D6/D7/D12 regime mandatory, Section 11.B audit +
blocking audits in each task log)**
- T11. `ObjectiveReadinessPanel.tsx` + objectives integration in `Optimize/Index.tsx` (readiness
  chips, enqueue CTA, tick-down loop, doc affordance) + interview card additive rendering
  (choices/answer_type/prefill/doc_affordance).
- T12. Choices stage: `ViewMode` 4th value + `StageIndicator` step, comparison cards
  (three-metric block, knob rows, guard chips, Illustration badge + assumptions tooltip,
  disclaimers, citation line), mix-your-own panel (compute round-trips), ConfirmDialog choose flow.
- T13. Checklist view (Choices stage + report mirror): groups, directive/confirm-ask states,
  benefit lines, header aggregate, done-state persistence.

**Wave 5 — hardening**
- T14. Full-suite run, ACA property test §T.10 in CI profile, money-leak regression greps (§T.8,
  §T.6, §T.17), `npm run build`, pint. Preservation + brand-fidelity audits (D7.4/D12) for the
  frontend tasks' logs.

Suggested commit grouping: one commit per task, same PR that follows the review-fixes push
(owner-ordered sequencing); checklist + scenarios land as one coherent unit (D10 "SUBSUMES
Decision-9" note).
