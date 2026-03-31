# Requirements: SpendifiAI v2.0

**Defined:** 2026-03-30
**Core Value:** Secure tax document vault with AI extraction, accountant collaboration portal, and intelligence layer -- bridging taxpayers and their accountants

## v2.0 Requirements

Requirements for Tax Document Vault & Accountant Portal milestone. Each maps to roadmap phases.

### Document Vault

- [x] **VAULT-01**: User can upload tax documents (PDF, JPG, PNG) with server-side MIME validation
- [x] **VAULT-02**: System stores documents locally by default at `storage/app/private/tax-vault/{user_id}/{year}/{category}/`
- [x] **VAULT-03**: Super Admin can toggle storage driver between local filesystem and Amazon S3
- [x] **VAULT-04**: Super Admin can configure S3 credentials (bucket, region, access key, secret key) with AES-256 encryption
- [x] **VAULT-05**: Super Admin can test S3 connection and trigger document migration job
- [x] **VAULT-06**: All document access uses signed URLs (local via `URL::temporarySignedRoute()`, S3 via `Storage::temporaryUrl()`)
- [x] **VAULT-07**: User can view their uploaded documents organized by tax year and category
- [x] **VAULT-08**: User can soft-delete documents (admin can purge)
- [x] **VAULT-09**: Document status tracks through upload → classifying → extracting → ready → failed states

### AI Extraction

- [x] **AIEX-01**: System auto-classifies uploaded documents into one of 25 tax form types using Claude AI
- [x] **AIEX-02**: Classification uses two-pass pipeline: classify first, then extract fields only if confidence >= threshold
- [x] **AIEX-03**: System extracts structured fields from W-2, 1099-NEC, 1099-INT, 1098 (Tier 1 forms)
- [x] **AIEX-04**: System extracts structured fields from remaining 21 form types (Tier 2+)
- [x] **AIEX-05**: Extracted data stored with `encrypted:array` cast -- SSN stored as last 4 digits only, EIN encrypted
- [x] **AIEX-06**: Extraction confidence scored per field, surfaced in review UI
- [x] **AIEX-07**: User can review and correct AI-extracted fields side-by-side with document viewer
- [x] **AIEX-08**: Extraction runs as queued job (`ExtractTaxDocument`) with retries

### Accountant Portal

- [x] **ACCT-01**: AccountingFirm model with firm registration flow (name, address, phone, branding)
- [x] **ACCT-02**: Accountant belongs to a firm; clients managed at firm level
- [x] **ACCT-03**: Firm generates branded invite links for client onboarding
- [x] **ACCT-04**: Accountant can view client's uploaded tax documents through existing portal
- [x] **ACCT-05**: Accountant can add annotations/comments on client documents (threaded)
- [x] **ACCT-06**: Accountant can request missing documents from client with description
- [x] **ACCT-07**: Client sees missing document requests as alerts with upload prompts
- [x] **ACCT-08**: Accountant dashboard shows client list with document completeness, deadline tracking
- [x] **ACCT-09**: 5 new Mail classes for accountant workflows (firm invite, document request, annotation notify, etc.)

### Audit & Security

- [x] **AUDIT-01**: Immutable `tax_vault_audit_log` table -- no update or delete routes, ever
- [x] **AUDIT-02**: Every document view, download, upload, delete, share, and extraction logged with user, IP, timestamp
- [x] **AUDIT-03**: Audit log enforced at database level (PostgreSQL rules to prevent UPDATE/DELETE)
- [x] **AUDIT-04**: Hash chain on audit entries (each entry stores `sha256(prev_hash + entry_data)`) for tamper detection
- [x] **AUDIT-05**: Audit log viewable by document owner and their accountant
- [x] **AUDIT-06**: All document access scoped through relationships -- never `TaxDocument::find($id)` without tenant check

### Intelligence Layer

- [x] **INTEL-01**: AI detects missing documents by cross-referencing Plaid transaction categories with expected tax form types
- [x] **INTEL-02**: Cross-document anomaly detection (e.g., W-2 wages vs bank deposit totals)
- [x] **INTEL-03**: Transaction-to-document linking (1099 linked to associated freelance deposits)
- [x] **INTEL-04**: Missing document alerts shown to user with explanation of why document is expected

### Frontend

