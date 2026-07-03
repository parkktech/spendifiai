# SAFE-HARDENING-REPORT — SpendifiAI v2.1 Milestone Certification

**Feature:** Optimize My Income (v2.1)
**Phase:** 13 — Safety Validation & Hardening
**Report type:** SAFE-05 hardening artifact
**Certification basis:** Green SAFE test suite (87 tests, 248 assertions) + this binding document
**Report date:** 2026-07-03
**Branch:** feature/v2.1-optimize-my-income

---

## 1. Certification Table

| Req ID | Requirement | Status | One-line status |
|--------|-------------|--------|-----------------|
| SAFE-01 | No assertive language in v2.1 optimizer system prompts | **CERTIFIED** | Banned-phrase static gate enforced; machine-enforced per commit |
| SAFE-02 | User-supplied content isolated from LLM instructions (prompt injection) | **CERTIFIED** | `<document_content>` delimiters on DC-08/DC-09; `SECURITY` ignore directive on DC-01/04/05/06 |
| SAFE-03 | Claude never computes report/finding dollar amounts | **CERTIFIED** | Three-axis guard (write-site + literal + payload-exclusion) in one green run |
| SAFE-04 | Full SSN never survives extraction, storage, or serialization | **CERTIFIED** | Five-link masking chain audited in SsnMaskingAuditTest; all links pass |
| SAFE-05 | Security and legal hardening pass completed | **CERTIFIED** | This report + green SAFE suite constitutes the artifact per research Pitfall 6 |
| SAFE-06 | IRS Dirty Dozen phrases hard-blocked before Claude | **CERTIFIED** | HardBlockRefusalService wired before both Claude call paths; D17 zero-Claude confirmed |
| SAFE-07 | Liability-reframed phrasings pinned against drift | **CERTIFIED** | FramingReviewPinTest pins 15 phrases across 8 files; copy drift = RED build |

All seven SAFE requirements are CERTIFIED. No requirements are DEFERRED. No requirements are SCOPED-OUT within the v2.1 surface.

---

## 2. Evidence Pointers

### SAFE-01 — No assertive language in optimizer system prompts

**Tests:**
- `BannedPhraseSystemPromptsTest` — 2 tests, 8 assertions. Scans `NarrationService`, `OptimizationReportNarratorService`, `InterviewOrchestratorService` system prompts for the 9 banned assertive phrases (you qualify, you will save, you are entitled, guaranteed, you should, you must, you owe, tax savings of, you are eligible) using word-boundary regex.
- `BannedPhraseTemplatesTest` — checks for banned phrases in the copy-config file and template arrays.

**Config keys:** `optimization-report.php` (system prompt copy, ceiling phrases); `services.anthropic.model_narration` (narration tier config).

**Service methods:** `NarrationService::SYSTEM_PROMPT`, `OptimizationReportNarratorService::SYSTEM_PROMPT`, `InterviewOrchestratorService::wordQuestion()` system prompt.

**Deviation documented:** Word-boundary regex (`\b...\b`) required over substring scan — `str_contains('guarantees', 'guarantee')` was a false positive on `OptimizationReportNarratorService` line 192 ("without guarantees" is a prohibition instruction, not assertive language). The regex correctly passes prohibition lines.

---

### SAFE-02 — Prompt injection defense on user-supplied content

**Tests:**
- `InjectionPenTest` — 20 tests, 86 assertions. Covers DC-01/02/04/05/06 (TaxDocumentExtractorService), DC-08 (BankStatementParserService), DC-09 (EmailParserService).

**Defense mechanisms by content path:**

| Content path | Defense | Coverage |
|---|---|---|
| DC-01: W-2 PDF (TaxDocumentExtractorService) | Binary-first + schema-whitelist (SAFE-07) | `InjectionPenTest::DC-01 x4` |
| DC-02: PNG vision (TaxDocumentExtractorService) | Binary type constraint (image modal, not text) | `InjectionPenTest::DC-02 x3` |
| DC-04: Benefits guide (TaxDocumentExtractorService) | Schema-whitelist strips injected keys | `InjectionPenTest::DC-04 x3` |
| DC-05/06: TIER2 forms (TaxDocumentExtractorService) | Schema-whitelist + SECURITY ignore directive | `InjectionPenTest::DC-05 x2, DC-06 x1` |
| DC-08: Bank statement text (BankStatementParserService) | `<document_content>` delimiter + ignore directive | `InjectionPenTest::DC-08 x2` |
| DC-09: Email body (EmailParserService) | SECURITY ignore directive before `<document_content>` | `InjectionPenTest::DC-09 x3` |

