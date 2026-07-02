---
phase: 14-action-center-scenarios-design-elevation
plan: 03
subsystem: api
tags: [optimization-scenarios, fact-resolution, provenance, encryption, hmac, laravel, pest, scn-02]

# Dependency graph
requires:
  - phase: 14-01
    provides: config/optimization-objectives.php (per-fact source-priority chains, fact_aliases, pay_periods_per_year, question_templates, bonus_election scenario domain)
  - phase: 12-13 (Optimize My Income foundation)
    provides: UserTaxFact (currentFact/recordFact confirm-gate), IncomeOptimizationProfile snapshot, IncomeOptimizerDataAssemblerService (normaliseFilingStatus/dollarsToCents/isStale), UserFinancialProfile, InterviewSession (encrypted-TEXT precedent)
provides:
  - app/Services/ScenarioFactResolverService.php (resolveAll/resolve/snapshotFactSet/isStale + §A.6.3 derivations)
  - app/Models/ScenarioFactSet.php (encrypted resolved_facts + $hidden + scopeForUser + resolvedFactsArray)
  - database/migrations/2026_07_03_000001_create_scenario_fact_sets_table.php (additive, GDPR cascade, [user_id,tax_year] index)
affects: [14-05 (ObjectiveReadinessService), 14-06 (ScenarioSolverService baseline), 14-08 (checklist fact-gating), 14-09 (bonus scenario domain)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Read-side provenance resolver: query-time join across snapshot+facts+profile, never mutates the snapshot"
    - "Two-tier resolution (§A.7): known (snapshot/profile/derived/detector) vs confirmed (interview_answer/user_edit/confirmed-doc)"
    - "Per-fact source-priority chains from config; canonical keys carry dots -> fetch whole map and index by literal string (config() dot-notation would mis-traverse)"
    - "Deterministic config-parameterized derivations (assembler-style cents arithmetic); bracket/limit math stays in TaxRulesEngineService"
    - "HMAC-SHA256(canonical_json(fact_key => [source_ref, value]), app.key) for small-search-space money hash (not bare SHA-256)"
    - "Encrypted TEXT + manual json_encode/decode (InterviewSession.assertions precedent, avoids encrypted:array double-encode)"

key-files:
  created:
    - app/Services/ScenarioFactResolverService.php
    - app/Models/ScenarioFactSet.php
    - database/migrations/2026_07_03_000001_create_scenario_fact_sets_table.php
    - tests/Feature/Scenarios/ScenarioFactResolverTest.php
    - tests/Feature/Scenarios/ScenarioFactSetTest.php
  modified: []

decisions:
  - "resolveAll unions canonical keys from the THREE readiness objectives only; is_scenario_domain entries (bonus_election) are excluded, mirroring ObjectiveReadinessService iteration (§A.8.1). Documented in allCanonicalKeys()."
  - "age_from_birth_year is implemented in the derivation dispatch but is NOT wired into any config chain (it is consumed by the engine in 14-06, K2 age->snapshot estimated_age). Its test exercises the private derivation directly via reflection so the public API stays exactly resolveAll/resolve/snapshotFactSet/isStale (§A.6.2)."
  - "Unimplemented derivation rules referenced by config chains (magi_projection, from_match_formula, prior_year_bonus_*, paystub_gross_pay, annual_gross_over_periods) return null so the chain falls through to its next step (fact/ask). They belong to later plans / the engine."
  - "resolve() returns null at chain exhaustion (the 'ask' terminal) — readiness (14-05) interprets null+blocking as blocking_missing."
  - "Fact-set hash intentionally excludes resolved_at so identical resolutions hash equal; resolved_at is retained in the stored (encrypted) resolved_facts for citation only."

metrics:
  duration_minutes: 41
  tasks_completed: 2
  files_created: 5
  tests_added: 28
  completed: 2026-07-02

status: complete
---

# Phase 14 Plan 03: ScenarioFactResolverService + ScenarioFactSet Summary

Read-side, provenance-consistent fact resolution substrate for optimization scenarios (SCN-02): a resolver that walks each fact's config-declared source-priority chain (snapshot / fact-with-aliases / profile / deterministic derivation / ask), classifies every resolution as two-tier `known` vs `confirmed`, and can freeze the current resolution into a versioned, HMAC-hashed, encrypted `ScenarioFactSet` that scenarios and checklist steps cite — never mutating facts, never calling Claude.

## What was built

### Task 1 — ScenarioFactResolverService (TDD)
- `resolve(user, year, canonicalKey)` walks the per-fact `chain` from `config('optimization-objectives.*')` and returns the first hit as a `ResolvedFact` array `{fact_key, value, value_type, source, source_ref, confirmed, resolved_at}`.
  - **Identity/enum facts resolve fact-first** (e.g. `profile.filing_status`: fact → snapshot → profile → ask); **money-YTD facts resolve snapshot-first** (M14, e.g. `retirement.traditional_401k_ytd_cents`: snapshot → fact → ask).
  - **Alias fallback (§A.1.3):** canonical key first, then `fact_aliases` (e.g. `retirement.k401_contribution_ytd_cents`), each year-scoped (`currentFact(u,k,null,year)`) before unscoped (`?? currentFact(u,k)`).
  - **M3 independence:** `w4.filing_status` and `profile.filing_status` resolve through their own chains and are never aliased to each other (regression-tested both directions).
  - **Two-tier flag (§A.7):** `interview_answer`/`user_edit`/confirmed `document_extraction` → `confirmed=true`; snapshot/profile/derived/detector → `known` (`confirmed=false`).
  - **Derivations (§A.6.3, each `source='derived'`, `confirmed=false`, input refs recorded):** `age_from_birth_year`, `annualize_ytd_gross`, `annualize_ytd_federal_tax`, `per_period_times_frequency`, `frequency_from_paystub` (span table incl. ambiguous 13–16-days-without-anchors → `null` → falls through to ask), `spouse_annual_from_profile`, `contribution_pct_from_ytd`.
- `resolveAll(user, year)` = union of the three readiness objectives' canonical keys (scenario-only domains excluded).
- **Never-does (§A.6.4) proven:** `resolveAll` performs zero `UserTaxFact` writes; the resolution path is pure DB reads (no Claude/HTTP). Decrypted values live only in-memory or in the encrypted fact-set column.

### Task 2 — ScenarioFactSet model + additive migration + snapshotFactSet/isStale
- Migration `create_scenario_fact_sets_table` (forward-only, additive): `id`, `user_id` (foreignId constrained `cascadeOnDelete` — GDPR), `integer tax_year`, `string fact_set_hash(64)`, `text resolved_facts`, timestamps, `index [user_id, tax_year]`. No existing object touched.
- Model: `resolved_facts` cast `encrypted` (manual `json_encode`/`resolvedFactsArray()` decode — InterviewSession precedent), `$hidden` on `resolved_facts`, `scopeForUser()`.
- `snapshotFactSet(user, year)` runs `resolveAll`, persists only non-null resolutions, and computes `fact_set_hash = hash_hmac('sha256', canonical_json(fact_key => [source_ref, value]), config('app.key'))` (HMAC, not bare SHA-256 — money values have a small brute-forceable search space).
- `isStale(set)` recomputes the current hash and compares (mirrors `IncomeOptimizerDataAssemblerService::isStale`); flips true on fact supersession.
- Migration applied to the app DB via `php artisan migrate --force` (single migration, 13.83ms).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] config() dot-notation mis-traversed dotted canonical keys**
- **Found during:** Task 1 (alias-fallback test failed — resolution returned null).
- **Issue:** `config("optimization-objectives.fact_aliases.{$canonicalKey}")` treats the dots inside `retirement.traditional_401k_ytd_cents` as nested-array traversal, so the alias list was never found.
- **Fix:** Fetch the whole `fact_aliases` map once and index by the literal string key (`$allAliases[$canonicalKey] ?? []`). Same care applied elsewhere (objectives/templates fetched whole).
- **Files modified:** app/Services/ScenarioFactResolverService.php
- **Commit:** 852512e

