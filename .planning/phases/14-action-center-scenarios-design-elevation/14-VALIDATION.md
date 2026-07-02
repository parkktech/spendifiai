---
phase: 14
slug: action-center-scenarios-design-elevation
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-02
---

# Phase 14 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest PHP 3 (Laravel 12) + Vite build |
| **Quick run command** | `php artisan test --compact --filter=<TouchedService>` |
| **Full suite command** | `php artisan test --compact` (822+) + `npm run build` |
| **Known pre-existing failure** | `DashboardFinancialBlocksTest` (f8ea199) — excluded; zero NEW failures allowed |

---

## Sampling Rate

- Per task commit: quick filter · Per plan: full suite + npm build + pint · Frontend tasks: taste-skill v2 blocking audits in writing (Decision 7/12 amended preserved-list)

---

## Validation Architecture (from 14-RESEARCH.md)

1. **D17 zero-AI-call proofs (the cost discipline's testable core):** template-first paths assert NO HTTP (`Http::fake()` + `assertNothingSent` — pattern confirmed working in codebase) for gap questions, standard probe wording, and standard finding/checklist narrations. Per-purpose model config tests (`services.anthropic.model_<purpose>` resolution + global fallback). Activity-gate tests: inactive-user (≥28d) paths dispatch zero AI jobs; lazy re-warm on simulated login. Call-counter increments + daily budget-cap graceful skip.
2. **Engine property tests (SCN-04):** ACA-cliff arithmetic guard invariant (post-guard MAGI ≤ cliff − buffer for marketplace enrollees, reallocation order Roth401k→Trad→RothIRA→TradIRA); withholding approximation vs config brackets at boundaries; futureValueRangeCents returns RANGE with assumptions array (never a single guaranteed figure); knob clamps (shared IRA limit via combined YTD; deferral floor ≥ current unless cash-constrained).
3. **Readiness/resolver (SCN-01/02):** known vs confirmed tier gating (directives require confirmed; math accepts known); readiness tick-down immediately after an interview answer; scenario_fact_sets HMAC hash + encrypted resolved_facts round-trip; no value duplication into UserTaxFact from resolution.
4. **Scenario flow (SCN-05..08):** solver determinism (same facts → same options); A/B/Balanced divergence detection; choose() persists choice + fact-set snapshot + fires the D13 user-action staleness path; chosen option renders checklist items with engine-sourced benefit cents (grep-gate: no dollar computation outside the engine — EstimatedValueGuardTest stays green).
5. **Action Center (ACT-01..05):** Stage-0 item derivation matrix (connection states × profile/interview completeness — incl. credit-card detection via BankAccount type query); item done-state persistence + timestamps; badge count extension; empty-state renders achievement copy; checked item creates a verification watch (MON handoff).
6. **ChangeMonitor (MON-01/02):** ≥2-pay-cycle persistence filter (one-off bonus deposit does NOT prompt); dedupe/no-nag within freshness window; calendar watcher fires bonus alert with configured lead time; expected-change verification marks item VERIFIED when the projected delta appears; all monitor paths activity-gated (stale users: zero work).
7. **Elevation (ELEV-01..03):** npm build clean with 41 new tokens; reduced-motion media query respected (no animation classes without the override); dark-mode counterparts present; existing pages' computed styles unchanged except granted elevation deltas (spot snapshot).

---

## Per-Task Verification Map
*Populated by planner/executor — every ACT/SCN/MON/ELEV task maps to ≥1 automated assertion; D17 zero-call tests are MANDATORY rows.*

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | Status |
|---------|------|------|-------------|-----------|-------------------|--------|
| (filled during planning/execution) | | | | | | ⬜ |

---

## Wave 0 Requirements
- Existing Pest + Vite infra suffices. Wave-1 config blockers (optimization-objectives.php, optimizer-scenarios.php, odc_amount, per-purpose model keys) precede all service code.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Instructions |
|----------|-------------|------------|--------------|
| Luxury quality bar | ELEV-*, D12 | Taste | taste-skill v2 blocking audits per frontend task; owner walkthrough after push |
| Scenario copy tone | SCN-06/08 | Subjective | banned-phrase tests cover the hard boundary; spot-review in SUMMARY |

---

## Validation Sign-Off
- [ ] D17 zero-AI-call tests green on all template-first paths
- [ ] ACA guard property test green
- [ ] Full suite green (zero new failures) + npm build clean
- [ ] `nyquist_compliant: true` set by auditor

**Approval:** pending
