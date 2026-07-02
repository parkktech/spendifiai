---
phase: 11-red-flag-detection-guided-interview-ai-feed-integration
plan: "02"
subsystem: durable-facts-store
tags: [store, append-only, encryption, facts, basis-ledger, interview, tdd]
dependency_graph:
  requires: [11-01-PLAN.md]
  provides: [UserTaxFact, TaxProfileEntity, answerableFields-extension, readProfileFlags-fix]
  affects: [IncomeOptimizationProfile, IncomeOptimizerDataAssemblerService, AppServiceProvider]
tech_stack:
  added: []
  patterns:
    - append-only fact store with partial unique index (Postgres)
    - SELECT FOR UPDATE concurrency guard on supersession
    - document-extraction proposal/confirmation gate (is_current=false until confirmed)
    - encrypted:array basis ledger on TaxProfileEntity
    - answerableFields() proxy extension pattern
key_files:
  created:
    - database/migrations/2026_07_02_100000_create_user_tax_facts_table.php
    - database/migrations/2026_07_02_100001_create_tax_profile_entities_table.php
    - database/migrations/2026_07_02_100002_add_business_type_housing_status_to_income_optimization_profiles.php
    - app/Models/UserTaxFact.php
    - app/Models/TaxProfileEntity.php
    - app/Policies/UserTaxFactPolicy.php
    - app/Policies/TaxProfileEntityPolicy.php
    - tests/Feature/UserTaxFactTest.php
    - tests/Feature/BasisLedgerTest.php
  modified:
    - app/Models/IncomeOptimizationProfile.php
    - app/Services/IncomeOptimizerDataAssemblerService.php
    - app/Providers/AppServiceProvider.php
    - tests/Feature/IncomeOptimizerDataAssemblerTest.php
decisions:
  - "Migrations ordered 100000=user_tax_facts, 100001=tax_profile_entities; deferred FK from user_tax_facts.entity_id added via ALTER TABLE in migration 100001 to resolve ordering dependency"
  - "recordFact() flips old row is_current=false BEFORE inserting new row (not after) to avoid partial unique index violation — the two-step supersession requires this ordering"
  - "TaxProfileEntity.attributes access via getAttribute()/setAttribute() to avoid PHP conflict with Eloquent's internal $this->attributes model attribute bag"
  - "No new Composer/npm packages: zero installs"
  - "answerableFields() accepts optional ?UserTaxFact proxy (not forced DI) to maintain backwards compatibility with callers passing no args"
metrics:
  duration: "632s (~10.5 min)"
  completed: "2026-07-02"
  tasks_completed: 3
  files_created: 9
  files_modified: 4
status: complete
---

# Phase 11 Plan 02: Durable-Facts Store — Summary

**One-liner:** Append-only UserTaxFact graph with partial-unique-index concurrency, document-extraction confirm gate, multi-account retirement representation, per-property basis ledger on TaxProfileEntity, and INT-03 answerableFields/assembler extension.

## What Was Built

### STORE-01: Append-Only UserTaxFact Store

`user_tax_facts` — the ask-once memory that prevents the interview from re-asking known facts:

- **Append-only invariant**: `recordFact()` never UPDATEs the value column; a re-answer inserts a new row, flips `is_current=false` + `superseded_by_id` on the old row, preserving full provenance chain.
- **Partial unique index** (`idx_tax_facts_current`): enforces exactly one `is_current=true` row per `(user_id, fact_key, COALESCE(entity_id,0), COALESCE(tax_year,0))`. Created via `DB::statement()` because Blueprint cannot express WHERE clauses.
- **Concurrency guard**: `recordFact()` uses `SELECT FOR UPDATE` inside a DB transaction, flips the old row to `is_current=false` BEFORE inserting the new row (ordering is critical — insert first causes index violation).
- **Confirm gate (Decision 4)**: `document_extraction` facts enter as proposals (`is_current=false`, `confirmed_at=null`), never feed `currentFactKeys()` / `answerableFields()`, and never supersede `user_edit`/`interview_answer`/`profile_field` facts until `confirmProposal()` is called.
- **Multi-account retirement**: `ira.roth_ytd_cents` and `ira.traditional_ytd_cents` coexist as separate current facts with per-type integer-cent amounts. Combined contributions = Roth + Traditional for IRA-limit headroom (IRA limit is shared across both types). Legacy `ira_type` column on UserFinancialProfile is untouched.
- **Volatility carry-forward**: `isDueForReconfirmation()` uses `config('tax-rules.facts.reconfirm_months', 12)` threshold on `asserted_at` for stable-volatility facts.
- **Encryption**: `value` column = TEXT + `'encrypted'` cast + `$hidden`. Money = integer-cents-as-string. `fact_key`/`label` are plain non-PII for indexing. `metadata` (JSONB, non-PII) stores extraction confidence for document_extraction source.
- **GDPR**: `cascadeOnDelete` FK to users. Policy allows no deletions (append-only enforced at policy layer).

### STORE-02: TaxProfileEntity Basis Ledger

`tax_profile_entities` — vehicles, properties, business entities (NOT people — Dependent/Household already exist):

