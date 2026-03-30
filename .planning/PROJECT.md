# SpendifiAI

## What This Is

SpendifiAI is an AI-powered personal finance platform built for freelancers, small business owners, and their tax accountants. It connects to bank accounts via Plaid, auto-categorizes transactions with Claude AI, detects subscriptions, generates savings recommendations, parses email receipts, and now provides a secure Tax Document Vault with AI-powered document extraction, an Accountant Portal for firm-based client management, and a dual sign-off workflow for tax filing readiness.

## Core Value

Users connect their bank and immediately get intelligent, automatic categorization of every transaction — with business/personal separation, tax deduction flagging, and AI-generated questions when confidence is low — so they never have to manually sort expenses again. Tax accountants get a secure portal to review client documents, request missing items, annotate extractions, and co-sign tax year completion.

## Current Milestone: v2.0 Tax Document Vault & Accountant Portal

**Goal:** Build a secure document vault for tax documents with AI-powered extraction, an accountant portal for firm-based client management, and a dual sign-off workflow — making SpendifiAI the bridge between taxpayers and their accountants.

**Target features:**
- Secure tax document vault with upload, AI classification, and field extraction (25 form types)
- Accountant portal with firm registration, branded invites, client management dashboard
- Dual sign-off workflow for tax year completion (taxpayer + accountant)
- Document sharing packages with time-limited signed URLs
- Tax worksheets with auto-populated fields from AI extraction
- Missing document detection and accountant-initiated document requests
- Immutable audit trail for all document actions
- Super Admin document storage configuration (local filesystem + S3)
- Annotation/comment threads on documents between taxpayer and accountant

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

### Active

- [ ] Tax Document Vault with upload, AI classification, and storage (local + S3)
- [ ] AI-powered tax document extraction (25 form types, two-pass classify→extract pipeline)
- [ ] Tax worksheets with auto-populated fields from extraction data
- [ ] Document annotation/comment threads between taxpayer and accountant
- [ ] Missing document detection and accountant-initiated document requests
- [ ] Accountant portal with firm registration and branded invite onboarding
- [ ] Accountant client management dashboard with deadline tracking
- [ ] Dual sign-off workflow for tax year completion
- [ ] Document sharing packages with time-limited signed URLs
- [ ] Immutable audit trail for all document actions
- [ ] Super Admin document storage configuration (local + S3 toggle)
- [ ] Anomaly detection on extracted document data (cross-document validation)
- [ ] Tax software export format generation (TurboTax, H&R Block, etc.)

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

### Existing Codebase (~60% complete)
The project has substantial existing code in `existing-code/` that needs to be integrated into a fresh Laravel 12 project:
- **16 Eloquent models** — all complete with typed casts, relationships, scopes, $hidden, $fillable
- **5 auth controllers** — registration, login, Google OAuth, 2FA, password reset, email verification
- **7 services** — PlaidService, TransactionCategorizerService, SavingsAnalyzerService, SavingsTargetPlannerService, SubscriptionDetectorService, TaxExportService, CaptchaService
- **1 monolithic controller** (SpendWiseController, ~939 lines) — all endpoint logic, needs splitting into 10 focused controllers
- **7 PHP 8.2 backed enums** — AccountPurpose, ConnectionStatus, ExpenseType, QuestionStatus, QuestionType, ReviewStatus, SubscriptionStatus
- **4 middleware** — VerifyCaptcha, EnsureBankConnected, EnsureProfileComplete, Enforce2FA
- **4 policies** — Transaction, BankAccount, AIQuestion, Subscription
- **5 database migrations** — 14+ core tables, account purpose, savings targets, auth columns, encryption column changes
- **1 seeder** — ExpenseCategorySeeder (50+ IRS-mapped categories)
- **1 job** — CategorizePendingTransactions
- **Expense parser module** — partially built (GmailService, EmailParserService, ReconciliationService, ProcessOrderEmails job)
- **Reference dashboard** — React prototype (reference-dashboard.jsx) with 5 pages using mock data
- **Routes** — api.php already references target controllers that don't exist yet
- **Config** — spendwise.php, services.php, fortify.php all configured
- **Python scripts** — generate_tax_excel.py, generate_tax_pdf.py for tax export
- **Plaid sandbox credentials** — pre-configured in .env, ready to use

### Transaction History
Plaid sync should pull transaction history up to 12 months back or to the beginning of the previous calendar year, whichever provides more data.

### Frontend Design Reference
The reference-dashboard.jsx prototype should be matched closely when building the real Inertia/React/TypeScript pages. Same layout, same flow — rebuilt properly with shadcn/ui components and real API calls.

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
| Local-first document storage with S3 config switch | Simplifies dev/staging, S3 for production — runtime toggle in Super Admin | — Pending |
| SSN last-4 only, EIN encrypted | Minimize PII exposure — compliance-first approach | — Pending |
| Immutable audit log (no update/delete) | Regulatory compliance — full traceability of document access | — Pending |
| Two-pass AI extraction (classify→extract) | Classification confidence gates extraction — prevents wasted API calls | — Pending |
| Accountant onboarding via branded invite links | Friction-free onboarding — accountant shares link, clients self-register | — Pending |
| Dual sign-off workflow | Both taxpayer and accountant must approve before tax year marked filed | — Pending |
| Signed URLs for all document access | No direct file paths exposed — time-limited, tamper-proof access | — Pending |

---
*Last updated: 2026-03-30 after v2.0 milestone initialization*
