---
phase: 11-red-flag-detection-guided-interview-ai-feed-integration
plan: "08"
subsystem: optimize-my-income/detectors
tags: [FLAG-16, FLAG-17, FLAG-20, FLAG-21, FLAG-22, FLAG-23, FLAG-24, FLAG-25, tdd, detector-content]
status: complete

dependency_graph:
  requires: [11-07]
  provides: [FLAG-16, FLAG-17, FLAG-20, FLAG-21, FLAG-22, FLAG-23, FLAG-24, FLAG-25]
  affects: [RedFlagDetectorService, config/tax-detection.php, OptimizationFinding]

tech_stack:
  added: [time_critical band in bandToSeverity]
  patterns:
    - prerequisite-gated probe pattern (FLAG-05) extended to all 8 new detectors
    - TDD RED→GREEN for all 3 task groups
    - bandToSeverity extended with time_critical → critical for FLAG-16 alarms
    - SAFE-03 enforced: no estimated_value_cents in any new detector class
    - Binding liability reframes from D10/D11/CONTEXT.md verified by banned-phrase tests

key_files:
  created:
    - app/Services/Detectors/SignalProbeMatrix.php
    - app/Services/Detectors/TimeCriticalAlarmDetector.php
    - app/Services/Detectors/W2BenefitArbitrageDetector.php
    - app/Services/Detectors/PublicSectorRetirementDetector.php
    - app/Services/Detectors/ReimbursementRoutingRule.php
    - app/Services/Detectors/IraToHsaQfdProbe.php
    - app/Services/Detectors/AcaCliffMonitor.php
    - app/Services/Detectors/RefundableCreditScanner.php
    - tests/Feature/SignalProbeMatrixTest.php
    - tests/Feature/W2BenefitDetectorsTest.php
    - tests/Feature/AcaCliffAndCreditsTest.php
  modified:
    - app/Services/TaxRulesEngineService.php (added time_critical band)
    - app/Services/RedFlagDetectorService.php (registered 8 new detectors)
    - config/tax-detection.php (added aca_cliff_cents, 14 new rule entries)

decisions:
  - Add time_critical band to bandToSeverity() mapping to critical severity for FLAG-16 alarms (83b/QOF/QSBS). This is additive and does not break existing tests.
  - ACA cliff monitor emits two findings: aca_magi_management at auto band (HIGH, sequences before Trad-vs-Roth per 2B.1) and aca_cliff_awareness at conditional band. Band-based ordering enforces the mandatory sequencing rule.
  - Saver's Match date-gate implemented as a class constant (SAVERS_MATCH_TAX_YEAR=2027) checked at runtime — no config needed for a single numeric gate.
  - PublicSectorRetirementDetector: non-governmental creditor risk caveat positioned as the very first sentence before any '457' mention, enforced by a character-position ordering test.

metrics:
  duration_minutes: ~90
  tasks_completed: 3
  files_created: 11
  files_modified: 3
  tests_added: 45
  tests_total_before: 641
  tests_total_after: 686
  new_failures: 0
  completed_date: "2026-07-02"
---

# Phase 11 Plan 08: FINAL Detector-Content Wave Summary

**One-liner:** 8 prerequisite-gated detector classes loading FLAG-16/17/20/21/22/23/24/25 onto the green harness — signal-probe matrix, time-critical alarms, W-2 benefit arbitrage, public-sector retirement, reimbursement routing, IRA→HSA QFD probe, ACA cliff monitor, and refundable-credit scanner; all deterministic, educational, SAFE-03-clean.

## What Was Built

### Task 1: Signal-Probe Matrix + Time-Critical Alarms (FLAG-17, FLAG-16)

**SignalProbeMatrix** — 8 prerequisite-gated probes implementing the PB-v1 §10 signal→question→strategy table:
1. `probe_deferral_gap` — payroll + no/low 401k deferral
2. `probe_se_income` — Schedule C education suite
3. `probe_solo_401k` — SE net > $10K with mandatory employee gate
4. `probe_entity_analysis` — SE net > $50K; 60-month lock FIRST (D10 binding)
5. `probe_qbi_high_income` — above-threshold sentinel only (D11 binding, no W-2/UBIA)
6. `probe_income_drop_roth` — income-drop 30%+ vs prior year; income-keyed only (CR-2 excluded)
7. `probe_iso_amt` — `equity.has_iso` fact; safe one-liner only (default ruling 2)
8. `probe_529_education` — has_children + no 529; federal-only (D10)

