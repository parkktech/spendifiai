---
phase: 13
slug: safety-validation-hardening
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-03
---

# Phase 13 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest PHP 3 (Laravel 12) + Vite build |
| **Quick run command** | `php artisan test --compact --filter=SAFE` (all SAFE-named tests) |
| **Per-service filter** | `php artisan test --compact --filter=<TouchedTest>` (e.g. `BannedPhraseSystem`, `FramingReviewPin`, `HardBlockRefusal`, `InjectionPen`, `SsnMaskingAudit`, `Safe03Consolidation`) |
| **Full suite command** | `php artisan test --compact` + `npm run build` |
| **Baseline** | 1,250 tests green as of P14 verification (2026-07-03) — Assumption A5. P13 must pass at this count or higher; zero NEW failures allowed (binding constraint 6). |
| **AI calls in tests** | None — `Http::fake()` / `Http::preventStrayRequests()` for every path; no live Anthropic key needed |

---

## Sampling Rate

- **Per task commit:** `php artisan test --compact --filter=SAFE` (all SAFE-named tests) + the touched-test filter
- **Per wave merge:** `php artisan test --compact` (full 1,250+ suite) + `vendor/bin/pint --dirty`
- **Phase gate (before `/gsd-verify-work`):** full suite green + SAFE filter green + `npm run build` clean

---

## Validation Architecture (from 13-RESEARCH.md)

1. **SAFE-01 framing gates (13-01, W1):** static source-scan over the THREE v2.1 optimizer system prompts (`BannedPhraseSystemPromptsTest`, reusing `bannedPhraseList()` — single source of truth) with a NARROWED negation-cue skip: a cue only suppresses a match within 3 tokens immediately before it (or a line-anchored allowlist of NarrationService:55 / OptimizationReportNarratorService:57) — an assertive line that only later contains "do not" still FAILS. `FramingReviewPinTest` pins every approved liability-reframed ceiling phrase verbatim to its exact config/source location, and pins the never-surface trio by ACTUAL mechanism: `residential_solar_2026_primary_home` + `gambling_losses_fully_deductible` at `band => 'suppress'`; `ev_credit_30d` by `effective_end === '2025-09-30'` AND `status === 'expired'` (band stays `'conditional'` ON PURPOSE — it feeds the retroactive amended-return scanner; never flip it to suppress). "if your plan allows" pinned in config/optimizer-scenarios.php only (lines 68/97/104), not the vacuous EmployerMatchGapDetector.php:81 comment. Both static gates are RED-provable — a demonstrated temporary-mutation → red → revert → green is recorded in the SUMMARY. The 12-call-site framing audit worksheet (CERTIFIED / SCOPED-OUT / N-A per site) lands in the SUMMARY.
2. **SAFE-06 refusal detector (13-02, W2):** `HardBlockRefusalService::check()` phrase/n-gram detection over `config('safe-refusal.clusters')` with ZERO Claude calls (research Pitfall 1) — every Dirty-Dozen cluster (831(b) micro-captive, conservation easement, offshore/FBAR-FATCA, Malta pension / abusive foreign trust, nonprofit shelter / corporation sole / "start a ministry", crypto non-reporting, cash structuring, PPLI, Hess body-mod) is detected by ≥1 abusive-corpus fixture; a legitimate-financial corpus (trust account, captive customer, ministry donation, HSA) returns null (Pitfall 5, multi-word triggers only). Wired BEFORE the Claude call in both `InterviewOrchestratorService::recordAnswer()` (escape-hatch) and `AIQuestionController::chat()`. D17 zero-call tests (`NoClaudeScenarioTest`, `ObjectiveEnqueueTest`) re-run as a regression gate; feature test uses `Http::preventStrayRequests()` so any Anthropic call fails. Education copy is what/why only (config-sourced, never Claude); config carries the best-effort disclaimer + anti-waste principle strings.
3. **SAFE-02/07 injection pen-test (13-03, W2):** additive schema-whitelist output validation intersects returned fields with `getFieldSchema(category)` and drops non-schema keys — `InjectionPenTest` proves it across the vision/binary paths DC-01 (W-2 PDF), DC-02 (image), DC-04 (DOC-07 BENEFITS_GUIDE_FIELDS — enumerated by name per SAFE-07), DC-05/DC-06 (TIER2 substantiation). Adversarial `Http::fake` responses (schema-violating field + banned assertive phrase from the reused list + full-SSN attempt) are stripped; legitimate fields + masked SSN survive; the request used a binary content block, not raw text. The two undefended text paths — DC-08 (BankStatementParserService text fallback) and DC-09 (EmailParserService) — are wrapped in `<document_content>` delimiters + an ignore-embedded-instructions line; assertions target the CONSTRUCTED PROMPT (delimiter present) + the OUTPUT contract, not model behavior. Extraction system prompt gains an explicit ignore-embedded-directives line (Open Question 3 resolution) verified not to alter legitimate output.
4. **SAFE-04 SSN masking audit (13-03, W2):** `SsnMaskingAuditTest` proves the five-link chain as distinct assertions — (1) extract() system prompt instructs last-4 only (CRITICAL SSN RULE present); (2) `sanitizeExtraction()` renames `ssn`→`ssn_last4` and reduces every ssn-ish value to ≤ 4 digits; (3) `TaxDocument.extracted_data` cast `encrypted:array` + `$hidden`/resource exclusion on TaxDocument/UserTaxFact/IncomeOptimizationProfile; (4) `UserTaxFact` `fact_key LIKE '%ssn%'` metadata contains no digit run > 4 (Pitfall 3); (5) API/resource layer never serializes raw `extracted_data` / a full SSN.
5. **SAFE-03 payload consolidation (13-04, W3):** one authoritative `Safe03ConsolidationTest` static-scans the narration/report Claude call sites (NarrationService, OptimizationReportNarratorService, InterviewOrchestratorService) and asserts NO report/finding dollar field (`estimated_value_cents`, `net_cash_cost`, `tax_saved`, `cliff_bonus_value`) appears in an actual Http payload array (comment/docblock lines skipped, same discipline as EstimatedValueGuardTest so the exclusion docblock does not self-trip). Anchors that `EstimatedValueGuardTest`, `NoLiteralGuardTest`, and `TaxRulesEngineService` are present. RED-provable — demonstrated mutation → red → revert → green recorded in the SUMMARY.
6. **SAFE-05 hardening report (13-04, W3):** `SAFE-HARDENING-REPORT.md` binds every SAFE-01..07 requirement to concrete evidence (exact test name + config key + service method), delineates the v1.0 liability scope as a documented owner recommendation (not a silent gap), records gray-area ceiling sign-off status, and lists known limitations (vision injection = schema-constraint defense not semantic; best-effort disclaimer ≠ detection guarantee; text-path delimiters are defense-in-depth). Per Pitfall 6 the report + the green SAFE suite IS the certification — not a decaying document.

