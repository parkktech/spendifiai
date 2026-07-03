---
phase: 13-safety-validation-hardening
verified: 2026-07-03T19:30:00Z
status: gaps_found
score: 15/17 must-haves verified
behavior_unverified: 0
overrides_applied: 0
gaps:
  - truth: "A user whose escape-hatch or chat free text names a hard-block abusive scheme receives a refuse-and-educate response (SAFE-06 'refuse-and-educate' — end to end)"
    status: partial
    reason: "The API layer refuses correctly (verified live + by feature tests, zero Claude calls), but the EDUCATE half never reaches the user, and the chat path actively mislabels the refusal. QuestionCard.tsx types the chat response as AISuggestion; the refusal payload's 'category' field (the scheme label, e.g. '831(b) Micro-Captive Insurance') collides with the suggestion shape, so the UI renders a green emerald box reading \"I'd categorize this as 831(b) Micro-Captive Insurance\" with an Apply button — a hard-blocked scheme label presented in an affirmative endorsement frame, with the education copy ('education' field) never displayed. On the interview escape-hatch path, HardBlockRefusalException renders HTTP 200, which InterviewCard.tsx treats as a successful answer: it appends the abusive answer to local history, increments the answered count, and fetches the next question — the user believes their answer was recorded (it was not) and sees no refusal or education at all."
    artifacts:
      - path: "resources/js/Components/SpendifiAI/QuestionCard.tsx"
        issue: "handleChatSubmit (line 61-79) sets any 200 response as aiSuggestion; refusal payload {refused, category, education} renders as an affirmative category suggestion (line 244-251); handleApplySuggestion would record the scheme label as a transaction category"
      - path: "resources/js/Components/SpendifiAI/InterviewCard.tsx"
        issue: "Answer submit (line ~203-220) treats the 200 refusal JSON as a recorded answer — response body never inspected for refused:true; education copy never shown; local history diverges from server state"
    missing:
      - "Frontend check for response.data.refused === true on the chat path: render the education copy as a refusal notice (not a suggestion), no Apply affordance"
      - "Frontend check for refused === true on the interview answer path: display education copy, do NOT append to history / increment answered count / auto-advance"
  - truth: "Guard-style warnings carry best-effort (not monitoring-guarantee) disclaimers (SAFE-06 sub-clause)"
    status: failed
    reason: "config('safe-refusal.best_effort_disclaimer') exists with the correct copy and a unit test pins the key, and the config comment says 'Rendered ONCE per session' — but no production code path renders it. Its only consumer is the test (HardBlockRefusalServiceTest.php:234). The refusal JSON payload does not include it, and no UI component references it. The disclaimer is documentation-only; users never see it."
    artifacts:
      - path: "config/safe-refusal.php"
        issue: "best_effort_disclaimer key defined (line 329) but has zero renderers in app/ or resources/"
    missing:
      - "Attach the disclaimer to a user-visible surface: e.g. include it in the refusal response payload (once per session), or render it in the report's what_we_refused section footer"
human_verification:
  - test: "Owner sign-off on gray-area ceiling phrasings"
    expected: "The 6 items tagged '[default ruling — owner review pending]' (MFS trade-off, capital-gains thresholds, tax-loss harvesting, account-type taxation, stepped-up basis, entity-analysis ceiling) are reviewed and either approved or re-worded (any re-word will RED the pin test by design)"
    why_human: "Legal/product judgment; explicitly listed as manual-only in 13-VALIDATION.md"
  - test: "Owner accepts the v1.0 liability delineation (SavingsAnalyzerService, SavingsTargetPlannerService, AlternativeSuggestionService, SyncSummaryService SCOPED-OUT)"
    expected: "Owner confirms deferring the v1.0 framing audit to a separate hardening pass is acceptable for milestone close"
    why_human: "Owner scope decision per binding constraint 1"
---

# Phase 13: Safety, Validation & Hardening — Verification Report

**Phase Goal:** The complete feature is certified as holding the educational-only liability boundary through a security, legal, and PII hardening pass on the full end-to-end system.
**Verified:** 2026-07-03 (goal-backward; executor reports treated as hearsay — all tests re-run, code read, user 1 live-checked, mutations spot-repeated)
**Status:** gaps_found
**Re-verification:** No — initial verification

## Independent Evidence (not from SUMMARYs)

