# Roadmap: SpendifiAI

## Milestones

- v1.0 MVP - Phases 1-5 (shipped 2026-02-11)
- v2.0 Tax Document Vault & Accountant Portal - Phases 6-9 (in progress)

## Phases

<details>
<summary>v1.0 MVP (Phases 1-5) - SHIPPED 2026-02-11</summary>

### Phase 1: Project Scaffolding & API Architecture
**Goal**: A running Laravel 12 application with all existing code properly integrated, the monolithic SpendWiseController decomposed into 10 focused controllers, and all API resources and form requests in place
**Depends on**: Nothing (first phase)
**Requirements**: FNDN-01, FNDN-02, FNDN-03, FNDN-04, FNDN-05, CTRL-01, CTRL-02, CTRL-03, CTRL-04, CTRL-05
**Plans**: 2 plans

Plans:
- [x] 01-01: Create Laravel 12 project and integrate all existing code
- [x] 01-02: Split SpendWiseController into 10 controllers, create API Resources and Form Requests

### Phase 2: Auth & Bank Integration
**Goal**: Users can register, log in, connect their bank via Plaid, sync transactions, and manage their financial profile
**Depends on**: Phase 1
**Requirements**: AUTH-01 through AUTH-15, PLAID-01 through PLAID-08, HOOK-01 through HOOK-07, PROF-01, PROF-02
**Plans**: 3 plans

Plans:
- [x] 02-01: Authentication system
- [x] 02-02: Plaid integration
- [x] 02-03: Webhooks and profile management

### Phase 3: AI Intelligence & Financial Features
**Goal**: AI categorization, subscriptions, savings, tax export, email parsing all functional
**Depends on**: Phase 2
**Requirements**: AICAT-01 through AICAT-07, AIQST-01 through AIQST-05, SUBS-01 through SUBS-05, SAVE-01 through SAVE-10, TAX-01 through TAX-07, EMAIL-01 through EMAIL-05
**Plans**: 3 plans

Plans:
- [x] 03-01: AI categorization and subscription detection
- [x] 03-02: Savings analysis and targets
- [x] 03-03: Tax export and email parsing

### Phase 4: Events, Notifications & Frontend
**Goal**: Event-driven architecture, notifications, and all React/Inertia pages
**Depends on**: Phase 3
**Requirements**: EVNT-01 through EVNT-15, NOTF-01 through NOTF-05, UI-01 through UI-10
**Plans**: 3 plans

Plans:
- [x] 04-01: Events, listeners, notifications
- [x] 04-02: Frontend pages (Dashboard, Transactions, Connect, Settings, AI Questions)
- [x] 04-03: Frontend pages (Subscriptions, Savings, Tax, shared components)

### Phase 5: Testing & Deployment
**Goal**: Full test suite and CI/CD pipeline
**Depends on**: Phase 4
**Requirements**: TEST-01 through TEST-13, DEPLOY-01, DEPLOY-02
**Plans**: 3 plans

Plans:
- [x] 05-01: Test infrastructure and factories
- [x] 05-02: Feature and unit tests
- [x] 05-03: CI pipeline and production config

</details>

### v2.0 Tax Document Vault & Accountant Portal

**Milestone Goal:** Build a secure document vault with AI-powered extraction, extend the accountant portal for firm-based client management, and add cross-document intelligence -- making SpendifiAI the bridge between taxpayers and their accountants.

**Phase Numbering:**
- Integer phases (6, 7, 8, 9): Planned milestone work
- Decimal phases (6.1, 7.1): Urgent insertions (marked with INSERTED)

- [x] **Phase 6: Document Vault & Audit Foundation** - Secure document storage (local/S3), upload/view/delete, signed URLs, immutable audit trail, Super Admin storage config, vault UI (completed 2026-03-31)
- [ ] **Phase 7: AI Document Extraction** - Two-pass classify-then-extract pipeline, Tier 1 form extraction, confidence scoring, extraction review UI, document detail page
- [ ] **Phase 8: Accountant Document Collaboration** - Firm registration, branded invites, document annotations, missing document requests, accountant dashboard, cross-role authorization
- [ ] **Phase 9: Intelligence Layer & Final Validation** - AI missing document detection, cross-document anomaly detection, transaction-to-document linking, full test coverage validation

## Phase Details

### Phase 6: Document Vault & Audit Foundation
**Goal**: Users can securely upload, view, and manage tax documents in a vault organized by year and category, with every action recorded in a tamper-proof audit trail and Super Admin control over storage backend
**Depends on**: Phase 5 (v1.0 complete)
**Requirements**: VAULT-01, VAULT-02, VAULT-03, VAULT-04, VAULT-05, VAULT-06, VAULT-07, VAULT-08, VAULT-09, AUDIT-01, AUDIT-02, AUDIT-03, AUDIT-04, AUDIT-05, AUDIT-06, UI-01, UI-04a, UI-05
**Success Criteria** (what must be TRUE):
  1. User can upload PDF, JPG, and PNG tax documents; invalid file types are rejected with a clear error message
  2. User can view their uploaded documents organized by tax year and category, and documents track status through upload/classifying/extracting/ready/failed states
  3. All document actions (view, download, upload, delete) are recorded in the audit log, viewable by document owner and their accountant, with hash chain integrity verifiable
  4. Super Admin can toggle between local and S3 storage, configure S3 credentials, test the connection, and trigger document migration
  5. All document access uses time-limited signed URLs -- no direct file paths are ever exposed to the browser
**Plans**: 5 plans