- [x] **UI-01**: Tax Vault page with year selector tabs, document category grid, upload zone, missing alerts banner
- [x] **UI-02**: Document Detail page with split-panel PDF viewer + extracted fields + annotations thread
- [x] **UI-03**: Accountant Dashboard page with stats bar, client list table, deadline tracker, invite link generator
- [x] **UI-04a**: 5 Phase 6 shared components (TaxYearTabs, DocumentCard, DocumentUploadZone, MissingAlertBanner, AuditLogTable)
- [x] **UI-04b**: 5 Phase 7/8 shared components (ExtractionPanel, AnnotationThread, DocumentRequestCard, and additional components built alongside their features)
- [x] **UI-05**: All new pages follow existing SpendifiAI design system (navy #1E3A5F, teal #0D9488, sw-* tokens)

### Testing

- [x] **TEST-01**: Feature tests for all new API endpoints (document upload, extraction, accountant access, audit log)
- [x] **TEST-02**: Unit tests for TaxDocumentStorageService, TaxDocumentExtractorService, TaxWorksheetService, TaxVaultAuditService
- [x] **TEST-03**: AI extraction tests mock Claude API via `Http::fake()` -- no live API calls
- [x] **TEST-04**: Cross-role authorization tests (owner access, accountant access, wrong-accountant blocked)
- [x] **TEST-05**: `npm run build` succeeds with zero TypeScript errors
- [x] **TEST-06**: `vendor/bin/pint` reports no formatting issues

## v2.1 Requirements

Deferred to next milestone. Tracked but not in current roadmap.

### Workflow & Sharing

- **WFLOW-01**: Dual sign-off workflow (taxpayer attestation + accountant approval)
- **WFLOW-02**: Tax year status tracking (in-progress -> taxpayer-signed -> accountant-signed -> filed)
- **WFLOW-03**: Document sharing packages with time-limited signed URLs
- **WFLOW-04**: Tax worksheets with auto-populated fields from AI extraction data
- **WFLOW-05**: Accountant can override worksheet values with explanation

### Firm Management

- **FIRM-01**: Multi-accountant firm support (team members under a firm)
- **FIRM-02**: Firm-level permissions and roles (owner, manager, accountant, viewer)
- **FIRM-03**: Firm branding on all client-facing communications

### Additional Form Types

- **FORMS-01**: Tier 2 forms (1099-MISC, 1099-DIV, 1099-B, 1099-R, 1099-G, 1099-K, 1098-E, 1098-T)
- **FORMS-02**: Tax software export formats (TurboTax TXF, H&R Block)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Full SSN/TIN storage | Security risk -- store last 4 digits only, strip from extraction |
| Legal e-signature (ESIGN Act) | Compliance burden disproportionate to value -- simple attestation with audit log |
| Direct IRS e-filing | Requires EFIN certification -- export to tax software instead |
| Real-time collaborative editing | Comments/annotations are sufficient for document collaboration |
| OCR for handwritten documents | AI extraction handles typed/digital forms only |
| ClamAV virus scanning | Defer to production hardening -- not MVP blocker |
| Multi-currency tax documents | USD only for v2.0 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| VAULT-01 | Phase 6 | Complete |
| VAULT-02 | Phase 6 | Complete |
| VAULT-03 | Phase 6 | Complete |
| VAULT-04 | Phase 6 | Complete |
| VAULT-05 | Phase 6 | Complete |
| VAULT-06 | Phase 6 | Complete |
| VAULT-07 | Phase 6 | Complete |
| VAULT-08 | Phase 6 | Complete |
| VAULT-09 | Phase 6 | Complete |
| AIEX-01 | Phase 7 | Complete |
| AIEX-02 | Phase 7 | Complete |
| AIEX-03 | Phase 7 | Complete |
| AIEX-04 | Phase 7 | Complete |
| AIEX-05 | Phase 7 | Complete |
| AIEX-06 | Phase 7 | Complete |
| AIEX-07 | Phase 7 | Complete |
| AIEX-08 | Phase 7 | Complete |
| ACCT-01 | Phase 8 | Complete |
| ACCT-02 | Phase 8 | Complete |
| ACCT-03 | Phase 8 | Complete |
| ACCT-04 | Phase 8 | Complete |
| ACCT-05 | Phase 8 | Complete |
| ACCT-06 | Phase 8 | Complete |
| ACCT-07 | Phase 8 | Complete |
| ACCT-08 | Phase 8 | Complete |
| ACCT-09 | Phase 8 | Complete |
| AUDIT-01 | Phase 6 | Complete |
| AUDIT-02 | Phase 6 | Complete |
| AUDIT-03 | Phase 6 | Complete |
| AUDIT-04 | Phase 6 | Complete |
| AUDIT-05 | Phase 6 | Complete |
| AUDIT-06 | Phase 6 | Complete |
| INTEL-01 | Phase 9 | Complete |
| INTEL-02 | Phase 9 | Complete |
| INTEL-03 | Phase 9 | Complete |
| INTEL-04 | Phase 9 | Complete |
| UI-01 | Phase 6 | Complete |
| UI-02 | Phase 7 | Complete |
| UI-03 | Phase 8 | Complete |
| UI-04a | Phase 6 | Complete |
| UI-04b | Phase 7, 8 | Complete |
| UI-05 | Phase 6 | Complete |
| TEST-01 | Phase 9 | Complete |
| TEST-02 | Phase 7 | Complete |
| TEST-03 | Phase 7 | Complete |
| TEST-04 | Phase 8 | Complete |
| TEST-05 | Phase 9 | Complete |
| TEST-06 | Phase 9 | Complete |

**Coverage:**
- v2.0 requirements: 45 total (UI-04 split into UI-04a + UI-04b)
- Mapped to phases: 45
- Unmapped: 0

---
*Requirements defined: 2026-03-30*
*Last updated: 2026-03-30 after plan revision (UI-04 split into UI-04a/UI-04b)*
