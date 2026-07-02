---
phase: 12-optimization-report-document-intake-feature-surface
plan: "01"
subsystem: tax-document-vault
tags: [enum, extraction, document-intake, DOC-01, DOC-06, DOC-07, DOC-02, additive]
status: complete

dependency_graph:
  requires:
    - phases/07-tax-vault (TaxDocumentCategory enum, TaxDocumentExtractorService, two-pass pipeline)
    - phases/10-rules-engine (OptimizationFinding)
    - phases/11-interview-narration (UserTaxFact, DurableFactsController)
  provides:
    - TaxDocumentCategory::PayStub, OfferLetter, RetirementStatement, StockStatement, InsuranceDoc, BenefitsGuide (DOC-01, DOC-07)
    - TaxDocumentCategory: 12 substantiation cases (DOC-06)
    - TaxDocumentExtractorService: PAY_STUB_FIELDS, BENEFITS_GUIDE_FIELDS, OFFER_LETTER_FIELDS, RETIREMENT_STATEMENT_FIELDS, STOCK_STATEMENT_FIELDS, INSURANCE_FIELDS
    - DOC-02 vision branch verified (no new library)
  affects:
    - 12-02-PLAN (proposal bridge reads PayStub/BenefitsGuide category)
    - 12-03-PLAN (report assembler reads new financial doc types from vault)
    - ExtractTaxDocument job (TaxDocumentCategory::tryFrom() now resolves new cases)

tech_stack:
  added: []
  patterns:
    - Additive PHP backed enum extension (append-only cases)
    - Additive PHP class const arrays (TIER2_FIELDS pattern extended)
    - Additive match() arm extension (default arm preserved)

key_files:
  created:
    - tests/Feature/TaxDocumentCategoryAdditivityTest.php
    - tests/Feature/TaxDocumentExtractorServiceTest.php
  modified:
    - app/Enums/TaxDocumentCategory.php
    - app/Services/AI/TaxDocumentExtractorService.php

decisions:
  - "6 financial FIELDS const arrays added as class constants after TIER2_FIELDS — mirrors the existing W2_FIELDS / NEC_1099_FIELDS pattern exactly"
  - "12 DOC-06 substantiation cases deliberately have NO getFieldSchema() arm — they fall through to TIER2_FIELDS (freeform extraction), matching plan intent"
  - "DOC-02: confirmed buildDocumentContent() already branches on mime_type to return type:image for JPEG/PNG — no new library added"
  - "BenefitsGuide placed in the financial group (has its own FIELDS const) not the substantiation group, per plan spec"

metrics:
  duration: "~3 minutes"
  completed_date: "2026-07-02"
  tasks_completed: 2
  tasks_total: 2
  files_created: 2
  files_modified: 2
  tests_added: 30
  assertions_added: 553
---

# Phase 12 Plan 01: Document Intake Enum & Extraction Schema Extension Summary

**One-liner:** 18 additive TaxDocumentCategory cases (PayStub, BenefitsGuide, 12 substantiation types) + 6 extraction field-schema const arrays extending the v2.0 vault two-pass pipeline with zero behavior changes to existing 25 form types.

## What Was Built

### Task 1 — 18 new TaxDocumentCategory enum cases (DOC-01, DOC-06, DOC-07)

Added after `CharitableDonation`, append-only:

**DOC-01 financial (5 cases):** `PayStub='pay_stub'`, `OfferLetter='offer_letter'`, `RetirementStatement='retirement_statement'`, `StockStatement='stock_statement'`, `InsuranceDoc='insurance_doc'`

**DOC-07 benefits (1 case):** `BenefitsGuide='benefits_guide'`

**DOC-06 substantiation (12 cases):** `SponsorshipAgreement`, `MarketCompMemo`, `PhysicianLetter`, `Appraisal`, `GallonsLog`, `RescueOrgLetter`, `SecurityMemo`, `LoanDoc`, `ContractorInvoice`, `MileageLog`, `DaycareLicense`, `SponsorshipVendorEvidence`

`label()` match extended with human-readable arms for all 18 cases. `forGrid()` iterates `self::cases()` so new types surface in the vault category grid automatically — zero UI change needed.

