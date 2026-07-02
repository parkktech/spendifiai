---
phase: 12-optimization-report-document-intake-feature-surface
plan: "02"
subsystem: document-proposal-bridge
tags: [event, proposal, d4-gate, hsa-shoebox, doc-fulfillment, DOC-03, DOC-05, DOC-07, STORE-03]
status: complete

dependency_graph:
  requires:
    - phases/12-01 (TaxDocumentCategory::PayStub, BenefitsGuide enum cases + FIELDS schemas)
    - phases/11 (UserTaxFact::recordFact + confirmProposal, DurableFactsController::confirm)
    - phases/10 (OptimizationFinding with docs_missing/docs_captured columns)
    - phases/07 (TaxDocument, ExtractTaxDocument job, TaxVaultAuditService)
  provides:
    - TaxDocumentExtracted event (DOC-03 staleness signal for 12-04)
    - ExtractProfileFacts job + PaystubFactExtractorService (DOC-07 D4 proposal bridge)
    - IncomeOptimizerDataAssemblerService PayStub arm (DOC-03 signal accumulation)
    - HsaShoeboxService (STORE-03)
    - DocumentRequestController::updateFindingDocsOnFulfillment() (DOC-05)
  affects:
    - 12-04-PLAN (consumes TaxDocumentExtracted for GenerateOptimizationReport dispatch)
    - DurableFactsController::confirm() (existing endpoint now exercises the D4 gate e2e)

tech_stack:
  added: []
  patterns:
    - Append-only fact store proposal-creation from extracted docs (D4 gate)
    - Event-driven staleness signal (TaxDocumentExtracted fires once on ready path only)
    - Defensive nested-with-fallback field access pattern (Pitfall 2 / plan-check fix)
    - Boolean-to-yes/no storage convention for BenefitsGuide fields (Pitfall 7)
    - Namespaced HSA shoebox fact keys (hsa_shoebox.<vault_document_id>)
    - Static fulfillment hook on DocumentRequestController for DOC-05

key_files:
  created:
    - app/Events/TaxDocumentExtracted.php
    - app/Jobs/ExtractProfileFacts.php
    - app/Services/AI/PaystubFactExtractorService.php
    - app/Services/HsaShoeboxService.php
    - tests/Feature/ExtractProfileFactsDispatchTest.php
    - tests/Feature/PaystubProposalFlowTest.php
    - tests/Feature/HsaShoeboxTest.php
  modified:
    - app/Jobs/ExtractTaxDocument.php (fire event + dispatch job on ready path)
    - app/Services/IncomeOptimizerDataAssemblerService.php (PayStub + BenefitsGuide arms + roth_401k_ytd)
    - app/Http/Controllers/Api/DocumentRequestController.php (DOC-05 finding update)
    - app/Http/Controllers/Api/TaxDocumentController.php (wire DOC-05 into autoFulfillRequests)
    - app/Models/UserTaxFact.php (confirmProposal ordering bug fix — Rule 1)

decisions:
  - "TaxDocumentExtracted carries the TaxDocument model directly (SerializesModels handles queue serialization); fires ONLY on the terminal Ready path"
  - "PAYSTUB_FACT_MAP maps 4 monetary fields to retirement.*/benefits.* fact keys; BENEFITS_FACT_MAP maps 15 availability fields"
  - "BenefitsGuide boolean fields stored as 'yes'/'no' with original_bool in metadata (Pitfall 7 convention)"
  - "BenefitsGuide assembler arm is an intentional no-op — dollar accumulation is not applicable; facts flow via proposal path only"
  - "roth_401k_ytd added to sumFromDocuments() $totals array (was missing, confirmed in IncomeOptimizationProfile::$fillable)"
  - "confirmProposal() unique index ordering bug fixed (flip existing row FIRST, then promote proposal)"
  - "HsaShoeboxService::EDUCATION_COPY uses 'may be reimbursable' / post-establishment framing only"
  - "DOC-05 logic placed in DocumentRequestController::updateFindingDocsOnFulfillment() static method, wired from TaxDocumentController::autoFulfillRequests()"