**2. [Rule 3 - Blocking] missing inferValueType() helper**
- **Found during:** Task 2 first run (undefined-method error from snapshot path).
- **Fix:** Added the presentational `inferValueType()` helper (`*_cents` → money_cents; enum/year/integer by key). Does not affect resolution.
- **Files modified:** app/Services/ScenarioFactResolverService.php
- **Commit:** 852512e

No architectural (Rule 4) deviations. No new packages.

## Test results (exact)

- `php artisan test --compact tests/Feature/Scenarios/` → **28 passed (65 assertions)**.
- `vendor/bin/pint --dirty` → clean (auto-fixed one `phpdoc_align` in the resolver test).
- **Full suite** `php artisan test --compact` → **863 passed, 1 failed, 1 risky (3024 assertions), 150.30s**.

### Test-DB finding (honest report requested by the plan)
The prior agent's claimed "~6 failures around the optimization_reports table / test-DB migration drift" **did NOT reproduce**. The suite runs under `RefreshDatabase`, which re-migrates the full schema (including the new `scenario_fact_sets` migration) into the testing DB on every test; all optimization and `optimization_reports` tests passed. There were **zero migration-drift or optimization_reports failures**.

The single failure is `Tests\Feature\Dashboard\DashboardFinancialBlocksTest > it shows b…` at line 149 — `Failed asserting that 333.33 is less than 0` (`budget_waterfall.monthly_surplus`). This is the **known pre-existing** DashboardFinancialBlocksTest failure the plan named: it is an assertion/test-fixture arithmetic issue in the dashboard waterfall, entirely unrelated to this plan (which adds only new files: a service, a model, an additive migration, and their tests — nothing touching DashboardController). The 1 risky test is likewise pre-existing and unrelated.

`php artisan migrate --env=testing` was **NOT** run (it is forbidden, and RefreshDatabase makes it unnecessary).

## Commits
- 7310358 — test(14-03): failing specs for ScenarioFactResolver + ScenarioFactSet (RED)
- 852512e — feat(14-03): ScenarioFactResolverService + citable ScenarioFactSet (GREEN)

## Known Stubs
None. `age_from_birth_year` is implemented (reflection-tested) but intentionally unwired pending 14-06 engine consumption; unimplemented config-referenced derivations (`magi_projection`, `from_match_formula`, `prior_year_bonus_*`, `paystub_gross_pay`, `annual_gross_over_periods`) return null and fall through their chains by design — documented in `resolveFromDerivation()`.

## Self-Check: PASSED
- app/Services/ScenarioFactResolverService.php — FOUND
- app/Models/ScenarioFactSet.php — FOUND
- database/migrations/2026_07_03_000001_create_scenario_fact_sets_table.php — FOUND
- tests/Feature/Scenarios/ScenarioFactResolverTest.php — FOUND
- tests/Feature/Scenarios/ScenarioFactSetTest.php — FOUND
- commit 7310358 — FOUND
- commit 852512e — FOUND
- scenario_fact_sets migration applied — DONE (13.83ms)
