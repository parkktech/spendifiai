---
phase: 12
slug: optimization-report-document-intake-feature-surface
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-02
---

# Phase 12 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest PHP 3 (Laravel 12) + Vite build |
| **Quick run command** | `php artisan test --compact --filter=<TouchedService>` |
| **Full suite command** | `php artisan test --compact` (699+ tests) + `npm run build` |
| **Estimated runtime** | ~2 min combined |
| **Known pre-existing failure** | `DashboardFinancialBlocksTest` (commit f8ea199) — NOT ours; zero NEW failures allowed |

---

## Sampling Rate

- **After every task commit:** quick filter for touched service/component
- **After every plan:** full suite + `npm run build` (zero TS errors) + `vendor/bin/pint --dirty`
- **Frontend tasks additionally:** taste-skill v2 blocking audits in writing (em-dash, Pre-Flight §14, Preservation empty-except-granted, Brand fidelity)

---

## Validation Architecture (from 12-RESEARCH.md)

1. **Report numbers = engine outputs:** test asserts every dollar figure in a generated OptimizationReport traces to TaxRulesEngineService/OptimizationFinding values; the existing `EstimatedValueGuardTest` grep-gate automatically covers the report narrator (3rd Claude call site) — must stay green.
2. **Staleness chain (NEW wiring):** e2e tests — document extraction completes → `TaxDocumentExtracted` event fires → report marked stale; bank sync → stale; interview answer → stale; profile change → stale. No thundering-herd: staleness = flag flip, regeneration only on demand/queued.
3. **Proposal bridge (D4 gate):** e2e — paystub extraction → `PaystubFactExtractorService` creates UserTaxFact PROPOSALS (is_current=false, per-field confidence in metadata) → NOT visible to answerableFields()/skip-logic → confirm via DurableFactsController → becomes current. Never overwrites user-entered facts (test both directions).
4. **Enum additivity:** new TaxDocumentCategory cases — grep gate: every match()/switch on the enum has a default or covers new cases; existing 25 form types' extraction behavior unchanged (regression tests).
5. **PDF export:** smoke test — report → Blade view renders → PDF file generated non-empty; memory_limit guard present; inline CSS only.
6. **Nav + pages:** badge count query guarded by hasBankConnected; `/user-profile` route (NOT /profile — Breeze collision); npm build clean; Inertia page tests for OptimizeIncome + UserFamilyProfile.
7. **Disclaimers:** every report section + optimization surface renders a non-dismissable educational disclaimer (component test), banned-phrase test on report narration prompts.

---

## Per-Task Verification Map

*Populated by planner/executor.*

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | Status |
|---------|------|------|-------------|-----------|-------------------|--------|
| (filled during planning/execution) | | | | | | ⬜ pending |

---

## Wave 0 Requirements

- Existing Pest + Vite infra covers everything — no new frameworks.
- New test files: `OptimizationReportGeneratorTest`, `ReportStalenessTest`, `PaystubFactExtractorTest` (proposal gate e2e), `TaxDocumentCategoryAdditivityTest`, `OptimizationReportPdfTest`, page/component tests for the two new pages.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| UI quality bar (elevate-don't-replace) | UI-01..03, D6 | Taste judgment | taste-skill v2 blocking audits in writing per frontend task |
| Report narrative quality | RPT-01 | Subjective prose | Spot-review in SUMMARY; banned-phrase test covers the hard boundary |
| OWNER REVIEW of the full UI | — | Standing order | P12 HOLDS unshipped until owner reviews |

---

## Validation Sign-Off

- [ ] All tasks automated-verified or Wave 0 covered
- [ ] Staleness + proposal-bridge e2e green
- [ ] Full suite green (zero new failures) + npm build clean
- [ ] `nyquist_compliant: true` set by auditor

**Approval:** pending