metrics:
  duration: "~18 minutes"
  completed_date: "2026-07-02"
  tasks_completed: 3
  tasks_total: 3
  files_created: 7
  files_modified: 5
  tests_added: 24
  assertions_added: 98
---

# Phase 12 Plan 02: Document→Facts Proposal Bridge Summary

**One-liner:** TaxDocumentExtracted event + D4 proposal bridge (PaystubFactExtractorService + ExtractProfileFacts job) + assembler PayStub arm + HSA shoebox (STORE-03) + DOC-05 vault-upload finding fulfillment — connecting document extraction to the P11 durable-facts store with an explicit confirm-gate.

## What Was Built

### Task 1 — TaxDocumentExtracted event + fire on ready path + ExtractProfileFacts dispatch (DOC-03)

**`app/Events/TaxDocumentExtracted.php`** — New event carrying `public readonly TaxDocument $document`. Mirrors OptimizationProfileBuilt convention (Dispatchable + SerializesModels). Fires exactly once at the terminal success (Ready) path in ExtractTaxDocument::handle(); never on error, splitter, or below-confidence-gate paths.

**`app/Jobs/ExtractProfileFacts.php`** — New queued job (queue='optimization', tries=3, timeout=120). Constructor takes scalar `$documentId`. Loads TaxDocument, calls PaystubFactExtractorService::proposeFacts(), logs proposal count.

**`app/Jobs/ExtractTaxDocument.php`** — Additive modification: after the audit log on the Ready path, fire `event(new TaxDocumentExtracted($document))` and, for PayStub/BenefitsGuide categories only, `ExtractProfileFacts::dispatch($document->id)`.

**Test coverage:** 7 tests, 29 assertions (ExtractProfileFactsDispatchTest):
- Event fires exactly once for PayStub
- Event fires exactly once for BenefitsGuide  
- Event fires but ExtractProfileFacts NOT dispatched for W2
- Event does NOT fire on classification error, below-gate confidence, extraction error
- Event carries correct document reference

### Task 2 — PaystubFactExtractorService + ExtractProfileFacts + assembler extension (DOC-07, DOC-03)

**`app/Services/AI/PaystubFactExtractorService.php`** — New service mapping extracted PayStub and BenefitsGuide fields to UserTaxFact proposals.

PAYSTUB_FACT_MAP (4 monetary fields):

| Extracted Field | fact_key | volatility |
|-----------------|----------|------------|
| traditional_401k_deduction | retirement.traditional_401k_ytd_cents | annual |
| roth_401k_deduction | retirement.roth_401k_ytd_cents | annual |
| hsa_deduction | retirement.hsa_ytd_cents | annual |
| fsa_deduction | benefits.fsa_ytd_cents | annual |

BENEFITS_FACT_MAP (15 availability fields): employer.has_401k, employer.match_formula, employer.after_tax_401k_available, employer.in_plan_roth_conversion_available, employer.hdhp_hsa_available, employer.fsa_available, employer.dependent_care_fsa_available, employer.espp_available, employer.espp_terms, employer.nqdc_available, employer.section_127_available, employer.commuter_benefits_available, employer.group_legal_available, employer.trump_account_available, employer.trump_account_employer_contribution.

All calls use `sourceType: 'document_extraction'` so recordFact() writes `is_current=false` — D4 gate enforced.

**`app/Services/IncomeOptimizerDataAssemblerService.php`** — Additive extensions:
- Added `roth_401k_ytd => 0` to `$totals` initialization (was missing despite being in `$fillable`)
- Added `TaxDocumentCategory::PayStub` case: defensive nested-with-fallback read of gross_pay/traditional_401k_deduction/roth_401k_deduction/hsa_deduction fields into w2_wages/traditional_401k_ytd/roth_401k_ytd/hsa_ytd totals
- Added `TaxDocumentCategory::BenefitsGuide` case: intentional no-op (dollar accumulation not applicable; facts flow via UserTaxFact proposal path)

