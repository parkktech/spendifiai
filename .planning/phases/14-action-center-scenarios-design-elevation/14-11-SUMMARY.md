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

---

## N/A + tier + frequency cluster

Executed as an additive owner feature/fix cluster on branch `feature/v2.1-optimize-my-income`.

### Item 1 — "Not applicable" per-type document exclusion

**Commits:** `8bddf7f`
**Tests:** 9 PHP (`DocumentTypeExclusionTest`) + 5 JS (`accordionDefault.test.mjs`) = 14 new tests

**What changed:**
- `user_document_type_exclusions` table (user_id, document_type, unique, additive migration).
- `UserDocumentTypeExclusion` model + `exclusionsForUser()` helper.
- `TaxDocumentController::typeStatus()`: adds `is_excluded` per type + `exclusions[]` array.
- `TaxDocumentController::toggleTypeExclusion()` (POST `/api/v1/tax-vault/type-exclusions`):
  - Allowlist of 7 types; paystub always required (422 if attempted).
  - `hsa_statement` exclusion writes `finance.has_hsa=no` as `user_edit` fact (confirmed at write time per Item 2); reversal supersedes it.
  - `medical_receipt`: preference-only, no semantic fact.
- `accordionDefault.ts`: `is_excluded=true` counts as covered; accordion auto-collapses when all types are either ready OR excluded.
- `DocumentUploadFlow.tsx`: "Not applicable" hover button on excludable types. Excluded state shows "Not applicable — marked by you" + Undo + "Upload anyway".
- `Optimize/Index.tsx`: `DocTypeStatus` gets `is_excluded?`; `onExclusionsChange` wired.

### Item 2 — user_edit facts confirmed at write time

**Commits:** Previously executed (Item 2 was completed before context reset).
**Tests:** 4 new tests in `UserTaxFactTest`

