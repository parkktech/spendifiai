---
phase: 11
slug: red-flag-detection-guided-interview-ai-feed-integration
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-01
---

# Phase 11 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest PHP 3 (Laravel 12) |
| **Config file** | `phpunit.xml` (existing) |
| **Quick run command** | `php artisan test --compact --filter=<TouchedService>` (e.g. `RedFlagDetector`, `InterviewOrchestrator`, `UserTaxFact`) |
| **Full suite command** | `php artisan test --compact` |
| **Estimated runtime** | ~90–120s full suite (349 existing tests + Phase 11 additions must stay green; 1 known pre-existing failure: `DashboardFinancialBlocksTest`, commit f8ea199 — NOT ours) |

---

## Sampling Rate

- **After every task commit:** quick command for the touched service
- **After every plan wave:** full suite — zero NEW failures allowed
- **Before verification:** full suite green (minus the known pre-existing failure) + `vendor/bin/pint --dirty` clean
- **Max feedback latency:** ~120 seconds

---

## Validation Architecture (from 11-RESEARCH.md)

1. **Detector rules are config/data-driven tests:** every detector's thresholds assert against `Config::get('tax-rules...')` (never literals); each versioned rule carries an expiration and the expiration-validator test fails on any expired-but-active rule.
2. **Materiality gates:** boundary tests at the gate thresholds (single-txn, recurring-annualized, always-interrogate categories) from config.
3. **Interview state machine:** transition-table tests (every status → allowed next statuses), resume mid-session, re-answer invalidation, prerequisite gating (e.g. backdoor-Roth probe blocked until IRA-balance answered), skip-logic vs `answerableFields()`.
4. **Feed integration regression:** `QuestionType::Optimization` added → grep-verified no `match()` without `default` breaks; `UpdateTransactionCategory` guard test proves optimization answers NEVER touch transaction categorization; all existing AIQuestion tests untouched and green.
5. **Durable-facts store:** partial-unique-index concurrency test (`is_current = true` uniqueness under concurrent insert attempts via transactions); confirm-before-write gate test (document-extracted facts persist as proposals; nothing writes to profile/facts without confirmation); provenance round-trip; carry-forward (prior-year fact visible, time-sensitive facts flagged re-confirm).
6. **No-Claude-in-numbers (SAFE-03 continuation):** `Http::preventStrayRequests()` on all detector/rules paths; NarrationService and InterviewOrchestratorService are the ONLY Claude call sites; test asserts `estimated_value_cents` and all dollar figures are excluded from Claude payloads.
7. **Educational framing:** prompt-template tests assert banned-phrase list ("you should", "you must", "you qualify") absent from system prompts; findings/questions render with disclaimer fields populated.

---

## Per-Task Verification Map

*Populated by planner/executor. Every FLAG/INT/FEED/STORE/TAX task maps to ≥1 automated Pest assertion.*

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | Status |
|---------|------|------|-------------|-----------|-------------------|--------|
| (filled during planning/execution) | | | | | | ⬜ pending |

---

## Wave 0 Requirements

- Existing Pest infrastructure covers all phase requirements — no new framework.
- New test files: `RedFlagDetectorServiceTest`, `DetectorRuleExpirationTest`, `InterviewOrchestratorServiceTest`, `InterviewSessionStateTest`, `UserTaxFactTest` (concurrency + confirm-gate), `OptimizationFeedIntegrationTest` (listener guard regression), `NarrationServiceTest` (no-numbers guard).

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Interview UX quality (one-question flow, elevate-don't-replace design bar) | INT-02, D6/D7 | Visual/taste judgment | Taste-skill v2 blocking audits run in writing per frontend task (em-dash, Pre-Flight §14, Preservation empty, Brand fidelity) — any Fail blocks the task |
| Claude question-wording quality | INT-01 | Subjective phrasing | Spot-review worded questions in SUMMARY; banned-phrase test covers the hard boundary |

---

## Validation Sign-Off

- [ ] All tasks have automated verify or Wave 0 dependencies
- [ ] No 3 consecutive tasks without automated verify
- [ ] Wave 11a suite green BEFORE wave 11b content loads
- [ ] Full suite green (349+ tests, zero new failures)
- [ ] `nyquist_compliant: true` set by auditor

**Approval:** pending
