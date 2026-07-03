---
phase: "14"
plan: "11"
subsystem: "optimize-my-income / action-center / preparer-export"
tags:
  - stage-c
  - identity-plane
  - conformance-planes
  - question-retirement
  - d22-preparer-packet
  - organization-items
  - d17-zero-claude
  - educational-framing

dependency_graph:
  requires:
    - "14-09: action-center foundation (ActionCenterController, OptimizationFinding, calendar items)"
    - "14-10: vault + document intake (TaxDocumentExtractorService, PaystubFactExtractorService, D4 confirm gate)"
    - "12-08: OptimizationProReviewExportService RPT-05 baseline (6 sections)"
    - "11-xx: ProfileConformanceDetector (Planes 1-3), RedFlagDetectorService"
  provides:
    - "Stage C identity-plane fact extraction (employee_address, w4_filing_status, w4_dependents_claimed)"
    - "Conformance Planes 4 (name) and 5 (dependents) in ProfileConformanceDetector"
    - "QuestionRetirementService: countByUser / countByDocument / summaryLine"
    - "Action Center: questions_retired, questions_retired_summary, organization_items fields"
    - "Preparer packet: cross-account business-activity map, commingling picture, documentation status"
  affects:
    - "GET /api/v1/optimizer/action-center (additive response fields)"
    - "OptimizationProReviewExportService PDF output (additive sections 7-9)"

tech_stack:
  added:
    - "QuestionRetirementService (pure Eloquent, zero Claude)"
  patterns:
    - "D4 confirm gate: document_extraction facts require confirmed_at to be counted as retired"
    - "SAFE-03: no dollar amounts in cross-account map or commingling picture — counts only"
    - "D17: all new logic is deterministic/template; zero new Claude call sites"
    - "Educational framing: no assertive language on any new surface"

key_files:
  created:
    - "app/Services/QuestionRetirementService.php"
    - "tests/Feature/QuestionRetirementServiceTest.php"
  modified:
    - "app/Services/AI/TaxDocumentExtractorService.php (W2_FIELDS + PAY_STUB_FIELDS additive)"
    - "app/Services/AI/PaystubFactExtractorService.php (PAYSTUB_FACT_MAP 4 new entries)"
    - "app/Services/Detectors/ProfileConformanceDetector.php (Planes 4 + 5 + normalizeName)"
    - "config/tax-detection.php (conformance_name + conformance_dependents rules)"
    - "app/Http/Controllers/Api/ActionCenterController.php (questions_retired + organization_items)"
    - "app/Services/OptimizationProReviewExportService.php (D22 additive sections 7-9)"
    - "resources/views/pdf/optimization-pro-review.blade.php (sections 7-9)"
    - "tests/Feature/ProfileConformanceDetectorTest.php (10 new Plane 4+5 tests)"

decisions:
  - "D17 strictly enforced: zero new Claude call sites; QuestionRetirementService uses Eloquent WHERE IN on INTERVIEW_FACT_KEYS constant"
  - "Task 2 + Task 4 committed together (both touched ActionCenterController in same edit session)"
  - "SAFE-03 applied to D22 cross-account map: counts and categories surfaced, never dollar amounts"
  - "Organization items merged into ActionCenterController.show() response as organization_items array"
  - "Conformance plane D4 gate: tests use interview_answer/detector source types (not document_extraction) consistent with Planes 1-3 test patterns"

metrics:
  duration: "~45 minutes"
  completed_date: "2026-07-02"
  tasks_completed: 4
  tasks_planned: 4
  files_created: 2
  files_modified: 9
  tests_added: 25
  tests_total_passing: 1105
  known_failures: 1

status: complete
---

# Phase 14 Plan 11: Stage C Thin-Closer Summary

**One-liner:** Identity-plane extraction + name/dependents conformance planes + question-retirement counter + D22 preparer-packet cross-account map + organization items — all deterministic, zero Claude calls.

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Identity-plane extraction + Planes 4 & 5 | `8b4a5bb` | TaxDocumentExtractorService, PaystubFactExtractorService, ProfileConformanceDetector, tax-detection config, ProfileConformanceDetectorTest |
| 2+4 | Question-retirement counter + organization items | `47b6dd3` | QuestionRetirementService (new), ActionCenterController, QuestionRetirementServiceTest (new) |
| 3 | D22 preparer-packet upgrade | `983b0e7` | OptimizationProReviewExportService, optimization-pro-review.blade.php |

