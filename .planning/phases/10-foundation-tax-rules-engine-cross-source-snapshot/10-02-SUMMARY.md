---
phase: 10-foundation-tax-rules-engine-cross-source-snapshot
plan: 02
subsystem: income-optimizer
tags: [model, migration, service, assembler, encrypted, tax-snapshot]
status: complete

dependencies:
  requires:
    - 10-01 (TaxRulesEngineService + config/tax-rules.php)
  provides:
    - IncomeOptimizationProfile model + migration
    - IncomeOptimizerDataAssemblerService
    - Feature tests (14 tests, 32 assertions)
  affects:
    - Phase 11 red-flag detectors (reads IncomeOptimizationProfile)
    - Phase 12 report (reads IncomeOptimizationProfile)

tech-stack:
  added:
    - IncomeOptimizationProfile (new model + migration + factory)
    - IncomeOptimizerDataAssemblerService (new pure-PHP service)
  patterns:
    - encrypted TEXT columns for all money fields (cents-as-string convention)
    - SHA-256 content hash for staleness detection
    - Direct calendar-year Transaction query (Jan 1–Dec 31, not rolling window)
    - updateOrCreate keyed on user_id + tax_year for safe rebuilds

key-files:
  created:
    - database/migrations/2026_07_01_100000_create_income_optimization_profiles_table.php
    - app/Models/IncomeOptimizationProfile.php
    - database/factories/IncomeOptimizationProfileFactory.php
    - app/Services/IncomeOptimizerDataAssemblerService.php
    - tests/Feature/IncomeOptimizerDataAssemblerTest.php
  modified: []

decisions:
  - "filing_status normalised from UserFinancialProfile 'married_jointly' to 'married_joint' to match tax-rules.php config keys"
  - "Bank deposit classification logic inlined in assembler (not calling IncomeDetectorService) to enforce calendar-year window and avoid rolling-window pitfall"
  - "profile_hash = SHA-256(user_id|tax_year|sorted doc IDs) — excludes updated_at so hash is purely content-driven, not time-driven"

metrics:
  duration: "~5 minutes"
  completed: "2026-07-01"
  tasks: 3
  files_created: 5
  tests_added: 14
  assertions_added: 32

requirements: [CTX-01, CTX-02, CTX-04]
---

# Phase 10 Plan 02: IncomeOptimizationProfile Snapshot & Assembler Summary

One-liner: **Encrypted per-user income snapshot model (14 TEXT/cents columns) assembled from UserFinancialProfile flags, ready TaxDocument extracted_data (dollars→cents), and calendar-year bank deposits via zero-Claude pure-PHP service.**

---

## What Was Built

### Task 1: Migration + Model + Factory

**Migration** (`2026_07_01_100000_create_income_optimization_profiles_table.php`):
- `Schema::create()` only — brand-new table, fully additive/forward-only
- 14 money columns: all `$table->text()->nullable()` — required by Laravel `encrypted` cast
- 8 non-sensitive flag columns: `boolean`/`string`/`tinyInteger`
- `jsonb data_sources`, `char(64) profile_hash`, `timestamp built_at`
- `unique(['user_id','tax_year'])` + composite index
- `php artisan migrate --pretend` confirms only the new table; no existing table touched
- Applied: `APP_ENV=testing php artisan migrate` — `16.57ms DONE`

**Model** (`app/Models/IncomeOptimizationProfile.php`):
- All 14 money columns cast as `'encrypted'` (Laravel 12 `protected function casts(): array`)
- `$hidden` lists all 14 money columns — prevents plaintext income in API responses
- `scopeForUser(Builder, int)` mirrors `TaxDocument::scopeForUser` for V4 access control
- `answerableFields(): array` — CTX-04 skip-logic map for Phase 11 interview/detectors
- Float accessors `getW2WagesCentsAttribute()`, `getBankDepositTotalCentsAttribute()`, `getSelfEmploymentIncomeCentsAttribute()` for arithmetic
- Docblock documents the cents-as-string convention with example

**Factory** (`database/factories/IncomeOptimizationProfileFactory.php`):
- `selfEmployed()` state for self-employment test scenarios
- `withDeductions()` state with mortgage/property tax/charitable data
- All money fields generated as cent strings