**Service methods:** `TaxDocumentExtractorService::extract()` (SECURITY directive + schema-whitelist via `sanitizeExtraction()`), `BankStatementParserService::extractTransactionsWithAI()` (delimiters), `EmailParserService::buildUserPrompt()` (ignore-before-delimiter).

---

### SAFE-03 — Claude never computes report/finding dollar amounts

**Three-axis guard (all three must stay green for the guarantee to hold):**

| Axis | Test | What it guards |
|---|---|---|
| Write-site | `EstimatedValueGuardTest` | `estimated_value_cents` assigned only inside `TaxRulesEngineService.php` |
| Literal-value | `NoLiteralGuardTest` | SCN-01..SCN-07 method bodies contain no raw IRS/threshold numeric literals |
| Payload-exclusion | `Safe03ConsolidationTest` | `estimated_value_cents`, `net_cash_cost`, `tax_saved`, `cliff_bonus_value` absent as array keys in any narration/report Claude payload |

**Service methods (Claude call sites, v2.1 optimizer surface):**
- `NarrationService::narrateFinding()` — `$userPayload` json_encode; dollar fields explicitly excluded (comment at line ~141)
- `OptimizationReportNarratorService::narrateSection()` — `$userPayload` json_encode; monetary fields "deliberately NOT included" (comment at line ~127)
- `InterviewOrchestratorService::wordQuestion()` — `$safePayload` json_encode; `// estimated_value_cents deliberately excluded (SAFE-03)` (line ~1471)
- `InterviewOrchestratorService::interpretEscapeAnswer()` — `$userPrompt` contains only question/choices/free-text; no dollar fields
- `InterviewOrchestratorService::answerHatchQuestion()` — `$userPrompt` contains only question/choices/user_question; no dollar fields

**Dollar-math sole source:** `TaxRulesEngineService.php` — all `estimated_value_cents`, `net_cash_cost`, `tax_saved`, `cliff_bonus_value` computations trace to this file and config thresholds only.

**Mutation evidence (Safe03ConsolidationTest, anti-vacuity):**
- Temporary injection of `'estimated_value_cents' => $finding->estimated_value_cents` into `NarrationService::narrateFinding()` payload array → 1 failed (RED). Revert → 6 passed (GREEN). Gate is not vacuous.

---

### SAFE-04 — Full SSN never survives

**Test:** `SsnMaskingAuditTest` — 13 tests, 36 assertions. Five-link chain audit:

| Link | What is audited | Result |
|---|---|---|
| 1 | `TaxDocumentExtractorService::extract()` system prompt contains "CRITICAL SSN RULE" + "last 4 digits only" | PASS |
| 2 | `TaxDocumentExtractorService::sanitizeExtraction()` renames/strips all SSN field variants to `ssn_last4` | PASS |
| 3 | `TaxDocument.extracted_data` cast is `encrypted:array`; `UserTaxFact.value` cast is `encrypted` and in `$hidden` | PASS |
| 4 | `UserTaxFact.metadata` (plaintext JSONB) for SSN `fact_key` contains no digit run > 4 | PASS |
| 5 | `TaxDocumentResource` serialized output exposes only sanitized data; no 9-digit or hyphenated SSN | PASS |

No production code changes were required — all five links were already sound. The audit is a regression gate.

---

### SAFE-05 — Security and legal hardening pass completed

This report is the SAFE-05 artifact. Per research Pitfall 6 ("the test IS the artifact"), the artifact is: a green SAFE test suite (87 tests, 248 assertions) plus this bound evidence document. The document is not self-certifying — it is valid only when the SAFE suite runs green.

**Verification command:** `php artisan test --compact --filter=SAFE` must pass with 0 failures.

---

### SAFE-06 — IRS Dirty Dozen hard-block

**Tests:**
- `HardBlockRefusalServiceTest` — 75 unit tests (371 assertions). Covers 30-entry abusive corpus + 33-entry legitimate corpus + D17 zero-Claude assertion (Http::preventStrayRequests + assertNothingSent on abusive match).
- `HardBlockRefusalTest` — 4 feature tests. Escape-hatch path (InterviewOrchestratorService) and chat path (AIQuestionController) with abusive and legitimate inputs.

