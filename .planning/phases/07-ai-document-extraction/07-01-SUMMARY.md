---
phase: 07-ai-document-extraction
plan: 01
subsystem: ai
tags: [claude-api, tax-extraction, document-classification, queued-jobs]

# Dependency graph
requires:
  - phase: 06-document-vault
    provides: TaxDocument model, TaxVaultStorageService, TaxVaultAuditService, vault routes
provides:
  - TaxDocumentExtractorService with classify/extract/sanitize pipeline
  - ExtractTaxDocument queued job with two-pass confidence-gated pipeline
  - 25 tax form types in TaxDocumentCategory enum
  - PATCH/POST endpoints for field correction, accept-all, retry-extraction
  - Auto-dispatch extraction on document upload
affects: [07-ai-document-extraction, 08-accountant-portal]

# Tech tracking
tech-stack:
  added: []
  patterns: [two-pass AI pipeline with confidence gate, defense-in-depth SSN stripping, tier-based field schemas]

key-files:
  created:
    - app/Services/AI/TaxDocumentExtractorService.php
    - app/Jobs/ExtractTaxDocument.php
    - app/Http/Requests/UpdateExtractionFieldRequest.php
  modified:
    - app/Enums/TaxDocumentCategory.php
    - config/spendifiai.php
    - app/Http/Controllers/Api/TaxDocumentController.php
    - app/Http/Resources/TaxDocumentResource.php
    - routes/api.php

key-decisions:
  - "Tier 1 schemas for W-2, 1099-NEC, 1099-INT, 1098 with full field lists; all others use generic TIER2_FIELDS"
  - "SSN defense-in-depth: prompt instructs last-4-only, sanitizeExtraction strips any full SSN post-response"
  - "Classification gate at 0.70 -- below this, extraction is skipped and document status set to Failed"

patterns-established:
  - "Two-pass AI pipeline: classify first, extract only if confidence >= gate"
  - "Multimodal Claude API calls with document/image content types following BankStatementParserService pattern"
  - "Field correction stores confidence=1.0 and verified=true with audit logging"

requirements-completed: [AIEX-01, AIEX-02, AIEX-03, AIEX-04, AIEX-05, AIEX-06, AIEX-08]

# Metrics
duration: 4min
completed: 2026-03-31
---

# Phase 7 Plan 1: AI Document Extraction Backend Summary

**Two-pass Claude AI pipeline for tax document classification (25 form types) and structured field extraction with SSN defense-in-depth and per-field confidence scoring**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-31T02:33:47Z
- **Completed:** 2026-03-31T02:37:42Z
- **Tasks:** 2
- **Files modified:** 8

## Accomplishments
- TaxDocumentExtractorService with classify/extract/sanitize methods calling Claude API with multimodal content
- ExtractTaxDocument queued job implementing two-pass pipeline: classify then extract with 0.70 confidence gate
- TaxDocumentCategory enum expanded from 8 to 25 cases covering all major IRS tax forms
- Controller endpoints for inline field correction, bulk accept, and retry extraction with full audit logging
- Automatic extraction dispatch on document upload

## Task Commits

Each task was committed atomically:

1. **Task 1: Enum expansion, config, extractor service, and extraction job** - `9c312c3` (feat)
2. **Task 2: Controller endpoints, form request, routes, and job dispatch** - `37256c0` (feat)

## Files Created/Modified
- `app/Services/AI/TaxDocumentExtractorService.php` - Claude API service with classify, extract, sanitizeExtraction methods
- `app/Jobs/ExtractTaxDocument.php` - Queued job with two-pass pipeline, 3 retries, exponential backoff
- `app/Enums/TaxDocumentCategory.php` - Expanded to 25 tax form types with labels
- `config/spendifiai.php` - Added ai.extraction_thresholds section
- `app/Http/Controllers/Api/TaxDocumentController.php` - Added updateField, acceptAll, retryExtraction methods
- `app/Http/Requests/UpdateExtractionFieldRequest.php` - Validation for PATCH field correction
- `app/Http/Resources/TaxDocumentResource.php` - Added extracted_data to response
- `routes/api.php` - Added PATCH /fields, POST /accept-all, POST /retry-extraction routes

## Decisions Made
- Tier 1 field schemas for W-2, 1099-NEC, 1099-INT, 1098 with full field lists; all other forms use generic TIER2_FIELDS schema
- SSN defense-in-depth: AI prompt instructs last-4-only return, plus sanitizeExtraction strips any full SSN post-response
- Classification gate at 0.70 confidence -- below this, extraction is skipped and status set to Failed

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Added extracted_data to TaxDocumentResource**
- **Found during:** Task 2 (Controller endpoints)
- **Issue:** TaxDocumentResource did not include extracted_data field, making extraction results invisible to frontend
- **Fix:** Added conditional extracted_data field to resource response
- **Files modified:** app/Http/Resources/TaxDocumentResource.php
- **Verification:** Resource properly returns extracted_data when present
- **Committed in:** 37256c0

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** Essential for frontend consumption of extraction results. No scope creep.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required. Uses existing ANTHROPIC_API_KEY from environment.

## Next Phase Readiness
- Backend extraction pipeline complete, ready for frontend extraction review UI (Plan 02)
- Field correction and accept-all endpoints ready for React component integration
- Retry extraction endpoint available for failed document recovery

---
*Phase: 07-ai-document-extraction*
*Completed: 2026-03-31*