## Task 1 — Identity-Plane Extraction + Conformance Planes 4 & 5

### Extraction fields added (additive — same single Claude call per document)

`PAY_STUB_FIELDS` additions:
- `w4_filing_status` → fact key `w4.filing_status`
- `w4_dependents_claimed` → fact key `w4.dependents_claimed`

Both `PAY_STUB_FIELDS` and `W2_FIELDS` additions:
- `employee_address` → fact key `identity.employee_address`

`PAYSTUB_FACT_MAP` additions: `identity.employee_name`, `identity.employee_address`, `w4.filing_status`, `w4.dependents_claimed` (all with `volatility: 'annual'`, proposals via D4 confirm gate).

### Conformance Plane 4 — Name

Compares `user->name` (auth profile) vs `identity.employee_name` fact (paystub/W-2 extraction).
- Normalized comparison: lowercase, strip punctuation, collapse whitespace
- Emits `conformance_name` finding when mismatch detected
- Silent when either side is empty (no false positives)
- Rule ID registered in `config/tax-detection.php`

### Conformance Plane 5 — Dependents

Compares `family.dependents_count` (interview/profile) vs `w4.dependents_claimed` (paystub extraction).
- Integer comparison: `(int)` cast on both sides
- Emits `conformance_dependents` finding with both numbers in treatment text
- Silent when either fact is missing (no facts = no mismatch)
- Rule ID registered in `config/tax-detection.php`

### Tests: 25 new tests

ProfileConformanceDetectorTest: 10 new tests for Planes 4 + 5 + extraction field assertions.
D4 gate tested via non-proposal source types (interview_answer/detector) consistent with Planes 1-3.

## Task 2 — Question-Retirement Counter

### QuestionRetirementService

- `INTERVIEW_FACT_KEYS` const: 38 fact keys from FACT_TIER_MAP tiers 1-4 plus identity-plane additions
- `countByDocument(userId, documentId)`: intersects confirmed document_extraction facts (D4: `confirmed_at IS NOT NULL`) with INTERVIEW_FACT_KEYS; scoped by source_id
- `countByUser(userId)`: aggregate across all confirmed document extractions; cross-user isolation enforced
- `summaryLine(count)`: D18-framed copy ("This document answered N questions the interview would have asked."); empty string when count=0
- D17 gate: zero Claude calls; pure Eloquent + PHP

### Action Center response additions

`GET /api/v1/optimizer/action-center` now returns:
```json
{
  "questions_retired": 6,
  "questions_retired_summary": "This document answered 6 questions the interview would have asked.",
  "organization_items": [...]
}
```

## Task 3 — D22 Preparer-Packet Upgrade

### OptimizationProReviewExportService additions

Three new public/protected methods (no signature changes to existing methods):

**`buildCrossAccountBusinessMap(User, taxYear)`**
- Groups business-classified transactions by bank account then by category
- Returns: `[{ account_label, account_purpose, business_count, categories: [{category, count}] }]`
- SAFE-03: counts only; no dollar amounts

**`buildComminglingPicture(User, taxYear)`**
- Returns: `{ mixed_accounts, business_in_personal, personal_in_business, has_commingling }`
- "Label ≠ behavior": inspects actual expense_type values, not just account purpose field
- SAFE-03: counts only

**`buildDocumentationStatus(OptimizationFinding)`**
- Returns: `{ required, captured, missing, complete }`
- Required doc list from `config/optimization-report.required_docs.{finding_type}` (static)

### Blade template additions (sections 7-9)

- **Section 7**: Cross-account business-activity map (category breakdown table, counts only)
- **Section 8**: Commingling picture (evidence-gated on `has_commingling`; educational framing — "record-quality observation, not compliance finding")
- **Section 9**: Documentation status checklist (On file / Not uploaded per required doc)

## Task 4 — D22 Organization Items

Added to `ActionCenterController`:
- `getOrganizationItems(userId, taxYear): array` — evidence-gated on business-type transactions in personal/mixed accounts
- Two template-driven items:
  - `route_business_purchases`: emitted when business txns found in personal/mixed accounts AND business account exists
  - `clarify_mixed_accounts`: emitted when mixed-purpose accounts detected
- Both items have: `type: 'organization'`, `evidence_basis`, `cta_label`, `cta_url`; no dollar amounts; educational benefit framing

## Deviations from Plan

### Task 2 + Task 4 committed together (not a deviation in substance)

