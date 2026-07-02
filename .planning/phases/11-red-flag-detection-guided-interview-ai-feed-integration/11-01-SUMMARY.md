---
phase: "11"
plan: "01"
subsystem: "tax-rules-engine"
status: complete
tags:
  - config
  - tax-rules
  - versioned-rules
  - materiality-gates
  - detection-foundation

dependency_graph:
  requires:
    - "10-01"  # TaxRulesEngineService base service
    - "10-02"  # TaxYear model + config structure
  provides:
    - "config/tax-detection.php"   # versioned rule registry + materiality config
    - "TaxRulesEngineService::validateRule()"
    - "TaxRulesEngineService::passesMaterialityGate()"
    - "TaxRulesEngineService::bandToSeverity()"
  affects:
    - "All Phase 11 detectors (read config/tax-detection.php and config/tax-rules.php detection block)"

tech_stack:
  added:
    - "config/tax-detection.php — versioned-rule schema (TAX-09) + materiality gates (FLAG-08)"
  patterns:
    - "Year-keyed config (2026.detection.*) for TAX-08 constants — zero literals in service code"
    - "Versioned-rule registry: rule_id/authority/effective_start/effective_end/band/status schema"
    - "Band enum: auto|conditional|specialist|suppress|hard_block drives finding severity"
    - "passesMaterialityGate reads all thresholds from config — SAFE-03 verified by grep-gate test"
    - "Carbon::setTestNow() for sunset boundary testing without date literals in assertions"

key_files:
  created:
    - "config/tax-detection.php"
    - "tests/Unit/TaxConfigExtensionTest.php"
    - "tests/Unit/DetectorRuleExpirationTest.php"
  modified:
    - "config/tax-rules.php  (additive: 2026.detection block appended)"
    - "app/Services/TaxRulesEngineService.php  (additive: 3 new public methods)"
    - "tests/Pest.php  (additive: extended Unit test case binding to Unit/ root)"

decisions:
  - "IRA shared limit annotated as COMBINED Roth+Traditional (D3 correctness) in detection block comment — $7,500 base + $1,100 catch-up 2026"
  - "passesMaterialityGate auto-floor does NOT apply to recurring patterns (spec: '$100 single-txn floor UNLESS recurring') — logic reordered from initial draft"
  - "Pest.php extended from Unit/Services only to full Unit/ directory — Rule 3 auto-fix to enable Http::preventStrayRequests() + Config facade in plan 11 root unit tests"
  - "bandToSeverity is additive on TaxRulesEngineService (not a new class) per architectural guidance from 10-01"
  - "[ASSUMED] values marked in config/tax-rules.php with [ASSUMED] inline comments for P13 sign-off gate — 11 values require IRS confirmation"

metrics:
  duration: "~45 minutes"
  completed: "2026-07-01"
  tasks_completed: 3
  tasks_total: 3
  tests_added: 105
  assertions_added: 242
  files_created: 3
  files_modified: 3
---

# Phase 11 Plan 01: Versioned Rule Schema + Materiality Gates Summary

**One-liner:** Config-driven detection foundation with versioned rule registry (10 rules, TAX-09 schema), TAX-08 detection constants (70+ IRS values), and FLAG-08 materiality gates ($100/$500yr/$1K) enforced by TaxRulesEngineService with zero hardcoded literals in service code.

## What Was Built

### TAX-08: Config Detection Block (config/tax-rules.php)

Added additive `2026.detection` block to the existing year-keyed config. Contains 70+ IRS constants used by all Phase 11 detectors:

