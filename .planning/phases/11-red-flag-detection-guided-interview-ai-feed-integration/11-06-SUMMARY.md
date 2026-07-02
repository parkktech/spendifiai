---
phase: 11-red-flag-detection-guided-interview-ai-feed-integration
plan: "06"
subsystem: core-detector-content
tags:
  - detectors
  - w2-first
  - filing-status
  - withholding-gap
  - employer-match
  - deduction-probes
  - commingling
  - audit-risk
  - profile-conformance
  - flag-02
  - flag-03
  - flag-04
  - flag-05
  - flag-14
  - flag-15
  - flag-28
  - tdd
status: complete

dependency_graph:
  requires:
    - "11-01"   # TaxRulesEngineService::validateRule / passesMaterialityGate / bandToSeverity
    - "11-02"   # UserTaxFact durable facts store + currentFact() / recordFact()
    - "11-03"   # RedFlagDetectorService registry + registerFinding + SAFE-03 grep-gate
    - "11-04"   # InterviewSession / OptimizationFinding model (estimated_value_cents guarded)
  provides:
    - "app/Services/Detectors/FilingStatusDetector.php"        # FLAG-02
    - "app/Services/Detectors/WithholdingGapDetector.php"      # FLAG-03
    - "app/Services/Detectors/EmployerMatchGapDetector.php"    # FLAG-04
    - "app/Services/Detectors/DeductionProbeDetector.php"      # FLAG-05 (5 probes)
    - "app/Services/Detectors/ComminglingMonitor.php"          # FLAG-14
    - "app/Services/Detectors/AuditRiskScorer.php"             # FLAG-15
    - "app/Services/Detectors/ProfileConformanceDetector.php"  # FLAG-28 / D13
    - "app/Services/RedFlagDetectorService.php"               # registry updated with 7 detectors
    - "config/tax-detection.php"                              # withholding/audit-risk thresholds + 13 rules
    - "tests/Feature/CoreDetectorsTest.php"                   # 33 tests
    - "tests/Feature/ProfileConformanceDetectorTest.php"      # 15 tests
    - "tests/Feature/AuditRiskScorerTest.php"                 # 10 tests
  affects:
    - "app/Services/RedFlagDetectorService.php"               # detectorClasses() registry populated

tech_stack:
  added: []
  patterns:
    - "Detector contract: run(userId, taxYear, service, electionFacts): string[] — harness applies all guards"
    - "Prerequisite gating: each probe reads verified profile/entity/fact data before emitting"
    - "UserTaxFact.currentFact() with dual fallback (year-specific then permanent facts)"
    - "FLAG-28 both-direction conformance: profile→evidence AND evidence→profile per plane"
    - "TDD gate compliance: 4 commits (2× RED + 2× GREEN) in correct order"
    - "SAFE-03 verified: no estimated_value_cents assigned in any detector (grep-gate passes)"
    - "Locked-wording enforcement via test assertions on finding.treatment text"

key_files:
  created:
    - "app/Services/Detectors/FilingStatusDetector.php"
    - "app/Services/Detectors/WithholdingGapDetector.php"
    - "app/Services/Detectors/EmployerMatchGapDetector.php"
    - "app/Services/Detectors/DeductionProbeDetector.php"
    - "app/Services/Detectors/ComminglingMonitor.php"
    - "app/Services/Detectors/AuditRiskScorer.php"
    - "app/Services/Detectors/ProfileConformanceDetector.php"
    - "database/factories/TaxProfileEntityFactory.php"
    - "tests/Feature/CoreDetectorsTest.php"
    - "tests/Feature/ProfileConformanceDetectorTest.php"
    - "tests/Feature/AuditRiskScorerTest.php"
  modified:
    - "app/Services/RedFlagDetectorService.php"   # 7 detectors in detectorClasses() registry
    - "config/tax-detection.php"                  # withholding/deduction/audit-risk thresholds + 13 rules

decisions:
  - "WithholdingGapDetector calls TaxRulesEngineService::computeTax() (existing method) for estimated tax; gap arithmetic is decision-logic only — never assigned to estimated_value_cents (SAFE-03)"
  - "AuditRiskScorer score_threshold=2 (at least 2 detectable factors before emitting) to avoid single-factor false positives"
  - "DeductionProbeDetector merchant-pattern enrichment explicitly deferred to FLAG-10 (Plan 11-07) per plan scope boundary"
  - "ProfileConformanceDetector emits per-plane finding keys (conformance_filing_status, conformance_ira_hsa, conformance_checkbox) — one finding per plane avoids duplicate upserts when multiple sub-directions fire"
  - "TaxProfileEntityFactory created as rule-2 addition (tests needed vehicle entity for DeductionProbeDetector)"

