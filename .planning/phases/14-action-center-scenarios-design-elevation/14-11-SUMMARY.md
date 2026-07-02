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