Plans:
- [x] 06-01-PLAN.md -- Backend foundation: models, migrations, enums, services (TaxDocument, TaxVaultAuditLog, storage + audit services)
- [x] 06-02-PLAN.md -- API layer: controllers, routes, form requests, policies, migration job, admin purge
- [x] 06-03-PLAN.md -- Vault UI: Tax Vault page, shared components (TaxYearTabs, DocumentCard, DocumentUploadZone, MissingAlertBanner, AuditLogTable)
- [x] 06-04-PLAN.md -- Admin Storage page: driver toggle, S3 config, connection test, migration progress
- [ ] 06-05-PLAN.md -- Gap closure: fix admin API URLs, S3 field names, migrate payload, category value mismatch

### Phase 7: AI Document Extraction
**Goal**: Uploaded documents are automatically classified into tax form types and have structured fields extracted by Claude AI, with per-field confidence scoring and a side-by-side review interface
**Depends on**: Phase 6
**Requirements**: AIEX-01, AIEX-02, AIEX-03, AIEX-04, AIEX-05, AIEX-06, AIEX-07, AIEX-08, UI-02, UI-04b, TEST-02, TEST-03
**Success Criteria** (what must be TRUE):
  1. After upload, documents are automatically classified into one of 25 tax form types; classification below confidence threshold skips extraction and flags for manual review
  2. W-2, 1099-NEC, 1099-INT, and 1098 forms have structured fields extracted with per-field confidence scores visible in the UI
  3. User can review extracted fields side-by-side with the document viewer and correct any AI-extracted values
  4. Extraction runs as a background job with retries; SSN stored as last 4 digits only, EIN encrypted, all extraction data uses encrypted:array cast
  5. Unit tests cover TaxDocumentExtractorService and TaxDocumentStorageService; AI extraction tests mock Claude API with no live API calls
**Plans**: 3 plans

Plans:
- [ ] 07-01-PLAN.md -- Backend: enum expansion (25 types), TaxDocumentExtractorService, ExtractTaxDocument job, controller endpoints, field correction API
- [ ] 07-02-PLAN.md -- Frontend: Document Detail page (split-panel viewer), ExtractionPanel, ConfidenceBadge, InlineEditField components
- [ ] 07-03-PLAN.md -- Tests: unit tests for extractor service, feature tests for pipeline and field correction, Http::fake() mocking

### Phase 8: Accountant Document Collaboration
**Goal**: Accountants can register their firm, invite clients via branded links, view client documents, annotate them with threaded comments, request missing documents, and track client readiness from a dedicated dashboard
**Depends on**: Phase 7
**Requirements**: ACCT-01, ACCT-02, ACCT-03, ACCT-04, ACCT-05, ACCT-06, ACCT-07, ACCT-08, ACCT-09, UI-03, UI-04b, TEST-04
**Success Criteria** (what must be TRUE):
  1. Accountant can register an accounting firm with name, address, phone, and branding details; firm generates branded invite links that clients use to self-register and link to the firm
  2. Accountant can view a client's uploaded tax documents through the portal and see document completeness status per client
  3. Accountant can add threaded annotations/comments on client documents; client sees the annotations on their document detail view
  4. Accountant can request missing documents from a client with a description; client sees requests as alerts with upload prompts
  5. Cross-role authorization tests verify: owner can access own documents, linked accountant can access client documents, unlinked accountant is blocked from accessing other clients' documents
**Plans**: TBD

Plans:
- [ ] 08-01: TBD
- [ ] 08-02: TBD

### Phase 9: Intelligence Layer & Final Validation
**Goal**: AI detects missing documents from transaction patterns, flags cross-document anomalies, links transactions to documents, and the full milestone passes comprehensive testing and build validation
**Depends on**: Phase 8
**Requirements**: INTEL-01, INTEL-02, INTEL-03, INTEL-04, TEST-01, TEST-05, TEST-06
**Success Criteria** (what must be TRUE):
  1. System detects missing tax documents by cross-referencing Plaid transaction categories with expected form types and shows alerts with explanations of why each document is expected
  2. Cross-document anomaly detection flags discrepancies (e.g., W-2 wages vs bank deposit totals) and surfaces them in the user's vault view
  3. Transactions are linked to their corresponding tax documents (e.g., 1099 linked to freelance deposits) and the links are visible in both transaction and document views
  4. Feature tests cover all new API endpoints across document upload, extraction, accountant access, and audit log; `npm run build` succeeds with zero TypeScript errors; `vendor/bin/pint` reports no formatting issues
**Plans**: TBD

Plans:
- [ ] 09-01: TBD
- [ ] 09-02: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 6 -> 7 -> 8 -> 9

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1. Scaffolding & API Architecture | v1.0 | 2/2 | Complete | 2026-02-11 |
| 2. Auth & Bank Integration | v1.0 | 3/3 | Complete | 2026-02-11 |
| 3. AI Intelligence & Financial Features | v1.0 | 3/3 | Complete | 2026-02-11 |
| 4. Events, Notifications & Frontend | v1.0 | 3/3 | Complete | 2026-02-11 |
| 5. Testing & Deployment | v1.0 | 3/3 | Complete | 2026-02-11 |
| 6. Document Vault & Audit Foundation | v2.0 | Complete    | 2026-03-31 | 2026-03-31 |
| 7. AI Document Extraction | 1/3 | In Progress|  | - |
| 8. Accountant Document Collaboration | v2.0 | 0/? | Not started | - |
| 9. Intelligence Layer & Final Validation | v2.0 | 0/? | Not started | - |