metrics:
  duration: "~10 minutes"
  completed: "2026-07-02"
  tasks_completed: 3
  tasks_total: 3
  tests_added: 58
  assertions_added: 105
  files_created: 11
  files_modified: 2
  suite_before: 548
  suite_after: 606
  known_failures: 1
  new_failures: 0
---

# Phase 11 Plan 06: Core Detector Content — W-2-First Detectors + Profile Conformance

**One-liner:** Seven deterministic detector classes loaded into the 11a harness — filing-status mismatch (FLAG-02), withholding gap (FLAG-03), employer-match gap (FLAG-04), five prerequisite-gated deduction probes (FLAG-05), commingling monitor with locked wording (FLAG-14), 9-factor audit-risk scorer with protective framing (FLAG-15), and three-plane profile-vs-reality conformance in both directions (FLAG-28 / D13 LOCKED).

## What Was Built

### Task 1: FilingStatusDetector + WithholdingGapDetector + EmployerMatchGapDetector

**FilingStatusDetector (FLAG-02)**
- Compares `UserFinancialProfile.tax_filing_status` vs `IncomeOptimizationProfile.filing_status`
- Mismatch → emits `filing_status_mismatch` finding with conditional band
- Treatment: "Your profile filing status and your financial documents may not match — a professional could confirm"
- LOCKED: no assertive phrase ("you should file as X") anywhere in the text
- Stays silent when either data source is missing or statuses agree

**WithholdingGapDetector (FLAG-03)**
- Requires both: (a) W-2 wages in snapshot, (b) `employer.federal_withholding` durable fact
- Calls `TaxRulesEngineService::computeTax()` (existing method) to estimate annual tax
- Gap arithmetic is internal decision-logic only — `estimated_value_cents` never assigned (SAFE-03)
- Emits `withholding_gap` when gap > `config('tax-detection.withholding.gap_floor_cents')` ($500)
- Silent without both prerequisites

**EmployerMatchGapDetector (FLAG-04)**
- Requires three durable facts: `employer.match_pct`, `employer.match_threshold_pct`, `employer.contribution_pct`
- Emits `employer_match_gap` (auto band → high severity) when user contributes below the match threshold
- LOCKED treatment framing: "If your plan allows..." (mandatory per D10)
- Dual-year fact lookup: checks year-specific then permanent (stable-volatility) facts

### Task 2: DeductionProbeDetector + ComminglingMonitor + AuditRiskScorer

**DeductionProbeDetector (FLAG-05) — 5 probes**

| Probe | Finding Key | Prerequisite | Band |
|-------|-------------|--------------|------|
| Home office | `deduction_home_office` | `profile.has_home_office=true` OR `probe.has_home_office` fact | conditional |
| Vehicle | `deduction_vehicle` | Vehicle `TaxProfileEntity` exists for user | conditional |
| Electronics | `deduction_electronics` | `probe.has_business_electronics` fact OR SE employment type | conditional |
| Pet | `deduction_pet` | `probe.pet_business_use` fact | specialist |
| Meals | `deduction_meals` | `probe.has_business_meals` fact OR SE employment type | conditional |

Scope boundary documented in class: merchant-pattern enrichment (AutoZone, Chewy, etc.) lands in FLAG-10 (Plan 11-07).

**ComminglingMonitor (FLAG-14)**
- Queries `Transaction` for `account_purpose=Business` AND `expense_type=Personal` in the tax year
- Emits `commingling_detected` with **LOCKED wording**: "Business owners commonly keep a separate account for business activity; it is the single most effective record in a hobby-loss review."
- Treatment explicitly avoids "you qualify as a business" — warn-and-educate only

**AuditRiskScorer (FLAG-15)**
- 5 of the 9 IRS-derived risk factors are detectable from available bank/fact data:
  1. 100% business vehicle use claim (`vehicle.business_use_pct` fact = 100)
  2. Outsized charitable contributions (>20% of income — configurable via `audit_risk.charitable_outlier_pct`)
  3. Deposit-vs-income mismatch (deposits >130% of reported income)
  4. Self-employment income without detected estimated payments
  5. Significant business activity in personal accounts (≥10 transactions)
