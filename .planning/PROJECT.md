# SpendifiAI

## What This Is

SpendifiAI is an AI-powered personal finance platform built for freelancers, small business owners, and their tax accountants. It connects to bank accounts via Plaid, auto-categorizes transactions with Claude AI, detects subscriptions, generates savings recommendations, parses email receipts, and now provides a secure Tax Document Vault with AI-powered document extraction, an Accountant Portal for firm-based client management, and a dual sign-off workflow for tax filing readiness.

## Core Value

Users connect their bank and immediately get intelligent, automatic categorization of every transaction — with business/personal separation, tax deduction flagging, and AI-generated questions when confidence is low — so they never have to manually sort expenses again. Tax accountants get a secure portal to review client documents, request missing items, annotate extractions, and co-sign tax year completion.

## Current State

v2.0 shipped 2026-03-31. No active milestone — ready for v2.1 planning.

## Requirements

### Validated

- ✓ Full auth system (email/password, Google OAuth, 2FA, email verification, password reset) — v1.0
- ✓ Plaid bank integration (link accounts, sync transactions, disconnect) — v1.0
- ✓ Plaid webhook handler (real-time transaction updates, error handling) — v1.0
- ✓ AI transaction categorization with confidence-based routing — v1.0
- ✓ Business/personal account purpose tagging — v1.0
- ✓ AI question generation and answer flow — v1.0
- ✓ Subscription detection with unused service flagging — v1.0
- ✓ Savings analysis and target planning — v1.0
- ✓ Tax summary with IRS Schedule C mapping and export — v1.0
- ✓ Email receipt parsing and reconciliation — v1.0
- ✓ Dashboard, Transactions, Subscriptions, Savings, Tax, Connect, Settings, AI Questions pages — v1.0
- ✓ Background jobs, event-driven architecture, notifications — v1.0
- ✓ Full test suite and CI/CD pipeline — v1.0
- ✓ Tax Document Vault with upload, AI classification, local/S3 storage, signed URLs — v2.0
- ✓ AI-powered document extraction (25 form types, two-pass classify→extract, per-field confidence) — v2.0
- ✓ Immutable hash-chain audit trail for all document actions — v2.0
- ✓ Super Admin document storage configuration (local + S3 toggle with live migration) — v2.0
- ✓ Accountant portal with firm registration, branded invite links, client dashboard — v2.0
- ✓ Document annotations (threaded comments between taxpayer and accountant) — v2.0
- ✓ Missing document detection from transaction patterns + accountant-initiated requests — v2.0
- ✓ Cross-document anomaly detection (W-2 wages vs deposits, 1099 amounts vs income) — v2.0
- ✓ Transaction-to-document linking — v2.0
- ✓ 225 tests (761 assertions), zero build errors — v2.0

### Active

- [ ] Dual sign-off workflow for tax year completion (taxpayer + accountant attestation)
- [ ] Tax worksheets with auto-populated fields from AI extraction data
- [ ] Document sharing packages with time-limited signed URLs
- [ ] Tax software export format generation (TurboTax TXF, H&R Block)
- [ ] Multi-accountant firm support (team members, roles, permissions)

### Out of Scope

- Real-time chat or messaging — not relevant to expense tracking
- Video content — no use case
- Mobile native app — web-first; mobile comes after v1 validation
- Billing/subscription management — free for now, monetization comes later
- Real-time push (WebSockets) — polling and page refresh sufficient for v2
- Multi-currency — USD only for v2
- Full SSN/TIN storage — security risk, store last 4 digits only
- Direct e-filing integration — export formats only, no IRS submission
- OCR for handwritten documents — AI extraction handles typed/digital forms only

## Context

### Current Codebase (v2.0 shipped)
- **33+ Eloquent models** including TaxDocument, TaxVaultAuditLog, AccountingFirm, DocumentAnnotation, DocumentRequest
- **15+ API controllers** across auth, transactions, vault, accountant, admin
- **14+ services** in app/Services/ and app/Services/AI/ including TaxDocumentExtractorService, TaxDocumentIntelligenceService
- **10+ PHP 8.3 backed enums** including DocumentStatus, TaxDocumentCategory (25 types), DocumentRequestStatus
- **225 tests (761 assertions)** — comprehensive coverage of vault, extraction, accountant, intelligence
- **28+ Inertia pages** including Vault, Document Detail, Accountant Dashboard, Admin Storage
- **20+ shared React components** in Components/SpendifiAI/
- **5 Mail classes** for accountant collaboration workflows
- Clean build: zero TypeScript errors, zero Pint formatting issues

## Constraints

- **Tech Stack**: Laravel 12 + React 19 + Inertia 2 + TypeScript + shadcn/ui (Laravel starter kit) — already decided, existing code is built for this
- **Database**: PostgreSQL 15+ — required for encrypted TEXT column support
- **Cache/Queue**: Redis 7+ — required for job queue and caching
- **AI Provider**: Anthropic Claude API (Sonnet) — all AI services already built against this API
- **Bank Integration**: Plaid API — existing PlaidService is built, sandbox credentials configured
- **Encryption**: All sensitive fields use Laravel model casts (`'encrypted'`, `'encrypted:array'`) — never manual encrypt/decrypt, all encrypted fields must be TEXT columns
- **Auth**: Laravel Sanctum + Fortify — existing auth controllers depend on this
- **PHP**: 8.2+ required — code uses backed enums, typed properties, `casts()` method syntax
- **Testing**: Pest PHP — Laravel 12 default

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Laravel 12 React starter kit | Provides Inertia 2 + React 19 + TypeScript + shadcn/ui out of the box — matches existing code expectations | — Pending |
| Encrypted model casts over manual encrypt/decrypt | Simpler, less error-prone, automatic on read/write | — Pending |
| Account purpose as strongest AI categorization signal | Business vs personal context dramatically improves accuracy | — Pending |
| Service layer pattern (thin controllers) | All business logic in services, controllers only handle HTTP | — Pending |
| Plaid transaction sync (12 months / beginning of prior year) | Covers full tax year for users connecting mid-year | — Pending |
| Free for now, monetize later | Build value first — billing infrastructure deferred to post-v1 | — Pending |
| Email parsing in v1 | Core differentiator — matching receipts to bank charges adds unique value | — Pending |
| Match reference dashboard closely | Consistent design vision — prototype already captures intended UX | ✓ Good |
| Local-first document storage with S3 config switch | Simplifies dev/staging, S3 for production — runtime toggle in Super Admin | ✓ Good |
| SSN last-4 only, EIN encrypted | Minimize PII exposure — compliance-first approach | ✓ Good |
| Immutable audit log (no update/delete) | Regulatory compliance — full traceability of document access | ✓ Good |
| Two-pass AI extraction (classify→extract) | Classification confidence gates extraction — prevents wasted API calls | ✓ Good |
| Accountant onboarding via branded invite links | Friction-free onboarding — accountant shares link, clients self-register | ✓ Good |
| Dual sign-off workflow | Both taxpayer and accountant must approve before tax year marked filed | — Deferred to v2.1 |
| Signed URLs for all document access | No direct file paths exposed — time-limited, tamper-proof access | ✓ Good |
| PostgreSQL BEFORE triggers over RULES for audit immutability | RULES conflicted with FK cascade operations — BEFORE triggers allow selective nullification | ✓ Good |
| On-demand intelligence with 4-hour cache | No background jobs for intelligence — cached analysis on vault page load | ✓ Good |
| $600 IRS threshold for missing document detection | Filters noise — only flags when income exceeds reporting threshold | ✓ Good |

---
*Last updated: 2026-03-31 after v2.0 milestone completion*
