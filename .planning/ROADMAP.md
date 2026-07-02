# Roadmap: SpendifiAI

## Milestones

- v1.0 MVP - Phases 1-5 (shipped 2026-02-11)
- v2.0 Tax Document Vault & Accountant Portal - Phases 6-9 (shipped 2026-03-31)
- v2.1 Optimize My Income - Phases 10-13 (in progress)

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

### v2.1 Optimize My Income (In Progress)

**Milestone Goal:** Help users find every legal edge to maximize take-home income and savings by reviewing all their financial documents plus linked email/bank data, then interviewing them with only high-value questions and red flags — as educational suggestions only, never definitive tax advice. Deterministic tax math lives in a `TaxRulesEngineService` reading a year-versioned `config/tax-rules.php`; Claude only narrates pre-computed findings. Additive/forward-only, full backwards-compat with v1.0/v2.0, reusing the Tax Document Vault, two-pass extraction, and AI Questions feed.

**Cross-cutting constraints (honored in every phase):** Educational-only framing ("may/could/consider"), banned assertive language, persistent non-dismissable disclaimers, Claude never computes dollar amounts, additive/forward-only migrations, no destructive DB ops, no changes to existing API responses/models/component interfaces.

- [ ] **Phase 10: Foundation — Tax Rules Engine & Cross-Source Snapshot** - Deterministic 2026 tax math and a per-user financial snapshot, both proven correct with zero Claude involvement in numbers
- [ ] **Phase 11: Red-Flag Detection, Guided Interview & AI Feed Integration** - Deterministic red-flag findings surfaced through a resumable one-question interview and the existing AI Questions feed
- [ ] **Phase 12: Optimization Report, Document Intake & Feature Surface** - Exportable educational optimization report reached through a dedicated Optimize My Income surface, fed by expanded financial document intake
- [ ] **Phase 13: Safety, Validation & Hardening** - The complete feature certified within the educational-only liability boundary via security, legal, and PII hardening

## Phase Details

### Phase 10: Foundation — Tax Rules Engine & Cross-Source Snapshot

**Goal**: A deterministic tax-math engine and a per-user financial snapshot exist and are proven correct, computing every number from year-versioned config with zero Claude calls — the load-bearing foundation all later phases read from.
**Depends on**: Phase 9 (v2.0 complete)
**Requirements**: TAX-01, TAX-02, TAX-03, TAX-04, TAX-05, TAX-06, TAX-07, CTX-01, CTX-02, CTX-03, CTX-04
**Success Criteria** (what must be TRUE):

  1. The system computes marginal + effective federal rates, standard-vs-itemized comparison, and remaining 401(k)/IRA/HSA headroom (with age-based catch-up) purely from `config/tax-rules.php`, verified by Pest tests asserting exact matches at bracket boundaries.
  2. The engine produces a deterministic Traditional-vs-Roth recommendation band and surfaces QBI eligibility and the SE-tax deduction where applicable — all without any Claude call.
  3. A user's financial snapshot is assembled from existing sources (UserFinancialProfile, transactions, income detection, vault-extracted documents), persisted in a new `IncomeOptimizationProfile` cache model, and rebuilt via a background job.
  4. Cross-source discrepancies between documents, bank deposits, and email data are detected deterministically, and downstream consumers can tell what is already answerable from the snapshot.
  5. All existing v1.0/v2.0 tests continue to pass with no changes to existing API responses, models, or component interfaces.

**Plans**: 2/3 plans executed

- [x] 10-01-PLAN.md — Tax rules engine: config/tax-rules.php + TaxRulesEngineService + Pest boundary/no-Claude tests (TAX-01–07)
- [x] 10-02-PLAN.md — IncomeOptimizationProfile model/migration + IncomeOptimizerDataAssemblerService + feature tests (CTX-01, CTX-02, CTX-04)
- [ ] 10-03-PLAN.md — CrossSourceReviewService + OptimizationFinding + BuildIncomeOptimizationProfile job/event + tests (CTX-03, CTX-04)

### Phase 11: Red-Flag Detection, Guided Interview & AI Feed Integration