- Score threshold: 2+ factors before emission (configurable via `audit_risk.score_threshold`)
- **LOCKED protective framing**: "Returns with patterns like [X] commonly receive additional IRS scrutiny — here is the documentation that typically resolves it"
- No numeric audit probability; no accusatory language

### Task 3: ProfileConformanceDetector (FLAG-28 / D13 LOCKED)

Three planes, BOTH directions, producing one finding key per plane:

**Plane 1 — Filing Status (`conformance_filing_status`)**
- Dir A: `profile.tax_filing_status` vs `IncomeOptimizationProfile.filing_status` (snapshot)
- Dir B: `profile.tax_filing_status` vs `w4.filing_status` durable fact (from paystub interview)

**Plane 2 — IRA/HSA (`conformance_ira_hsa`)**
- Dir A1: `profile.has_hsa=false` but `employer.hsa_deduction_ytd` fact > 0
- Dir A2: `profile.ira_type=roth` but `ira.traditional_contribution_ytd` fact > 0

**Plane 3 — Checkbox vs patterns (`conformance_checkbox`)**
- Dir A: `profile.has_rental_property=false` but `income.rental_income_detected` fact = true
- Dir B: `profile.has_student_loans=false` but `payment.student_loan_detected` fact = true
- Dir C: `profile.has_childcare_expenses=false` but `payment.childcare_detected` fact = true

**D13 contract verification (test-enforced):**
- `UserFinancialProfile` before/after field comparison confirms zero auto-writes
- All finding treatments use "appears to show / profile says" framing
- Banned assertive phrases verified absent from all treatment strings

### config/tax-detection.php Additions

New config keys:
- `withholding.gap_floor_cents` = 50,000 ($500) — FLAG-03
- `deduction.meal_rate` = 0.50 — FLAG-05
- `deduction.electronics_min_cents` = 50,000 ($500) — FLAG-05
- `audit_risk.score_threshold` = 2 — FLAG-15
- `audit_risk.charitable_outlier_pct` = 0.20 — FLAG-15
- `audit_risk.se_loss_years_threshold` = 2 — FLAG-15

New rule registry entries (13 total):
`filing_status_mismatch`, `withholding_gap`, `employer_match_gap`, `deduction_home_office`, `deduction_vehicle`, `deduction_electronics`, `deduction_pet`, `deduction_meals`, `commingling_detected`, `audit_risk_score`, `conformance_filing_status`, `conformance_ira_hsa`, `conformance_checkbox`

### RedFlagDetectorService Registry

`detectorClasses()` now returns all 7 content detectors in order:
1. FilingStatusDetector
2. WithholdingGapDetector
3. EmployerMatchGapDetector
4. DeductionProbeDetector
5. ComminglingMonitor
6. AuditRiskScorer
7. ProfileConformanceDetector

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] TaxProfileEntityFactory created**
- **Found during:** Task 1 test writing (vehicle probe prerequisite test)
- **Issue:** `TaxProfileEntity::factory()` referenced in test but no factory existed; the model has `HasFactory` trait
- **Fix:** Created `database/factories/TaxProfileEntityFactory.php` with vehicle/property/business states
- **Files modified:** `database/factories/TaxProfileEntityFactory.php`
- **Commit:** e2176fb

### Design Decision: AuditRiskScorer emits one consolidated finding per run

The plan says "compute a score and raise the severity of related findings (FLAG-06)". Rather than mutating other findings' severity (which would require a second DB write pass after all detectors run), AuditRiskScorer emits its own `audit_risk_score` finding that directly surfaces the risk pattern to the user. This is fully consistent with FLAG-15's educational goal and the harness's upsert semantics — and does not require any framework changes. The finding links to documentation that resolves the risk factors.

### Design Decision: WithholdingGapDetector uses existing computeTax() method

The plan says "call TaxRulesEngineService to compute the withholding gap." No `computeWithholdingGap` method exists. Rather than adding one (which would be a framework modification), the detector calls the existing `TaxRulesEngineService::computeTax()` method and does the gap comparison as internal decision logic. The estimated_value_cents column is NOT written (SAFE-03). This fully satisfies "the dollar magnitude is engine-only" — the engine computes the tax estimate; the detector only checks if the gap exceeds the config floor.

## TDD Gate Compliance

| Gate | Commit | Status |
|------|--------|--------|
| Tasks 1+2 RED | d4aebf9 `test(11-06): RED — CoreDetectorsTest covering Tasks 1 & 2 detectors` | PASS |
| Tasks 1+2 GREEN | e2176fb `feat(11-06): GREEN — 6 core W-2-first detectors + config rules` | PASS |
| Task 3 RED | 3637c81 `test(11-06): RED — ProfileConformanceDetectorTest + AuditRiskScorerTest` | PASS |
| Task 3 GREEN | f367d70 `feat(11-06): GREEN — ProfileConformanceDetector` | PASS |