**TimeCriticalAlarmDetector** — 3 critical-severity alarms (time_critical band → severity=critical):
- `alarm_83b_election` — 30-day window from restricted stock grant date; "30-day hard deadline" as fact
- `alarm_qof_recognition` — pre-2027 QOF investors must recognize deferred gain by Dec 31, 2026
- `alarm_qsbs_eligibility` — C-corp formation + recent (<12mo); §1244 note mandatory

### Task 2: W-2 Benefit Arbitrage + Public-Sector + Reimbursement + QFD (FLAG-20, FLAG-21, FLAG-25, FLAG-24)

**W2BenefitArbitrageDetector** — W-2-gated benefit gap detection:
- `w2_benefit_hsa_gap` — employer offers HSA, user not enrolled
- `w2_benefit_espp_participation` — ESPP participation education; "free money" and "guaranteed return" banned; no disposition modeling
- `w2_benefit_nqdc_education` — mandatory employer-credit-risk warning in treatment
- `w2_benefit_mega_backdoor` — "if your plan allows" gate; maxing employee deferral signal

**PublicSectorRetirementDetector** — employer-type-gated (school/university/hospital/nonprofit/government/police/fire):
- `ps_457b_education` — non-governmental: creditor risk caveat as FIRST sentence before '457'; governmental: separate trust, no creditor caveat; 3-year catch-up question always included

**ReimbursementRoutingRule** — W-2 employee expense routing:
- `reimbursement_routing_w2` — "consider asking your employer" framing; never "deduct this"
- `reimbursement_survivor_reservist` — surviving above-the-line category; deduction education (Schedule 1 line 12)

**IraToHsaQfdProbe** — strict 5-gate prerequisite:
- Gate 1: `health.hsa_eligible = true`
- Gate 2: `retirement.has_ira_balance = true`
- Gate 3: `finance.is_cash_constrained = true`
- Gate 4: `health.has_medical_expenses = true` (or `wants_to_fund_hsa`)
- Gate 5: `health.qfd_previously_used = false` (one-time lifetime limit)
- Treatment always includes: "does not create an extra deduction, and testing-period rules apply"

### Task 3: ACA Cliff Monitor + Refundable Credit Scanner (FLAG-22, FLAG-23)

**AcaCliffMonitor** — marketplace premium detection + FPL cliff proximity:
- Gate: `marketplace.pays_marketplace_premiums = true`
- Income proximity check: 70% of FPL cliff to warning zone top
- `aca_magi_management` at **auto band** (HIGH severity) — sequences BEFORE Trad-vs-Roth per 2B.1 binding rule
- `aca_cliff_awareness` at conditional band — awareness framing; NEVER a computed subsidy or clawback amount

**RefundableCreditScanner** — deterministic eligibility signals:
- `credit_eitc` — with mandatory investment-income-limit caveat (~$11,950/2026); investment income gate suppresses when over limit
- `credit_ctc` — qualifying children under 17; phaseout at $200K/$400K
- `credit_savers` — Saver's Credit with Saver's Match DATE-GATED to 2027 (SECURE 2.0 §103)
- All findings: "may be eligible" framing only, never "you qualify" / "you are eligible"

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| 1 RED | b3fafe5 | test(11-08): RED — SignalProbeMatrixTest |
| 1 GREEN | 268f8cf | feat(11-08): SignalProbeMatrix + TimeCriticalAlarmDetector |
| 2 RED | 7041260 | test(11-08): RED — W2BenefitDetectorsTest |
| 2 GREEN | dc4b59e | feat(11-08): W2BenefitArbitrageDetector + PublicSectorRetirementDetector + ReimbursementRoutingRule + IraToHsaQfdProbe |
| 3 RED | 19721b5 | test(11-08): RED — AcaCliffAndCreditsTest |
| 3 GREEN | 9c2d540 | feat(11-08): AcaCliffMonitor + RefundableCreditScanner |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `bandToSeverity()` did not support `time_critical` band**
- **Found during:** Task 1 GREEN — TimeCriticalAlarmDetector tests expected `severity='critical'`
- **Issue:** `bandToSeverity()` only produced `high` (auto) and `medium` (conditional/specialist); alarms needed `critical`
- **Fix:** Added `'time_critical' => 'critical'` case to `TaxRulesEngineService::bandToSeverity()` with updated error message. Additive change; existing tests unaffected.
- **Files modified:** `app/Services/TaxRulesEngineService.php`
- **Commit:** 268f8cf

**2. [Rule 1 - Bug] Entity probe treatment contained "60 months" not "60-month"**
- **Found during:** Task 1 GREEN — test `toContain('60-month')` failed on "60 months" text
- **Fix:** Changed treatment to use "60-month period" to match binding test assertion
- **Files modified:** `app/Services/Detectors/SignalProbeMatrix.php`
- **Commit:** 268f8cf