**Goal**: Red-flag findings are detected deterministically from verified data and surfaced to the user through a resumable, one-high-value-question-at-a-time interview and the existing AI Questions feed — with Claude used only to word questions and describe flags.
**Depends on**: Phase 10 (requires the rules engine + snapshot before any detector or interview)
**Requirements**: FLAG-01, FLAG-02, FLAG-03, FLAG-04, FLAG-05, FLAG-06, INT-01, INT-02, INT-03, INT-04, INT-05, FEED-01, FEED-02, FEED-03, FEED-04
**Success Criteria** (what must be TRUE):

  1. A user sees prioritized red-flag findings (filing-status mismatch surfaced not asserted, withholding gap > $500, unclaimed 401(k) employer match, and deduction probes that fire only when their data prerequisites are verified), each carrying a severity so high-value items appear first.
  2. A user completes a guided interview one high-value question at a time with skip, back, resume, and re-answer, capped to a small initial pass, and is never asked anything derivable from the snapshot; probes stay gated behind explicit prerequisite answers (e.g. backdoor-Roth gated behind IRA balance).
  3. High-priority red flags appear as `QuestionType::Optimization` records in the existing AI Questions feed, and a user's answers feed back into the optimization profile.
  4. Existing transaction-categorization behavior and tests are unaffected — the `UpdateTransactionCategory` listener ignores optimization questions.

**Plans**: TBD
**UI hint**: yes

### Phase 12: Optimization Report, Document Intake & Feature Surface

**Goal**: Users reach a complete, ranked, exportable, educational optimization report through a dedicated Optimize My Income surface, fed by expanded financial document intake that reuses the v2.0 Vault two-pass pipeline.
**Depends on**: Phase 11 (report generation requires completed interview sessions and findings)
**Requirements**: RPT-01, RPT-02, RPT-03, RPT-04, DOC-01, DOC-02, DOC-03, UI-01, UI-02, UI-03
**Success Criteria** (what must be TRUE):

  1. A user views a ranked, four-section report (deductions / taxes / filings / 401k) where every number originates from `TaxRulesEngineService` and Claude writes only the narrative prose; the report is generated by a background job, stored in a new `OptimizationReport` model, and marked stale on new upload or bank sync.
  2. A user uploads new document types (check/pay stub, employer offer letter, 401(k)/retirement, benefits, stock, insurance, mortgage) — including screenshots via the existing Claude vision pattern — which feed the optimization snapshot and mark the report stale.
  3. A user navigates via a new "Optimize My Income" nav item (badged with pending red-flag questions) through the flow findings → guided interview → report.
  4. Persistent, non-globally-dismissable educational disclaimers render on every section, every surface uses modal framing ("may/could/consider"), and the report exports to PDF via the existing dompdf pipeline.

**Plans**: TBD
**UI hint**: yes

### Phase 13: Safety, Validation & Hardening

**Goal**: The complete feature is certified as holding the educational-only liability boundary through a security, legal, and PII hardening pass on the full end-to-end system.
**Depends on**: Phase 12 (hardening runs last, on the complete feature)
**Requirements**: SAFE-01, SAFE-02, SAFE-03, SAFE-04, SAFE-05
**Success Criteria** (what must be TRUE):

  1. Every Claude system prompt across the feature hard-codes educational framing and bans assertive language ("you should/must/qualify," filing-status assertions), verified by a documented framing/legal review.
  2. A test suite asserts that no report or finding dollar amount originates from Claude — all numbers trace to `TaxRulesEngineService`/config.
  3. Uploaded-document content reaches Claude only inside `<document_content>` delimiters with a structured JSON output schema and output validation, and a prompt-injection penetration test passes.
  4. Sensitive PII from stubs and offer letters (SSN, wages) follows existing encryption + SSN-last-4 rules and stays within the existing audit trail, verified by an SSN-masking audit.

**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 10 → 11 → 12 → 13

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
| 10. Foundation — Tax Rules Engine & Cross-Source Snapshot | v2.1 | 2/3 | In Progress|  |
| 11. Red-Flag Detection, Guided Interview & AI Feed Integration | v2.1 | 0/TBD | Not started | - |
| 12. Optimization Report, Document Intake & Feature Surface | v2.1 | 0/TBD | Not started | - |
| 13. Safety, Validation & Hardening | v2.1 | 0/TBD | Not started | - |
