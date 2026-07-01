---
phase: 10
slug: foundation-tax-rules-engine-cross-source-snapshot
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-01
---

# Phase 10 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest PHP 3 (Laravel 12) |
| **Config file** | `phpunit.xml` (existing) |
| **Quick run command** | `php artisan test --compact --filter=TaxRulesEngine` |
| **Full suite command** | `php artisan test --compact` |
| **Estimated runtime** | ~60–90 seconds (full suite; 225+ existing tests must stay green) |

---

## Sampling Rate

- **After every task commit:** Run the quick command for the touched service (e.g. `--filter=TaxRulesEngine`, `--filter=IncomeOptimizerDataAssembler`)
- **After every plan wave:** Run `php artisan test --compact` (full suite — no regressions in the 225+ existing tests)
- **Before `/gsd-verify-work`:** Full suite green + `vendor/bin/pint --dirty` clean
- **Max feedback latency:** ~90 seconds

---

## Validation Architecture (from RESEARCH.md)

The Phase 10 deliverables are deterministic, so validation is almost entirely automated and high-signal:

1. **Config-match assertions (TAX-07):** Every rules-engine test asserts against `Config::get('tax-rules.2026.*')`, never hardcoded dollar amounts — so editing the config for a new tax year automatically surfaces tests needing review.
2. **Boundary tests:** Tax computed at each bracket edge (e.g. exactly at the 22%/24% threshold) for all filing statuses; standard-vs-itemized at the crossover point; contribution headroom at exactly the limit and at limit+catch-up.
3. **Property tests:** Marginal rate is monotonic non-decreasing in income; effective rate ≤ marginal rate; headroom never negative; Roth-band boundaries (≤12% / ≥32%) resolve deterministically.
4. **No-Claude-in-numbers guard:** A test asserts `TaxRulesEngineService` performs zero HTTP/Claude calls (`Http::preventStrayRequests()` / no `Http::fake` needed) — all numbers originate from config (supports SAFE-03 downstream).
5. **Snapshot assembly:** `IncomeOptimizerDataAssemblerService` tested against seeded transactions/profile/documents with a calendar-year (Jan 1–Dec 31) date range — guarding the IncomeDetectorService rolling-window trap flagged in research.
6. **Migration safety:** Additive/forward-only migration; encrypted money columns are TEXT; assert model round-trips encrypted values.

---

## Per-Task Verification Map

*Populated by the planner/executor as tasks are defined. Every TAX-*/CTX-* task maps to at least one automated Pest assertion; the rules-engine tasks (TAX-02..06) map to the 19 boundary/property test cases specified in RESEARCH.md.*

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | Status |
|---------|------|------|-------------|-----------|-------------------|--------|
| (filled during planning/execution) | | | | unit | `php artisan test --compact --filter=…` | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- Existing Pest infrastructure covers all phase requirements — no framework install needed.
- New test files to stub: `tests/Unit/Services/TaxRulesEngineServiceTest.php`, `tests/Unit/Services/IncomeOptimizerDataAssemblerServiceTest.php`, `tests/Feature/CrossSourceReviewServiceTest.php` (analog: `SubscriptionDetectorServiceTest`).

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| 2026 IRS constant correctness | TAX-01 | Values are external facts (IRS Rev. Proc. 2025-32 / Notice 2025-67 / Notice 2026-05); one `[ASSUMED]` SECURE 2.0 §603 threshold flagged | Cross-check `config/tax-rules.php` against cited IRS sources; resolve the `[ASSUMED]` mandatory-Roth-catch-up threshold before Phase 13 |

---

## Validation Sign-Off

- [ ] All tasks have automated verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Rules-engine tests reference `Config::get()`, not hardcoded amounts
- [ ] Full suite (225+ existing tests) stays green
- [ ] `nyquist_compliant: true` set in frontmatter (by Nyquist auditor)

**Approval:** pending