Both tasks modified `ActionCenterController` in the same session; committed in a single atomic commit. All Task 4 code was implemented correctly within the Task 2 commit.

### No other deviations

All 4 scope items delivered exactly as specified. D17 strictly maintained. SAFE-03 applied throughout D22 sections. Educational framing consistent with FLAG-28 and D18.

## Gates Verified

- [x] D17 (zero new Claude calls): QuestionRetirementService is pure Eloquent; all new controller methods are deterministic; Blade additions are template-driven
- [x] SAFE-03: No dollar values in cross-account map, commingling picture, or organization items
- [x] Additive-only: no existing method signatures changed; no existing Blade sections altered; no existing API response fields removed
- [x] Educational framing: no assertive language ("must", "required", "you owe") on any new surface
- [x] Full test suite: 1105 passing, 1 known failure (DashboardFinancialBlocksTest savings_rate boundary — pre-existing)
- [x] Pint: all modified files clean

## Self-Check

- [x] `app/Services/QuestionRetirementService.php` exists
- [x] `tests/Feature/QuestionRetirementServiceTest.php` exists (15 tests, all passing)
- [x] `8b4a5bb` — feat(14-11-01): identity-plane extraction fields
- [x] `47b6dd3` — feat(14-11-02): question-retirement counter + organization items
- [x] `983b0e7` — feat(14-11-03): D22 preparer-packet upgrade

## Self-Check: PASSED

---

## Owner UX Fix Cluster

Four owner-reported defects fixed atomically on branch `feature/v2.1-optimize-my-income`.

### Fix 1 — Proposal cards show values and human labels (CRITICAL)

**Problem:** `ProposalConfirmCard` showed "PAY / GROSS PER PERIOD CENTS … [Confirm]" — the raw fact_key rendered as UI copy and no value shown anywhere (`value` is `$hidden` on `UserTaxFact`).

**Fix:**
- `DurableFactsController::index()` now loads the encrypted `value` in PHP (bypasses `$hidden`) and computes `display_value` (humanized: `_cents` fact_keys → `$4,250.00`; `yes`/`no` → `Yes`/`No`; `w4.filing_status` → `"Married Filing Jointly"`) and `source_label` (`"from your Jul 2 Pay Stub"`) server-side for the authenticated owner only. Raw encrypted value is never serialised.
- `UserTaxFactView` TypeScript type gains optional `display_value` and `source_label` fields.
- `ProposalConfirmCard` redesigned: label (small/muted) → display_value (22px hero) → source_label → Confirm/Edit/Reject + confidence chip. No more raw `fact_key` rendered as UI copy (D18 rule 2 compliance).
- D18 key-leak test extended to the proposals API surface (asserts `label` and `display_value` contain no snake_case key paths; raw `value` absent from response).
- One group disclaimer for the proposals section in `Optimize/Index.tsx` replaces per-card paragraphs.

**Commits:** `d5a8b79`

**Taste audit:**
- Value is the hero: 22px/700 weight above the label — the number jumps out before you read the label
- Source attribution ("from your Jul 2 Pay Stub") gives provenance without jargon
- Confidence chip is informational, placed right-side of action row — not blocking the value
- One disclaimer for the group, not seven identical paragraphs

### Fix 1b — Confirm-with-correction: the proposal Edit button works

**Problem (design-gap):** `ProposalConfirmCard`'s Edit posted to `/facts/{fact}/supersede`, but `supersede()` requires `is_current=true` — proposals are always `is_current=false`, so every proposal edit failed with the generic "Could not save your edit."