- **Retirement plan limits:** IRA shared limit ($7,500 + $1,100 catch-up, D3 note), 457(b)/403(b) $24,500, solo-401(k) 20% share, §415(c) ~$72K [ASSUMED], cash-balance $150K–$350K min/max with age gate 45
- **OBBBA provisions:** Tips deduction cap $25K (phaseout $150K/$300K), OT deduction $12.5K/$25K [ASSUMED], senior deduction $6K (age 65+, $75K/$150K MAGI cap)
- **New deductions:** Auto-loan interest $10K cap, SALT $40K cap, charitable non-itemizer $1K/$2K, QCD $108K [ASSUMED], saver match starts 2027, §127 $5,250
- **Credits:** CTC $2,200, adoption ~$17K [ASSUMED], AOTC $2,500, 529 k12 $20K/yr, 529→Roth lifetime $35K, Trump Account $5K/$2.5K/$1K
- **Business:** §179 $2.56M limit, §195 $5K immediate, de minimis $2,500, home-office simplified $5/sqft cap $1,500, mileage 72.5¢, MACRS periods (5/15/7 yr)
- **Investor/estate:** LTCG zero bracket $49K/$98K [ASSUMED], NIIT 3.8% on $200K/$250K, gift exclusion $19K [ASSUMED], estate $15M/$30M [ASSUMED], QSBS $15M cap, §1244/§1341
- **Medical/other:** AGI floor 7.5%, lodging $50/night, student loan $2,500, educator $300, FEIE ~$130K [ASSUMED], Augusta 14-day, ACA FPL 400% [ASSUMED]
- **Suppress-only:** gambling loss 90% factor (band=suppress in rules registry — never renders finding)

### TAX-09: Versioned Rule Registry (config/tax-detection.php)

New sibling config file with full TAX-09 schema for 10 rules:

| Rule ID | Authority | Effective End | Band | Notes |
|---------|-----------|---------------|------|-------|
| tips_deduction | IRC §224 (OBBBA) | 2028-12-31 | auto | MAGI phaseout $150K/$300K |
| ot_deduction | IRC §225 (OBBBA) | 2028-12-31 | conditional | W-2 box TP/TT required |
| senior_deduction | IRC §226 (OBBBA) | 2028-12-31 | conditional | Age 65+ gate |
| auto_loan_interest | IRC §163(h) (OBBBA) | 2028-12-31 | conditional | New vehicle gate |
| salt_deduction_cap | IRC §164(b)(6) (OBBBA) | 2029-12-31 | auto | $40K cap |
| qof_recognition | IRC §1400Z-2 | 2026-12-31 | specialist | Mandatory recognition year |
| residential_energy_credit_25d | IRC §25D | 2025-12-31 | conditional | Retro scanner only (expired) |
| ev_credit_30d | IRC §30D | 2025-09-30 | conditional | Pre-Oct-2025 only (expired) |
| residential_solar_2026_primary_home | — | null | suppress | Never surface primary-home solar 2026+ |
| gambling_losses_fully_deductible | — | null | suppress | Never surface as "deductible" |

Also includes: materiality thresholds (cents), confidence band cutpoints, facts reconfirm timing, staleness_days 90, onboarding_history_months 36, 24 doc_request_labels.

### FLAG-08: Materiality Gates (TaxRulesEngineService)

Three additive methods on the existing service:

**`validateRule(string $ruleId): array`**
- Reads rule from config('tax-detection.rules.{ruleId}')
- Returns: `{suppressed, band, status, stale}`
- `suppressed=true` when: today > effective_end OR band in [suppress, hard_block]
- `stale=true` when: now - last_verified > staleness_days (via Carbon diffInDays)
- Throws InvalidArgumentException on unknown ruleId

**`passesMaterialityGate(int $amountCents, bool $isRecurring, int $annualTotalCents, bool $addressMatch = false, bool $loanServicer = false): bool`**
- Always true: address-matched, loan-servicer
- Recurring: gates on annualTotalCents >= recurring_pattern_annual_cents (auto-floor bypassed)
- Single-txn: auto-floor false if < single_txn_auto_floor_cents; true if >= single_txn_interrogate_cents
- All thresholds from config — no literals (SAFE-03 verified by grep-gate test)