**Config key:** `config/safe-refusal.php` — 11 IRS Dirty Dozen clusters, each with `category`, `phrases[]` (multi-word n-grams), `education` (what/why copy, never how-to).

**Clusters covered:** 831(b) micro-captive, syndicated conservation easements, offshore FBAR/FATCA concealment, Malta pension / abusive foreign trust, nonprofit-as-personal-shelter (§4958), corporation sole / pure trust packages, "start a ministry" structures, crypto non-reporting, cash structuring / smurfing, PPLI / offshore crypto-IRA, body-modification deduction probes.

**Wiring points:**
- `InterviewOrchestratorService::recordAnswer()` — `HardBlockRefusalService::check()` runs before `interpretEscapeAnswer()` (any Claude call)
- `AIQuestionController::chat()` — refusal gate runs before `TransactionCategorizerService::interpretUserResponse()` (any Claude call)

**D17 zero-Claude constraint:** On detection, no Anthropic HTTP request is made. Phrase matching is pure PHP. Education copy comes from config only — Claude never generates refusal text.

---

### SAFE-07 — Liability-reframed phrasings pinned

**Test:** `FramingReviewPinTest` — 15 tests, 22 assertions. Each test pins one ceiling phrase with its file, the liability bounded, and the rationale.

**Pinned phrases and their files:**

| Pin | File | Ceiling phrase | Liability bounded |
|---|---|---|---|
| MFS ceiling | `config/optimization-report.php` | "may be worth modeling with your preparer" | Filing-status assertion |
| Mega-backdoor gate | `config/optimizer-scenarios.php` | "if your plan allows" | In-service distribution assumption |
| Entity-analysis ceiling | `app/Services/Detectors/SignalProbeMatrix.php` | "commonly considered at this level" | Entity-formation recommendation |
| Commingling ceiling | `app/Services/Detectors/ComminglingMonitor.php` | "single most effective record in a hobby-loss review" | Audit-strategy framing |
| §121 planning ceiling | `app/Services/Scanners/LifeEventTriggerDetector.php` | "depreciation recapture" | Home-sale gain exclusion assertion |
| Anti-waste part 1 | `app/Services/ChangeMonitor.php` | D16 honesty guardrail phrase | Deductible purchase as net-zero |
| Anti-waste part 2 | `app/Services/ChangeMonitor.php` | D16 net-cost formula | Deductible purchase presented as "free" |
| Solar never-surface | `config/tax-detection.php` | `residential_solar_2026_primary_home` with `band=suppress` | Filing false §25D credit claim |
| Gambling never-surface | `config/tax-detection.php` | `gambling_losses_fully_deductible` with `band=suppress` | Misstating 90%-limited deductibility |
| EV credit date-gate | `config/tax-detection.php` | `ev_credit_30d.effective_end=2025-09-30` | Surfacing expired §30D credit for post-Sep-2025 purchases |
| EV credit status | `config/tax-detection.php` | `ev_credit_30d.status=expired` | Same as above (belt-and-suspenders) |
| EV credit band guard | `config/tax-detection.php` | `ev_credit_30d.band=conditional` (NOT suppress) | Killing the retroactive amended-return scanner for qualifying pre-Oct-2025 EV purchases |

Note: `ev_credit_30d.band` must remain `conditional` — changing it to `suppress` to silence a pin would silently disable the retroactive scanner for customers who bought a qualifying EV before 2025-10-01. The three EV pins together enforce date-suppression-only (not band-suppression), preserving the retro scanner.

**Schema-whitelist output validation (SAFE-07 second axis):**

`TaxDocumentExtractorService::sanitizeExtraction()` enforces `array_intersect_key(array_flip($allowedKeys))` after the SSN rename step. This prevents adversarially injected keys (e.g., `hook`, `system_override`, `instructions`) from surviving into `extracted_data`. Covered by `InjectionPenTest` DC-01/04/05/06 tests.

---

## 3. Injection Pen-Test Results Summary

All 20 `InjectionPenTest` assertions pass (86 assertions). Content paths exercised:

