# LedgerIQ

## What This Is

LedgerIQ is an AI-powered expense tracking SaaS that connects to users' bank accounts via Plaid, automatically categorizes transactions using Claude AI, detects subscriptions, generates savings recommendations, parses email receipts to match against bank charges, and exports tax-ready reports with IRS Schedule C mapping. It serves freelancers, small business owners, and consumers — the business/personal account purpose system adapts the experience to each user type.

## Core Value

Users connect their bank and immediately get intelligent, automatic categorization of every transaction — with business/personal separation, tax deduction flagging, and AI-generated questions when confidence is low — so they never have to manually sort expenses again.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] Full auth system (email/password, Google OAuth, 2FA, email verification, password reset)
- [ ] Plaid bank integration (link accounts, sync transactions up to 12 months back or beginning of prior year, disconnect)
- [ ] Plaid webhook handler (real-time transaction updates, error handling, re-auth notifications)
- [ ] AI transaction categorization with confidence-based routing (auto, flag-for-review, multiple-choice, open-ended)
- [ ] Business/personal account purpose tagging that cascades to transactions and AI context
- [ ] AI question generation and answer flow (single + bulk)
- [ ] Subscription detection from transaction patterns with unused service flagging
- [ ] Savings analysis (90-day spending analysis, recommendations, dismissible/actionable)
- [ ] Savings target planning (goals with deadlines, AI-generated action plans, pulse checks)
- [ ] Tax summary with IRS Schedule C line mapping and deduction tracking
- [ ] Tax export (Excel 5-tab workbook + PDF cover sheet + CSV) with email-to-accountant
- [ ] Email receipt parsing (Gmail OAuth, Claude-powered extraction, bank transaction reconciliation)
- [ ] Dashboard (spending summary, category breakdown chart, recent transactions, AI question alerts)
- [ ] Transaction list with filtering (date, category, account, business/personal, search) and inline category editing
- [ ] Subscription management page (card grid, unused warnings, cost totals)
- [ ] Savings page (target gauge, recommendation cards, action plan checklist)
- [ ] Tax page (deduction summary, Schedule C mapping, export/send modals)
- [ ] Connect page (Plaid Link button, connected accounts list, email connection flow)
- [ ] Settings page (financial profile, security/2FA, delete account)
- [ ] AI questions page (pending questions with transaction context, multiple-choice/free-text, bulk mode)
- [ ] Background jobs (transaction sync, categorization, subscription detection, savings analysis, email processing, reconciliation)
- [ ] Event-driven architecture (bank connected, transactions imported, categorized, question answered, etc.)
- [ ] Notifications (AI questions ready, unused subscriptions, budget threshold, weekly digest)
- [ ] Full test suite (Pest PHP — factories, feature tests for all flows, unit tests for services)
- [ ] Deployment config (Docker, queue workers, scheduler, CI/CD)

### Out of Scope

- Real-time chat or messaging — not relevant to expense tracking
- Video content — no use case
- Mobile native app — web-first; mobile comes after v1 validation
- Billing/subscription management — free for now, monetization comes later
- Admin panel — build when multi-user management is needed
- Real-time push (WebSockets) — polling and page refresh sufficient for v1
- Multi-currency — USD only for v1

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
| Match reference dashboard closely | Consistent design vision — prototype already captures intended UX | — Pending |

---
*Last updated: 2026-02-10 after initialization*
