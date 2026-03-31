# Roadmap: SpendifiAI

## Milestones

- v1.0 MVP - Phases 1-5 (shipped 2026-02-11)
- v2.0 Tax Document Vault & Accountant Portal - Phases 6-9 (shipped 2026-03-31)

## Phases

<details>
<summary>v1.0 MVP (Phases 1-5) - SHIPPED 2026-02-11</summary>

### Phase 1: Project Scaffolding & API Architecture
**Goal**: A running Laravel 12 application with all existing code properly integrated, the monolithic SpendWiseController decomposed into 10 focused controllers, and all API resources and form requests in place
**Plans**: 2 plans

Plans:
- [x] 01-01: Create Laravel 12 project and integrate all existing code
- [x] 01-02: Split SpendWiseController into 10 controllers, create API Resources and Form Requests

### Phase 2: Auth & Bank Integration
**Goal**: Users can register, log in, connect their bank via Plaid, sync transactions, and manage their financial profile
**Plans**: 3 plans

Plans:
- [x] 02-01: Authentication system
- [x] 02-02: Plaid integration
- [x] 02-03: Webhooks and profile management

### Phase 3: AI Intelligence & Financial Features
**Goal**: AI categorization, subscriptions, savings, tax export, email parsing all functional
**Plans**: 3 plans

Plans:
- [x] 03-01: AI categorization and subscription detection
- [x] 03-02: Savings analysis and targets
- [x] 03-03: Tax export and email parsing

### Phase 4: Events, Notifications & Frontend
**Goal**: Event-driven architecture, notifications, and all React/Inertia pages
**Plans**: 3 plans

Plans:
- [x] 04-01: Events, listeners, notifications
- [x] 04-02: Frontend pages (Dashboard, Transactions, Connect, Settings, AI Questions)
- [x] 04-03: Frontend pages (Subscriptions, Savings, Tax, shared components)

### Phase 5: Testing & Deployment
**Goal**: Full test suite and CI/CD pipeline
**Plans**: 3 plans

Plans:
- [x] 05-01: Test infrastructure and factories
- [x] 05-02: Feature and unit tests
- [x] 05-03: CI pipeline and production config

</details>

<details>
<summary>v2.0 Tax Document Vault & Accountant Portal (Phases 6-9) - SHIPPED 2026-03-31</summary>

### Phase 6: Document Vault & Audit Foundation
**Goal**: Secure document storage, upload/view/delete, signed URLs, immutable audit trail, Super Admin storage config, vault UI
**Plans**: 5 plans (completed 2026-03-31)

Plans:
- [x] 06-01: Backend foundation (models, migrations, enums, services)
- [x] 06-02: API layer (controllers, routes, requests, policies, migration job)
- [x] 06-03: Vault UI (page + 5 shared components)
- [x] 06-04: Admin Storage page (driver toggle, S3 config, migration progress)
- [x] 06-05: Gap closure (admin API URLs, S3 field names, migrate payload, category values)

### Phase 7: AI Document Extraction
**Goal**: Two-pass classify-then-extract pipeline, Tier 1 form extraction, confidence scoring, extraction review UI
**Plans**: 3 plans (completed 2026-03-31)

Plans:
- [x] 07-01: Backend (extractor service, job, enum expansion, endpoints, config)
- [x] 07-02: Frontend (document detail page, extraction panel, confidence badges, inline editing)
- [x] 07-03: Tests (18 tests for extraction pipeline)

### Phase 8: Accountant Document Collaboration
**Goal**: Firm registration, branded invites, document annotations, missing document requests, accountant dashboard
**Plans**: 5 plans (completed 2026-03-31)

Plans:
- [x] 08-01: Models + migrations (AccountingFirm, DocumentAnnotation, DocumentRequest)
- [x] 08-02: Firm controller + 5 mail classes
- [x] 08-03: Annotation + request controllers, auto-fulfillment
- [x] 08-04: Dashboard + components UI
- [x] 08-05: Authorization tests (25 tests)

### Phase 9: Intelligence Layer & Final Validation
**Goal**: Missing document detection, anomaly detection, transaction linking, build validation quality gate
**Plans**: 3 plans (completed 2026-03-31)

Plans:
- [x] 09-01: Intelligence backend (service, pivot table, API endpoint)
- [x] 09-02: Intelligence UI + feature tests
- [x] 09-03: Build validation (npm build clean, pint clean, 225 tests passing)

</details>

## Progress

| Phase | Milestone | Plans | Status | Completed |
|-------|-----------|-------|--------|-----------|
| 1. Scaffolding & API Architecture | v1.0 | 2/2 | Complete | 2026-02-11 |
| 2. Auth & Bank Integration | v1.0 | 3/3 | Complete | 2026-02-11 |
| 3. AI Intelligence & Financial Features | v1.0 | 3/3 | Complete | 2026-02-11 |
| 4. Events, Notifications & Frontend | v1.0 | 3/3 | Complete | 2026-02-11 |
| 5. Testing & Deployment | v1.0 | 3/3 | Complete | 2026-02-11 |
| 6. Document Vault & Audit Foundation | v2.0 | 5/5 | Complete | 2026-03-31 |
| 7. AI Document Extraction | v2.0 | 3/3 | Complete | 2026-03-31 |
| 8. Accountant Document Collaboration | v2.0 | 5/5 | Complete | 2026-03-31 |
| 9. Intelligence Layer & Final Validation | v2.0 | 3/3 | Complete | 2026-03-31 |