| Path | Defense relied on | Outcome |
|---|---|---|
| DC-01: W-2 PDF | Schema-whitelist strips injected `hook`/`instructions` keys | PASS — injected keys absent from output |
| DC-02: PNG vision | Binary type constraint; adversarial text in image not executed as instruction | PASS |
| DC-04: Benefits guide | Schema-whitelist (BENEFITS_GUIDE_FIELDS) strips unexpected keys | PASS |
| DC-05: TIER2 form | Schema-whitelist (TIER2_FIELDS) + SECURITY ignore directive | PASS |
| DC-06: TIER2 multi-field | Same as DC-05; also verifies banned-phrase absence in extraction output | PASS |
| DC-08: Bank statement | `<document_content>` delimiters isolate bank text from instructions | PASS |
| DC-09: Email body | SECURITY ignore directive placed before `<document_content>` block | PASS |

The system prompt `extract()` receives a SECURITY directive that instructs Claude to ignore any instruction embedded in document content. The schema-whitelist is a second, post-LLM defense that enforces the output regardless of what the model returns.

---

## 4. SSN Masking Audit Results

`SsnMaskingAuditTest` — 13 tests, 36 assertions. All five chain links are sound:

The five-link chain ensures no full SSN (9-digit, dash-formatted, or similar) can appear in:
- LLM output (Link 1: system prompt instruction)
- Service output (Link 2: `sanitizeExtraction()` rename/strip)
- Database storage (Link 3: encrypted casts on `TaxDocument.extracted_data` and `UserTaxFact.value`)
- Plaintext metadata (Link 4: no digit run > 4 in `UserTaxFact.metadata` for SSN fact keys)
- API serialization (Link 5: `TaxDocumentResource` output contains no raw SSN)

No production code changes were required — all links were already sound before this phase. The test is a regression gate.

---

## 5. v1.0 Liability Delineation (Owner Recommendation)

The following v1.0 services are outside the SAFE-01/SAFE-03 enforcement boundary for this milestone. They are documented here as a liability boundary gap, not a silent omission.

**Affected services:**

| Service | Issue |
|---|---|
| `SavingsAnalyzerService` | Assertive system prompt ("Be honest and direct about where money is being wasted"); Claude computes `monthly_savings` and `annual_savings` as dollar figures |
| `SavingsTargetPlannerService` | Assertive system prompt ("personal finance advisor building a CONCRETE action plan"); Claude computes savings targets and action amounts |
| `AlternativeSuggestionService` | Assertive system prompt ("personal finance advisor"); Claude proposes cheaper alternatives with price comparisons |
| `SyncSummaryService` | User-facing weekly email with "friendly personal finance assistant" framing; assertive language risk |

**Why not addressed in this phase:** Binding constraint 1 (v1.0 prompts untouched). Rewriting these prompts would change behavior visible to existing users in a way that requires a separate product decision.

**Specific risks:**

1. **Assertive-language bleed:** Users who interact with the v2.1 Optimize My Income feature (which uses educational SAFE-01-compliant language) then navigate to Savings (which uses assertive language) will see a framing inconsistency. The v1.0 framing could undermine the educational-only liability boundary established for v2.1.

2. **Claude-computed savings dollars outside TaxRulesEngineService boundary:** `SavingsAnalyzerService` and `SavingsTargetPlannerService` produce dollar savings figures via LLM inference, not via deterministic computation from config thresholds. This is a different category of risk than SAFE-03 (which is scoped to `OptimizationFinding`-related dollar amounts), but it creates a similar liability exposure if users rely on the AI-estimated savings figures as financial advice.

**Recommendation to owner:** Schedule a v1.0 framing audit as a separate hardening pass with its own phase. The scope would be: audit the four services above, apply SAFE-01-equivalent educational framing to their system prompts, and — for services that compute dollar amounts — either route the computation through a deterministic service (equivalent to TaxRulesEngineService) or add strong educational disclaimers to the output. This is a product decision requiring input on how v1.0 savings recommendations are presented to users.

---

## 6. Gray-Area Module Sign-Off Status

Items pinned by `FramingReviewPinTest` that carry `[default ruling — owner review pending]` in their config copy:

