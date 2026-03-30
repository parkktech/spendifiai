# Requirements: SpendifiAI v2.0

**Defined:** 2026-03-30
**Core Value:** Secure tax document vault with AI extraction, accountant collaboration portal, and intelligence layer — bridging taxpayers and their accountants

## v2.0 Requirements

Requirements for Tax Document Vault & Accountant Portal milestone. Each maps to roadmap phases.

### Document Vault

- [ ] **VAULT-01**: User can upload tax documents (PDF, JPG, PNG) with server-side MIME validation
- [ ] **VAULT-02**: System stores documents locally by default at `storage/app/private/tax-vault/{user_id}/{year}/{category}/`
- [ ] **VAULT-03**: Super Admin can toggle storage driver between local filesystem and Amazon S3
- [ ] **VAULT-04**: Super Admin can configure S3 credentials (bucket, region, access key, secret key) with AES-256 encryption
- [ ] **VAULT-05**: Super Admin can test S3 connection and trigger document migration job
- [ ] **VAULT-06**: All document access uses signed URLs (local via `URL::temporarySignedRoute()`, S3 via `Storage::temporaryUrl()`)
- [ ] **VAULT-07**: User can view their uploaded documents organized by tax year and category
- [ ] **VAULT-08**: User can soft-delete documents (admin can purge)
- [ ] **VAULT-09**: Document status tracks through upload → classifying → extracting → ready → failed states

### AI Extraction

- [ ] **AIEX-01**: System auto-classifies uploaded documents into one of 25 tax form types using Claude AI
- [ ] **AIEX-02**: Classification uses two-pass pipeline: classify first, then extract fields only if confidence ≥ threshold
- [ ] **AIEX-03**: System extracts structured fields from W-2, 1099-NEC, 1099-INT, 1098 (Tier 1 forms)
- [ ] **AIEX-04**: System extracts structured fields from remaining 21 form types (Tier 2+)
- [ ] **AIEX-05**: Extracted data stored with `encrypted:array` cast — SSN stored as last 4 digits only, EIN encrypted
- [ ] **AIEX-06**: Extraction confidence scored per field, surfaced in review UI
- [ ] **AIEX-07**: User can review and correct AI-extracted fields side-by-side with document viewer
- [ ] **AIEX-08**: Extraction runs as queued job (`ExtractTaxDocument`) with retries

### Accountant Portal

- [ ] **ACCT-01**: AccountingFirm model with firm registration flow (name, address, phone, branding)
- [ ] **ACCT-02**: Accountant belongs to a firm; clients managed at firm level
- [ ] **ACCT-03**: Firm generates branded invite links for client onboarding
- [ ] **ACCT-04**: Accountant can view client's uploaded tax documents through existing portal
- [ ] **ACCT-05**: Accountant can add annotations/comments on client documents (threaded)
- [ ] **ACCT-06**: Accountant can request missing documents from client with description
- [ ] **ACCT-07**: Client sees missing document requests as alerts with upload prompts
- [ ] **ACCT-08**: Accountant dashboard shows client list with document completeness, deadline tracking
- [ ] **ACCT-09**: 5 new Mail classes for accountant workflows (firm invite, document request, annotation notify, etc.)

### Audit & Security

- [ ] **AUDIT-01**: Immutable `tax_vault_audit_log` table — no update or delete routes, ever
- [ ] **AUDIT-02**: Every document view, download, upload, delete, share, and extraction logged with user, IP, timestamp
- [ ] **AUDIT-03**: Audit log enforced at database level (PostgreSQL rules to prevent UPDATE/DELETE)
- [ ] **AUDIT-04**: Hash chain on audit entries (each entry stores `sha256(prev_hash + entry_data)`) for tamper detection
- [ ] **AUDIT-05**: Audit log viewable by document owner and their accountant
- [ ] **AUDIT-06**: All document access scoped through relationships — never `TaxDocument::find($id)` without tenant check

### Intelligence Layer

- [ ] **INTEL-01**: AI detects missing documents by cross-referencing Plaid transaction categories with expected tax form types
- [ ] **INTEL-02**: Cross-document anomaly detection (e.g., W-2 wages vs bank deposit totals)
- [ ] **INTEL-03**: Transaction-to-document linking (1099 linked to associated freelance deposits)
- [ ] **INTEL-04**: Missing document alerts shown to user with explanation of why document is expected

### Frontend

