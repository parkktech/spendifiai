---
phase: 10-foundation-tax-rules-engine-cross-source-snapshot
plan: 01
subsystem: api
tags: [tax, php, pest, config, irs, optimize-my-income]

requires: []

provides:
  - config/tax-rules.php — year-versioned IRS constants for 2026 (brackets, deductions, 401k/IRA/HSA/SE-tax/QBI/Roth)
  - TaxRulesEngineService — 15-method deterministic PHP tax-math engine, zero Claude/HTTP calls
  - TaxRulesConfigTest — 14 structural assertions verifying config completeness
  - TaxRulesEngineServiceTest — 50 boundary + property + no-Claude-guard tests (TAX-07)

affects: [10-02, 10-03, phase-11-detectors, phase-12-report]

tech-stack:
  added: []
  patterns:
    - "Year-versioned config pattern: config('tax-rules.{year}.*') for annual IRS update cadence"
    - "Cents-only service layer: all monetary I/O in integer cents; config stores human-readable dollars"
    - "Config-derived test assertions: Config::get() in expected values so config changes surface in tests"
    - "Http::preventStrayRequests() guard: proves deterministic engine makes zero outbound calls"
    - "InvalidArgumentException on bad inputs: filing_status allow-list + income >= 0 + year existence"

key-files:
  created:
    - config/tax-rules.php
    - app/Services/TaxRulesEngineService.php
    - tests/Unit/Services/TaxRulesConfigTest.php
    - tests/Unit/Services/TaxRulesEngineServiceTest.php
  modified: []

key-decisions:
  - "All IRS dollar amounts live in config/tax-rules.php only — zero literals in service (grep gate enforced)"
  - "401k age tiers: 60-63 gets SECURE 2.0 §109 super catch-up ($11,250); 50-59 and 64+ get standard ($8,000)"
  - "mandatory_roth_catchup_threshold tagged [ASSUMED] in config — exact 2026 indexed value needs IRS final reg confirmation before Phase 13"
  - "QBI Phase 10 scope: below-threshold 20% estimate; above-threshold non-SSTB returns professional-review sentinel (deduction_cents=null)"
  - "Pint code style enforced; all whitespace/operator fixes applied before commit"

patterns-established:
  - "Pattern 1: config/tax-rules.php — one file per domain with annual update cadence, year as top-level integer key"
  - "Pattern 2: TaxRulesEngineService — pure-PHP service with no constructor injection, all methods take year=2026 default"
  - "Pattern 3: TAX-07 test wiring — Config::set() overrides in tests ensure assertions track config, not memory"

requirements-completed: [TAX-01, TAX-02, TAX-03, TAX-04, TAX-05, TAX-06, TAX-07]

coverage:
  - id: D1
    description: "config/tax-rules.php with all 2026 IRS constants and §603 caveat comment"
    requirement: TAX-01
    verification:
      - kind: unit
        ref: tests/Unit/Services/TaxRulesConfigTest.php
        status: pass
    human_judgment: false
  - id: D2
    description: "TaxRulesEngineService computeTax + marginalRate + effectiveRate (bracket math)"
    requirement: TAX-02
    verification:
      - kind: unit
        ref: tests/Unit/Services/TaxRulesEngineServiceTest.php#taxes income of $12,399 entirely at 10%
        status: pass
      - kind: unit
        ref: tests/Unit/Services/TaxRulesEngineServiceTest.php#marginal rate is correct at each bracket boundary
        status: pass
    human_judgment: false
  - id: D3
    description: "standardDeductionCents + compareStandardVsItemized (standard vs itemized comparison)"
    requirement: TAX-03
    verification:
      - kind: unit
        ref: tests/Unit/Services/TaxRulesEngineServiceTest.php#standard deduction cents for single equals config value times 100
        status: pass
    human_judgment: false
  - id: D4
    description: "remaining401kRoomCents + remainingIraRoomCents + remainingHsaRoomCents with all age catch-up tiers"
    requirement: TAX-04
    verification:
      - kind: unit
        ref: tests/Unit/Services/TaxRulesEngineServiceTest.php#401k room with no contributions equals base deferral limit for age 45
        status: pass
    human_judgment: false
  - id: D5
    description: "rothVsTraditionalBand + requiresMandatoryRothCatchup + rothIraEligibility + traditionalIraDeductibility"
    requirement: TAX-05
    verification:
      - kind: unit
        ref: tests/Unit/Services/TaxRulesEngineServiceTest.php#rothVsTraditionalBand returns roth
        status: pass
    human_judgment: false
  - id: D6
    description: "selfEmploymentTax (SS cap + Medicare) + qbiDeduction (20% below threshold, professional-review above)"
    requirement: TAX-06
    verification:
      - kind: unit
        ref: tests/Unit/Services/TaxRulesEngineServiceTest.php#selfEmploymentTax computes correctly using config multiplier
        status: pass
    human_judgment: false
  - id: D7
    description: "All test assertions derive expected values from Config::get(), not hardcoded amounts; Http::preventStrayRequests() guard proves zero outbound HTTP"
    requirement: TAX-07
    verification:
      - kind: unit
        ref: tests/Unit/Services/TaxRulesEngineServiceTest.php#makes zero outbound HTTP calls during all computation paths
        status: pass
      - kind: unit
        ref: tests/Unit/Services/TaxRulesEngineServiceTest.php#requiresMandatoryRothCatchup result flips when config threshold is changed
        status: pass
    human_judgment: false

duration: 12min
completed: 2026-07-01
status: complete
---

# Phase 10 Plan 01: Tax Rules Engine Foundation Summary

**Year-versioned config/tax-rules.php with all 2026 IRS constants + pure-PHP TaxRulesEngineService (15 methods, zero Claude calls) proven by 64 Pest tests including Http::preventStrayRequests() guard**

