# SCENARIOS-SPEC Part 2 — Scenario Engine, Conflict Options, Choice-to-Checklist UX

> Design for Decision 10 (Optimization Scenarios) + the engine/UX half of Decision 9
> (benefit lines, choice-to-checklist). Implementation-ready. Verified against shipped
> code on `release/v2.0.0` as of 2026-07-02.
>
> Binding sources: `.planning/reference/enhanced-profile-integration-notes.md` Decisions 9 + 10.
> Shipped machinery referenced (verified signatures, do not re-invent):
> - `app/Services/TaxRulesEngineService.php` (pure engine, integer cents, config-driven)
> - `config/tax-rules.php` (2026 constants: brackets, 401k/IRA/HSA limits + catch-ups, `detection.*` block incl. ACA FPL)
> - `app/Models/UserTaxFact.php` (`currentFact()`, `recordFact()`, `confirmProposal()`, append-only)
> - `app/Models/IncomeOptimizationProfile.php` (encrypted cents snapshot, `answerableFields()`)
> - `app/Services/IncomeOptimizerDataAssemblerService.php` (profile flags + doc sums + calendar-year deposits)
> - `app/Services/InterviewOrchestratorService.php` (queue state machine, `GATED_PROBES`, `recordAnswer()`)
> - `app/Services/Detectors/AcaCliffMonitor.php` (2B.1 cliff-before-Roth sequencing, FLAG-22 liability rails)
> - `app/Services/Detectors/EmployerMatchGapDetector.php` / `WithholdingGapDetector.php` (fact keys, engine usage)
> - `resources/js/Pages/Optimize/Index.tsx` (three-stage page: findings → interview → report)
> - `app/Services/OptimizationReportGeneratorService.php` + `config/optimization-report.php` (section defs)
>
> NOTE ON PART 1: no `SCENARIOS-SPEC-part1-*.md` exists on disk yet. This document pins its
> fact-key contract to keys **already present in shipped code** (grep-verified list in §1.3) and
> flags every NEW fact key it introduces. When Part 1 (data acquisition / fact map / checklist
> store) lands, reconcile §1.3 and §4.6 against it — the shipped keys are the source of truth.

---

## 0. Non-negotiables carried into this design

1. **All dollar math in `TaxRulesEngineService`** (or a sibling pure service calling it). Zero
   Claude/HTTP in any computation path. Claude words prose only; **dollar figures are never in
   Claude payloads** (SAFE-01/SAFE-03, same pattern as `InterviewOrchestratorService::wordQuestion()`).
2. **Integer cents everywhere** in PHP; config stores whole dollars; engine converts (existing convention).
3. **Additive only**: new config keys, new service, new controller, new routes, new page stage.
   No changes to existing engine method signatures, `OptimizationFinding` shape, report section
   response shape, or `UserTaxFact` semantics.
4. **Educational frame**: scenarios are "approaches to consider"; the user's pick IS the election
   (D10.6). No "should", no guarantees. Long-horizon numbers follow D9.7 illustration rules
   (config growth assumptions, ranges, labeled "illustration").
5. **Cliff-before-Roth (2B.1, verbatim binding)**: for marketplace enrollees, MAGI management is
   modeled BEFORE any Roth allocation. In this engine that is a **hard guard inside scenario
   computation** (§2.5), not just narration ordering.
6. **Fact-gated directives (D9.2)**: a checklist step that depends on an unconfirmed fact renders
   as the confirmation ask, not the directive.

---

## 1. THE KNOB SET

Six knobs. A **knob vector** is the canonical input to scenario computation:

```php
// Canonical knob vector shape (all keys always present; engine clamps, never trusts)
[
    'w4' => [
        'filing_status'       => 'married_joint',  // W-4 Step 1(c): 'single_or_mfs'|'married_joint'|'head_of_household'
        'dependents_under_17' => 3,                // W-4 Step 3 qualifying children
        'other_dependents'    => 0,                // W-4 Step 3 other dependents
    ],
    'k401' => [
        'deferral_pct'   => 10.0,   // % of gross, employee deferral (trad + Roth combined)
        'roth_share_pct' => 50,     // % of the deferral that is Roth; grid {0,25,50,75,100}
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

### 1.1 Knob definitions, data dependencies, hard constraints

**K1 — W-4 alignment (filing status + dependents).**
Never searched by solvers: in every scenario K1 is set to "align the W-4 with the user's
*confirmed* facts" (D9.4: operationalize what the user confirmed; never assert an election in the
abstract). W-4 Step 1(c) has three boxes; `married_separate` maps to `single_or_mfs`, which uses
the `single` bracket/deduction tables (identical in `config/tax-rules.php`).
- Data: `profile.filing_status` (confirmed durable fact; falls back to
  `IncomeOptimizationProfile.filing_status`), `w4.filing_status` (evidence of what's on file —
  shipped fact key, written by paystub conformance / interview), `family.dependents_count`,
  `family.qualifying_children_under_17` (shipped keys), **NEW** `w4.dependents_claimed`
  (what the current W-4 claims — interview ask; enables the "update your dependents from 0 to 3"
  directive with a real from/to), gross pay + pay frequency (K-common, §1.2).
- Constraints (config): brackets + `standard_deduction` per W-4 status; `detection.ctc_amount`
  (2,200) per qualifying child; **NEW config** `detection.odc_amount` (500, [CITED: IRC §24(h)(4)])
  per other dependent. K1 changes *withholding/take-home only* — it never changes computed annual
  tax (the engine reports both so the UI can say "this moves money from your refund into paychecks").
- Fact gate: the directive step ("Contact your payroll department and change your W-4 filing status
  from head of household to married filing jointly") renders only when `profile.filing_status` is
  confirmed AND (`w4.filing_status` known-divergent OR `w4.dependents_claimed` known-divergent).
  Otherwise the step is "Confirm what filing status and dependents your current W-4 claims — check
  your latest paystub or payroll portal" (which, once answered, unlocks the directive).

**K2 — Traditional-vs-Roth 401(k) split.**
- Domain: `roth_share_pct` ∈ config grid `{0, 25, 50, 75, 100}`.
- Data: `employer.has_401k` (BenefitsGuide fact), `retirement.traditional_401k_ytd_cents`,
  `retirement.roth_401k_ytd_cents` (paystub facts via `PaystubFactExtractorService`; snapshot
  columns `traditional_401k_ytd` / `roth_401k_ytd` as fallback), marginal rate (engine, from
  baseline taxable income), age (`IncomeOptimizationProfile.estimated_age`), prior-year FICA wages
  (`prior_year.agi_cents` as proxy fact; interview ask when absent), `marketplace.pays_marketplace_premiums`.
- Constraints:
  - Combined trad+Roth deferral ≤ `401k.employee_deferral` + catch-up (`catchup_age_50_plus` for
    50–59/64+, `catchup_age_60_to_63` for 60–63) via `remaining401kRoomCents()` semantics.
  - `requiresMandatoryRothCatchup()` (SECURE 2.0 §603): when true, the catch-up *portion* of the
    deferral is forced Roth regardless of the knob; engine sets `guards.mandatory_roth_catchup_applied`.
  - `rothVsTraditionalBand()` is the solver *prior* (§2.7), never a hard constraint.
  - **ACA CLIFF GUARD (hard, §2.5): for marketplace enrollees near the cliff, Roth share is forced
    down (Roth → Traditional reallocation) until projected MAGI clears the cliff buffer. This gate
    runs inside `computeScenarioOutcome()` — no scenario can be emitted that puts a marketplace
    enrollee over the cliff via Roth allocation. This is the cliff-before-Roth sequencing rule made
    arithmetic.**

**K3 — 401(k) contribution level vs match formula.**
- Domain: `deferral_pct` ∈ [floor, ceil] in 1-point steps; floor = current pct (never propose a
  reduction) unless `finance.is_cash_constrained` = 'true', in which case floor =
  `employer.match_threshold_pct` (never below full-match capture); ceil = pct that exhausts
  `remaining401kRoomCents()` for the year.
- Data: `employer.match_pct`, `employer.match_threshold_pct`, `employer.contribution_pct` (all
  shipped keys — same trio `EmployerMatchGapDetector` reads), `employer.match_formula` (raw text
  fact for display), gross pay, `retirement.k401_contribution_ytd_cents`.
- Constraints: `remaining401kRoomCents(ytd, age)`; §415(c) total-limit awareness only
  (`detection.section_415c_limit`) — never modeled, one glossary line when after-tax facts exist.
  Match capture math in §2.3. "If your plan allows" framing is mandatory in all K3 copy (FLAG-04 rail).

**K4 — HSA / FSA election.**
- Domain: HSA `annual_election_cents` ∈ [current YTD, coverage limit]. FSA is **awareness-only** in
  v2.1 (no FSA limit exists in `config/tax-rules.php`; adding real FSA math needs a P13-gated
  constant — see §6 config additions with [ASSUMED] marker; until sign-off the FSA knob renders as
  an educational note, never a computed scenario dimension).
- Data: `employer.hdhp_hsa_available` (BenefitsGuide), `health.hsa_eligible`, `employer.hsa_enrolled`,
  `hsa.ytd_contribution_cents` (or snapshot `hsa_ytd`), **NEW** `health.hsa_coverage_type`
  ('self_only'|'family' — required to pick the limit; interview ask, or proposed from BenefitsGuide),
  age (55+ catch-up), `medicare.enrollment_date` (shipped key).
- Constraints: `remainingHsaRoomCents(ytd, coverageType, age)`; HDHP eligibility is fact-gated
  (no confirmed HDHP fact → the HSA step is a confirm-ask, not a directive);
  **Medicare lookback hard stop**: if `medicare.enrollment_date` is set and within
  `detection.medicare_hsa_lookback_months` (6) of now (or user is 65+ with enrollment unknown),
  the HSA knob is pinned to current YTD and the scenario carries a caution line (IRS Notice 2004-2
  Q&A 28 rail — mirrors the shipped constant's comment).
- Take-home nuance the engine MUST model: payroll HSA is §125 cafeteria money — exempt from income
  tax AND employee FICA. 401(k) deferrals are income-tax-relevant only (still FICA-taxed). §2.3.

**K5 — IRA type/amount within the shared limit.**
- Domain: `(traditional_cents, roth_cents)` on a quarter-grid of remaining shared room:
  each ∈ {0, ¼, ½, ¾, 1} × remainingRoom, with trad + roth ≤ remainingRoom.
- Data: `ira.traditional_ytd_contribution_cents` + `ira.roth_ytd_contribution_cents` (shipped keys;
  snapshot `ira_ytd` fallback) — **combined** YTD passed to `remainingIraRoomCents()` per the D2/D3
  shared-limit rule documented in `config/tax-rules.php` (`detection.ira_shared_limit` comment),
  MAGI (`profile.estimated_magi_cents` fact, else engine-projected from baseline), filing status,
  covered-by-plan (true when any 401k YTD > 0 or `employer.has_401k` confirmed), spouse coverage
  (spouse facts when present), `retirement.has_ira_balance` + `ira.balance_range` (pro-rata
  awareness), `ira.backdoor_roth_eligible` (existing gated probe — stays interview-gated).
- Constraints: `remainingIraRoomCents(combinedYtd, age)`; `rothIraEligibility(magi, status)` caps
  `roth_cents` at the phased-out limit (0 when fully phased out — backdoor Roth is NOT a scenario
  knob; it stays a specialist-band finding); `traditionalIraDeductibility(magi, status, covered,
  spouseCovered)` caps the *deduction* used in tax math at `partial_limit_cents` (contributions above
  the deductible limit are excluded from scenario proposals — nondeductible-basis tracking is
  specialist territory); ACA guard prefers `traditional_cents` for marketplace enrollees (MAGI reducer).

**K6 — Auto-transfer to savings.**
- Domain: `per_period_cents` ∈ [0, cap], cap = `scenario_assumptions.auto_transfer_max_surplus_share`
  × monthly surplus, converted to per-period. Monthly surplus comes from the existing dashboard
  budget-waterfall math (income minus spending, `DashboardController` figures) — reuse, do not re-derive.
- Data: pay frequency, monthly surplus (server-side from dashboard aggregates), take-home delta of
  the other knobs (K6 is sized last), `finance.is_cash_constrained`.
- Constraints: pure annualization, zero tax math. In the RETIREMENT objective K6 is sized to fund
  the K5 IRA amounts per period (IRA contributions are bank transfers, not payroll — the transfer IS
  the mechanism); in the INCOME objective K6 routes `scenario_assumptions.income_objective_transfer_share`
  (default 0.50) of the per-paycheck take-home gain ("set up direct deposit to savings of $X every
  2 weeks" per the owner's D9 verbatim). Benefit line = per_period × periods/yr, exact arithmetic.

### 1.2 Common baseline inputs (assembled once, §2.2)

| Input | Source priority (D10.1: known → interview) |
|---|---|
| Annual gross cents | snapshot `w2_wages` (doc-summed) → `bank_deposit_total` (employment-classified share) → interview |
| Pay frequency / periods per year | **NEW fact** `income.pay_frequency` ('weekly'\|'biweekly'\|'semimonthly'\|'monthly'); proposed from paystub `pay_period_start`/`pay_period_end` delta (7→weekly, 14→biweekly, 15/16→semimonthly, 28-31→monthly) via the `PaystubFactExtractorService` proposal path (confirm-before-write), else interview |
| Confirmed filing status | `profile.filing_status` fact → snapshot `filing_status` |
| Per-year federal withholding | `employer.federal_withholding` fact (same key `WithholdingGapDetector` reads) |
| Age | snapshot `estimated_age` → interview |
| Target retirement age | **NEW fact** `retirement.target_age` → default `scenario_assumptions.default_retirement_age` (65) |
| SE income | snapshot `self_employment_income` (SE users additionally get the existing specialist findings; scenario engine v1 treats SE income as ordinary income in the tax stack and does not knob solo-401k/SEP — those remain findings) |
| Marketplace enrollee | `marketplace.pays_marketplace_premiums` (same gate as `AcaCliffMonitor`) |

### 1.3 Fact-key contract (Part 1 coordination surface)

Shipped keys this spec depends on (grep-verified in `app/`):
`profile.filing_status`, `w4.filing_status`, `family.dependents_count`,
`family.qualifying_children_under_17`, `employer.match_pct`, `employer.match_threshold_pct`,
`employer.contribution_pct`, `employer.match_formula`, `employer.has_401k`,
`employer.federal_withholding`, `employer.hdhp_hsa_available`, `employer.hsa_enrolled`,
`health.hsa_eligible`, `hsa.ytd_contribution_cents`, `retirement.traditional_401k_ytd_cents`,
`retirement.roth_401k_ytd_cents`, `retirement.hsa_ytd_cents`, `retirement.k401_contribution_ytd_cents`,
`ira.traditional_ytd_contribution_cents`, `ira.roth_ytd_contribution_cents`, `ira.balance_range`,
`ira.backdoor_roth_eligible`, `retirement.has_ira_balance`, `profile.estimated_magi_cents`,
`prior_year.agi_cents`, `marketplace.pays_marketplace_premiums`, `medicare.enrollment_date`,
`finance.is_cash_constrained`, `benefits.fsa_ytd_cents`, `employer.fsa_available`.

NEW keys introduced by this spec (all written via `UserTaxFact::recordFact()`; document-sourced
ones go through the proposal/confirm gate):
- `income.pay_frequency` (paystub proposal or interview)
- `health.hsa_coverage_type` (BenefitsGuide proposal or interview)
- `w4.dependents_claimed` (interview)
- `retirement.target_age` (interview, optional — config default applies)
- `scenario.chosen_option`, `scenario.chosen_knobs` (choice persistence, §4.6 — tax_year-scoped)

New interview asks are queued through the existing orchestrator (§4.2) — never a parallel Q&A path.

---

## 2. SCENARIO COMPUTATION — TaxRulesEngineService extensions

All new methods live in `TaxRulesEngineService` (pure, cents, config-driven, zero HTTP), except the
solver/assembler orchestration which lives in a new `App\Services\ScenarioSolverService` (also pure —
DB reads for facts/snapshot only, engine calls for every dollar figure).

### 2.1 New engine methods (signatures + algorithms)

```php
// ── SCN-01: Per-paycheck federal withholding approximation ──────────────────
// Pub 15-T percentage method, standard withholding schedule (W-4 Step 2 unchecked,
// no extra withholding). This is a DECISION-SUPPORT ESTIMATE, labeled as such in UI.
public function estimatePeriodWithholdingCents(
    int $periodGrossCents,
    int $periodPreTaxCents,        // trad 401k + HSA + §125 premiums (federal-wage reducers)
    string $w4FilingStatus,        // 'single_or_mfs' | 'married_joint' | 'head_of_household'
    int $dependentsUnder17,
    int $otherDependents,
    int $payPeriodsPerYear,
    int $year = 2026,
): int
```
Algorithm:
1. Map `single_or_mfs` → `single` tables (identical brackets/deduction in config); validate status.
2. `annualWages = ($periodGrossCents - $periodPreTaxCents) * $payPeriodsPerYear`.
3. `adjusted = max(0, annualWages - standardDeductionCents($status))`.
4. `tentative = computeBracketTax(adjusted, brackets[$status])` (reuse private helper).
5. `credits = $dependentsUnder17 * detection.ctc_amount * 100 + $otherDependents * detection.odc_amount * 100`.
6. `return max(0, (int) round((tentative - credits) / $payPeriodsPerYear))`.

```php
// ── SCN-02: Employee-share FICA (for take-home math; HSA §125 is FICA-exempt) ─
// Uses se_tax.ss_rate/2 and se_tax.medicare_rate/2 with ss_wage_base from config.
// Additional Medicare surtax intentionally excluded from per-paycheck estimates
// (documented assumption; high earners get a caution line, never silent wrongness).
/** @return array{social_security_cents:int, medicare_cents:int, total_cents:int} */
public function employeeFicaCents(int $annualFicaWagesCents, int $year = 2026): array
```

```php
// ── SCN-03: Employer match capture ────────────────────────────────────────────
// match earned = gross × min(contribPct, thresholdPct) × matchPct  (all pct as 0-100 floats)
public function matchCaptureCents(
    int $annualGrossCents,
    float $contributionPct,
    float $matchPct,
    float $matchThresholdPct,
): int
```

```php
// ── SCN-04: Long-horizon illustration (D9.7 rules: config rates, RANGE output) ─
// Annuity FV: FV = P * ((1+g)^n - 1) / g  computed at growth_low and growth_high.
// NEVER returns a single point estimate. 'assumptions' strings feed the UI tooltip
// and the report verbatim — narrator may reword around them but figures come from here.
/** @return array{low_cents:int, high_cents:int, horizon_years:int,
 *                growth_rate_low:float, growth_rate_high:float, assumptions:string[]} */