**Rule 1 auto-fix — `confirmProposal()` ordering bug:** The existing `UserTaxFact::confirmProposal()` set `is_current=true` on the proposal BEFORE setting `is_current=false` on the existing current row, violating the partial unique index (`idx_tax_facts_current`). Fixed to match `recordFact()` ordering: flip existing row first, then promote proposal, then set superseded_by_id.

**Test coverage:** 8 tests, 36 assertions (PaystubProposalFlowTest):
- (a) Proposals created with is_current=false + source_type=document_extraction + metadata.confidence
- (b) Proposals excluded from currentFactKeys() and answerableFields() pre-confirm
- (c) User-edit fact NOT overwritten when proposal created for same key
- (d) confirm() promotes proposal to current, supersedes user-edit fact

### Task 3 — HSA shoebox (STORE-03) + DOC-05 in-flow vault-upload fulfillment

**`app/Services/HsaShoeboxService.php`** — Records out-of-pocket medical receipts as permanent UserTaxFact rows:
- Key: `hsa_shoebox.<vault_document_id>` (one row per receipt, idempotent)
- volatility: `permanent` (IRS allows indefinite reimbursement deferral)
- value: amount-cents-as-string (encrypted, $hidden)
- metadata: vault_document_id, incurred_on (Y-m-d), description

**EDUCATION_COPY constant** (approved wording): "Medical expenses you incurred after opening your HSA may be reimbursable tax-free in any future year, as long as you keep your receipts. The IRS sets no deadline for reimbursement after the HSA was established. Consider discussing your specific situation with a tax professional."

**`app/Http/Controllers/Api/DocumentRequestController.php`** — Added static method `updateFindingDocsOnFulfillment(TaxDocument $document): void`:
- Queries OptimizationFinding via `scopeForUser()` (T-12-02-03 cross-user mutation mitigation)
- Finds findings with the document's category in `docs_missing` (whereJsonContains)
- Moves the category from `docs_missing` → removes it; adds `$document->id` to `docs_captured`
- Additive — no changes to existing DocumentRequest response shapes or existing methods

**`app/Http/Controllers/Api/TaxDocumentController.php`** — One-line addition at the end of `autoFulfillRequests()`: calls `DocumentRequestController::updateFindingDocsOnFulfillment($document)` (DOC-05 wire-up).

**Test coverage:** 9 tests, 33 assertions (HsaShoeboxTest):
- shoebox fact has correct namespaced key, volatility=permanent, source_type=user_edit
- value is $hidden from toArray()
- metadata carries vault_document_id/incurred_on/description
- EDUCATION_COPY uses conditional framing, no absolute assertions
- listByUser returns shoebox facts (not other facts) ordered by tax_year desc
- DOC-05: vault upload moves entry from docs_missing to docs_captured
- DOC-05: scopeForUser guard prevents cross-user finding mutation
- DOC-05: no match in docs_missing = no update

## Runtime Shape Verification (Plan-Check Requirement)

**Confirmed runtime shape of `extracted_data`:** `{ "fields": { "field_name": { "value": "...", "confidence": 0.9 } }, "overall_confidence": 0.87 }` — nested-with-confidence object.

**Evidence sources:**
1. `TaxDocumentExtractionTest.php` — existing test stubs return this shape explicitly (Http::fake pushes `{ "fields": { "wages": { "value": "52000.00", "confidence": 0.97 } } }`)
2. `ExtractTaxDocument::detectContentDuplicate()` (lines 180-191) reads `$fields = $extraction['fields'] ?? []` then `$fields['employer_ein']['value']` — confirming the production code already uses this nested path
3. `PaystubFactExtractorService::proposeFacts()` reads `$extractedData['fields'][$fieldName]['value']` — matching the confirmed shape