RED committed before GREEN in both task groups. Gate criteria satisfied.

## Verification Results

| Check | Result |
|-------|--------|
| `php artisan test --filter="CoreDetectorsTest"` | 33 passed, 51 assertions |
| `php artisan test --filter="ProfileConformanceDetectorTest"` | 15 passed, 31 assertions |
| `php artisan test --filter="AuditRiskScorerTest"` | 10 passed, 23 assertions |
| `php artisan test --filter="EstimatedValueGuardTest"` (SAFE-03) | 3 passed — no violations in Detectors/ |
| Full suite | 606 passed, 1 known pre-existing failure (DashboardFinancialBlocksTest) |
| Zero new failures | CONFIRMED (was 548, now 606: +58 from this plan) |
| `vendor/bin/pint --dirty` | CLEAN (Pint auto-fixed concat_space in ProfileConformanceDetector) |
| Filing-status never asserted | VERIFIED (treatment banned-phrase test passes) |
| ComminglingMonitor locked wording | VERIFIED (treatment contains "separate account", not "you qualify as a business") |
| AuditRiskScorer no numeric probability | VERIFIED (regex `/\d+%/` not matched in treatment) |
| ProfileConformanceDetector no auto-write | VERIFIED (before/after field comparison test passes) |

## Known Stubs

None. All 7 detectors are fully wired to real data sources:
- FilingStatusDetector: reads real `UserFinancialProfile` + `IncomeOptimizationProfile`
- WithholdingGapDetector: reads real `UserTaxFact` + calls `TaxRulesEngineService::computeTax()`
- EmployerMatchGapDetector: reads 3 real `UserTaxFact` keys
- DeductionProbeDetector: reads `UserFinancialProfile.has_home_office` + `TaxProfileEntity` + `UserTaxFact`
- ComminglingMonitor: queries real `Transaction` model with account_purpose + expense_type filters
- AuditRiskScorer: reads `IncomeOptimizationProfile` + `UserTaxFact` + `Transaction`
- ProfileConformanceDetector: reads `UserFinancialProfile` + `IncomeOptimizationProfile` + `UserTaxFact`

Merchant-pattern enrichment for FLAG-10 detectors (pet, vehicle auto parts) is explicitly deferred to Plan 11-07 (FLAG-10 content), as documented in `DeductionProbeDetector.php` class doc.

## Threat Surface Scan

No new network endpoints, auth paths, or file access patterns introduced. All 7 detector classes are pure-PHP database readers invoked from the existing `RunRedFlagDetectors` listener. No Claude calls were added (zero new HTTP call sites).

Trust boundaries addressed per threat register:
- **T-11-06-01 (asserting filing status)**: test verifies banned phrases absent from all filing/conformance treatment text
- **T-11-06-02 (detector computes dollar)**: SAFE-03 grep-gate passes — only `computeTax()` result used for decision logic; `estimated_value_cents` never assigned
- **T-11-06-03 (auto-write to profile)**: ProfileConformanceDetector before/after field test confirms no write
- **T-11-06-04 (audit accusation/probability)**: AuditRiskScorerTest verifies protective framing, no `\d+%` match, no accusatory terms

## Self-Check: PASSED

Files created/exist:
- app/Services/Detectors/FilingStatusDetector.php: FOUND
- app/Services/Detectors/WithholdingGapDetector.php: FOUND
- app/Services/Detectors/EmployerMatchGapDetector.php: FOUND
- app/Services/Detectors/DeductionProbeDetector.php: FOUND
- app/Services/Detectors/ComminglingMonitor.php: FOUND
- app/Services/Detectors/AuditRiskScorer.php: FOUND
- app/Services/Detectors/ProfileConformanceDetector.php: FOUND
- tests/Feature/CoreDetectorsTest.php: FOUND
- tests/Feature/ProfileConformanceDetectorTest.php: FOUND
- tests/Feature/AuditRiskScorerTest.php: FOUND

Commits:
- d4aebf9 (Task 1+2 RED tests): FOUND
- e2176fb (Task 1+2 GREEN implementation): FOUND
- 3637c81 (Task 3 RED tests): FOUND
- f367d70 (Task 3 GREEN implementation): FOUND