**3. [Rule 1 - Bug] Income-drop treatment contained "asset values" phrase**
- **Found during:** Task 1 GREEN — CR-2 banned phrase test failed
- **Fix:** Rewrote treatment to use "income signals only" instead of "not from portfolio or asset values" (which contained the banned phrase "asset values")
- **Files modified:** `app/Services/Detectors/SignalProbeMatrix.php`
- **Commit:** 268f8cf

**4. [Rule 1 - Bug] PublicSectorRetirementDetector treatment had '457' before 'creditor'**
- **Found during:** Task 2 GREEN — ordering test `creditorPos < deferPos` failed
- **Fix:** Restructured non-governmental treatment to lead with "employer creditor risk" without mentioning 457 until after the caveat
- **Files modified:** `app/Services/Detectors/PublicSectorRetirementDetector.php`
- **Commit:** dc4b59e

**5. [Rule 1 - Bug] Mega-backdoor treatment used 'If' (capital) not 'if' (lowercase)**
- **Found during:** Task 2 GREEN — `toContain('if your plan allows')` case-sensitive check failed
- **Fix:** Restructured treatment to place "if your plan allows" mid-sentence (lowercase)
- **Files modified:** `app/Services/Detectors/W2BenefitArbitrageDetector.php`
- **Commit:** dc4b59e

**6. [Rule 1 - Bug] AcaCliffMonitor treatment contained 'Consider' (capital C) not 'consider' (lowercase)**
- **Found during:** Task 3 GREEN — `toContain('consider')` case-sensitive check failed
- **Fix:** Rewrote sentence to use lowercase 'consider' mid-sentence
- **Files modified:** `app/Services/Detectors/AcaCliffMonitor.php`
- **Commit:** 9c2d540

**7. [Rule 1 - Bug] Saver's Credit income ceiling too restrictive ($23K instead of $38,250)**
- **Found during:** Task 3 GREEN — Saver's Match 2027 test failed (income $25K > $23K ceiling)
- **Fix:** Increased `SAVERS_MAX_INCOME_SINGLE_CENTS` to $38,250 (covers all credit rate brackets)
- **Files modified:** `app/Services/Detectors/RefundableCreditScanner.php`
- **Commit:** 9c2d540

## Threat Surface Scan

All findings from new detectors carry educational framing with no user-specific financial computations. No new network endpoints, auth paths, file access patterns, or schema changes introduced. The `time_critical` band addition is additive to `bandToSeverity()` and does not affect existing band behavior.

| Threat ID | Verified Mitigation |
|-----------|---------------------|
| T-11-08-01 | entity probe 60-month-lock-first tested; AMT one-liner only; MFS ceiling line only — banned-phrase tests green |
| T-11-08-02 | ACA finding contains no subsidy/clawback dollar strings — tested with not-toContain assertions |
| T-11-08-03 | "may be eligible" throughout; "you qualify" banned-phrase test across all credit findings |
| T-11-08-04 | SAFE-03 grep-gate: no `estimated_value_cents` in any new detector file (confirmed) |
| T-11-08-05 | non-governmental 457(b) creditor risk caveat position tested by character-index ordering assertion |
| T-11-08-SC | Zero new packages installed |

## Known Stubs

None — all 8 detectors emit real educational content from verified durable facts. The EITC, CTC, and Saver's Credit scanners use simplified income-ceiling gates (class constants) rather than the full IRS bracket tables; these are conservative (may miss some edge-case eligibility) but will not over-assert. A future TAX-08 pass can replace constants with config-driven full tables.

## Self-Check

Files created:
- `app/Services/Detectors/SignalProbeMatrix.php` FOUND
- `app/Services/Detectors/TimeCriticalAlarmDetector.php` FOUND
- `app/Services/Detectors/W2BenefitArbitrageDetector.php` FOUND
- `app/Services/Detectors/PublicSectorRetirementDetector.php` FOUND
- `app/Services/Detectors/ReimbursementRoutingRule.php` FOUND
- `app/Services/Detectors/IraToHsaQfdProbe.php` FOUND
- `app/Services/Detectors/AcaCliffMonitor.php` FOUND
- `app/Services/Detectors/RefundableCreditScanner.php` FOUND
- `tests/Feature/SignalProbeMatrixTest.php` FOUND
- `tests/Feature/W2BenefitDetectorsTest.php` FOUND
- `tests/Feature/AcaCliffAndCreditsTest.php` FOUND

Commits verified: b3fafe5, 268f8cf, 7041260, dc4b59e, 19721b5, 9c2d540 — all in git log.

## Self-Check: PASSED