## Performance

- **Duration:** 12 min
- **Started:** 2026-07-01T23:52:00Z
- **Completed:** 2026-07-01T23:52:00Z (approx — within session)
- **Tasks:** 3 of 3
- **Files created:** 4

## Accomplishments

- Created `config/tax-rules.php` with all 2026 IRS constants (brackets for 4 filing statuses, standard deductions + senior additions, 401k employee/catch-up limits, IRA limits + phase-outs, HSA limits, SE tax rates, QBI thresholds, Roth optimization bands) — each section cited to IRS Rev. Proc. 2025-32, Notice 2025-67, Notice 2026-05. The §603 mandatory-Roth catch-up threshold retained its [ASSUMED] caveat.
- Created `TaxRulesEngineService` with all 15 public methods from Pattern 2: computeTax, marginalRate, effectiveRate, standardDeductionCents, compareStandardVsItemized, remaining401kRoomCents, remainingIraRoomCents, remainingHsaRoomCents, rothVsTraditionalBand, rothIraEligibility, requiresMandatoryRothCatchup, traditionalIraDeductibility, selfEmploymentTax, qbiDeduction, taxSavingsFromDeductionCents. Zero Claude/HTTP calls; grep gate confirms no IRS dollar literals in the service.
- Created 64 Pest tests (14 config-structure + 50 engine boundary/property/validation), all passing. Tests use Config::get() not hardcoded amounts; Http::preventStrayRequests() guard proves the engine makes no outbound requests.

## Task Commits

1. **Task 1: config/tax-rules.php + TaxRulesConfigTest** — `072a491` (feat)
2. **Task 2: TaxRulesEngineService** — `5efc842` (feat)
3. **Task 3: TaxRulesEngineServiceTest** — `643406e` (test)

## Files Created

- `config/tax-rules.php` — Year-versioned IRS constants; top-level key `2026`; no env() calls; all values plain PHP integers (dollars)
- `app/Services/TaxRulesEngineService.php` — 15-method deterministic engine; reads config only; integer-cents I/O; InvalidArgumentException on bad inputs
- `tests/Unit/Services/TaxRulesConfigTest.php` — 14 assertions: 4-status bracket structure, deduction keys, 401k/IRA/HSA/se_tax/qbi/roth_optimization sub-keys, bracket ordering
- `tests/Unit/Services/TaxRulesEngineServiceTest.php` — 50 tests: bracket boundaries, marginal rate at all thresholds for 4 statuses, effective rate property, standard deduction wiring, 401k/IRA/HSA headroom at age tiers, Roth band at 12/22/32%, §603 threshold, SE tax with wage-base cap, QBI below/above threshold, Http::preventStrayRequests() guard, Config::set() override wiring test

## Test Results

| Filter | Tests | Assertions | Status |
|--------|-------|-----------|--------|
| TaxRulesConfigTest | 14 | 94 | PASS |
| TaxRulesEngineServiceTest | 50 | 125 | PASS |
| Full suite | 320 total | 1058 | 1 pre-existing failure (DashboardFinancialBlocksTest — unrelated to this plan) |

**Pint:** Clean (pass) — no style issues after auto-fix applied to operator spacing.

**Pre-existing failure note:** `DashboardFinancialBlocksTest > it shows budget in deficit mode` was failing before Phase 10 work began (confirmed by git stash isolation). My changes do not touch DashboardController, budget waterfall logic, or any existing service.

## Decisions Made

- Kept `mandatory_roth_catchup_threshold` as `[ASSUMED]` with exact comment from RESEARCH.md — the IRS final-regulation 2026 indexed value must be confirmed before Phase 13.
- QBI Phase 10 scope: below-threshold returns 20% estimate; above-threshold non-SSTB returns `eligible=true, deduction_cents=null, reason='above_threshold_requires_professional_review'` — W-2 wage limitation deferred to Phase 11.
- OBBBA $400 minimum deduction floor implemented: applied when `qbi >= $1,000` and computed deduction falls below `$400 * 100` cents.
- `taxSavingsFromDeductionCents` delegates to `marginalRate()` rather than accepting a rate directly, ensuring the rate always matches the config brackets.

## Deviations from Plan

None — plan executed exactly as written. The validateFilingStatus method includes both the allow-list check and a year-existence guard (belt-and-suspenders: validateYear() is also called first in all public methods).

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes in this plan. All new code is pure-PHP reading from config.

## Known Stubs

None — TaxRulesEngineService is complete for Phase 10 scope. The `deduction_cents=null` return for above-threshold QBI is intentional Phase 10 scope boundary, not a stub; documented in the return reason field.

## Next Phase Readiness

- `TaxRulesEngineService` is ready for Phase 11 detectors to call — all 15 public methods exist with documented signatures and default `year=2026`
- `config/tax-rules.php` is the single source of truth; Phase 11/12 must read through the service, not the config directly
- The `mandatory_roth_catchup_threshold` [ASSUMED] value requires tax-professional confirmation before Phase 13 ships UI copy that quotes the dollar amount
- Pre-existing `DashboardFinancialBlocksTest` failure should be investigated separately (unrelated to Phase 10)

## Self-Check: PASSED

- config/tax-rules.php: FOUND
- app/Services/TaxRulesEngineService.php: FOUND
- tests/Unit/Services/TaxRulesConfigTest.php: FOUND
- tests/Unit/Services/TaxRulesEngineServiceTest.php: FOUND
- Commit 072a491: FOUND
- Commit 5efc842: FOUND
- Commit 643406e: FOUND

---
*Phase: 10-foundation-tax-rules-engine-cross-source-snapshot*
*Completed: 2026-07-01*