| Check | Result |
|---|---|
| Full suite (`php artisan test --compact`) | **1396 passed, 0 failed** (6163 assertions, 1 risky, 92s) — matches expected ~1396/0 |
| SAFE subset (`--filter=SAFE`) | **87 passed** (250 assertions — report says 248; cosmetic drift) |
| All 7 phase-13 test files + dependency | All green (note: `BannedPhraseSystemPromptsTest` requires `BannedPhraseTemplatesTest.php` in the same run for `bannedPhraseList()`; running the file alone fails CLOSED with a self-diagnosing message — acceptable fail-closed coupling, not vacuous) |
| Mutation 1 — inject "you must" into `NarrationService::SYSTEM_PROMPT` | RED (1 failed) → revert → GREEN (4 passed). SAFE-01 gate non-vacuous |
| Mutation 2 — drift pinned MFS phrase in `config/optimization-report.php` | RED (1 failed, 14 passed) → revert → GREEN (15 passed). SAFE-07 pin non-vacuous |
| Mutation 3 — inject `'estimated_value_cents' => $finding->estimated_value_cents` into `NarrationService` Claude payload | RED (1 failed, 5 passed) → revert → GREEN (6 passed). SAFE-03 payload gate non-vacuous |
| Live refusal spot-check (tinker, 10 phrasings) | 5/5 realistic scheme phrasings REFUSED (captive insurance pitch, syndicated easement, deposit structuring, start-a-ministry, crypto non-reporting); 3/3 legitimate controls PASS (kids' trust account, "captive customers", offshore oil-rig work); 2/2 paraphrases PASS THROUGH ("starting a ministry", "keep my coin profits off my return") — n-gram detection limit, matches documented L-02 limitation |
| Working tree | Both mutation files fully reverted via `git checkout --` |

## Goal Achievement — Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SC1: Every v2.1 Claude system prompt hard-codes educational framing; assertive language banned, verified by documented framing review | ✓ VERIFIED | `BannedPhraseSystemPromptsTest` green + mutation-proven RED; 12-call-site worksheet in 13-01-SUMMARY (4 CERTIFIED / 7 SCOPED-OUT / 1 N/A); `NarrationService::SYSTEM_PROMPT` read directly — rules 1-6 educational-only |
| 2 | SC2: A test suite asserts no report/finding dollar amount originates from Claude | ✓ VERIFIED | Three-axis guard: `EstimatedValueGuardTest` (write-site) + `NoLiteralGuardTest` (literals) + `Safe03ConsolidationTest` (payload keys) all green; payload axis mutation-proven RED; `narrateFinding()` payload read — monetary fields excluded (line 136-144) |
| 3 | SC3: Document content reaches Claude only inside `<document_content>` delimiters with structured JSON schema + output validation; injection pen test passes | ✓ VERIFIED | `InjectionPenTest` 20 tests green; delimiters verified IN CODE at `BankStatementParserService:257-259` (DC-08), `EmailParserService:165-172` (DC-09, ignore-directive-before-block), and `TaxDocumentExtractorService:536-543` (text fallback); schema whitelist verified in code: `array_intersect_key($sanitized, $allowedKeys)` at `TaxDocumentExtractorService:427-428` genuinely drops non-schema keys |
| 4 | SC4: SSN/wage PII follows encryption + SSN-last-4 rules within the existing audit trail | ✓ VERIFIED | `SsnMaskingAuditTest` 13 tests green; `sanitizeExtraction()` read — renames ssn variants → `ssn_last4`, strips >4-digit values to last 4 (line 402-413); `encrypted:array` cast + `$hidden` confirmed; see W-2 warning re: parse-failure log preview |
| 5 | 13-01: Static source-scan gate fails build on assertive phrase in v2.1 optimizer prompt | ✓ VERIFIED | Mutation-proven (RED/GREEN cycle re-executed by verifier) |
| 6 | 13-01: Static config-scan gate fails build on reframed-phrase drift | ✓ VERIFIED | Mutation-proven; never-surface trio pinned by actual mechanism (solar/gambling `band=suppress`; EV credit by `effective_end`/`status` with band deliberately `conditional` for the retro scanner) |
| 7 | 13-01: SUMMARY carries 12-call-site framing worksheet | ✓ VERIFIED | Table present, all 12 sites ruled with rationale |
| 8 | 13-02: User naming a hard-block scheme receives refuse-and-educate (end to end) | ✗ FAILED (partial) | API layer correct (live-verified + feature tests); UI layer broken — chat path renders the refusal as an affirmative AI category suggestion; interview path silently swallows it. See Gaps |
| 9 | 13-02: Legitimate financial free text passes unblocked | ✓ VERIFIED | Live tinker: trust account / captive customer / offshore work all pass; 33-entry legit corpus in unit test |
| 10 | 13-02: Refusal path adds no Anthropic HTTP call | ✓ VERIFIED | `Http::preventStrayRequests()` + `assertNothingSent()` in both unit (line 166, 272) and feature (98/109, 123/133) tests; service code has zero HTTP dependencies (read in full) |
| 11 | 13-03: Non-schema fields from Claude are dropped by output validation | ✓ VERIFIED | Code read (whitelist after SSN rename so `ssn_last4` survives); `InjectionPenTest` DC-01/02/04/05/06 green |
| 12 | 13-03: Both undefended text-interpolation paths delimiter-wrapped | ✓ VERIFIED | DC-08 + DC-09 verified in code (see truth 3); extractor text-fallback wrapped too (beyond plan scope) |
| 13 | 13-03: SSN masking holds end to end | ✓ VERIFIED | 5-link chain green; one log-channel edge noted as warning (below) |
| 14 | 13-04: Single consolidation test asserts no dollar field in any Claude payload | ✓ VERIFIED | Green + mutation-proven |
| 15 | 13-04: Hardening report certifies SAFE-01..07 with concrete evidence pointers | ✓ VERIFIED | Report binds exact test names + config keys; plain-language, owner-readable. Accuracy nits: L-04 (full-suite failures) no longer reproduces — suite is green sequentially; "248 assertions" is now 250 |
| 16 | 13-04: v1.0 liability scope delineated as documented owner recommendation | ✓ VERIFIED | Report §5 names all 4 services, specific risks, concrete recommendation |
| 17 | SAFE-06 sub-clause: guard-style warnings carry best-effort disclaimers | ✗ FAILED | `best_effort_disclaimer` config key exists and is test-pinned but has NO renderer anywhere in app/ or resources/ — users never see it |

**Score:** 15/17 truths verified

## SAFE-01..07 Requirement Map (verbatim, item by item)

| Req | Verdict | Evidence |
|---|---|---|
| SAFE-01 | CERTIFIED (v2.1 surface) | Gate + worksheet + mutation; v1.0 SCOPED-OUT explicitly with owner recommendation |
| SAFE-02 | CERTIFIED | Delimiters + JSON schema + output validation verified in code; pen test green |
| SAFE-03 | CERTIFIED | Three-axis guard, payload axis mutation-proven by verifier |
| SAFE-04 | CERTIFIED (one warning) | 5-link chain green; W-2 log-preview edge below |
| SAFE-05 | CERTIFIED (report accuracy nits) | Report + green SAFE suite; owner sign-offs pending are flagged, not hidden |
| SAFE-06 | **PARTIAL** | All 11 clusters present in `config/safe-refusal.php` (831(b), easements, offshore/FBAR-FATCA, Malta/foreign trusts, §4958 nonprofit, corporation sole/pure trust, start-a-ministry, crypto non-reporting, structuring, PPLI/offshore-IRA, Hess body-mod) ✓; never-surface trio in `config/tax-detection.php` pinned ✓; anti-waste principle key ✓ + ChangeMonitor pins ✓; RPT-06 what_we_refused section fed from config (7 entries incl. Dirty Dozen + structuring) ✓; refuse-and-educate wired before BOTH Claude calls ✓ — BUT the educate half never renders in the UI (Gap 1) and the best-effort disclaimer has no renderer (Gap 2) |
| SAFE-07 | CERTIFIED | Every new content path covered (DC-01/02/04/05/06/08/09 incl. vision/image and benefits guides); framing review enumerates reframed items by name as pin-test assertions |

## Anti-Pattern / Adversarial Findings

| # | File | Finding | Severity |
|---|------|---------|----------|
| W-1 | resources/js (QuestionCard, InterviewCard) | SAFE-06 refusal payload not handled by frontend — see Gap 1 | 🛑 Blocker (gap) |
| W-2 | `app/Services/AI/TaxDocumentExtractorService.php:658-661` | On JSON parse failure, `Log::error` includes `text_preview` = first 500 chars of Claude's RAW, pre-sanitization response. If a misbehaving model returned a full SSN, it would bypass the 5-link masking chain into the log channel. Narrow (only malformed-JSON responses; prompt instructs last-4) but it is exactly the defense-in-depth scenario `sanitizeExtraction` exists for. Recommend masking digit runs > 4 before logging | ⚠️ Warning |
| W-3 | `SAFE-HARDENING-REPORT.md` | L-04 claims full-suite sequential RefreshDatabase failures; the full suite now passes 1396/0 sequentially — stale limitation. "87 tests, 248 assertions" is now 250. Otherwise the report is accurate and appropriately plain-language for the owner; the v1.0 delineation and gray-area sign-off sections are honest | ℹ️ Info |
| W-4 | `13-VALIDATION.md` | Frontmatter still `status: draft`, `nyquist_compliant: false`, sign-off checkboxes unchecked — bookkeeping not updated post-execution; the req→test map itself is accurate and every row was independently confirmed green | ℹ️ Info |
| W-5 | `tests/Unit/BannedPhraseSystemPromptsTest.php` | Cross-test-file coupling on `bannedPhraseList()` — fails closed with diagnostic when run standalone; passes in full/filter runs. Acceptable by design (single source of truth), documented in the test itself | ℹ️ Info |
| W-6 | Full suite | 1 risky test in the 1396 (pre-existing; not in the SAFE set) | ℹ️ Info |

## Ruling — Item 5: Base-salary vs total-comp as the scenario tax input (P14 domain)

**Verdict: DEFENSIBLE as a documented limitation — not a gap requiring the income fact.** Reasoning from live data and code:

1. **The "confirmed income fact" alternative does not exist.** Live check of user 1: `income.annual_gross_cents` is ABSENT (no fact, any tax year). The confirmed facts are `pay.gross_per_period_cents = 760875` ($7,608.75) and `pay.frequency = biweekly` → $197,827.50 annualized base. The snapshot's `w2_wages` column holds `760875` — the per-period units bug that `assembleBaseline()`'s C2 comment documents; preferring per-period × periods is a deliberate, correct fix, with `income.annual_gross_cents` as the documented fallback when the per-period fact is absent. Bank deposits ($282.7k inflows/12mo incl. a $70.65k TRAK ACH pair and a $60k single deposit) are net-of-withholding cash, not gross comp — deriving gross from them requires exactly the inference the deterministic engine is built to avoid.
2. **The knobs act on payroll, and the delta math is bracket-stable for this user.** Scenario deltas (401(k) deferral %, Roth share, W-4, per-paycheck take-home) are computed against per-period gross, which is correct. A constant omitted income block (the bonus) cancels in deltas EXCEPT across bracket/threshold boundaries. Checked against `config/tax-rules.php`: 2026 MFJ 24% bracket starts at $211,400 taxable. Base-only: ~$165.6k taxable; with ~$44k bonus: ~$209.8k taxable — **both in the 22% bracket**. The marginal rate pricing every deferral delta is identical in both views. Knife-edge caveat: total comp above ~$243.6k gross would tip into 24% and understate deferral value by 2pp.
3. **The absolute annual-tax figure IS understated** (~$44k of income missing → roughly $9-10k of tax), but every scenario output is pinned to "illustration / under these assumptions" framing (verified in `config/optimizer-scenarios.php`), SAFE-01 bans asserted dollar precision, and — decisively — the system already surfaces this exact mismatch to this exact user: a live, open, high-severity `income_discrepancy` finding ("Your reported income and your bank deposits may differ enough to be worth a closer look before you file"), generated by the deterministic cross-source detector.

**Recommendation (backlog, not phase-13 rework):** (a) add a confirmable `income.bonus_annual_cents` / supplemental-income interview fact folded into `annual_gross_cents` for bracket placement only (knob math stays per-paycheck); (b) add one scenario-assumption line stating estimates reflect regular payroll and exclude bonus/supplemental income; (c) consider having an open `income_discrepancy` finding attach a caveat to bracket-boundary-sensitive scenario outputs.

## Deploy Runbook Review

Report §8: `php artisan queue:restart` present with correct rationale (stale worker = SAFE-06 gate inactive on in-flight jobs) ✓; `php artisan migrate --force` ✓ (v2.1 migrations exist and are additive); no-seeder warning ✓. Infra walk: `bootstrap/cache/` has no cached `config.php`, so the new `config/safe-refusal.php` is picked up without `config:cache` — but add a line: **if config caching is ever enabled, `php artisan config:cache` must be re-run or the SAFE-06 cluster list will be invisible to the detector** (`config()` would read the stale cache). No frontend assets shipped by this phase, so `npm run build` not required for phase 13 itself (will be required when Gap 1's UI fix lands).

## Gaps Summary

The backend hardening is real, tested, and mutation-proven — this was a genuinely well-executed phase at the API/service layer. Both gaps are last-mile delivery of SAFE-06's user-facing half: (1) the frontend never renders the refuse-and-educate payload — the chat UI actively re-skins a refusal as a green "I'd categorize this as [scheme name]" endorsement, and the interview UI silently pretends the blocked answer was recorded; (2) the best-effort disclaimer exists only as config + test, never shown to a user. Both are small frontend/config-consumption fixes; neither requires backend changes. Until Gap 1 lands, the "educational-only liability boundary on the full end-to-end system" (phase goal) is certified for the API surface but not the pixel surface.

---

_Verified: 2026-07-03T19:30:00Z_
_Verifier: Claude (gsd-verifier) — full suite re-run, 3 mutations re-executed, 10-phrase live refusal probe, user-1 live income audit_
