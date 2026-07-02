---
phase: "14"
plan: "06"
subsystem: "scenarios-solver"
tags: [solver, optimization, tax-rules-engine, tdd, deterministic]
requires: [14-01, 14-02, 14-03]
provides: [ScenarioSolverService, knob-vectors, balanced-synthesis, diff-knobs, benefit-attribution]
affects: [TaxRulesEngineService]
tech_stack:
  added: [ScenarioSolverService]
  patterns: [greedy-fill, tdd-red-green, floor-guard, grid-snapping, midpoint-synthesis]
key_files:
  created:
    - app/Services/ScenarioSolverService.php
    - tests/Unit/ScenarioSolverTest.php
    - tests/Feature/Scenarios/ScenarioAgreementTest.php
  modified:
    - app/Services/TaxRulesEngineService.php
decisions:
  - "Floor guard uses baseline_per_period_take_home_cents (computed in assembleBaseline once) — zero now()-branching in solve()"
  - "TaxRulesEngineService bug fixes (projectedMagiCents, computeScenarioOutcome, coveredByPlan) committed here (were 14-02 scope but left uncommitted)"
  - "attributeBenefits uses ≤6 single-knob engine calls + interaction remainder to header_aggregate only (never to individual steps)"
  - "diffKnobs IRA epsilon: max(config_default, remainingIraRoom/4) via optional param — avoids baseline being required at diff time"
  - "RETIREMENT K2 roth_share derived from marginalRate on simplified taxable income (gross - trad401k - hsa - std_deduction) via engine helpers"
metrics:
  duration: "~120 minutes"
  completed: "2026-07-02"
  tasks_completed: 2
  files_changed: 4
  tests_added: 33
  tests_total_after: 937
status: complete
---

# Phase 14 Plan 06: ScenarioSolverService Summary

**One-liner:** Deterministic six-knob solver over three objectives (take_home, tax_burden, retirement) + Balanced synthesis, knob divergence detection (diffKnobs §C.1), and per-knob benefit attribution (attributeBenefits §B.8) — all figures sourced exclusively from TaxRulesEngineService::computeScenarioOutcome() with zero Claude/HTTP calls.

## What Was Built

### ScenarioSolverService (`app/Services/ScenarioSolverService.php`)

Five public methods implementing the §B / §C spec exactly:

**`assembleBaseline(User $user, int $year, int $monthlySurplusCents = 0): array`**
- Built from `ScenarioFactResolverService::resolveAll()` output (M15 dependency)
- Annualizes YTD 401k/HSA using `Carbon::now()` elapsed fraction — the ONLY `now()`-dependent code; isolated here so `solve()` has zero time-branching
- Computes `baseline_per_period_take_home_cents` via engine helpers (SCN-01 FICA, estimatePeriodWithholdingCents) for floor guard consumption
- Returns the complete §B.2 17-field shape including `fact_set_hash` from resolver snapshot

**`solve(array $baseline, string $objective, int $year = 2026): array`**
- Dispatches to `solveTakeHome()`, `solveTaxBurden()`, `solveRetirement()`
- Returns §B.1 knob vector (clamped and guarded by engine)

**`solveTakeHome()`** — Maximise per-paycheck take-home:
- K3 = max(current_pct, match_threshold_pct) — captures free match, never leaves money
- K2 = 0 (traditional lowers FITW → higher take-home)
- K4 = current HSA (unchanged)
- K5 = 0 additional IRA
- K6 = `income_objective_transfer_share` × per-paycheck gain (routes gain to auto-savings)

**`solveTaxBurden()`** — Minimise current-year federal tax via greedy fill:
1. K3 to match threshold (dominant free-return rule)
2. K4 HSA → largest `hsa_grid_step_cents` ($250) grid step passing the take-home floor
3. K3 continue → raise in 1-pt steps while floor holds and 401k room remains
4. K5 trad IRA → largest quarter-grid amount where engine-clamped deductible > 0 and floor holds
5. K6 = 0

**`solveRetirement()`** — Maximise retirement savings delta:
1. K3 to max floor-safe deferral (up to annual limit)
2. K2 from `rothVsTraditionalBand(marginalRate(taxable_current, status))` — engine helpers only
3. K4 HSA → fill room within floor
4. K5 IRA → fill remaining shared room on quarter-grid; Roth/trad split per band + phase-out cap
5. K6 = ceil(K5_annual / periods) — funds the IRA transfer per paycheck

**`solveBalanced(array $thKnobs, array $rKnobs, array $tbKnobs, array $baseline, int $year): array`**
- §C.2 TB-seeded rule: when TB diverges from BOTH TH and R (diffKnobs non-empty in both directions), Balanced seeds from TB and labels "Balanced — also lowest 2026 tax" (label applied by caller/API layer)
- Standard path: per-knob midpoints of TH and R, snapped to each knob's grid (roth_share → {0,25,50,75,100}; HSA → $250 steps; IRA → quarter-grid)
- Seed re-run through engine for re-clamping/re-guarding; returns clamped knob vector