public function futureValueRangeCents(int $annualContribCents, int $horizonYears, int $year = 2026): array
```
Reads `optimizer-scenarios.assumptions.illustrative_growth_rate_low/high` (§6). Zero/negative
horizon returns zeros with `horizon_years => 0` (UI then omits the illustration block).

```php
// ── SCN-05: Projected MAGI under a knob vector (educational approximation) ────
// MAGI ≈ gross + SE income − trad401k − HSA − deductible trad IRA (partial-limit-capped).
public function projectedMagiCents(array $baseline, array $knobs, int $year = 2026): int
```

```php
// ── SCN-06: ACA cliff headroom ────────────────────────────────────────────────
// Threshold selection mirrors AcaCliffMonitor: married_joint → family4 threshold,
// else single ([ASSUMED] household granularity — same P13 caveat as the config keys).
// Positive = dollars of MAGI room below the cliff; negative = over.
public function acaCliffHeadroomCents(int $projectedMagiCents, string $filingStatus, int $year = 2026): int
```
Reads `detection.aca_fpl_threshold_single` / `detection.aca_fpl_threshold_family4`.

```php
// ── SCN-07: The core — one knob vector → three outcome metrics ────────────────
/**
 * @param array $baseline  see §2.2 (ScenarioBaseline shape)
 * @param array $knobs     see §1 (knob vector shape; engine clamps a COPY, never trusts input)
 * @return array           see §2.4 (Outcome shape) — includes the clamped/guarded knob vector
 */