- [ ] **UI-01**: Tax Vault page with year selector tabs, document category grid, upload zone, missing alerts banner
- [ ] **UI-02**: Document Detail page with split-panel PDF viewer + extracted fields + annotations thread
- [ ] **UI-03**: Accountant Dashboard page with stats bar, client list table, deadline tracker, invite link generator
- [ ] **UI-04**: 10 shared components (TaxYearTabs, DocumentCard, DocumentUploadZone, ExtractionPanel, AnnotationThread, DocumentRequestCard, MissingAlertBanner, AuditLogTable, etc.)
- [ ] **UI-05**: All new pages follow existing SpendifiAI design system (navy #1E3A5F, teal #0D9488, sw-* tokens)

### Testing

- [ ] **TEST-01**: Feature tests for all new API endpoints (document upload, extraction, accountant access, audit log)
- [ ] **TEST-02**: Unit tests for TaxDocumentStorageService, TaxDocumentExtractorService, TaxWorksheetService, TaxVaultAuditService
- [ ] **TEST-03**: AI extraction tests mock Claude API via `Http::fake()` — no live API calls
- [ ] **TEST-04**: Cross-role authorization tests (owner access, accountant access, wrong-accountant blocked)
- [ ] **TEST-05**: `npm run build` succeeds with zero TypeScript errors
- [ ] **TEST-06**: `vendor/bin/pint` reports no formatting issues

## v2.1 Requirements

Deferred to next milestone. Tracked but not in current roadmap.

### Workflow & Sharing

- **WFLOW-01**: Dual sign-off workflow (taxpayer attestation + accountant approval)
- **WFLOW-02**: Tax year status tracking (in-progress → taxpayer-signed → accountant-signed → filed)
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
| Full SSN/TIN storage | Security risk — store last 4 digits only, strip from extraction |
| Legal e-signature (ESIGN Act) | Compliance burden disproportionate to value — simple attestation with audit log |
| Direct IRS e-filing | Requires EFIN certification — export to tax software instead |
| Real-time collaborative editing | Comments/annotations are sufficient for document collaboration |
| OCR for handwritten documents | AI extraction handles typed/digital forms only |
| ClamAV virus scanning | Defer to production hardening — not MVP blocker |
| Multi-currency tax documents | USD only for v2.0 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| VAULT-01 | TBD | Pending |
| VAULT-02 | TBD | Pending |
| VAULT-03 | TBD | Pending |
| VAULT-04 | TBD | Pending |
| VAULT-05 | TBD | Pending |
| VAULT-06 | TBD | Pending |
| VAULT-07 | TBD | Pending |
| VAULT-08 | TBD | Pending |
| VAULT-09 | TBD | Pending |
| AIEX-01 | TBD | Pending |
| AIEX-02 | TBD | Pending |
| AIEX-03 | TBD | Pending |
| AIEX-04 | TBD | Pending |
| AIEX-05 | TBD | Pending |
| AIEX-06 | TBD | Pending |
| AIEX-07 | TBD | Pending |
| AIEX-08 | TBD | Pending |
| ACCT-01 | TBD | Pending |
| ACCT-02 | TBD | Pending |
| ACCT-03 | TBD | Pending |
| ACCT-04 | TBD | Pending |
| ACCT-05 | TBD | Pending |
| ACCT-06 | TBD | Pending |
| ACCT-07 | TBD | Pending |
| ACCT-08 | TBD | Pending |
| ACCT-09 | TBD | Pending |
| AUDIT-01 | TBD | Pending |
| AUDIT-02 | TBD | Pending |
| AUDIT-03 | TBD | Pending |
| AUDIT-04 | TBD | Pending |
| AUDIT-05 | TBD | Pending |
| AUDIT-06 | TBD | Pending |
| INTEL-01 | TBD | Pending |
| INTEL-02 | TBD | Pending |
| INTEL-03 | TBD | Pending |
| INTEL-04 | TBD | Pending |
| UI-01 | TBD | Pending |
| UI-02 | TBD | Pending |
| UI-03 | TBD | Pending |
| UI-04 | TBD | Pending |
| UI-05 | TBD | Pending |
| TEST-01 | TBD | Pending |
| TEST-02 | TBD | Pending |
| TEST-03 | TBD | Pending |
| TEST-04 | TBD | Pending |
| TEST-05 | TBD | Pending |
| TEST-06 | TBD | Pending |

**Coverage:**
- v2.0 requirements: 44 total
- Mapped to phases: 0
- Unmapped: 44 ⚠️

---
*Requirements defined: 2026-03-30*
*Last updated: 2026-03-30 after initial definition*