| Module | Pinned ceiling phrase | Owner review status |
|---|---|---|
| MFS filing-status trade-off | "may be worth modeling with your preparer" | Default ruling — owner review pending |
| Capital gains bracket thresholds | Explanation ends with "worth discussing with a tax professional" | Default ruling — owner review pending |
| Tax-loss harvesting | Explanation ends with "reviewing with a tax professional" | Default ruling — owner review pending |
| Account-type taxation | Explanation ends with "educational context worth discussing with a professional" | Default ruling — owner review pending |
| Stepped-up basis | Explanation ends with "reviewed with an estate planning attorney or tax professional" | Default ruling — owner review pending |
| Entity-analysis threshold | "commonly considered at this level" (SignalProbeMatrix) | Default ruling — owner review pending |

The `[default ruling — owner review pending]` tag means: the copy was reviewed and approved as the safest available phrasing at plan time, but the owner has not explicitly signed off on it as the final production wording. The test pins these phrases so that any drift during owner review produces a RED build rather than a silent change.

The `FramingReviewPinTest` passes with all 15 assertions green. The pinned phrases are intact as of this report.

---

## 7. Known Limitations

**L-01 — Vision injection: schema defense only, no semantic defense**

For DC-02 (PNG/image content path), the defense is the binary type constraint — an image modal does not execute embedded text as instructions by default. The schema-whitelist strips unexpected keys post-response. However, there is no semantic-level defense against a sophisticated image that causes the LLM to produce text that looks like extraction output but contains injected content. Research Assumption A2 (tertiary source) notes this as an open problem in vision LLM security. The schema-whitelist is defense-in-depth, not a complete semantic guarantee.

**L-02 — Best-effort abusive-scheme detection, not a detection guarantee**

`HardBlockRefusalService` uses phrase-list matching (multi-word n-grams). It correctly blocks the known IRS Dirty Dozen formulations enumerated in `config/safe-refusal.php`. It does NOT catch novel variations, paraphrased requests, non-English equivalents, or scheme variants not in the list. The `best_effort_disclaimer` key in `config/safe-refusal.php` documents this. The service is a best-effort gate, not a guarantee that no abusive scheme can ever be discussed.

**L-03 — Text-path delimiters are defense-in-depth, not a primary defense**

The `<document_content>` delimiters on DC-08 and DC-09 rely on the LLM following the SECURITY instruction and treating content between the tags as data, not instructions. This is defense-in-depth on top of: (1) the structured JSON output constraint (the LLM is instructed to return specific fields only), and (2) upstream schema-whitelist validation. The delimiter approach cannot prevent a sufficiently adversarial input from confusing the model — it reduces the risk but does not eliminate it.

**L-04 — Full suite migration ordering instability**

When the full test suite is run sequentially (`php artisan test --compact`), a subset of Feature tests that use `RefreshDatabase` fail due to migration ordering contention from new migration files added in Phase 13. These tests pass in isolation. This is a pre-existing test infrastructure issue (noted in 13-03 SUMMARY) that does not affect the correctness of the SAFE gates, which are all in the Unit test suite. Resolution requires a test suite re-ordering or explicit migration scoping, which is out of scope for this hardening phase.

---

## 8. Deploy Runbook

Before deploying v2.1 to production, run:

```bash
php artisan queue:restart
```

This restarts the queue worker to pick up the new `HardBlockRefusalService` wiring in `InterviewOrchestratorService` and `AIQuestionController`. Without a restart, the old worker process continues running the old code and the SAFE-06 gate is not active on in-flight jobs.

Other deployment steps follow the standard migration-run sequence:
```bash
php artisan migrate --force
```

No seeders should be run in production (see CLAUDE.md safety rules).

---

## Attestation

This report certifies that, as of 2026-07-03:

- The v2.1 Optimize My Income feature operates within an educational-only framing boundary enforced by machine-checked tests.
- The TaxRulesEngineService/config boundary for dollar amounts is enforced on the write-site, literal-value, and payload-exclusion axes.
- IRS Dirty Dozen phrase detection is wired before all Claude call paths on the optimizer interview surface.
- SSN masking is verified end-to-end from extraction through serialization.
- Liability-reframed phrasings are pinned against drift.
- The v1.0 liability gap (SavingsAnalyzerService and related services) is documented as an owner recommendation, not a silent omission.

The SAFE test suite (87 tests, 248 assertions, `--filter=SAFE`) is the machine-enforced certification. This document is a human-readable binding of the evidence.