public function computeScenarioOutcome(array $baseline, array $knobs, int $year = 2026): array
```

### 2.2 ScenarioBaseline shape (assembled by `ScenarioSolverService::assembleBaseline()`)

```php
[
    'annual_gross_cents'        => int,
    'se_income_cents'           => int,
    'pay_periods_per_year'      => int,      // from income.pay_frequency map
    'filing_status'             => string,   // confirmed ('single'|'married_joint'|...)
    'w4_on_file'                => ['filing_status' => ?string, 'dependents_claimed' => ?int],
    'family'                    => ['dependents_under_17' => int, 'other_dependents' => int],
    'age'                       => ?int,
    'target_retirement_age'     => int,      // fact or config default
    'prior_year_fica_wages_cents' => ?int,
    'current' => [                            // annualized current run-rates (from YTD facts)
        'trad_401k_cents' => int, 'roth_401k_cents' => int,
        'hsa_cents' => int, 'ira_trad_ytd_cents' => int, 'ira_roth_ytd_cents' => int,
        'deferral_pct' => ?float,             // employer.contribution_pct
    ],
    'employer' => ['match_pct' => ?float, 'match_threshold_pct' => ?float, 'has_401k' => bool],
    'hsa_coverage_type'         => ?string,   // 'self_only'|'family'
    'medicare_enrollment_date'  => ?string,
    'is_marketplace_enrollee'   => bool,
    'is_cash_constrained'       => bool,
    'monthly_surplus_cents'     => int,       // dashboard waterfall figure
    'annual_withholding_cents'  => ?int,      // employer.federal_withholding
]
```
Annualization of YTD facts: `annual = ytd / elapsed_pay_periods * periods` when pay dates are known;
fallback `ytd / months_elapsed * 12`. Deterministic; document in code.

### 2.3 computeScenarioOutcome() algorithm

**Step 1 — Clamp (ordered; every clamp appends to `guards.clamps[]`):**
1. `k401`: `deferralCents = gross × deferral_pct`; clamp so `deferralCents ≤ remaining401kRoomCents(0, age)`
   annual-limit equivalent (the knob expresses the full-year election, so compare against the full
   limit incl. catch-ups, not remaining-after-YTD; YTD is only used to annualize the baseline).
2. Mandatory Roth catch-up: if `prior_year_fica_wages_cents` known and
   `requiresMandatoryRothCatchup()` true and deferral exceeds the base `employee_deferral` limit,
   the excess is forced Roth (added to the Roth side after the split); flag
   `mandatory_roth_catchup_applied`.
3. `hsa`: clamp to coverage-type limit + 55+ catch-up (`remainingHsaRoomCents(0, coverage, age)`
   full-year equivalent). Medicare lookback: if enrollment within `detection.medicare_hsa_lookback_months`
   or (age ≥ 65 and enrollment unknown) → pin to `current.hsa_cents`, flag `medicare_hsa_guard`.
4. `ira`: combined additional ≤ `remainingIraRoomCents(ira_trad_ytd + ira_roth_ytd, age)` (COMBINED
   YTD — the D2/D3 shared-limit rule). `roth_cents` further capped by
   `rothIraEligibility(magi, status)['limit_cents']` minus Roth YTD. Traditional *deduction* used in
   Step 3 capped by `traditionalIraDeductibility(...)`; proposed `traditional_cents` itself is capped
   at the deductible amount (see K5 rationale).
5. `transfer`: clamp to surplus cap (§1.1 K6).

**Step 2 — ACA cliff guard (AFTER limits, BEFORE tax math — the cliff-before-Roth rule):**
```
if baseline.is_marketplace_enrollee:
    magi   = projectedMagiCents(baseline, knobs)
    buffer = optimizer-scenarios.assumptions.aca_cliff_buffer_cents
    need   = buffer - acaCliffHeadroomCents(magi, filing_status)
    if need > 0:
        # (a) Roth 401k share → Traditional (dollar-for-dollar MAGI reducer, take-home neutral-ish)
        shift = min(need, roth_401k_annual_cents); move to trad; need -= shift
        # (b) Roth IRA → Traditional IRA, up to the deductible partial limit
        shift = min(need, ira.roth_cents, deductible_headroom); move; need -= shift
        # (c) still short → flag only; solvers MAY raise trad-401k/HSA within their take-home
        #     floor (solver loop, §2.7); the scenario card carries the aca caution line and
        #     links the shipped aca_magi_management finding. NEVER compute subsidy/clawback $
        #     (FLAG-22 rail).
        set guards.aca_cliff_applied = true (with need_remaining_cents when (c))