- `attributes` = TEXT + `'encrypted:array'` cast + `$hidden` (basis ledger entries, vehicle data, etc.)
- `addBasisEntry()` enforces STORE-02 rules: maintenance entries rejected, rebates reduce basis (`is_rebate=true`), recapture years tracked per entry, each entry references Vault receipt by `tax_document_id`.
- `computeNetBasisCents()` = sum(improvements) − sum(rebates).
- Entity type guard: `addBasisEntry()` only valid for `entity_type=property`.

### INT-03: answerableFields() Extension + Assembler FACT-CHECK FIX

- `IncomeOptimizationProfile::answerableFields(?UserTaxFact $proxy = null)`: additive proxy parameter merges `UserTaxFact::currentFactKeys($userId)` (confirmed facts only — proposals excluded). All 9 existing base keys preserved with identical shape.
- `IncomeOptimizerDataAssemblerService::readProfileFlags()`: additively adds `business_type` and `housing_status` to both the no-profile default array and the populated array. No existing key changed.
- `income_optimization_profiles` migration 100002: adds `business_type` (varchar 50) + `housing_status` (varchar 30) columns.
- `AppServiceProvider`: `Route::model('tax-fact', UserTaxFact)` + `Route::model('tax-entity', TaxProfileEntity)` + `Gate::policy` for both new models.

## Test Coverage

**33 tests pass** (14 new in UserTaxFactTest + BasisLedgerTest, 5 new in IncomeOptimizerDataAssemblerTest, 14 pre-existing assembler tests remain green).

Named tests verified:
- `append_only_no_update` — old value preserved; superseded_by_id set; 2 rows total
- `partial_unique_concurrency` — two sequential writes yield 1 is_current row; latest value wins
- `confirmed_fact_answerable` — confirmProposal() flips is_current=true; key becomes answerable
- `proposal_not_answerable` — unconfirmed proposal excluded from currentFactKeys; user_edit remains current
- `provenance_round_trip` — source_type/source_id/asserted_at/confirmed_at persist through encrypted cast
- `carry_forward` — isDueForReconfirmation() uses config threshold; 14-month fact flagged, 6-month not
- `retirement_multi_account` — roth_ytd_cents and traditional_ytd_cents are separate current facts; combined = $7,500
- `basis_ledger_accumulates` — improvement entries stored in encrypted attributes bag
- `basis_ledger_rebate` — rebate entry reduces net basis ($30k − $1k = $29k)
- `basis_ledger_maintenance_excluded` — InvalidArgumentException on maintenance kind
- `basis_ledger_recapture_year` — recapture_year tracked per entry
- `basis_ledger_tax_document_id` — tax_document_id references Vault receipt
- `basis_ledger_entity_type_guard` — vehicle entity rejects addBasisEntry()
- `basis_ledger_attributes_encrypted` — attributes hidden from toArray()
- INT-03: answerableFields() confirmed fact → true; proposal → false; base 9 keys unchanged
- FACT-CHECK-FIX: readProfileFlags() returns business_type + housing_status when profile exists and when null

**Http::preventStrayRequests()** guards all test files — zero Claude/HTTP calls in this plan.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] recordFact() ordering: flip is_current BEFORE insert, not after**
- **Found during:** Task 1 GREEN (partial unique index violation on first test run)
- **Issue:** Original code inserted new row with `is_current=true` first, then tried to flip old row — violated the partial unique index because two `is_current=true` rows briefly coexisted.
- **Fix:** Reordered to: (1) `UPDATE old.is_current=false`, (2) `INSERT new row`, (3) `UPDATE old.superseded_by_id=new.id`.
- **Files modified:** `app/Models/UserTaxFact.php`
- **Commit:** ad3fb36

**2. [Rule 1 - Bug] TaxProfileEntity::addBasisEntry() attribute access conflict**
- **Found during:** Task 1 GREEN (column "attributes_column" does not exist error)
- **Issue:** Used `$this->attributes` in the method body, which conflicts with Eloquent's internal `$this->attributes` array (all model columns). Attempted workaround `$this->attributes_column` produced "undefined column" SQL error.
- **Fix:** Replaced with `$this->getAttribute('attributes')` and `$this->setAttribute('attributes', $bag)`.
- **Files modified:** `app/Models/TaxProfileEntity.php`
- **Commit:** ad3fb36

**3. [Rule 2 - Missing functionality] Migration ordering for deferred FK**
- **Found during:** Planning (dependency: user_tax_facts.entity_id FK → tax_profile_entities, but user_tax_facts runs first)
- **Fix:** Declared `entity_id` as plain `unsignedBigInteger` in migration 100000, added the FK via `ALTER TABLE` in migration 100001 (after tax_profile_entities exists). Self-reference FK (`superseded_by_id`) also added via `ALTER TABLE` after `Schema::create()` completes.
- **Files modified:** Both migration files.
- **Commit:** ad3fb36

## Known Stubs

None. All implemented behaviors are fully wired. `docs_missing` affordance is a P12 concern (per D3/CONTEXT.md deferred section) — no stub added in this plan.

## Self-Check: PASSED

- All 9 created files exist on disk (verified with shell check)
- All commits exist: a8f6a5e, ad3fb36, ecffdcc, 5f33d31, 96a6760
- `php artisan test --compact` = 473 passed, 1 pre-existing failure (DashboardFinancialBlocksTest — not ours)
- `vendor/bin/pint --dirty` = pass
- Migration pretend output confirms additive SQL only (CREATE TABLE, ALTER TABLE ADD CONSTRAINT, CREATE INDEX — no DROP, no TRUNCATE)