---

## Phase Requirements → Test Map (from 13-RESEARCH.md)

| Req ID | Behavior | Test Type | Automated Command | Wave |
|--------|----------|-----------|-------------------|------|
| SAFE-01 | No banned assertive phrase in the 3 v2.1 optimizer system-prompt strings | unit/static | `--filter=BannedPhraseSystem` | W1 (new) |
| SAFE-01 | BannedPhraseTemplatesTest continues to cover Phase-14 config arrays | unit/static | `--filter=BannedPhrase` | exists, green |
| SAFE-02 | Extraction sends binary content types (not raw text) for PDF/image paths | unit | `--filter=InjectionPen` | W2 (new) |
| SAFE-02 | Email + statement text-fallback paths wrap content in `<document_content>` | unit/pen | `--filter=InjectionPen` | W2 (new) |
| SAFE-03 | estimated_value_cents only assigned in TaxRulesEngineService | unit/static | `--filter=EstimatedValueGuard` | exists, green |
| SAFE-03 | No raw IRS literals in scenario methods | unit/static | `--filter=NoLiteral` | exists, green |
| SAFE-03 | No report/finding dollar field in any Claude payload (consolidated) | unit/static | `--filter=Safe03Consolidation` | W3 (new) |
| SAFE-04 | SSN stripping end-to-end: last-4 only; full SSN never stored or leaked | unit/audit | `--filter=SsnMaskingAudit` | W2 (new) |
| SAFE-04 | Wages + PII encrypted at rest (TaxDocument, UserTaxFact, IncomeOptimizationProfile) | unit/audit | `--filter=SsnMaskingAudit` | W2 (new) |
| SAFE-05 | Hardening report written + certifies SAFE-01..07 with evidence | documentation | review `SAFE-HARDENING-REPORT.md` | W3 (new) |
| SAFE-06 | HardBlockRefusalService detects Dirty-Dozen phrases, refuse-and-educate, zero AI | unit | `--filter=HardBlockRefusal` | W2 (new) |
| SAFE-06 | Escape-hatch + chat routes check refusal BEFORE Claude call | feature | `--filter=HardBlockRefusal` | W2 (new) |
| SAFE-06 | Anti-waste honesty guardrail copy pinned | unit/static | `--filter=FramingReviewPin` | W1 (new) |
| SAFE-07 | Schema whitelist drops injected fields from extraction output (incl. DC-04, TIER2) | unit/pen | `--filter=InjectionPen` | W2 (new) |
| SAFE-07 | Approved liability-reframed phrasings + never-surface trio pinned via grep test | unit/static | `--filter=FramingReviewPin` | W1 (new) |
| SAFE-07 | Framing review enumerates all gray-area modules with ceiling phrasings | documentation | part of `SAFE-HARDENING-REPORT.md` | W3 (new) |