### Task 2: IncomeOptimizerDataAssemblerService

`app/Services/IncomeOptimizerDataAssemblerService.php` — pure PHP, zero Claude/HTTP:

- **`buildProfile(User, int): IncomeOptimizationProfile`** — orchestrates 3 sources, upserts profile
- **`isStale(IncomeOptimizationProfile): bool`** — recomputes hash from current DB docs, detects changes

**Source 1 — `readProfileFlags(User)`:** Reads UserFinancialProfile flags: employment_type, tax_filing_status (normalised to config key format), has_hsa→has_hsa_eligible_plan, has_ira, ira_type, has_home_office; derives has_self_employment from employment_type membership in self-employed types.

**Source 2 — `sumFromDocuments(User, int)`:** Queries `TaxDocument::forUser()->byYear()->byStatus(DocumentStatus::Ready)`. Iterates by `TaxDocumentCategory` enum case, summing:
- W2 → `w2_wages`
- NEC_1099 → `self_employment_income` (nonemployee_compensation)
- MISC_1099 → `self_employment_income` (other_income/rents/royalties)
- INT_1099 → `interest_income`
- DIV_1099 → `dividend_income` (ordinary_dividends)
- R_1099 → `retirement_distributions` (gross_distribution)
- Mortgage_1098 → `mortgage_interest` (mortgage_interest_received)
- PropertyTax → `property_tax_paid`
- E_1098 → `student_loan_interest` (interest)
- CharitableDonation → `charitable_contributions`
- HSA_5498 → `hsa_ytd` (contributions_made)
- IRA_5498 → `ira_ytd` (contributions_made)

All extracted_data values converted: `(int) round((float) $value * 100)` — dollars to cents.

**Source 3 — `sumBankDeposits(User, int)`:** Direct `Transaction` query for `amount < 0` within `Carbon::create($taxYear, 1, 1)` to `Carbon::create($taxYear, 12, 31)`. Does NOT call `IncomeDetectorService::analyze()`. Classifies each transaction using the same type maps as IncomeDetectorService (inlined to enforce calendar-year window). Excludes `transfer` types. Sums `(int) round(abs($amount) * 100)`.

**Staleness hash:** `hash('sha256', user_id . '|' . tax_year . '|' . implode(',', sorted_doc_ids))`. Content-driven — changes whenever document set changes, not on time passage.

### Task 3: Feature Tests

`tests/Feature/IncomeOptimizerDataAssemblerTest.php` — 14 tests, 32 assertions:

| Test | Req | Result |
|------|-----|--------|
| W2 wages 72500.00 → 7,250,000 cents | CTX-01 | PASS |
| $hidden hides money columns from toArray() | CTX-01 | PASS |
| Profile flags persisted from UserFinancialProfile | CTX-02 | PASS |
| profile_hash changes → isStale() flips true | CTX-02 | PASS |
| Multi-W2 wages summed correctly | CTX-02 | PASS |
| Mid-year call returns full Jan 1–Dec 31 total | Pitfall3 | PASS |
| Cross-year transactions excluded | Pitfall3 | PASS |
| answerableFields() reports set flags as answerable | CTX-04 | PASS |
| answerableFields() reports unset flags as false | CTX-04 | PASS |
| answerableFields() for hsa_ytd > 0 | CTX-04 | PASS |
| 1099-NEC maps to self_employment_income | CTX-01 | PASS |
| 1098-E maps to student_loan_interest | CTX-01 | PASS |
| 1098 maps to mortgage_interest | CTX-01 | PASS |
| Transfer transactions excluded from bank total | CTX-01 | PASS |

---

## Verification

### Migration
```
APP_ENV=testing php artisan migrate --pretend 2>&1 | grep income_optimization_profiles
→ create table "income_optimization_profiles" (14 money columns all TEXT, unique+index present)
Only new table touched — no existing table modified.

APP_ENV=testing php artisan migrate → 16.57ms DONE
```