**Defensive pattern implemented:** Both the assembler PayStub arm and PaystubFactExtractorService use the defensive nested-with-fallback read:
```php
$fieldData = $fields[$fieldName] ?? null;
$rawValue  = is_array($fieldData) ? ($fieldData['value'] ?? null) : $fieldData;
```
This handles both the canonical nested shape and any legacy flat-stored values without silent null returns.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed confirmProposal() unique index ordering**
- **Found during:** Task 2 — test (d) "confirm() promotes a proposal to current and supersedes the user_edit fact"
- **Issue:** `UserTaxFact::confirmProposal()` updated the proposal to `is_current=true` BEFORE flipping the existing current fact to `is_current=false`. This violates the partial unique index `idx_tax_facts_current` (only one `is_current=true` per key tuple allowed). The bug would cause a `SQLSTATE[23505]: Unique violation` whenever a user-entered fact existed for the same key.
- **Fix:** Reordered the steps to match `recordFact()` convention — flip existing row to `is_current=false` FIRST, then promote proposal to `is_current=true`, then set `superseded_by_id`.
- **Files modified:** `app/Models/UserTaxFact.php`
- **Commit:** 279f983

**2. [Rule 2 - Missing critical functionality] Added roth_401k_ytd to $totals initialization**
- **Found during:** Task 2 assembler extension
- **Issue:** `roth_401k_ytd` was in `IncomeOptimizationProfile::$fillable` and `casts()` but absent from the `$totals` array in `sumFromDocuments()`. The PayStub arm would have needed `?? 0` fallback or would fail on addition to undefined key.
- **Fix:** Added `'roth_401k_ytd' => 0` to the `$totals` initialization array alongside existing retirement fields.
- **Files modified:** `app/Services/IncomeOptimizerDataAssemblerService.php`
- **Commit:** 279f983

## Threat Flags

No new network endpoints, auth paths, or schema changes introduced. The plan's STRIDE register mitigations were implemented:

| Threat | Mitigation Applied |
|--------|-------------------|
| T-12-02-01: Proposal silently overwriting user-entered fact | source_type='document_extraction' enforces is_current=false; PaystubProposalFlowTest asserts both directions |
| T-12-02-02: PII in extracted fields | UserTaxFact.value uses 'encrypted' cast + $hidden; only confidence (non-PII) in metadata |
| T-12-02-03: Cross-user finding mutation on DOC-05 | scopeForUser() in updateFindingDocsOnFulfillment(); cross-user test asserts no mutation |

## Verification Results

| Check | Result |
|-------|--------|
| `php artisan test --filter=ExtractProfileFactsDispatchTest` | 7 passed, 29 assertions |
| `php artisan test --filter=PaystubProposalFlowTest` | 8 passed, 36 assertions |
| `php artisan test --filter=HsaShoeboxTest` | 9 passed, 33 assertions |
| `php artisan test --filter='ExtractProfileFacts\|PaystubProposal\|HsaShoebox'` | 24 passed, 98 assertions |
| `php artisan test --compact` (full suite) | 766 passed, 1 known pre-existing failure (DashboardFinancialBlocksTest) |
| `vendor/bin/pint --dirty` | pass |
| SAFE-03 grep (estimated_value_cents in new files) | No violations |

## Self-Check: PASSED

- `app/Events/TaxDocumentExtracted.php` — FOUND
- `app/Jobs/ExtractProfileFacts.php` — FOUND
- `app/Services/AI/PaystubFactExtractorService.php` — FOUND
- `app/Services/HsaShoeboxService.php` — FOUND
- `app/Http/Controllers/Api/DocumentRequestController.php::updateFindingDocsOnFulfillment()` — FOUND
- `tests/Feature/ExtractProfileFactsDispatchTest.php` — FOUND, 7 tests pass
- `tests/Feature/PaystubProposalFlowTest.php` — FOUND, 8 tests pass
- `tests/Feature/HsaShoeboxTest.php` — FOUND, 9 tests pass
- Commits 394efb3, 279f983, e44871a — verified in git log