**What changed:**
- `UserTaxFact::recordFact()`: `$isSelfConfirmed` for source types `['user_edit', 'interview_answer', 'profile_field', 'detector']` → `confirmed_at=now()` at write time (previously null → invisible to `QuestionRetirementService`).
- Migration backfill: `SET confirmed_at=asserted_at WHERE source_type='user_edit' AND confirmed_at IS NULL` — fixed fact id 21 (user 1's w4.dependents_claimed).

### Item 3 — Pay-frequency derivation + income reconciliation

**Commits:** `6f838bf`
**Tests:** 14 new tests in `PayFrequencyDerivationTest`

**What changed:**
- `PaystubFactExtractorService::PAYSTUB_FACT_MAP`: added `pay_period_start` → `pay.period_start` and `pay_period_end` → `pay.period_end`.
- `proposeFrequency()`: derives `pay.frequency` from inclusive span. Canonical table: 6-8→weekly, 13-15→biweekly, 15-16 anchored (1st-15th / 16th-EOM)→semimonthly, 27-31→monthly, else null. Emits `document_extraction` proposal (D4 gate; user must confirm).
- `proposeIncomeReconciliation()`: when gross_per_period + frequency known and profile `monthly_income` diverges >5%, writes `income.paystub_monthly_base_cents` proposal. YTD-aware: sets `ytd_variable_comp_note=true` when annualized YTD ≥20% above base.
- `ScenarioFactResolverService::deriveFrequencyFromPaystub()`: span table aligned to canonical spec (13-15 biweekly; was 12-14. 27-31 monthly; was 27-32). Existing ambiguous-span test updated from 15-day unanchored (now biweekly per spec) to 10-day span (genuinely ambiguous).
- `DurableFactsController::confirm()`: when `income.paystub_monthly_base_cents` is confirmed with `is_income_reconciliation=true` metadata, updates `UserFinancialProfile.monthly_income` and dispatches `CategorizePendingTransactions` + `BuildIncomeOptimizationProfile`.
- **User 1 doc 31 retroactively derived** (tinker): 06/07→06/20 = 14 days → biweekly; $7,608.75 gross × 26 / 12 = $16,485.63/mo. Proposals now in DB: `pay.frequency=biweekly`, `income.paystub_monthly_base_cents=1648563`. `ytd_variable_comp_note=true`. Profile still says $15,000 — user can confirm to update.

**Deviation documented:** `ScenarioFactResolverTest` ambiguous-span test updated from 15-day (now biweekly per Item 3 spec) to 10-day span. This is a spec-driven behavior change, not a regression.

### Gates

| Gate | Result |
|------|--------|
| `php artisan test --compact` | 1175 passed, 0 failed, 1 risky (pre-existing) |
| `node tests/js/accordionDefault.test.mjs` | 14 passed, 0 failed |
| `npm run build` | Clean (5.74s) |
| `vendor/bin/pint --dirty` | Clean |
| Accordion tests | 14/14 pass (5 new Item 1 tests + 9 existing) |

---

## Report quality cluster

Three fixes atomically committed on branch `feature/v2.1-optimize-my-income`, 2026-07-02.

**Coordinator note acknowledged:** Fix 3 spec was upgraded mid-execution from "banner only" to "full overlay + CTA gate + notify". The full overlay implementation below is the coordinator's upgraded spec, not the original banner approach. Acknowledged in this summary per coordinator's grep-verification requirement.

**Fix 2 defect acknowledged:** After the initial Fix 2 commit, the coordinator verified live on production user 1 that zero RET findings were emitted. Root cause: `employer.allows_after_tax_401k` was an invented key; real key is `employer.after_tax_401k_available` with value `'yes'` (bool_field convention, not `'true'`). Also, RET-B/RET-C read profile model fields instead of `UserTaxFact`. Both issues corrected in a follow-on commit. Live sweep for user 1 after correction confirmed RET-A and RET-B both fire.

### Fix 1 — D13 wiring gap: UserAnsweredQuestion + DurableFactsController regen

**Problem:**
- `UserAnsweredQuestion` event was wired to `MarkOptimizationReportStale` (flag flip) but NOT to `DispatchReportGeneration` (the regen dispatch).
- `DurableFactsController::confirm()`, `confirm-with-correction`, and `supersede` paths fired zero events — confirmed facts never triggered a report rebuild.

**Fixes:**
- `DispatchReportGeneration::handleUserAnsweredQuestion()`: filters to optimization-type questions only (`QuestionType::Category` with optimization context), then calls `dispatchDebounced()` following USER_ACTION always-dispatch policy (D13).
- `AppServiceProvider`: wired `UserAnsweredQuestion` → both `MarkOptimizationReportStale::handleUserAnsweredQuestion` (already existed) and `DispatchReportGeneration::handleUserAnsweredQuestion` (new).
- `DurableFactsController::dispatchRegenAfterFactChange()`: private helper that marks report stale immediately, then dispatches `BuildIncomeOptimizationProfile` (when no profile exists) or `GenerateOptimizationReport` with 30s delay (when profile ready). Called in confirm, confirm-with-correction, and supersede paths.

**Tests:** 4 new tests in `ReportStalenessTest`:
- Queue regen on optimization `UserAnsweredQuestion`
- No regen on non-optimization `UserAnsweredQuestion`
- Confirm-via-API marks stale + queues regen
- Confirm dispatches `BuildIncomeOptimizationProfile` when no profile exists

**Commit:** `856a550`

---

### Fix 2 — RetirementOpportunitySweep: 4 RET findings from confirmed facts

**What was built:**
New `RetirementOpportunitySweep` detector registered in `RedFlagDetectorService::detectorClasses()` that emits four educational retirement-opportunity findings from confirmed durable facts. Zero Claude calls. All dollar amounts from `TaxRulesEngineService` (SAFE-03).

**Four findings:**

| ID | Key | Trigger | Band |
|----|-----|---------|------|
| RET-A | `retirement_after_tax_401k_opportunity` | `employer.after_tax_401k_available='yes'` AND total YTD < employee deferral max | conditional |
| RET-B | `retirement_contribution_mix_review` | `retirement.roth_401k_ytd_cents > 0` AND `retirement.traditional_401k_ytd_cents > 0` | conditional |
| RET-C | `retirement_match_pace_gap` | YTD annualized pace < `employer.match_threshold_pct`; gap via `TaxRulesEngineService::matchCaptureCents()` | auto |
| RET-D | `retirement_w4_step3_alignment` | `w4.step3_annual_credits_cents` ≠ `family.qualifying_children_under_17 × config CTC` | conditional |

**Corrected fact keys (Fix 2 defect remediation):**
- RET-A original (wrong): `employer.allows_after_tax_401k` === `'true'` → Corrected: `employer.after_tax_401k_available` === `'yes'` (bool_field convention per PaystubFactExtractorService::BENEFITS_FACT_MAP)
- RET-B/RET-C original (wrong): reads from `IncomeOptimizationProfile->roth_401k_ytd` / `traditional_401k_ytd` → Corrected: `UserTaxFact::currentFact($userId, 'retirement.roth_401k_ytd_cents', null, $taxYear)` etc.
- Profile fields retained as fallback only when fact not yet confirmed.

**DRIFT GATE (new):** Two tests use `ReflectionClass` to assert that `employer.after_tax_401k_available` exists in `BENEFITS_FACT_MAP` and that `retirement.{roth,traditional}_401k_ytd_cents` + `w4.step3_annual_credits_cents` exist in `PAYSTUB_FACT_MAP`. Fails loudly on key rename — prevents this class of drift.

**Live sweep results (user 1, tax_year 2026, post-correction):**
```
Confirmed retirement facts for user 1:
  employer.after_tax_401k_available = yes
  w4.step3_annual_credits_cents = 320000
  retirement.traditional_401k_ytd_cents = 60870
  retirement.roth_401k_ytd_cents = 15218

RET findings emitted: 2
  - retirement_after_tax_401k_opportunity (RET-A)
  - retirement_contribution_mix_review (RET-B)
```

RET-C and RET-D not emitted for user 1 because `employer.match_pct` and `family.qualifying_children_under_17` are not confirmed facts — correct behavior.

**Report rebuilt:** `Bus::dispatchSync(new GenerateOptimizationReport(1, 2026))` ran cleanly. Final finding count for user 1: 26 findings including `retirement_after_tax_401k_opportunity` and `retirement_contribution_mix_review`.

**Tests:** 22 tests in `RetirementOpportunitySweepTest` (19 original + 2 DRIFT-GATE + 1 composite user-1 baseline)

**Commits:** `8bad59d` (initial implementation), `e7c871c` (corrected fact keys + DRIFT-GATE tests)

---

### Fix 3 — Honest section zero-states, full overlay, CTA gating, notification

**Coordinator spec upgrade acknowledged:** Original spec was "banner only". Upgraded mid-execution to: (a) full overlay, (b) gate CTAs, (c) notify on completion. All three implemented below.

#### Per-section SCALE badges (RED/YELLOW/GREEN/ANALYZING)

`OptimizationReportView.tsx`:
- `SectionScale` type: `'RED' | 'YELLOW' | 'GREEN' | 'ANALYZING'`
- `sectionScale(findings)`: 0 findings → ANALYZING, any `high` severity → RED, any `medium` → YELLOW, else GREEN
- `ScaleBadge` component maps scale to `sw-*` Badge variants
- Each `SectionCard` header now shows the section-scale badge — per-section honesty on a READY report

#### Full overlay during regeneration

`OptimizationReportView.tsx`:
- Accepts `isRebuilding?: boolean` prop
- When `true`: absolute-positioned full overlay with `backdropFilter: 'blur(3px)'`, pulse spinner, copy "Report running — we'll notify you when it's ready, or check back in a few minutes"
- Content beneath dimmed and not interactive during rebuild

#### CTA gate during regeneration

`Optimize/Index.tsx`:
- `isRegenerating` computed: `report?.is_stale === true || report?.status === 'generating' || (!report && !loading)`
- `StageIndicator`: Choices and Checklist stages locked (`disabled + title="Available when your updated report is ready"`) while regenerating
- "See your options" header button and CTA card button muted/disabled during regen
- Poll `useEffect` also fires on `is_stale` (not just `status=generating`)

#### Database notification on completion

`OptimizationReportReadyNotification` (new):
- Database channel only (`via() → ['database']`)
- Payload: `{ tax_year, report_url: '/optimize', message: "Your {year} income optimization report is ready." }`
- Dispatched in `GenerateOptimizationReport::handle()` after generator completes
- Non-fatal: wrapped in try/catch + `Log::warning()` — notification failure never breaks report generation

**Tests:** 0 new PHP tests for Fix 3 (notification tested by existence of notification class + manual verify that report generation does not break).

**Commit:** `94d961a`

---

### Gates Verified (Report Quality Cluster)

| Gate | Result |
|------|--------|
| `php artisan test --compact` | 1178 passed, 0 failed, 1 risky (pre-existing) |
| `npm run build` | Clean (5.95s), zero TypeScript errors |
| `vendor/bin/pint --dirty` | Clean |
| RetirementOpportunitySweepTest (22 tests) | All 22 pass |
| ReportStalenessTest (21 tests) | All 21 pass |
| DRIFT-GATE: BENEFITS_FACT_MAP contains `employer.after_tax_401k_available` | Pass |
| DRIFT-GATE: PAYSTUB_FACT_MAP contains retirement YTD + W-4 Step 3 keys | Pass |
| Live sweep user 1 (RET-A + RET-B) | Both emit after correction |
| Report rebuild `Bus::dispatchSync(GenerateOptimizationReport(1, 2026))` | 26 findings, status cleared |
| D18: educational framing in all RET treatments | `may/could/consider/worth exploring` present |
| SAFE-03: only RET-C sets `estimated_value_cents` (from engine) | Pass |
| FLAG-20: RET-A treatment contains "if your plan allows" + mega-backdoor hedges | Pass |
| FLAG-20: RET-A treatment contains "if your plan allows" + mega-backdoor hedges | Pass |

---

## IRA multi-select + doc-driven accounts

### Fix 1 — IRA Type becomes multi-select

**Problem:** The "IRA Type" field was a single-select dropdown, but the owner holds both Traditional and Roth IRAs (Decision 2). The system already has multi-IRA support in the facts/engine layer.

**Solution (additive):**
- Added `ira_types` JSON array column to `user_financial_profiles` alongside the preserved `ira_type` string column
- Migration backfills: `ira_type IS NOT NULL` → `ira_types = [ira_type]`
- `UpdateFinancialProfileRequest` accepts `ira_types` (array, each `in:traditional,roth,sep,simple`)
- `UserProfileController::updateFinancial` syncs `ira_type = ira_types[0]` for backward compat
- `ProfileConformanceDetector` (Plane 2, Dir A2): roth-only check now reads `ira_types` array; falls back to `ira_type` for legacy rows. "Roth-only" = `roth` present AND `traditional` absent
- `IncomeOptimizerDataAssemblerService::readProfileFlags` exposes `ira_types` alongside `ira_type`
- `EnhancedProfileSection.tsx`: replaced `<select>` with multi-checkboxes (Traditional / Roth / SEP / SIMPLE)

**Commits:** `4740892`

### Fix 2 — Accounts section driven by documents/facts

**Problem:** The Tax-Advantaged Accounts checkboxes were purely form-driven, ignoring what the system already knows from documents and interviews.

**Solution (additive GET-only, user choice wins):**
- `UserProfileController::showFinancial` now returns an additive `derived_accounts` block:
  ```json
  {
    "hsa":       { "value": "no"|"yes"|null, "source": "your answers"|"documents"|... },
    "ira":       { "value": "yes"|null, "source": "documents" },
    "ira_types": {
      "traditional": { "value": true, "source": "documents" },
      "roth":        { "value": true, "source": "documents" }
    }
  }
  ```
- Sources checked: `finance.has_hsa`, `employer.hsa_deduction_ytd`, `retirement.hsa_ytd_cents`, `ira.traditional_ytd_contribution_cents`, `ira.roth_ytd_contribution_cents`, `retirement.has_ira_balance`, `finance.has_ira`
- User form choice ALWAYS wins. When `has_hsa` or `has_ira` is saved, a `user_edit` fact is written (`finance.has_hsa`, `finance.has_ira`) so the system reflects the explicit choice
- Frontend: `EnhancedProfileSection.tsx` reads `derived_accounts` for soft annotation notes; pre-fills IRA types from facts when profile has none; shows source attribution (`"(from documents)"`)
- Existing `profile` response shape unchanged — `derived_accounts` is a new top-level key

**D18 copy compliance:** All annotation notes use soft educational framing ("Based on your documents, it looks like…"; "your selection will be saved"). No raw fact keys exposed to UI.

### Rule 1 Auto-Fix — DurableFactsController humanizeValue

**Found during:** gate run (PaystubProposalFlowTest:386 regression)
**Issue:** `PaystubFactExtractorService.normalizeW4FilingStatus` (parallel executor) stores `married_joint`/`single_or_mfs`/`head_of_household` enums; `humanizeValue` in `DurableFactsController` only matched verbatim form strings, so `married_joint` fell through to `ucwords($value)` → `'Married_joint'`
**Fix:** Added normalized enum cases to the `w4.filing_status` match table; improved default fallback with `str_replace('_', ' ', $value)` before `ucwords`
**Commit:** `b81ea9b`

### Gates Verified (IRA multi-select + doc-driven accounts)

| Gate | Result |
|------|--------|
| `php artisan test --compact` | 1224 passed, 0 failed, 1 risky (pre-existing) — full suite |
| `npm run build` | Clean (5.78s), zero TypeScript errors |
| `vendor/bin/pint --dirty` | Clean |
| EnhancedProfileTest (15 tests, was 7) | All 15 pass |
| Fix 1: ira_types saves + backfills ira_type | Pass |
| Fix 1: ira_types enum validation | Pass |
| Fix 2: derived_accounts block in GET | Pass |
| Fix 2: finance.has_hsa user_edit fact written on save | Pass |
| Fix 2: finance.has_ira user_edit fact written on save | Pass |
| Fix 2: derived hsa reflects finance.has_hsa fact | Pass |
| Fix 2: derived ira_types from YTD contribution facts | Pass |
| ProfileConformanceDetector Plane 2: roth-only uses ira_types | Pass |
| Migration additive (ADD COLUMN only, backfill safe) | Pass |
| syncAccountFacts deadlock guard (try-catch, non-fatal) | `ee0f52f` |

---

## Choices repair + D23

Four defects fixed + D23 done-for-you Choices stage shipped on branch `feature/v2.1-optimize-my-income`, 2026-07-02.

### Fix 1 — W-4 filing status normalization

**Root cause:** `PaystubFactExtractorService::proposeFacts()` stored the W-4 filing status verbatim (e.g. `"Married filing jointly (or Qualifying widow(er))"`) without normalization. `TaxRulesEngineService` only accepts `married_joint | single_or_mfs | head_of_household` → engine threw at scenario compute time.

**Three-layer fix:**

1. **Extractor** (`PaystubFactExtractorService`): `normalizeW4FilingStatus(string $raw): string` static method maps all W-4 display strings to engine enums. Called in `proposeFacts()` when `fact_key === 'w4.filing_status'`; original display string preserved in `metadata['original_display']`.

2. **Resolver boundary** (`ScenarioFactResolverService`): defensive second layer in `resolveFromFact()` — if resolved fact is `w4.filing_status`, run through `PaystubFactExtractorService::normalizeW4FilingStatus()` before returning. Guards against any verbatim strings still in the DB from pre-fix writes.

3. **Snapshot normalization** (`ScenarioFactResolverService`): `normaliseFilingStatus()` extended with `'married'` → `'married_joint'` and common abbreviations (`mfj/mfs/hoh`). Applied in `resolveFromSnapshot()` for the `filing_status` column — `IncomeOptimizationProfile` stores values from `UserFinancialProfile::tax_filing_status` which uses legacy bare `'married'`.

4. **Artisan backfill** (`NormalizeW4FilingStatusCommand`): `optimizer:normalize-w4-filing-status [--user=ID] [--dry-run]` — idempotent migration for existing `is_current=true` facts with verbatim values. Run for user 1: fact id=29 migrated `"Married filing jointly (or Qualifying widow(er))"` → `"married_joint"`.

**Live verification:** `ScenarioSolverService::assembleBaseline(user 1, 2026)` → `w4_on_file.filing_status: married_joint`, all three `solve()` objectives (take_home / tax_burden / retirement) complete without enum error.

**Commits:** `b8492be`

---

### Fix 2 — Readiness lookup: confirmed facts were still blocking

**Root cause:** `config/optimization-objectives.php` chain for `pay.gross_per_period_cents` was `['derive:paystub_gross_pay', 'derive:annual_gross_over_periods', 'ask']` — both derivations return null when the derivation-rule scaffolding is incomplete, so the chain fell through to `'ask'` (null). A confirmed `UserTaxFact` for this key was never read because `'fact'` was not in the chain.

**Fixes:**
- Added `'fact'` at position 2 of `pay.gross_per_period_cents` chain: `['derive:paystub_gross_pay', 'fact', 'derive:annual_gross_over_periods', 'ask']` — resolver now reads the confirmed fact before derivations.
- Added `'prerequisite' => 'health.hsa_eligible'` to `hsa.ytd_contribution_cents` in both `take_home` and `tax_burden` objectives. When `health.hsa_eligible` resolves to `'no'` (or the profile `has_hsa=false`), the dependent fact flips to `not_applicable` instead of appearing as `blocking_missing`. Prevents non-HSA users from being gated on HSA contribution facts.

**Live verification:** `pay.gross_per_period_cents` NO LONGER in `blocking_missing` for user 1's take_home/tax_burden objectives. `hsa.ytd_contribution_cents` NO LONGER blocking (flips to not_applicable).

**Commits:** `4b73ea6`

---

### Fix 3 — D23 done-for-you Choices stage

**D23 principle:** The app does everything it can automatically; it only asks the user for things it genuinely cannot compute.

**Three changes:**

**A — Backend `enqueue-gaps` batch endpoint:**
- `OptimizationObjectiveController::enqueueAll()` (new method)
- `POST /api/v1/optimizer/objectives/{year}/enqueue-gaps` — enqueues gap questions for ALL not-ready objectives in a single call. Returns union of enqueued fact keys.
- Idempotent: objectives already ready are skipped; duplicate facts in union are deduplicated.

**B — Frontend auto-enqueue on Choices mount:**
- `Optimize/Index.tsx`: fire-once `useEffect` — when `viewMode === 'choices'` and `objectivesData` has loaded, POST `/enqueue-gaps` if any objective is not-ready. Fires only once per `objectivesData` load (`gapsEnqueued` gate). Non-fatal — catch is silent.
- Replaced dead-end "Scenarios not yet computed. Complete the interview to unlock scenario comparison." with an inline `<InterviewCard>` — when `scenariosData.options.length === 0`, the interview renders inline in the Choices stage. The user answers questions where they already are; gaps auto-resolve.
- `ObjectiveReadinessPanel`: removed `onEnqueue / enqueueing / enqueueError` props; removed "See your optimization options" button; dead-end footer copy replaced with "A few quick questions below will unlock your options" (with MessageSquare icon).

**C — MixPanel demoted:**
- `ScenarioMixPanel` moved inside a collapsible "Fine-tune this plan" expander (`ChevronDown/ChevronUp` toggle). Collapsed by default. Users who want knob-level control can expand; the default view is the scenario result cards.

**Commits:** `c32bb0f`

---

### Fix 4 — Label + questions_to_unlock accuracy

**Two fixes:**

1. **`employer.federal_withholding` label:** Added label-only template entry to `question_templates` in `config/optimization-objectives.php`:
   ```php
   'employer.federal_withholding' => ['label' => 'Annual federal withholding'],
   ```
   This key is derived (not directly asked), so it has no `'question'` key — it only provides a human-readable label for display in `blocking_missing` arrays.

2. **`questions_to_unlock` count:** `ObjectiveReadinessService` changed from checking for template presence (any key in `question_templates`) to checking for `question` key presence (`isset($templates[$key]['question'])`). Label-only template entries (like `employer.federal_withholding`) are excluded from the unlock count — they are never directly asked.

**Commits:** `4b73ea6` (included with Fix 2)

---

### Gates Verified (Choices repair + D23)

| Gate | Result |
|------|--------|
| `php artisan test` on new tests (23 tests) | 23 passed, 0 failed |
| `npm run build` | Clean (5.87s), zero TypeScript errors |
| `vendor/bin/pint --dirty` | Clean |
| W4FilingStatusNormalizationTest (12 tests) | All 12 pass |
| ChoicesStageRepairTest (11 tests) | All 11 pass |
| D17 (zero new Claude calls) | Pass — all changes deterministic |
| SAFE-03 (no dollar figures in narrative) | Pass — no amounts added to narrative payloads |
| Scenario compute live (user 1, all 3 objectives) | All 3 succeed without enum error |
| `pay.gross_per_period_cents` no longer blocking | Verified live |
| `hsa.ytd_contribution_cents` not_applicable when has_hsa=false | Verified live |
| W-4 filing_status: user 1 migrated → `married_joint` | Verified (currentFact id=29) |

**Commits:** `b8492be` (Fix 1 — W-4 normalization), `4b73ea6` (Fix 2 + Fix 4 — readiness chain + labels), `c32bb0f` (Fix 3 — D23 done-for-you), `682184c` (tests)

---

## Fact-aware suppression

**Defect:** Owner was served "Does your employer offer a 401(k)? What is your current contribution percentage?" even though `employer.has_401k=yes` was CONFIRMED (document_extraction, confirmed_at 2026-07-03) from a benefits guide. The question originated from `SignalProbeMatrix` Probe 1 (`probe_deferral_gap`) which never consulted the fact store.

**Root cause chain:**
1. `SignalProbeMatrix::run()` Probe 1 fired on payroll income — never checked `employer.has_401k` confirmed fact
2. `SurfaceHighPriorityRedFlags` listener pre-created AIQuestion(Optimization) without a fact-store check
3. `nextQuestion()` found the pre-created question via idempotency check and returned it directly, skipping `isAlreadyAnswered()` path

**Secondary bug:** `isMaxing401k()` checked `retirement.k401_contribution_ytd_cents` but `PaystubFactExtractorService` writes `retirement.traditional_401k_ytd_cents` + `retirement.roth_401k_ytd_cents` — split-key mismatch meant the probe always thought the user was NOT maxing.

**Tertiary bug:** Both `targetFactsConfirmed()` and the emission-time gate called `currentFact()` with no `taxYear`. PaystubFactExtractorService stores employer-level facts with `taxYear=$document->tax_year`. Non-scoped queries missed year-scoped document facts entirely.

### Fixes applied

| Fix | Description | Commits |
|-----|-------------|---------|
| 1 — Serve-time fact gate | `TARGET_FACTS_MAP` in `InterviewOrchestratorService`; `targetFactsConfirmed()` + `expireByFactGate()` in `nextQuestion()` and idempotency path of `createOptimizationQuestion()` | `88b832b` |
| 2 — Emission-time gate | `SignalProbeMatrix` Probe 1 checks `employer.has_401k` confirmed before emitting `probe_deferral_gap`; `isMaxing401k()` checks combined + split keys | `88b832b` |
| 3 — Backlog hygiene | `interview:sweep-fact-gate` artisan command — sweeps pending optimization questions against TARGET_FACTS_MAP + fact store; skips categorization questions | `46aa11e` |
| 4 — D18 copy cleanup | `SuggestedConfirmCard`: removed "AI Suggestion" / "Suggested treatment" / "Not counted yet" labels; added `questionText` prop for evidence-lead → ask anatomy | `a3d6dce` |
| 5 — Dual-scope fact check | `targetFactsConfirmed()` and emission-time gate now check both non-scoped (interview_answer) and year-scoped (document-extracted) facts | `33d17ec` |

### Live verification — user 1

| Check | Result |
|-------|--------|
| `employer.has_401k` non-scoped | Not found (not answered via interview) |
| `employer.has_401k` year-scoped (2026) | `yes` (document_extraction, confirmed 2026-07-03) |
| Emission-time gate fires | YES — probe_deferral_gap NOT emitted |
| Serve-time gate fires | YES — question suppressed |
| Sweep command (`--user=1`) | Scanned 0 (no probe_deferral_gap in queue) / Expired 0 |
| User 1 remaining pending optimization | 2 questions (`life_event_marketplace_premium`, `penalty_1099k_mismatch`) — no confirmed TARGET_FACTS_MAP entries for these keys; correctly NOT suppressed |
| 401k question served to user 1 | NONE — fully suppressed |

### Gates

| Gate | Result |
|------|--------|
| `FactAwareSuppressionTest` (14 tests) | All 14 pass |
| Full interview test suite (69 tests) | All 69 pass |
| `npm run build` | Clean — `InterviewCard-BRPEdxjB.js` rebuilt, zero TS errors |
| `vendor/bin/pint --dirty` | Clean |
| D17 / D18 no-regression | Pass — template questions unaffected; D18 anatomy intact |
| User 1 interview: no confirmed-fact question served | Verified — only `life_event_marketplace_premium` + `penalty_1099k_mismatch` remain |

**Commits:** `88b832b` (Fix 1+2), `46aa11e` (Fix 3 + test suite), `a3d6dce` (Fix 4 D18 copy), `33d17ec` (Fix 5 dual-scope)

---

## Clarity Pass

Executed as a follow-on session against `OptimizationReportView.tsx`, `Optimize/Index.tsx`, and `ObjectiveReadinessPanel.tsx`. All 5 fixes implemented and verified.

### Fix 1 — Semantic Section Zero-States

| Section | Before | After |
|---------|--------|-------|
| Documents That Would Strengthen | "0 areas to consider" (count subtitle) | Green "You've provided everything we'd ask for" card |
| What This Report Did Not Recommend | Empty section card rendered | Returns null — section hidden when no refused recs |
| Year-End Tax Awareness | Empty section card rendered | Returns null — collapsed into CollapsedSectionsRow |
| Educational Glossary | Count subtitle (wrong per spec) | "Key terms referenced in this report — educational context only" (no count) |
| Topical zero-finding section in Overview | "0 areas to consider" | Green "Nothing notable found in this area — looking good" |

RED/YELLOW/GREEN/ANALYZING scale preserved for analytic sections. `FindingSummaryCard` icon changes color (accent → success) when a topical section has 0 findings.

### Fix 2 — Kill the "Analysis in Progress" Lie

- New utility: `resources/js/utils/findingTypeLabel.ts` — `findingTypeLabel(type)` maps 12 snake_case prefix families to plain English labels (D18 compliant)
- New function: `renderFindingDescription(desc, type, isGenerating)` — the ONLY legal use of "Analysis in progress..." is when `isGenerating=true` AND `desc` is null
- `FindingRow` (in OptimizationReportView) and `FindingSummaryCard` (in Index.tsx Overview) both use this function
- TDD: 15 unit tests in `tests/js/findingTypeLabel.test.mjs` — all pass. Fix 2 gate test: `null description + isGenerating=false → NOT 'Analysis in progress...'`

### Fix 3 — Empty-Section Presentation

- New `CollapsedSectionsRow` component in `OptimizationReportView.tsx`
- Empty sections (year_end with no items, what_we_refused with no entries) collapse into a single muted footer row: "Nothing needed in these areas — Year-End · Did-Not-Recommend · …"
- Expandable to per-section semantic empty copy
- Full-height empty cards no longer compete visually with real findings

### Fix 4 — Journey Stage-Completion Checklist

- New `JourneyProgressChecklist` component in `Optimize/Index.tsx`
- Three stage rows: Documents / Suggestions / Questions
- Tri-state per row: green (done) / amber+count (pending) / muted lock (loading)
- Documents: green when all `typeStatus` entries have `has_ready_doc || is_excluded`; `typeStatusLoaded` flag tracks first successful load
- Suggestions: green when `proposals.length === 0` and not `factsLoading`
- Questions: green when `!needsInterview` and action center loaded
- Advance CTA affirmative (`bg-sw-success` color, checkmark prefix): "Everything's ready — See your options" when all three stages green and not regenerating
- Old bottom CTA card removed — JourneyProgressChecklist owns the CTA
- Respects existing `isRegenerating` gating (CTA disabled but visible)

### Fix 5 — Locked Cards Clickable (Owner Live-Testing, HIGH)

Coordinator relayed owner live-testing finding: "A few quick questions below will unlock your options" rendered tiny; three locked option cards dead-ended on click.

- New `LockedScenariosOverlay` component in `Optimize/Index.tsx`:
  - Replaces dead-end loading skeleton when `scenarios.options.length === 0`
  - 3 blurred ghost cards (blur-[3px], opacity-40) beneath an elevated overlay card
  - Overlay: `Lock` icon + large headline (`text-[22px] font-bold`): "{N} quick questions unlock your options"
  - Count-down: `questionsToUnlock` summed from `objectivesData.objectives` — updates after each answer as `refreshObjectives()` is called on `onAnswered`
  - Clicking overlay OR any ghost card scrolls to InterviewCard below with smooth scroll + 1.6s ring pulse (`ring-4 ring-sw-accent ring-offset-2`)
  - When last gap closes (`options.length > 0`), overlay unmounts and `ScenarioComparisonCards` renders in its place
- `ObjectiveReadinessPanel.tsx`: new optional `onUnlockClick` prop — footer becomes a clickable button (text-[13px] font-semibold, sw-accent color) when interview needed and handler provided

### Gates (Clarity Pass)

| Gate | Result |
|------|--------|
| `node tests/js/findingTypeLabel.test.mjs` | 15/15 pass |
| `php artisan test --compact` | 1224 passed, 1 risky (pre-existing) |
| `npm run build` | Clean — zero TS errors |
| `vendor/bin/pint --dirty` | Pass |
| D18 copy rules | All rendered text human-readable; no raw snake_case keys or jargon |
| `sw-*` tokens | All new components use only `sw-*` design tokens |
| Fix 5 acknowledgment | LockedScenariosOverlay implemented; coordinator grep-target: `LockedScenariosOverlay` |

**Clarity Pass Commits:**
- `7503831` — test(14-11): Fix 2 gate — 15 JS tests for renderFindingDescription
- `4e4f582` — feat(14-11): renderFindingDescription utility (Fix 2 GREEN)
- `401a2c1` — feat(14-11): OptimizationReportView clarity pass (Fix 1+2+3)
- `a418e24` — feat(14-11): Optimize/Index clarity pass (Fix 1+2+4)
- `6a730be` — feat(14-11): ObjectiveReadinessPanel Fix 5 unlock footer CTA

### Fix 6 — Done-for-you interview loop (session-drain re-enqueue)

**Root cause (coordinator-confirmed):** `enqueueGaps` used `$excluded = array_merge($queue, $asked, $skipped)` — this permanently excluded any key in `asked[]` even if it was served but never answered (user navigated away). The 5 gap keys were marked `asked` after question creation; on return visit `enqueueGaps` saw them in `asked[]` and refused to re-enqueue. Queue drained → false "Review complete — 0 questions".

**Backend fix (ObjectiveReadinessService.php):**
- Narrowed exclusion to only ANSWERED keys: `$answeredInAsked = array_filter($asked, fn(key) => UserTaxFact::currentFact exists for user+year)`
- `$excluded = array_merge($queue, answered_asked, $skipped)` — served-but-unanswered keys are now re-enqueueable

**Frontend fix (InterviewCard.tsx):**
- New `onQueueEmpty?: () => void` prop — called when queue drains (on init with queue_size=0 OR fetchNextQuestion returns null)
- New `remainingFacts?: Array<{label, source}>` prop — shown in "Looking for more questions..." spinner state instead of terminal dead-end
- `no-questions` state: when `onQueueEmpty` is wired, renders a spinner with remaining fact labels

**Frontend wiring (Index.tsx):**
- `interviewKey` state: incrementing this forces React to unmount/remount InterviewCard, triggering fresh `initSession()`
- `handleInterviewQueueEmpty`: re-fires `enqueueGaps` POST → `refreshObjectives()` → after 900ms increments `interviewKey`
- `enqueueAttempts` state: caps loop at 3 (prevents infinite if genuinely nothing to enqueue); resets to 0 on each answered question

**Test update (ObjectiveReadinessTest.php):**
- Original `it enqueueGaps dedupes against already-asked keys` test was asserting BUG behavior (asked = excluded). Replaced with two tests:
  - `it enqueueGaps can re-enqueue asked-but-unanswered keys (Fix 6)` — verifies new behavior
  - `it enqueueGaps does not re-enqueue keys with a confirmed UserTaxFact` — verifies permanent exclusion for answered keys
- Both pass: 14/14 ObjectiveReadinessTest

### Fix 7 — D18 label-only template guard (no raw key served to user)

**Root cause (coordinator-confirmed):** `employer.federal_withholding` (and similar entries) in `config/optimization-objectives.php` has only a `label` key, no `question` key. Previously:
1. `enqueueGaps` would enqueue these keys as gaps
2. `createTemplateQuestion` fell back to `"Please confirm: {$factKey}"` — raw snake_case key, D18 violation
3. User was served "Please confirm: employer.federal_withholding" in the interview UI

**Fix 7a (ObjectiveReadinessService.php):** Skip label-only templates in `enqueueGaps`:
```php
if (empty($templates[$key]['question'] ?? null) && empty($templates[$key]['dynamic'] ?? false)) {
    continue; // label-only — not directly askable
}
```

**Fix 7b (InterviewOrchestratorService.php):** Dual defence at question creation:
- `createOptimizationQuestion`: guard returns `null` + logs for label-only templates (D18/Fix-7 log tag)
- `createTemplateQuestion`: fallback changed from raw `$factKey` to human label: `"Please confirm: {$template['label']}"` or `"Please confirm this item applies to your situation."` (no raw key)

**Fix 7c (old queue probe_deferral_gap / battery_medicare_enrollment):** Lower priority — deferred. Old sessions may have stale finding-type keys in queue; eligible-predicate gate in `nextQuestion()` will return null (no matching template) and consume them silently. No blocking UX impact confirmed.

### Updated Gates (Fixes 6+7)

| Gate | Result |
|------|--------|
| `node tests/js/findingTypeLabel.test.mjs` | 15/15 pass |
| `php artisan test --compact` | 1225 passed, 1 risky (pre-existing) |
| `npm run build` | Clean — zero TS errors |
| `vendor/bin/pint --dirty` | Pass |
| D18 copy rules | No raw snake_case keys; label-only templates blocked at enqueue AND creation |
| Fix 6 acknowledged | interviewKey re-mount loop; onQueueEmpty callback; enqueueAttempts cap |
| Fix 7 acknowledged | enqueueGaps skips label-only; createOptimizationQuestion guards; fallback uses human label |

**Fix 6+7 Commits:**
- `669bd64` — feat(14-11): Fix 6+5 — re-enqueue loop + LockedScenariosOverlay in Index.tsx
- `bcc3e51` — fix(14-11): Fix 6+7 — enqueueGaps re-enqueue loop + D18 label-only template guard

## D24 reliability hardening

Four workstreams committed to `feature/v2.1-optimize-my-income` as part of D24
(Decision 24 — reliability doctrine):

### Work 1 — Fact-key registry + contract test
- `config/fact-registry.php`: ~110-key canonical map covering all fact namespaces
- `tests/Feature/FactRegistryContractTest.php`: 4-test contract suite — sweep for
  unregistered literals, enum-choice validation (kills married_joint bug class),
  question_template completeness, objective canonical_key coverage
- Commit: `e57f315`

### Work 2 — Queue smoke + silent no-op logging + error views
- `optimizer:queue-smoke` artisan command proving dispatch reaches worker end-to-end
- Root-cause documented: `onQueue('optimization')` vs `default` queue + ShouldBeUnique
  cache lock (300s TTL) silently drops duplicate dispatches; `Bus::dispatchSync()`
  bypassed queue which is why tinker worked
- `ScenarioChecklistService::materialize()` logs reason on empty return (no knob diverges
  vs all diverging knobs filtered)
- Brand-consistent 429/500/503 error views (JSON requests still get JSON)
- Commit: `9052639`

### Work 3 — Inertia asset versioning + new-version toast
- `HandleInertiaRequests::version()` explicit override hashing `public/build/manifest.json`
  via xxh128; `buildVersion` shared prop exposed to SPA
- `GET /api/v1/meta/build-version` public endpoint (throttle 60/min)
- `NewVersionToast` component polls every 5 minutes, shows dismissable bottom-right
  toast on hash mismatch; mounts in AuthenticatedLayout
- 4 new Pest tests verifying version derivation, shared prop, and endpoint behavior
- Commit: `aa02c3a`

### Work 4 — e2e:walk single-command + CI gate
- `npm run e2e:walk` / `composer e2e:walk` aliases running optimize-journey.spec.ts
  on chromium-desktop
- `.github/workflows/e2e-walk.yml`: PR gate provisioning PostgreSQL + Redis, seeding
  DemoAccountSeeder + ExpenseCategorySeeder, starting Laravel server, running walk
- Commit: `5017ba2`

### Gates
- Full D24 test suite: 22 passed (66 assertions) in isolation
- `vendor/bin/pint --dirty`: pass (no changes)
- `npm run build`: built in 6.19s

## Morning polish batch

Three owner-mandated items implemented on `feature/v2.1-optimize-my-income`. TDD, one atomic commit per item.

### Item 1 — Derived-value confirm-shape

**What:** When a typed interview question has a KNOWN/DERIVED (unconfirmed) value from `ScenarioFactResolverService::resolve()`, the GET `/next` endpoint now returns `derived_confirm: true` + `prefill_display` (humanized dollar) + `prefill_approximate: bool`. `InterviewCard` renders a "Based on your data, we estimate this at about $X — does that sound right?" card with [Yes, about right] [Higher] [Lower] choices. Higher/Lower pre-fill the typed input with the known value for easy correction. Choice/multi-select questions are excluded.

**Files:** `app/Http/Controllers/Api/InterviewController.php`, `resources/js/Components/SpendifiAI/InterviewCard.tsx`, `resources/js/types/spendifiai.d.ts`

**Tests:** `tests/Feature/MorningPolishItem1Test.php` — 3 tests, 14 assertions (known snapshot → derived_confirm=true; no value → false; choice question → false)

**Commits:** `ab615ed` (RED), `7d57f1c` (GREEN)

---

### Item 2 — Not-sure handling on typed fields (D17)

**What:** A phrase list (`NOT_SURE_PHRASES` constant — zero new Claude call sites) detects not-sure input on typed fields (`money_dollars`, `integer`, `year`, `pct`). Matched phrases record `UserTaxFact(value='0', metadata.unknown=true)` and, when the template has a `doc_affordance`, create a `DocumentRequest(status=Pending)`. Controller returns "No problem — we'll get this from your [doc] when you upload it." `InterviewCard` adds an explicit "I'm not sure" secondary button on all typed fields. Other unparseable text gets specific 422 messages: "Please enter a dollar amount, like $4,250" / "Please enter a whole number, like 3."

**Files:** `app/Services/InterviewOrchestratorService.php`, `app/Http/Controllers/Api/InterviewController.php`, `resources/js/Components/SpendifiAI/InterviewCard.tsx`

**Tests:** `tests/Feature/MorningPolishItem2Test.php` — 9 tests, 23 assertions (owner phrase "I don't remember", "not sure", "idk", "?"; doc_affordance path; no-doc path; "No problem" copy; specific 422 messages)

**Commits:** `58d34c8`

**D17 gate:** `NOT_SURE_PHRASES` is a `private const string[]` — phrase list comparison only, no Claude calls added.

---

### Item 3 — Checklist activation UX

**What:** `ScenarioChecklistService::resolveGatedFacts(user, knobKey, year)` returns the unconfirmed anchor facts for a confirm_ask knob with `{fact_key, label, display_value, fact_id}`. Confirmed anchor facts are excluded. `OptimizationChecklistController::formatItem()` injects `gated_facts` (array for confirm_ask, null for directive). `OptimizationChecklistView` renders `GatedFactConfirmRow` components showing "Activate by confirming: [label] [value] [Confirm]" inline. POST to `/api/v1/optimizer/facts/{id}/supersede` on confirm; on success, refetch (`refresh()`) and the item upgrades from confirm_ask to directive.

**Files:** `app/Services/ScenarioChecklistService.php`, `app/Http/Controllers/Api/OptimizationChecklistController.php`, `resources/js/Components/SpendifiAI/OptimizationChecklistView.tsx`, `resources/js/types/spendifiai.d.ts`

**Tests:** `tests/Feature/MorningPolishItem3Test.php` — 4 tests, 20 assertions (confirm_ask → gated_facts present; directive → gated_facts null; entry has label/display_value/fact_id; confirmed facts excluded from gated_facts)

**Commits:** `99d5fc2` (RED), `5d27332` (GREEN)

---

### Gates

| Gate | Result |
|------|--------|
| `php artisan test --compact` | 1250 passed, 0 failures (baseline 1234 + 16 new) |
| `vendor/bin/pint --dirty` | pass |
| `npm run build` | built in 5.79s |
| `npm run e2e:walk` | INFRA BLOCKED — `libatk-1.0.so.0` missing on server (pre-existing, same failure on prior HEAD). Remediation: `dnf install -y atk at-spi2-atk libXcomposite libXdamage libXrandr mesa-libgbm alsa-lib` OR `npx playwright install-deps chromium`. CI (`e2e-walk.yml`, ubuntu-latest runner) runs the walk regardless — local infra does not block CI green. |
| D17 — zero new Claude call sites | PASS — `isNotSurePhrase()` is string comparison only |
| D18 copy rules | PASS — "Based on your data", "No problem", "Activate by confirming" all educational framing |