**`diffKnobs(array $a, array $b, ?int $remainingIraRoomCents = null): array`**
- §C.1 epsilons from `config/optimizer-scenarios.php` divergence keys
- IRA epsilon = max(config_epsilon, ¼ × remainingRoom) when room provided
- `w4.*` never in result (identical in all scenarios by construction)
- Returns `string[]` dimension keys e.g. `['k401.deferral_pct', 'hsa.annual_election_cents']`

**`attributeBenefits(array $baseline, array $chosenKnobs, int $year = 2026): array`**
- ≤6 isolated single-knob engine calls + 1 total call = ≤7 total
- Dimensions: `k401_deferral`, `k401_roth`, `w4`, `hsa`, `ira`, `transfer`
- Interaction remainder = total − sum(singles); attributed to `header_aggregate` only
- Returns `{knobs: {...per_dim_deltas}, header_aggregate: {...total+interaction}}`

### TaxRulesEngineService Bug Fixes (uncommitted from 14-02 scope)

Four bug fixes committed here that were needed for solver correctness:
1. `projectedMagiCents()`: `$a['deferralCents']` path → `deductibleTradIraCents` lookup
2. `computeScenarioOutcome()` coveredByPlan call: `$deferralCents` (not `$trad401k`)
3. `computeScenarioOutcome()` `curDedIra`: `$curTrad401k + $curRoth401k` for the IRA deductibility base
4. `coveredByPlan()`: renamed param to `$totalDeferralCents`; logic keys off total deferral correctly

### Tests (TDD RED → GREEN)

**RED commit** (`9c7bfea`): 33 failing tests with `BindingResolutionException` (class not yet created).

**GREEN commit** (`733fee4`): All 33 tests pass. Pre-existing failures unchanged (29).

| Test file | Tests | Coverage |
|---|---|---|
| `tests/Unit/ScenarioSolverTest.php` | 20 | Determinism, dominance, floor guard, K2/K6 structure, diffKnobs epsilons, attributeBenefits sum |
| `tests/Feature/Scenarios/ScenarioAgreementTest.php` | 13 | Agreement/conflict model, Balanced synthesis, TB-seeded rule, lowest-tax badge, no-HTTP guard |

**Final test run:** 907 passed, 29 failed (pre-existing), 1 risky — **0 new failures.**

## Architecture Decisions

| Decision | Rationale |
|---|---|
| `assembleBaseline()` is the ONLY `now()` call | Solvers must be deterministic; same baseline × same objective → identical output every time. Annualization isolated here. |
| Floor guard via pre-computed `baseline_per_period_take_home_cents` | Engine returns deltas only; absolute take-home requires baseline. Computed once (with now()) in assembleBaseline(); consumed in solve() without any time calls. |
| diffKnobs IRA epsilon as optional param | diffKnobs(a, b) callers may not have remaining IRA room. Default = config_epsilon (50_000 cents); if room is provided, uses max(config, room/4). |
| SE income set to 0 in assembleBaseline | In v2.1, `income.annual_gross_cents` from the resolver already combines W2+SE. Adding se_income_cents separately would double-count. SE is invariant across scenario knobs per spec, so 0 is safe for delta math. |
| TaxRulesEngineService bug fixes committed here | These were 14-02 scope (same PR branch) but left uncommitted. Committing in 14-06 since the solver depends on them for correctness. |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] TaxRulesEngineService uncommitted bug fixes included**
- **Found during:** Task 1 GREEN phase (discovered from session summary noting "uncommitted changes")
- **Issue:** Four bug fixes to coveredByPlan, projectedMagiCents, computeScenarioOutcome were written but not committed in 14-02 scope
- **Fix:** Included in GREEN commit alongside ScenarioSolverService.php
- **Files modified:** `app/Services/TaxRulesEngineService.php`
- **Commit:** 733fee4

**2. [Rule 2 - Missing critical functionality] Added `snapshotFactSet()` call for fact_set_hash**
- **Found during:** assembleBaseline() implementation
- **Issue:** The §B.2 spec requires `fact_set_hash` for cache invalidation
- **Fix:** Added `$this->resolver->snapshotFactSet($user, $year)->fact_set_hash` to baseline assembly
- **Files modified:** `app/Services/ScenarioSolverService.php`

## Known Stubs

None. The solver produces deterministic output from real engine computations. No hardcoded placeholders.

## Threat Flags

None. ScenarioSolverService makes zero network calls, reads no user-controlled input directly (all inputs from the fact resolver which has its own validation layer), and writes nothing to the database. No new attack surface.

## Self-Check: PASSED

- [x] `app/Services/ScenarioSolverService.php` — FOUND
- [x] `tests/Unit/ScenarioSolverTest.php` — FOUND
- [x] `tests/Feature/Scenarios/ScenarioAgreementTest.php` — FOUND
- [x] Commit `9c7bfea` (RED test files) — FOUND
- [x] Commit `733fee4` (GREEN implementation) — FOUND
- [x] 0 new test failures introduced