---

## Per-Task Verification Map

*Populated by planner/executor — every SAFE task maps to ≥1 automated assertion; the D17 zero-call re-run (13-02) and the two RED-provable static-gate demonstrations (13-01, 13-04) are MANDATORY rows.*

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | Status |
|---------|------|------|-------------|-----------|-------------------|--------|
| (filled during planning/execution) | | | | | | ⬜ |

---

## Wave 0 Requirements

Existing Pest + Vite infra suffices — no new frameworks. New artifacts introduced by this phase:

- [ ] `tests/Unit/BannedPhraseSystemPromptsTest.php` — SAFE-01 service-file system-prompt static gate (13-01)
- [ ] `tests/Unit/FramingReviewPinTest.php` — SAFE-07 pinned-phrasing + never-surface trio (13-01)
- [ ] `config/safe-refusal.php` — SAFE-06 keyword clusters + education copy + disclaimer + anti-waste principle (13-02)
- [ ] `app/Services/HardBlockRefusalService.php` — SAFE-06 zero-AI detector (13-02)
- [ ] `app/Exceptions/HardBlockRefusalException.php` — self-rendering refusal (13-02)
- [ ] `tests/Feature/HardBlockRefusalTest.php` — SAFE-06 refusal detector feature test (13-02)
- [ ] `tests/Unit/InjectionPenTest.php` — SAFE-02/07 injection paths incl. DC-04 benefits guide (13-03)
- [ ] `tests/Unit/SsnMaskingAuditTest.php` — SAFE-04 end-to-end SSN masking (13-03)
- [ ] `tests/Unit/Safe03ConsolidationTest.php` — SAFE-03 payload-exclusion consolidation (13-04)
- [ ] `.planning/phases/13-safety-validation-hardening/SAFE-HARDENING-REPORT.md` — SAFE-05 certification artifact (13-04)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Instructions |
|----------|-------------|------------|--------------|
| Gray-area ceiling sign-off | SAFE-05, SAFE-07 | Legal judgment | Owner reviews the reframed-ceiling rulings + v1.0 scope delineation in `SAFE-HARDENING-REPORT.md` before milestone certification; items still `[default ruling — owner review pending]` are flagged, not hidden |
| v1.0 assertive-framing scope | SAFE-01, A1 | Owner call | 13-01 worksheet rules v1.0 services SCOPED-OUT; owner confirms the deferred-hardening recommendation is acceptable for this milestone |
| Refusal education tone | SAFE-06 | Subjective prose | Unit test forbids implementation verbs (what/why only); spot-review the education copy in the SUMMARY |

---

## Validation Sign-Off

- [ ] SAFE-01 static gates green + both RED-provable demonstrations recorded (13-01, 13-04)
- [ ] SAFE-06 detector + wiring green; D17 zero-call tests still green after wiring
- [ ] SAFE-02/04/07 injection pen-test + SSN masking audit green (incl. DC-04 benefits guide)
- [ ] SAFE-HARDENING-REPORT.md certifies SAFE-01..07 with evidence pointers
- [ ] Full suite green (zero new failures vs 1,250 baseline) + `npm run build` clean + pint clean
- [ ] `nyquist_compliant: true` set by auditor

**Approval:** pending
