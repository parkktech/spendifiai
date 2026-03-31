# Phase 7: AI Document Extraction - Context

**Gathered:** 2026-03-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Uploaded documents are automatically classified into tax form types and have structured fields extracted by Claude AI, with per-field confidence scoring and a side-by-side review interface. Document vault infrastructure (Phase 6), accountant collaboration (Phase 8), and intelligence layer (Phase 9) are separate phases.

</domain>

<decisions>
## Implementation Decisions

### Classification Pipeline
- Two-pass pipeline: classify first, then extract only if classification confidence >= threshold (AIEX-02)
- Classification confidence threshold: 0.70 — below this, document is flagged for manual review and extraction is skipped
- On upload, document status transitions: upload → classifying → extracting → ready (or failed)
- Classification dispatched as a queued job (`ExtractTaxDocument`) triggered after successful upload — not inline
- Job has 3 retries with exponential backoff (matches existing queue patterns)
- Claude AI called via `Http::` facade (same pattern as `TransactionCategorizerService`)

### Form Type Expansion
- Expand TaxDocumentCategory enum from 8 to 25 form types for classification
- Tier 1 extraction (W-2, 1099-NEC, 1099-INT, 1098): full structured field extraction with field-specific schemas
- Tier 2+ forms: classified but extraction deferred (AIEX-04 maps to Phase 7 in requirements but can have simpler extraction schemas)
- Unrecognized forms classified as "Other" with low confidence — flagged for manual category assignment
- Each form type has a defined field schema (e.g., W-2: employer_name, employer_ein, wages, federal_tax_withheld, state, state_wages, state_tax_withheld, ssn_last4)

### Confidence Scoring
- Per-field confidence scores stored alongside extracted values in `extracted_data` (encrypted:array)
- Structure: `{ fields: { field_name: { value: "...", confidence: 0.92 } }, overall_confidence: 0.87 }`
- Confidence thresholds aligned with existing transaction categorizer pattern:
  - >= 0.85: Auto-accepted, shown as verified (green)
  - 0.60-0.84: Shown with amber indicator, suggested for review
  - < 0.60: Shown with red indicator, requires user confirmation
- SSN stripped to last 4 digits during extraction (never stored full) — AIEX-05
- EIN stored encrypted via `encrypted:array` cast — AIEX-05

### Extraction Review UI (Document Detail Page)
- Split-panel layout: PDF/image viewer on the left, extracted fields on the right
- Left panel: embedded PDF viewer (iframe/object for PDFs, img tag for JPG/PNG) using signed URL
- Right panel: structured field list with per-field confidence badge (green/amber/red)
- Inline editing: click a field value to edit it in place — saves via PATCH API call
- After user correction, field confidence set to 1.0 and marked as "user-verified"
- Tab structure on document detail: Overview | Extracted Fields | Audit Log (carries forward from Phase 6 decision)

### Field Correction UX
- Inline edit on each field — click to toggle between display and input mode
- Save individual field corrections via PATCH endpoint (not full form submit)
- "Accept All" button to mark all fields as reviewed when user is satisfied
- No AI retraining on corrections — corrections are one-way user overrides
- Corrections are audit-logged (action: "field_corrected", details include field name and old/new values)

### Claude's Discretion
- Exact Claude prompt engineering for classification and extraction
- PDF text extraction approach (pdftotext vs Claude vision vs hybrid)
- Extraction job timeout and memory limits
- Error messaging for failed extractions
- Loading states during extraction processing
- Field ordering and grouping in the review panel
- How to handle multi-page documents in the viewer

</decisions>

<specifics>
## Specific Ideas

- Follow the `TransactionCategorizerService` pattern exactly: constructor loads API key from config, `callClaude()` method uses `Http::withHeaders()`, structured JSON response parsing
- Confidence thresholds should be configurable in `config/spendifiai.php` under `ai.extraction_thresholds` (same pattern as `ai.confidence_thresholds` for transactions)
- The extraction job should update document status at each stage so the vault UI shows real-time progress (classifying → extracting → ready)
- SSN handling is critical: the Claude prompt must explicitly instruct "return only last 4 digits of SSN" and the service must validate/strip before storage

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `TransactionCategorizerService` (`app/Services/AI/TransactionCategorizerService.php`): Reference pattern for Claude API calls, confidence thresholds, prompt building, response parsing
- `BankStatementParserService` (`app/Services/BankStatementParserService.php`): Reference for document parsing patterns
- `TaxDocument` model: Already has `extracted_data` (encrypted:array), `classification_confidence`, `category`, `status` fields
- `DocumentStatus` enum: upload/classifying/extracting/ready/failed lifecycle already defined
- `TaxDocumentCategory` enum: 8 categories — needs expansion to 25
- `TaxVaultAuditService`: Audit logging for extraction events
- `TaxVaultStorageService`: Signed URL generation for document viewer

### Established Patterns
- AI services in `app/Services/AI/` namespace
- `Http::withHeaders()->post()` for Claude API calls
- Confidence thresholds as class constants (TransactionCategorizerService::CONFIDENCE_AUTO etc.)
- Config-driven thresholds in `config/spendifiai.php`
- Queued jobs with retries for background processing
- `encrypted:array` cast for sensitive extracted data

### Integration Points
- `ExtractTaxDocument` job dispatched after upload in `TaxDocumentController::store()`
- Document status updates visible in vault UI category cards (color-coded badges from Phase 6)
- New document detail page at `/vault/documents/{id}` — Inertia page
- PATCH endpoint for field corrections on `TaxDocumentController`
- Audit log entries for extraction and field correction actions
- UI-02 requirement: Document Detail page with split-panel viewer + extracted fields + annotations thread
- UI-04b: ExtractionPanel component (Phase 7 component from the UI-04 split)

</code_context>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 07-ai-document-extraction*
*Context gathered: 2026-03-30*