```

**Step 3 — Annual federal tax (baseline vs scenario):**
```
pretax(v)   = trad401k(v) + hsa(v) + deductible_trad_ira(v)     # per vector v
taxable(v)  = max(0, gross + se_income - pretax(v) - standardDeductionCents(confirmed_status, age))
tax(v)      = computeTax(taxable(v), confirmed_status)
federal_tax.annual_delta_cents = tax(scenario) - tax(current)    # negative = savings
```
Uses the CONFIRMED filing status (not the W-4 status) — annual tax is what the return will say;
the W-4 only shapes withholding. SE tax itself is invariant across these knobs (no solo-401k knob
in v1) so it cancels in deltas; documented assumption.

**Step 4 — Per-paycheck take-home (baseline vs scenario):**
```
periodGross    = gross / periods
periodPreTaxWH(v) = (trad401k(v) + hsa(v)) / periods           # federal-withholding reducers
withholding(v) = estimatePeriodWithholdingCents(periodGross, periodPreTaxWH(v),
                                                w4_status(v), deps17(v), depsOther(v), periods)
fica(v)        = employeeFicaCents(gross - hsa(v))/periods      # HSA §125 is FICA-exempt; 401k is NOT
takeHome(v)    = periodGross - (trad401k(v)+roth401k(v)+hsa(v))/periods
                 - withholding(v) - fica(v) - transfer_display_note
take_home.per_paycheck_delta_cents = takeHome(scenario) - takeHome(current)
take_home.annual_delta_cents       = per_paycheck_delta * periods
```
Baseline uses `w4_on_file` when known (so K1 alignment shows a real delta); when the W-4 on file is
unknown, baseline withholding uses `annual_withholding_cents / periods` (observed) and the K1 delta
is suppressed until the fact is confirmed (fact-gating, D9.2). K6 transfers are reported separately
("moved to savings"), never subtracted as "lost" take-home.

**Step 5 — Retirement:**
```
annual_contributions_delta = Δ(trad401k + roth401k + ira_trad + ira_roth)   # vs current run-rate
employer_match_delta       = matchCaptureCents(gross, deferral_pct(scn), match_pct, threshold)
                             - matchCaptureCents(gross, deferral_pct(cur), ...)
illustration               = futureValueRangeCents(annual_contributions_delta + employer_match_delta,
                                                   target_retirement_age - age)