**`bandToSeverity(string $band): string`**
- Deterministic: auto→high, conditional→medium, specialist→medium, suppress→suppressed, hard_block→blocked
- Throws on unknown band

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed passesMaterialityGate auto-floor applying to recurring patterns**
- **Found during:** Task 3 test run
- **Issue:** Initial implementation checked `$amountCents < auto_floor` before the recurring branch, causing recurring patterns with individual transactions under $100 (e.g., $42/month = $500/yr) to return false incorrectly. Spec states "$100 single-txn floor UNLESS recurring."
- **Fix:** Moved recurring branch before the auto-floor check so recurring patterns gate only on annualTotalCents >= recurring_pattern_annual_cents regardless of individual txn size
- **Files modified:** app/Services/TaxRulesEngineService.php
- **Commit:** 2a4d438

**2. [Rule 3 - Blocking] Extended Pest.php TestCase binding to all of Unit/**
- **Found during:** Task 3 test run (first attempt)
- **Issue:** Tests in tests/Unit/ root (not subdirectory) could not resolve the `config` service container binding because Pest.php only extended Tests\TestCase::class for `Unit/Services/`, not `Unit/` root
- **Fix:** Changed `->in('Unit/Services')` to `->in('Unit')` in tests/Pest.php — purely additive; existing Unit/Services tests are unaffected since TestCase already applied there
- **Files modified:** tests/Pest.php
- **Commit:** cb4c9b4

## Known [ASSUMED] Values for P13 Sign-Off Gate

The following constants in config/tax-rules.php 2026.detection block are marked `[ASSUMED]` and require IRS publication confirmation before production deployment:

| Constant | Value | Source Needed |
|----------|-------|---------------|
| section_415c_limit | 72,000 | IRS Notice 2025-67 |
| qcd_limit | 108,000 | IRS Notice 2026 |
| ot_deduction_cap_single | 12,500 | OBBBA §70102 final text |
| ot_deduction_cap_mfj | 25,000 | OBBBA §70102 final text |
| ltcg_zero_bracket_single | 49,000 | IRS Rev. Proc. 2025-32 |
| ltcg_zero_bracket_mfj | 98,000 | IRS Rev. Proc. 2025-32 |
| gift_exclusion_annual | 19,000 | IRS Rev. Proc. 2025-32 |
| estate_exemption_single | 15,000,000 | IRS Rev. Proc. 2025-32 |
| estate_exemption_joint | 30,000,000 | IRS Rev. Proc. 2025-32 |
| adoption_credit | 17,000 | IRS Rev. Proc. 2025-32 |
| feie_limit | 130,000 | IRS Notice 2026 (FEIE) |

These values flow only through config — no service code changes needed when confirmed.

## Verification Results

| Check | Result |
|-------|--------|
| Plan-specific tests: 105 tests | PASSED (242 assertions) |
| Full suite: 1 pre-existing failure (DashboardFinancialBlocksTest) | No NEW failures |
| Net new tests added | +105 tests, +242 assertions |
| vendor/bin/pint --dirty | CLEAN |
| Zero outbound HTTP calls (Http::preventStrayRequests guard) | VERIFIED |
| No literals in passesMaterialityGate body (SAFE-03 grep-gate) | VERIFIED |

## Threat Flags

None. This plan builds config files and pure in-process computation methods only. No new network endpoints, auth paths, file access patterns, or schema changes at trust boundaries.

## Self-Check: PASSED

- config/tax-detection.php: FOUND
- config/tax-rules.php (detection block): FOUND
- app/Services/TaxRulesEngineService.php (new methods): FOUND
- tests/Unit/TaxConfigExtensionTest.php: FOUND
- tests/Unit/DetectorRuleExpirationTest.php: FOUND
- Commit cb4c9b4 (Task 1): FOUND
- Commit 2a4d438 (Task 2): FOUND
- Commit 4253a7a (Task 3): FOUND