**Test:** `TaxDocumentCategoryAdditivityTest.php` — 7 tests, 348 assertions. Validates: all 25 pre-existing values byte-identical, all 18 new values resolve, `label()` has no UnhandledMatchError for any case, total count is 43, `forGrid()` returns 43 entries.

### Task 2 — 6 extraction field schemas + DOC-02 vision branch confirmation (DOC-01, DOC-07, DOC-02)

Six new `const *_FIELDS` arrays added after `TIER2_FIELDS` in `TaxDocumentExtractorService`:

| Const | Fields |
|-------|--------|
| `PAY_STUB_FIELDS` | 21 fields: employer/employee identifiers, pay period dates, gross pay, all withholdings (federal/state/FICA/Medicare), benefit deductions (HSA/401k/Roth/FSA/health/dental), YTD totals |
| `BENEFITS_GUIDE_FIELDS` | 17 fields: plan-year metadata + boolean/string flags for 401k, match formula, after-tax 401k, HDHP/HSA, FSA, DCFSA, ESPP, NQDC, §127, commuter, group legal, Trump account |
| `OFFER_LETTER_FIELDS` | 7 fields: employer, start date, salary, signing bonus, equity |
| `RETIREMENT_STATEMENT_FIELDS` | 7 fields: institution, account type/balance, YTD contributions, vesting |
| `STOCK_STATEMENT_FIELDS` | 6 fields: institution, account type, total value, realized/unrealized gains |
| `INSURANCE_FIELDS` | 4 fields: provider, policy type, annual premium, coverage amount |

`getFieldSchema()` match extended with 6 new financial case arms. DOC-06 substantiation cases intentionally have no arms — they fall through to `default => self::TIER2_FIELDS`.

**DOC-02 confirmed:** `buildDocumentContent()` already branches to `type: image` with base64 for JPEG/PNG — no new library added; the existing vision path handles image uploads of all new doc types.

**Test:** `TaxDocumentExtractorServiceTest.php` — 23 tests, 205 assertions. Covers: `buildDocumentContent()` returns `type:image` for JPEG and PNG (and `type:document` for PDF); `getFieldSchema()` returns correct non-empty schema for each of the 6 new financial cases with key field spot-checks; all 12 substantiation cases return `TIER2_FIELDS`; W2/NEC_1099/Other regressions pass; all 43 cases return non-empty arrays.

## Verification Results

| Check | Result |
|-------|--------|
| `php artisan test --filter=TaxDocumentCategoryAdditivityTest` | 7 passed, 348 assertions |
| `php artisan test --filter=TaxDocumentExtractorServiceTest` | 23 passed, 205 assertions |
| `php artisan test --filter=TaxDocument` (full set) | 52 passed, 617 assertions |
| `php artisan test --compact` (full suite) | 720 passed, 1 known pre-existing failure (DashboardFinancialBlocksTest) |
| `vendor/bin/pint --dirty` | pass (1 binary_operator_spaces fix auto-applied by Pint) |
| SAFE-03 grep (estimated_value_cents) | No violations in modified files |

## Deviations from Plan

None — plan executed exactly as written. All cases, field schemas, and test assertions match the 12-RESEARCH.md specification byte-for-byte.

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes introduced. All changes are pure PHP enum / service class additions. The existing `<document_content>` delimiter pattern in `buildDocumentContent()` / `extract()` already provides prompt-injection isolation for the vision path (full SAFE-07 pen test deferred to P13 per plan).

## Self-Check: PASSED

- `app/Enums/TaxDocumentCategory.php` — FOUND, 43 cases, all arms in label()
- `app/Services/AI/TaxDocumentExtractorService.php` — FOUND, 6 new FIELDS consts, 6 new getFieldSchema() arms
- `tests/Feature/TaxDocumentCategoryAdditivityTest.php` — FOUND, 7 tests pass
- `tests/Feature/TaxDocumentExtractorServiceTest.php` — FOUND, 23 tests pass
- Commits 731ac90 and 3e3a501 — verified in git log