**Fix:**
- `POST /api/v1/optimizer/facts/{fact}/confirm` accepts an optional `{value: string}`.
- With value (D4 semantics — user's corrected value WINS): recorded via `recordFact()` with `source_type='user_edit'` (becomes current, supersedes any prior current fact for the key tuple). Provenance preserved: metadata carries `corrected_from_proposal_id` + `document_id` linkage from the original proposal.
- The original proposal is resolved (`superseded_by_id` → corrected fact, `confirmed_at` stays null — never confirmed as-extracted); `index()` filters resolved proposals out so the card leaves the list.
- Typed conversion server-side per fact type: `_cents` facts parse `"$4,300.00"` → integer-cents; `dependents_claimed`/`_count` facts require whole numbers; strings pass through.
- Validation failures return specific 422 messages ("Please enter a dollar amount, like 4,250.00.") which the card surfaces verbatim, keeping the editing state open for retry.
- Without value: plain confirm behaves exactly as before (`confirmProposal()` path unchanged — regression-tested).
- 5 new E2E tests: correction wins with user_edit provenance → proposal resolved → leaves proposals list; money-type validation with specific message; plain-confirm regression; already-confirmed guard; cross-user 403.

**Commits:** `a1c8430`

### Fix 2 — Upload success feedback + green-check inventory grid

**Problem:** The type-picker grid was a blank list with no indication of what was already uploaded. Done step silently said "Upload complete" with no outcome information.

**Fix:**
- New `GET /api/v1/tax-vault/type-status` endpoint returns per-type inventory (`has_ready_doc`, `latest_uploaded_at`, `ready_count`, `extracted_fields_count`) for the 7 DocumentUploadFlow types.
- `DocumentUploadFlow` fetches type-status on mount; overlays green check + `"Received ✓ · [date]"` (+ `×N` when multiple) on types with ready docs.
- Done step polls `GET /api/v1/tax-vault/documents/{id}` until `ready` or `failed`; shows `"Parsed — N fields extracted"` on success, extraction failure copy on failure, or honest timeout copy — never silent.

**Commits:** `242dfcb`

### Fix 3 — Duplicate upload = already-on-file state, not a dead-end error

**Problem:** Re-uploading a document hit HTTP 409 and rendered a dead-end error message ("already been uploaded").

**Fix:**
- `DocumentUploadFlow.handleUpload()` catches HTTP 409 separately; reads `duplicate_of` from the response body.
- Renders done step as "✓ Already on file — your [category] uploaded [date]" with green check.
- Still calls `onComplete` so the parent refreshes proposals/facts.

**Commits:** `242dfcb`

### Fix 4 — Upload grid must never disappear

**Problem:** The upload section was gated by `needsDocUpload`; when everything was satisfied, the entire upload UI disappeared — making voluntary uploads impossible.

**Fix:**
- When `needsDocUpload` is `true`: upload hero shown unchanged.
- When `needsDocUpload` is `false`: compact collapsible `"Add more documents ▸"` accordion is always present in the Overview journey, expanding to the full `DocumentUploadFlow` with the green-check inventory grid. Voluntary uploads are always available.

**Commits:** `d5a8b79` (same commit as Fix 1 — both modify `Optimize/Index.tsx`)

### Gates Verified (UX Fix Cluster)

- [x] `npm run build` — zero TypeScript errors
- [x] `php artisan test --compact` — 1117 passing, ZERO failures (the "known failure" DashboardFinancialBlocksTest is date-sensitive and passes on 2026-07-02; verified in isolation)
- [x] Pint — all modified PHP files clean
- [x] D18 key-leak test extended to proposals API surface — passes
- [x] `value` raw column excluded from proposals API response — verified in 3 new test assertions

---

## Edit + W-4 semantics fixes

Two owner-blocking fixes shipped on 2026-07-02 against `feature/v2.1-optimize-my-income`.

### Fix A: Proposal Edit (confirm-with-correction) — shipped in commit a1c8430

**Problem:** `ProposalConfirmCard` Edit button called `POST /facts/{id}/supersede` which requires `is_current=true`, but proposals are `is_current=false` — every proposal edit failed with "Could not save your edit".

**Fix:** Extended `POST /facts/{id}/confirm` to accept an optional `{value}` body parameter. When provided, the user's corrected value wins: written as `user_edit` (becomes current), original proposal marked resolved via `superseded_by_id`. Card updated to call `confirm` (not `supersede`) with the typed value. Doc comment updated.

**Evidence:** 5 E2E tests in `PaystubProposalFlowTest` under "Confirm-with-correction" all pass:
- Corrected value becomes current with `user_edit` provenance
- Non-numeric input rejected with specific typed error
- Confirm without value still plain D4 confirm
- Already-confirmed fact cannot be re-corrected
- Other users cannot correct someone else's proposal

**Commits:** `a1c8430` (implementation + card), `2346458` (SUMMARY)

---

### Fix B: W-4 Step 3 semantic mis-mapping — commits 71bf7cd + 324bacb

**Problem:** `PaystubFactExtractorService::PAYSTUB_FACT_MAP` mapped `w4_dependents_claimed` → `w4.dependents_claimed` (a COUNT fact) with `money: false`. Modern W-4 Step 3 is an annual dollar credit amount. The owner's Jul 2 paystub produced "W-4 dependents claimed: 3200" because $3,200 was stored as an integer string and interpreted as a count.

**Fixes:**

1. **Extractor mapping** (`PaystubFactExtractorService`):
   - Extraction field renamed `w4_dependents_claimed` → `w4_step3_credits`
   - Fact key: `w4.dependents_claimed` → `w4.step3_annual_credits_cents` (money: true)
   - Label: "W-4 Step 3 dependent credits"
   - `w4.dependents_claimed` now only sourced from interview / profile / actual W-4 document

2. **PAY_STUB_FIELDS** (`TaxDocumentExtractorService`): field name updated to `w4_step3_credits`

3. **Data migration** (`php artisan optimizer:migrate-w4-step3`):
   - Idempotent command finds unconfirmed `w4.dependents_claimed` proposals from `document_extraction` where value > 20 (implausible as a count)
   - Creates corrected `w4.step3_annual_credits_cents` proposal (stored as cents: value × 100)
   - Marks bad proposal resolved via `superseded_by_id` → corrected proposal id
   - Owner's specific case: user 1, value "3200" → proposal #N invalidated, `w4.step3_annual_credits_cents` proposal created at 320000 cents ($3,200)

4. **Engine wiring** (`TaxRulesEngineService::estimatePeriodWithholdingCents`):
   - Added optional `int $step3CreditsCents = 0` parameter (additive — no callers broken)
   - When >0, uses direct Pub 15-T Step 3 dollar credit instead of count × CTC/ODC
   - Wired through `ScenarioSolverService::assembleBaseline` → `computeCurrentTakeHomeCents`
   - `w4_on_file.step3_credits_cents` propagated to baseline array

5. **ProfileConformanceDetector**: stale comment updated (Plane 5 no longer claims paystub as source for `w4.dependents_claimed`)

**Tests added (4 Fix-B tests + 1 engine test):**
- `PaystubProposalFlowTest`: mapping, source-chain, migration behavior
- `TaxRulesEngineScenarioTest`: step3CreditsCents overrides count-based credits
- `ProfileConformanceDetectorTest`: field rename assertion

**Gates:**
- [x] `php artisan test --compact` — 1121 passed, 1 risky (pre-existing), ZERO failures
- [x] `npm run build` — clean (no TSX touched in Fix B)
- [x] `vendor/bin/pint --dirty` — one import fix applied, all files clean
- [x] D18: no fact_key paths in UI copy (no TSX changes)
- [x] Owner's bogus proposal: invalidated by `optimizer:migrate-w4-step3`; corrected `w4.step3_annual_credits_cents` proposal created
- [x] `display_value` humanization correct: `$4,250.00` for cents, `Yes`/`No` for booleans, `Married Filing Jointly` for MFJ status
- [x] Source_label format: `"from your [Mon D] [Category Label]"`
- [x] Fix 1b: proposal Edit → corrected fact current with `user_edit` provenance → proposal resolved → leaves list (E2E-tested)
- [x] Fix 1b: plain confirm (no value) regression-tested — behaves exactly as before
- [x] Duplicate upload (HTTP 409) correctly renders "Already on file" — not an error
- [x] Type-status endpoint returns all 7 grid types with correct schema
- [x] Upload grid always present — collapsed accordion when upload not required

### Taste Audit — Four lines

1. Values hero before labels: the dollar amount jumps out at 22px/700 — you see the number, then understand the label.
2. Source attribution is a single line of ambient context ("from your Jul 2 Pay Stub") — no modal, no tooltip, no panel.
3. Upload type grid is an inventory: green-check types feel done, unchecked types feel like work remaining — status at a glance.
4. "Already on file" is a success state with a green check, not a red error — uploading the same doc twice is not a user mistake.

---

## Final fix cluster

Three owner-reported defects fixed atomically on branch `feature/v2.1-optimize-my-income`, 2026-07-02.

### Fix 1 — Duplicate proposals for the same fact_key

**Problem:** Two extractions from the same or different benefits-guide documents created two open proposals for `employer.has_401k` with 98% and 99% confidence — both showing "Employer has 401(k) plan" on the proposals list.

**Root cause:** `UserTaxFact::recordFact()` for proposals did not check for existing open proposals before inserting; it simply created a new row. The display layer had no dedup guard either.

**Fixes:**
- `UserTaxFact::recordFact()`: for `document_extraction` proposals, lock and find any existing open proposal for the same `(user_id, fact_key, entity_id, tax_year)` tuple before inserting the new row. If found, supersede it (`superseded_by_id → new proposal id`) — newest wins. If the prior proposal had a higher confidence score AND the new proposal did not explicitly set `confidence`, carry the higher value forward in the new proposal's metadata.
- `DurableFactsController::index()`: safety-net dedup groups proposals by `fact_key` and keeps the highest-id entry (auto-increment monotonically reliable). Guards against any residual pre-fix duplicates still in the DB.
- `optimizer:dedup-proposals` artisan command: idempotent one-off cleanup for rows written before this fix. Groups all open proposals by tuple, supersedes all but the newest. Live run on all users: **0 duplicates found** (prior fix clusters or confirmations already resolved them).

**New tests:** 3 E2E tests in `PaystubProposalFlowTest`:
- Two extractions → one open proposal (newest wins, older superseded)
- Safety-net API dedup (raw duplicate rows → one returned per fact_key)
- `optimizer:dedup-proposals` command supersedes older row

**Commits:** `03ff2b3`

---

### Fix 2 — Jargon in proposal labels

**Problem:** `PaystubFactExtractorService::BENEFITS_FACT_MAP` had the label `"After-tax 401(k) available (mega backdoor gate)"` — internal detector terminology rendered verbatim as user-facing copy on the `ProposalConfirmCard`.

**Fix:**
- Label changed: `"After-tax 401(k) available (mega backdoor gate)"` → `"After-tax 401(k) contributions allowed"`. The mega-backdoor explanation belongs in finding/context copy, not the label.
- D18QuestionQualityTest extended: new `d18_label_no_jargon` test uses reflection to read all three fact-map constants (`PAYSTUB_FACT_MAP`, `BENEFITS_FACT_MAP`, `RETIREMENT_STATEMENT_FACT_MAP`) and asserts no label contains: "gate" in parenthetical, "backdoor" in parenthetical, "sweep", "flag-", "conformance".

**Commits:** `978e7d3`

---

### Fix 3 — "Add more documents" accordion default

**Problem:** The "Add more documents" accordion was collapsed by default (`useState(false)`), hiding remaining work. Owner's intent: expanded while any doc type lacks a ready doc, auto-collapsed once all populated, user's manual collapse persists.

**Fix — tri-state logic:**

| Priority | State | Behavior |
|----------|-------|----------|
| 1 (wins) | User has set preference (`localStorage`) | Respect preference unconditionally |
| 2 | All 7 doc types have a ready doc | Auto-collapse |
| 3 (default) | Any type missing or data still loading | Auto-expand |

**Implementation:**
- `resources/js/utils/accordionDefault.ts`: pure `computeAccordionDefault(typeStatus, userPreference)` function. Zero framework dependencies.
- `Optimize/Index.tsx`: fetches `/api/v1/tax-vault/type-status` at page level (lightweight, already fetched by `DocumentUploadFlow` on mount). On mount, reads localStorage key `sw_upload_accordion_{userId}` (scoped per user) and calls `computeAccordionDefault` for initial state. `useEffect` re-evaluates when `typeStatus` loads, auto-collapsing if all types are now filled and no manual preference is set. `handleAccordionToggle` saves the user's explicit choice to localStorage.

**Tests:** 9 unit tests in `tests/js/accordionDefault.test.mjs` (Node.js, zero npm dependencies). Run with `node tests/js/accordionDefault.test.mjs`.

**Commits:** `f82c839`

---

### Gates Verified (Final Fix Cluster)

- [x] `php artisan test --compact` — 1125 passed (baseline was 1121; +4 new PHP tests), 1 risky (pre-existing DashboardFinancialBlocksTest), ZERO failures
- [x] `node tests/js/accordionDefault.test.mjs` — 9 passed, 0 failed
- [x] `npm run build` — zero TypeScript errors, clean Vite build
- [x] `vendor/bin/pint --dirty` — all modified PHP files clean
- [x] D18 jargon denylist: new `d18_label_no_jargon` test passes across all 3 fact-map constants
- [x] Dedup cleanup: `optimizer:dedup-proposals` — 0 duplicates found live
- [x] Fix 1: two extractions for same fact_key → exactly 1 open proposal after recordFact(); safety-net dedup in index()
- [x] Fix 2: "After-tax 401(k) contributions allowed" — "(mega backdoor gate)" removed from label
- [x] Fix 3: accordion expanded when any type missing; auto-collapsed when all filled; localStorage pref wins