### Grep Gates (assembler)
```
grep DocumentStatus::Ready   → present (2 hits: byStatus calls)
grep DocumentStatus::Extracted → absent (0 hits)
grep ->analyze(              → absent (0 hits — only comment references)
```

### Tests
```
php artisan test --compact --filter=IncomeOptimizerDataAssemblerTest
→ 14 passed (32 assertions) | Duration: 1.42s

php artisan test --compact
→ Tests: 1 failed, 333 passed (1090 assertions) | Duration: 9.70s
  1 failure = DashboardFinancialBlocksTest (KNOWN PRE-EXISTING FAILURE, unrelated to this plan)
  Zero new failures introduced
```

### Pint
```
vendor/bin/pint --dirty [all 5 files]
→ All files clean after auto-fix pass (binary_operator_spaces, phpdoc_align, unary_operator_spaces)
```

---

## Security Review

| Threat | Mitigation Applied |
|--------|--------------------|
| T-10-05: Cross-user profile access | `scopeForUser($userId)` on all queries; assembler only operates on passed User |
| T-10-06: Plaintext income in DB | All 14 money columns TEXT with `'encrypted'` cast + `$hidden`; verified via migrate --pretend |
| T-10-07: Non-additive migration | `Schema::create()` on new table only; `migrate --pretend` confirms no existing table touched |
| T-10-08: Silent AI dependency | `DocumentStatus::Ready` used (not Extracted); no `->analyze()` call; grep gates pass |

---

## Deviations from Plan

### Auto-fix: filing_status normalisation

**Rule 2 — Auto-add missing critical functionality**

- **Found during:** Task 2 (readProfileFlags implementation)
- **Issue:** `UserFinancialProfile.tax_filing_status` uses `'married_jointly'` while `TaxRulesEngineService` config keys use `'married_joint'`. Without normalisation the downstream engine would receive an unrecognised filing status.
- **Fix:** Added `normaliseFilingStatus()` helper mapping `married_jointly`/`married_filing_jointly` → `married_joint`, etc.
- **Files modified:** `app/Services/IncomeOptimizerDataAssemblerService.php`

### Auto-fix: income type classification inlined (not delegated)

**Rule 2 — Missing critical functionality**

- **Found during:** Task 2 (sumBankDeposits implementation)
- **Issue:** RESEARCH note said to call IncomeDetectorService but with a calendar-year range. The `classifyType()` method is `protected` on IncomeDetectorService, making it inaccessible without extending or reflection. Rather than coupling to an internal method, the type maps were inlined.
- **Fix:** Copied the three type maps (plaidTypeMap, plaidPrimaryMap, aiTypeMap) and the classification logic into a protected `classifyTransactionType()` helper. Identical logic, no coupling to internal methods.
- **Files modified:** `app/Services/IncomeOptimizerDataAssemblerService.php`

---

## Known Stubs

None. All columns are wired to real data sources. The service reads from existing v2.0 data (no placeholder values).

---

## Commits

| Hash | Type | Description |
|------|------|-------------|
| 52c6724 | feat(10-02) | IncomeOptimizationProfile model + additive migration + factory |
| 7f2ecf1 | feat(10-02) | IncomeOptimizerDataAssemblerService — zero-Claude multi-source assembler |
| 9d8ede0 | test(10-02) | IncomeOptimizerDataAssemblerTest — CTX-01/02/04 + Pitfall3 guard |

---

## Self-Check: PASSED

### Created files exist
- FOUND: database/migrations/2026_07_01_100000_create_income_optimization_profiles_table.php
- FOUND: app/Models/IncomeOptimizationProfile.php
- FOUND: database/factories/IncomeOptimizationProfileFactory.php
- FOUND: app/Services/IncomeOptimizerDataAssemblerService.php
- FOUND: tests/Feature/IncomeOptimizerDataAssemblerTest.php

### Commits exist
- 52c6724: feat(10-02): IncomeOptimizationProfile model + additive migration + factory
- 7f2ecf1: feat(10-02): IncomeOptimizerDataAssemblerService — zero-Claude multi-source assembler
- 9d8ede0: test(10-02): IncomeOptimizerDataAssemblerTest — CTX-01/02/04 + Pitfall3 guard