```
HSA increases are reported on their own line ("+$X/yr into your HSA") — not folded into the
retirement FV (conservative; HSA-as-retirement is a glossary note). Roth/Trad after-tax equivalence
is deliberately NOT modeled (specialist territory); the scenario card carries one fixed line:
"Roth dollars are contributed after tax; traditional dollars are taxed later" (template, no Claude).

### 2.4 Outcome shape (returned by SCN-07, serialized by the API)

```php
[
    'knobs'   => [...],                      // the clamped + guarded vector actually computed
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
        'clamps' => string[],                // e.g. '401k_annual_limit', 'roth_ira_phaseout'
    ],
]
```

### 2.5 The ACA sequencing invariant (testable)

> For any baseline with `is_marketplace_enrollee = true`, any outcome emitted by
> `computeScenarioOutcome()` satisfies: `projectedMagiCents(baseline, outcome.knobs)`
> ≤ cliff − buffer, OR `guards.aca_cliff_applied = true` with `aca_need_remaining_cents > 0`
> and the Roth knobs at 0. Pest property test over randomized baselines (§7).

### 2.6 Per-knob benefit attribution (feeds Decision-9 benefit lines)

`ScenarioSolverService::attributeBenefits(array $baseline, array $chosenKnobs): array`
- For each knob dimension d: compute `computeScenarioOutcome(baseline, current_vector_with_only_d_changed)`
  and record its three deltas as knob d's attributed benefit. ≤ 6 extra engine calls, pure.
- Interaction remainder (chosen-total minus sum of singles) is attributed to the checklist header
  aggregate only (D9.7 header line), never to an individual step.
- Deterministic arithmetic → exact figures on checklist steps ("increases your take-home by ~$X/paycheck
  ($Y/yr)"); illustration figures → range framing, "illustration" label (D9.7 split honored).

### 2.7 Objective solvers (deterministic heuristics — no LLM, no randomness)

`ScenarioSolverService::solve(array $baseline, string $objective): array` returns a knob vector.
Common to all three: K1 = align-to-confirmed-facts; every candidate is evaluated through SCN-07
(so clamps/guards apply); the take-home floor guard is:
`takeHome(candidate) ≥ min_take_home_ratio × takeHome(current)` with
`min_take_home_ratio` = 0.90 (config), tightened to 0.97 when `finance.is_cash_constrained`.

**INCOME (maximize per-paycheck take-home now):**
1. K3 deferral_pct = max(current_pct, match_threshold_pct) — never leave match money (dominant
   free-return rule); if cash-constrained and current > threshold, propose threshold as the floor
   option but never auto-select below current (both shown in the knob detail).
2. K2 roth_share = 0 (traditional lowers withholding → raises take-home), subject to mandatory-Roth
   catch-up clamp.
3. K4 HSA = current. K5 IRA additional = 0.
4. K6 = `income_objective_transfer_share` × per-paycheck take-home gain (routes the gain to savings —
   the owner's "direct deposit to savings of $500 every 2 weeks" pattern).

**TAX (minimize current-year federal tax):**
Greedy fill in config priority order `tax_objective_priority = [match_capture, hsa, trad_401k, trad_ira]`:
1. K3 to match threshold (as INCOME).
2. K4 HSA → largest election on a $250 grid keeping the take-home floor satisfied (HSA first: income
   tax + FICA exempt).
3. K3 continue: raise deferral_pct in 1-pt steps (roth_share = 0) while take-home floor holds and
   401k room remains.
4. K5 traditional IRA → largest quarter-grid amount within deductible partial limit while the floor
   holds. Roth IRA = 0.
5. K6 = 0. ACA guard is naturally satisfied (all-traditional is MAGI-minimizing).

**RETIREMENT (maximize retirement delta):**
1. K3: raise deferral_pct to the max the take-home floor allows (up to annual-limit pct).
2. K2: roth_share from `rothVsTraditionalBand(marginalRate(taxable(current), status))`:
   'roth' → 100, 'split' → 50, 'traditional' → 0 (config band thresholds); ACA guard may force down.
3. K4 HSA → fill room within the floor (after K3, same $250 grid).
4. K5: fill remaining shared IRA room (quarter grid); type split by the same band + Roth phase-out cap.
5. K6 sized to fund the K5 amounts per pay period (the transfer is the execution mechanism).

**BALANCED (synthesis, §3):** per-knob midpoints between the INCOME and RETIREMENT vectors, snapped
to each knob's grid, then re-run through SCN-07 (re-clamped, re-guarded). Balanced is always
*computed*, never averaged outcomes.

Determinism requirements: fixed grids, fixed iteration order, integer math, no `now()`-dependent
branching inside solve() (annualization happens in assembleBaseline() and is part of the cached input).

---

## 3. CONFLICT MODEL

### 3.1 Knob-level divergence detection

`ScenarioSolverService::diffKnobs(array $a, array $b): array` — a knob dimension diverges when the
difference exceeds its epsilon (all from config `optimizer-scenarios.divergence`):

| Dimension | Epsilon |
|---|---|
| `k401.deferral_pct` | ≥ 1.0 pt |
| `k401.roth_share_pct` | ≥ 25 (one grid step) |
| `hsa.annual_election_cents` | ≥ 25_000 ($250) |
| `ira.traditional_cents` / `ira.roth_cents` | ≥ ¼ of remaining room or ≥ 50_000, whichever larger |
| `transfer.per_period_cents` | ≥ 2_500 ($25) |
| `w4.*` | never diverges (identical in all scenarios by construction) |

### 3.2 Agree vs conflict

Compute the three objective vectors (income I, tax T, retirement R).
- **AGREEMENT** — `diffKnobs(I, R)` empty AND `diffKnobs(I, T)` empty: emit a SINGLE merged plan
  (`agreement = true`, one option `merged`). UI goes straight to one card + "Build my action list".
- **CONFLICT** — otherwise, emit exactly three options (D10.5):
  - **Option A — "More take-home now"** = I
  - **Option B — "More toward retirement"** = R
  - **Balanced (default highlight)** = midpoint synthesis (§2.7), EXCEPT: when `diffKnobs(T, I)`
    and `diffKnobs(T, R)` are both non-empty (the tax vector matches neither pole), Balanced is
    seeded from T instead of the midpoint and labeled "Balanced — also lowest 2026 tax"
    (rationale: T is inherently the middle path — max pre-tax = strong current-year tax AND strong
    retirement). This rule is deterministic and documented in code.
  - The option among the three with the minimum `federal_tax.annual_delta_cents` gets the
    "Lowest 2026 tax" badge (computed, never asserted).

### 3.3 Trade-off one-liners (template-based — zero Claude for figures)

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
(new method on the existing narrator — one of the two sanctioned call sites) produces the 2–3
sentence intro prose above the cards from a payload of knob names, objectives, and guard flags —
**no cents fields, ever** (same exclusion discipline as `narrateSection()`).

---

## 4. UX — comparison, pick, checklist, persistence, page placement

### 4.1 Placement in Optimize/Index.tsx

Add a fourth stage: `type ViewMode = 'findings' | 'interview' | 'scenarios' | 'report'`.
`StageIndicator` gains a "Choices" step between Interview and Report (label: **Choices**).
Flow: findings → interview → **choices (scenarios)** → report. The interview's completion state
("queue exhausted") surfaces a "See your options" CTA that jumps to the scenarios stage. The report
stage renders the chosen plan section once a choice exists (§4.7).
Design per Decisions 6/7: sw-* tokens only, Preserve-brand mode, ui-ux-pro-max + soft-skill +
frontend-design skills mandatory for the implementing task, blocking audits per D7.4.

### 4.2 Per-objective readiness header (D10.1)

Top of the scenarios stage: three readiness chips computed server-side (in the GET response):

```
Take-home optimization: Ready
Tax optimization: Ready
Retirement optimization: Answer 2 more questions
```

Readiness = required-fact sets per objective (config `optimizer-scenarios.readiness`):
- `income`: gross, pay frequency, confirmed filing status (+ withholding fact for K1 delta display)
- `tax`: income set + at least one of {401k facts, HSA eligibility facts, IRA YTD facts}
- `retirement`: tax set + age (+ match facts optional but listed as "improves accuracy")

Unready objectives render their card as a locked placeholder with the missing-question count and an
"Answer now" button that (a) calls `POST /optimizer/interview/start`, (b) the backend enqueues the
missing fact keys via a NEW additive orchestrator method
`InterviewOrchestratorService::queueFactKeys(InterviewSession $s, array $factKeys): void`
(appends to `queue`, skipping keys already in `asked` — mirrors the stale-queue self-heal), then
(c) navigates to the interview stage. Answers write UserTaxFacts through the existing
`recordAnswer()` path; returning to Choices recomputes.

### 4.3 Comparison rendering

- **Agreement case**: one full-width card "Your objectives point the same way" + merged plan +
  primary CTA "Build my action list".
- **Conflict case**: 3-up grid (`grid md:grid-cols-3 gap-4`, stacked on mobile), Balanced card
  visually pre-selected (ring-sw-accent). Card anatomy (rounded-2xl border-sw-border bg-sw-card
  shadow-sm — matches FindingSummaryCard):
  1. Header: option label + one-line objective ("Optimize for income" / "Optimize for retirement" /
     "Balanced"), badges ("Lowest 2026 tax", "Default").
  2. **Three-metric outcome block** (always all three, D10.2): Take-home Δ (per paycheck AND /yr,
     exact), Federal tax Δ (/yr, exact, signed), Retirement (+$/yr contributions + match; FV range
     "≈ $X–$Y by 65" with an Info tooltip listing `illustration.assumptions` verbatim and the word
     "Illustration" as a persistent sw-info Badge — never plain text that could read as a promise).
  3. **Knob rows**: only DIVERGING knobs are shown highlighted (bg-sw-accent-light row, per-option
     value bolded) with the §3.3 trade-off line beneath in text-sw-muted. Agreeing knobs collapse
     under "Same in every option (N)" expandable row.
  4. Guard chips when set: "Marketplace-plan guard applied" (links the shipped
     `aca_magi_management` finding narration), "Catch-up must be Roth", "HSA held (Medicare timing)".
  5. CTA: "Choose this approach".
- **Mix-your-own (D10.4 "or mixes per-knob")**: a "Customize" link expands a panel seeded from the
  selected card: segmented control per diverging knob (grid values only). Every change fires
  `POST .../compute` (deterministic, fast) and live-updates a fourth "Your mix" outcome strip.
  "Choose my mix" persists the custom vector.
- Educational rail: the page-level disclaimer block (existing pattern) plus one scenario-specific
  line above the cards: "These are approaches to consider based on facts you confirmed and current
  IRS limits. Whichever you choose, the elections are yours to make with your employer and
  institutions." (static copy, not Claude).

### 4.4 Pick-an-option flow

"Choose this approach" opens a ConfirmDialog (existing component):
- Restates the option's three metrics + the knob changes as plain rows.
- Copy: "Choosing builds your personal action checklist. You can change your mind anytime —
  choosing again replaces the checklist." The user's pick IS the confirmation (D10.6) — no extra
  attestation step.
- Confirm → `POST /optimizer/scenarios/{year}/choose` → success routes to the checklist view
  (rendered inside the Choices stage below the cards, and mirrored in the report).

### 4.5 Generated checklist (Decision 9 contract)

The choose endpoint materializes checklist items from `optimizer-scenarios.checklist_templates`,
one group per knob whose chosen value differs from current, each step: numbered, imperative, one
action, employer/portal/form named (D9.5), with an engine-computed benefit line (§2.6 attribution):

- K1: "Contact your payroll department (or your payroll portal's W-4 form) and update your filing
  status to {confirmed_status_label} and your dependents from {w4_deps} to {family_deps}." →
  benefit: "≈ +{per_paycheck}/paycheck ({annual}/yr) in take-home." **Fact-gated**: renders as a
  directive only when `profile.filing_status` confirmed and W-4 on-file facts known; otherwise the
  step is the confirm-ask (D9.2 verbatim pattern) and the directive step shows as locked-next.
- K2/K3: "Log into your 401(k) portal and set your contribution to {pct}%, split
  {trad_pct}% traditional / {roth_pct}% Roth (if your plan allows both)." → benefit lines split:
  exact take-home/tax deltas + match line "captures ≈ {match}/yr in employer match" + FV range line
  labeled illustration.
- K4: HSA election step (open-enrollment / qualifying-event caveat as fixed copy) — gated on HDHP fact.
- K5: "Contribute {trad}/{roth} to your Traditional/Roth IRA — as {n} transfers of {amount}" →
  pairs with K6.
- K6: "Set up an automatic transfer of {amount} every {period_label} from checking to savings." →
  "This automatic transfer sets aside {annual}/yr."
- Header aggregate (D9.7): "Completing these {n} actions ≈ {take_home}/mo take-home ·
  {tax}/yr in 2026 federal tax · roughly {fv_low}–{fv_high} more by {age} (illustration)."

Item persistence + done-state: owned by the Decision-9 checklist store (built in the same
implementation unit; if Part 1 defines the store, use it). Minimum contract this spec requires of
that store: `(user_id, tax_year, source_type='scenario_choice', source_id=choice fact id, knob,
step_key, directive_or_confirm, benefit_line_params(json), done_at, position)`.
**Completion writes reality facts**: checking off the K3 step supersedes `employer.contribution_pct`
(and K2 → a new `retirement.elected_roth_share_pct` fact) via `UserTaxFact::recordFact(source_type:
'user_edit')` — the CHOICE writes intent (§4.6); the CHECKBOX writes reality. Detectors
(EmployerMatchGapDetector etc.) then naturally stop firing.

### 4.6 Persistence of the chosen scenario (facts store, D10.4)

On choose, two `UserTaxFact::recordFact()` writes (source_type `user_edit`, `tax_year` set,
volatility `stable`):
- `scenario.chosen_option` → `'income'|'retirement'|'balanced'|'merged'|'custom'`
- `scenario.chosen_knobs` → JSON-encoded clamped knob vector (value column is encrypted TEXT —
  cents inside are fine; `$hidden` keeps it out of serialization).
Re-choosing supersedes (append-only chain preserves history). No new tables for the choice itself.
`MarkOptimizationReportStale` listener pattern: fire the existing report-stale path so the report
regenerates with the chosen-plan section.

### 4.7 Report integration

New section injected by `OptimizationReportGeneratorService` when `scenario.chosen_option` exists:
`section_key: 'chosen_plan'`, title "Your Chosen Approach — Action Plan", `section_type: 'topical'`,
inserted before the `documents_missing` wrapper. Contents: the option label, the three-metric
summary (figures from a fresh SCN-07 run — never stored prose), and the aggregated unlocked
checklist steps (D9.6's "User Actions Needed" aggregation). Narrator writes the section prose from
a no-dollars payload as usual. Additive to `config/optimization-report.php` sections list.

---

## 5. API SURFACE

New controller `App\Http\Controllers\Api\ScenarioController`; routes inside the existing
`auth:sanctum` v1 group, `optimizer/scenarios` prefix (NO `bank.connected` middleware — mirrors the
interview/facts/report groups so statement-upload-only users can participate).

```php
Route::prefix('optimizer/scenarios')->group(function () {
    Route::get('/{year}', [ScenarioController::class, 'show'])->middleware('throttle:30,1');
    Route::post('/{year}/compute', [ScenarioController::class, 'compute'])->middleware('throttle:60,1');
    Route::post('/{year}/choose', [ScenarioController::class, 'choose'])->middleware('throttle:10,1');
});
```
Throttles: math is deterministic and cheap (no Claude anywhere in these endpoints — the comparison
intro prose is narrated once during report generation, not here), so normal limits apply; `choose`
is tighter only because it writes facts and dispatches report-stale.

**GET /api/v1/optimizer/scenarios/{year}** — assemble baseline, run solvers, return the set.
Server caches the response per `scenarios:{user_id}:{year}:{facts_hash}` for 60s (DashboardCacheService
pattern); `facts_hash` = sha256 of the relevant current-fact ids + snapshot `profile_hash`, so any
interview answer invalidates.

```jsonc
{
  "tax_year": 2026,
  "readiness": {
    "income":     { "ready": true,  "missing_fact_keys": [] },
    "tax":        { "ready": true,  "missing_fact_keys": [] },
    "retirement": { "ready": false, "missing_fact_keys": ["income.pay_frequency", "retirement.target_age"] }
  },
  "agreement": false,
  "options": [
    {
      "key": "income", "label": "More take-home now", "badges": [],
      "knobs": { /* clamped vector, §1 */ },
      "outcome": { /* §2.4 shape */ }
    },
    { "key": "retirement", "label": "More toward retirement", "badges": [], ... },
    { "key": "balanced", "label": "Balanced", "badges": ["default", "lowest_tax"], ... }
  ],
  "knob_diffs": [
    {
      "knob": "k401.roth_share_pct",
      "values": { "income": 0, "retirement": 100, "balanced": 50 },
      "tradeoff_line": "Option A: 0% Roth — Option B: 100% Roth. A keeps about $120/mo more now; ..."
    }
  ],
  "same_knobs": ["w4", "hsa.annual_election_cents"],
  "intro_prose": null,                       // filled from report narrator cache when available
  "chosen": { "option_key": "balanced", "chosen_at": "...", "knobs": { ... } } | null,
  "disclaimer": "These are approaches to consider ... elections are yours to make."
}
```
All money fields are integer cents with `_cents` suffix (frontend divides by 100 — no decimal-string
pitfalls). Only ready objectives produce option cards; unready objectives appear in `readiness` only.

**POST /api/v1/optimizer/scenarios/{year}/compute** — body `{ "knobs": { ...vector } }` (FormRequest
`ComputeScenarioRequest`: numeric bounds, grid membership for roth_share, non-negative cents).
Returns `{ "outcome": { §2.4 } }` for the mix-your-own panel. Engine clamps regardless — validation
is UX nicety, clamping is the security boundary.

**POST /api/v1/optimizer/scenarios/{year}/choose** — body
`{ "option_key": "income"|"retirement"|"balanced"|"merged"|"custom", "knobs": {...}? }` (`knobs`
required iff `custom`; FormRequest `ChooseScenarioRequest`). Server recomputes the outcome from its
OWN solver output (never trusts client figures), writes the two facts (§4.6), materializes checklist
items (§4.5), fires report-stale. Returns
`{ "chosen": {...}, "checklist": { "header_aggregate": {...}, "items": [...] } }`.

Authorization: all three endpoints operate strictly on `auth()->id()` — no route-model binding on
user-owned rows (year is a scalar). `choose` validates `year` ∈ {currentYear, currentYear−1} like
the report controller.

---

## 6. CONFIG ADDITIONS (all additive)

**`config/tax-rules.php`** — two keys appended inside the existing `2026 → detection` block:
```php
// ── Credit for Other Dependents (W-4 Step 3 second line) ────────────────
// [CITED: IRC §24(h)(4); $500 non-refundable, not inflation-indexed]
'odc_amount' => 500,
```
(FSA health limit deliberately NOT added in v2.1 — FSA stays awareness-only until the P13
sign-off gate confirms the 2026 indexed amount; adding it later upgrades K4 without redesign.)

**NEW file `config/optimizer-scenarios.php`** (assumptions and knob metadata — NOT IRS constants):
```php
return [
    'assumptions' => [
        'illustrative_growth_rate_low'  => 0.04,   // D9.7 config growth-rate assumption (range floor)
        'illustrative_growth_rate_high' => 0.07,   // range ceiling — NEVER shown as a single number
        'default_retirement_age'        => 65,
        'aca_cliff_buffer_cents'        => 200_000, // $2,000 safety margin below the 400% FPL cliff
        'min_take_home_ratio'           => 0.90,
        'min_take_home_ratio_cash_constrained' => 0.97,
        'auto_transfer_max_surplus_share' => 0.80,
        'income_objective_transfer_share' => 0.50,
        'hsa_grid_step_cents'           => 25_000,
    ],
    'grids' => [ 'roth_share_pct' => [0, 25, 50, 75, 100], 'ira_room_fractions' => [0, 0.25, 0.5, 0.75, 1.0] ],
    'divergence' => [ /* §3.1 epsilons */ ],
    'pay_frequency_periods' => ['weekly' => 52, 'biweekly' => 26, 'semimonthly' => 24, 'monthly' => 12],
    'tax_objective_priority' => ['match_capture', 'hsa', 'trad_401k', 'trad_ira'],
    'readiness' => [ /* per-objective required fact keys, §4.2 */ ],
    'tradeoff_templates' => [ /* §3.3 */ ],
    'checklist_templates' => [ /* §4.5 step + benefit-line templates with token slots */ ],
];
```
A Pest guard test greps `ScenarioSolverService` + the new engine methods for raw threshold literals
(same pattern as the FLAG-08 materiality test) — every number must trace to config.

---

## 7. TESTS (Pest, additive)

1. **Engine unit tests** (pure, no DB): SCN-01 withholding vs hand-computed Pub-15-T-style values for
   each W-4 status; SCN-02 FICA incl. wage-base cap; SCN-03 match capture over/under threshold;
   SCN-04 FV range monotonicity + zero-horizon; SCN-05/06 MAGI + headroom; SCN-07 full-vector cases:
   (a) MFJ 3 kids W-4-misaligned baseline reproduces the owner's D9 example shape, (b) age-61
   super-catch-up clamp, (c) mandatory-Roth-catch-up reassignment, (d) Roth IRA phase-out cap,
   (e) shared-IRA-limit clamp with combined YTD, (f) Medicare HSA guard.
2. **ACA invariant property test** (§2.5): 200 randomized marketplace baselines near the cliff —
   every emitted outcome clears cliff−buffer or flags with Roth at 0.
3. **Solver determinism**: same baseline twice → identical vectors; objective dominance sanity
   (income option take-home ≥ others; retirement option retirement-delta ≥ others; tax option
   federal-tax ≤ others — allowing epsilon ties).
4. **Agreement rule**: constructed baseline where the 12% band makes all solvers converge → single
   merged option; divergent baseline → exactly 3 options + non-empty knob_diffs.
5. **API tests**: readiness gating (missing facts → locked objective), compute clamps hostile input,
   choose writes both facts + supersession on re-choose, cross-user 403 via scopeForUser, throttles.
6. **No-Claude guard**: assert zero HTTP calls in show/compute/choose (Http::fake + assertNothingSent).
7. **No-literal grep guard** (§6).

Run `php artisan test --compact` + `vendor/bin/pint --dirty` per house rules. No migrations required
by this spec (choice persists as facts; checklist store is the Decision-9 unit's one additive table).

---

## 8. Implementation order (single coherent unit per D10 sequencing)

1. Config additions (§6) + engine methods SCN-01..07 + tests (pure PHP, zero risk).
2. `ScenarioSolverService` (assembleBaseline, solve ×3, balanced synthesis, diffKnobs,
   attributeBenefits) + tests.
3. `ScenarioController` + FormRequests + routes + cache + tests.
4. Orchestrator additive `queueFactKeys()` + readiness wiring.
5. Checklist materialization (joint with the Decision-9 checklist executor — shared templates file).
6. Frontend: Choices stage (cards, mix panel, choose dialog, checklist render), StageIndicator 4th
   step, readiness chips — under the D6/D7 skill + audit regime.
7. Report `chosen_plan` section + narrator `narrateScenarioComparison()` (no-dollars payload).
