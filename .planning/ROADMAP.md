# Roadmap: LedgerIQ

## Overview

LedgerIQ is an AI-powered expense tracker with ~60% of backend code already built. The roadmap integrates existing code into a fresh Laravel 12 project, decomposes the monolithic controller, wires up all backend features (bank integration, AI categorization, subscriptions, savings, tax, email parsing), builds the React/Inertia frontend, and delivers a tested, deployable application. Five phases move from scaffolding through working product.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Project Scaffolding & API Architecture** - Create Laravel 12 project, integrate existing code, split monolithic controller into 10 focused controllers
- [x] **Phase 2: Auth & Bank Integration** - Wire up authentication, Plaid bank connection, webhooks, and user profile management
- [ ] **Phase 3: AI Intelligence & Financial Features** - AI categorization, questions, subscriptions, savings, tax export, email parsing
- [ ] **Phase 4: Events, Notifications & Frontend** - Event-driven automation, notification system, and all React/Inertia pages
- [ ] **Phase 5: Testing & Deployment** - Full Pest PHP test suite with factories, CI/CD pipeline

## Phase Details

### Phase 1: Project Scaffolding & API Architecture
**Goal**: A running Laravel 12 application with all existing code properly integrated, the monolithic SpendWiseController decomposed into 10 focused controllers, and all API resources and form requests in place
**Depends on**: Nothing (first phase)
**Requirements**: FNDN-01, FNDN-02, FNDN-03, FNDN-04, FNDN-05, CTRL-01, CTRL-02, CTRL-03, CTRL-04, CTRL-05
**Success Criteria** (what must be TRUE):
  1. Running `php artisan serve` starts the application without errors and the welcome page loads
  2. Running `php artisan migrate` creates all 14+ database tables successfully
  3. Running `php artisan db:seed --class=ExpenseCategorySeeder` populates 50+ expense categories
  4. `php artisan route:list --path=api` shows all routes pointing to 10 separate controllers (not SpendWiseController)
  5. Each API controller returns proper JSON via API Resources when called (even if empty data)
**Plans**: 2 plans

Plans:
- [x] 01-01: Create Laravel 12 project and integrate all existing code (models, services, enums, middleware, policies, migrations, configs, routes, seeders)
- [x] 01-02: Split SpendWiseController into 10 controllers, create API Resources and Form Requests

### Phase 2: Auth & Bank Integration
**Goal**: Users can register, log in (with optional 2FA and Google OAuth), connect their bank via Plaid, sync transactions, and manage their financial profile -- with real-time webhook handling for ongoing updates
**Depends on**: Phase 1
**Requirements**: AUTH-01, AUTH-02, AUTH-03, AUTH-04, AUTH-05, AUTH-06, AUTH-07, AUTH-08, AUTH-09, AUTH-10, AUTH-11, AUTH-12, AUTH-13, AUTH-14, AUTH-15, PLAID-01, PLAID-02, PLAID-03, PLAID-04, PLAID-05, PLAID-06, PLAID-07, PLAID-08, HOOK-01, HOOK-02, HOOK-03, HOOK-04, HOOK-05, HOOK-06, HOOK-07, PROF-01, PROF-02
**Success Criteria** (what must be TRUE):
  1. User can register with email/password, verify email, log in, and stay logged in across browser refresh via Sanctum token
  2. User can enable TOTP 2FA, is prompted for code on login, and can disable it or regenerate recovery codes
  3. User can log in via Google OAuth and disconnect the linked Google account
  4. User can connect a bank via Plaid Link, view connected accounts with balances, tag account purpose (business/personal), and disconnect
  5. Transactions sync from Plaid (up to 12 months or beginning of prior year) and account purpose cascades to all transactions from that account
  6. Plaid webhooks trigger automatic transaction sync, handle connection errors, and process transaction removals idempotently
  7. User can view and update their financial profile, reset/change password, and delete their account with cascading data removal
**Plans**: 3 plans

Plans:
- [x] 02-01: Verify and complete authentication system (email/password, Google OAuth, 2FA, password reset, email verification, captcha, account lockout)
- [x] 02-02: Wire up Plaid integration (link token, exchange, sync, balances, disconnect, account purpose tagging)
- [x] 02-03: Build Plaid webhook handler and user profile management

### Phase 3: AI Intelligence & Financial Features
**Goal**: Transactions are automatically categorized by Claude AI with confidence-based routing, users can answer AI questions, subscriptions are detected, savings recommendations are generated, tax reports are exportable, and email receipts are parsed and reconciled
**Depends on**: Phase 2
**Requirements**: AICAT-01, AICAT-02, AICAT-03, AICAT-04, AICAT-05, AICAT-06, AICAT-07, AIQST-01, AIQST-02, AIQST-03, AIQST-04, AIQST-05, SUBS-01, SUBS-02, SUBS-03, SUBS-04, SUBS-05, SAVE-01, SAVE-02, SAVE-03, SAVE-04, SAVE-05, SAVE-06, SAVE-07, SAVE-08, SAVE-09, SAVE-10, TAX-01, TAX-02, TAX-03, TAX-04, TAX-05, TAX-06, TAX-07, EMAIL-01, EMAIL-02, EMAIL-03, EMAIL-04, EMAIL-05
**Success Criteria** (what must be TRUE):
  1. After transaction sync, uncategorized transactions are batched and sent to Claude API; results are routed by confidence (auto-categorize at >=0.85, flag for review at 0.60-0.84, generate questions below 0.60)
  2. User can view pending AI questions with transaction context, answer individually or in bulk, and answers update the transaction category
  3. System detects recurring subscriptions from transaction patterns, flags unused ones, and user can view subscription list with costs
  4. User can trigger savings analysis, view AI-generated recommendations with action steps, dismiss or apply them, set savings targets, and track progress with pulse checks
  5. User can view tax summary by IRS Schedule C line, export tax packages (Excel/PDF/CSV), email exports to accountant, and download previous exports
  6. User can connect Gmail, system parses email receipts via Claude AI, creates order records, and reconciles them against bank transactions
**Plans**: 3 plans

Plans:
- [ ] 03-01: Wire up AI categorization pipeline and question flow (Claude API integration, confidence routing, question CRUD, bulk answers)
- [ ] 03-02: Complete subscription detection, savings analysis and target planning (recurring charge detection, Claude savings analysis, action plans)
- [ ] 03-03: Tax export pipeline and email receipt parsing (Schedule C mapping, Excel/PDF/CSV export, Gmail OAuth, receipt parsing, reconciliation)

### Phase 4: Events, Notifications & Frontend
**Goal**: All backend features are connected via event-driven architecture with automated jobs and scheduled tasks, users receive actionable notifications, and all React/Inertia/TypeScript pages are built matching the reference dashboard design
**Depends on**: Phase 3
**Requirements**: EVNT-01, EVNT-02, EVNT-03, EVNT-04, EVNT-05, EVNT-06, EVNT-07, EVNT-08, EVNT-09, EVNT-10, EVNT-11, EVNT-12, EVNT-13, EVNT-14, EVNT-15, NOTF-01, NOTF-02, NOTF-03, NOTF-04, NOTF-05, UI-01, UI-02, UI-03, UI-04, UI-05, UI-06, UI-07, UI-08, UI-09, UI-10
**Success Criteria** (what must be TRUE):
  1. Connecting a bank automatically triggers initial sync and categorization; importing transactions dispatches AI categorization; categorizing transactions triggers subscription detection
  2. Scheduled tasks run: bank sync every 4 hours, categorization every 2 hours, subscription detection daily, savings analysis weekly, question expiry daily
  3. User receives database and email notifications for AI questions ready, unused subscriptions, budget thresholds, and weekly savings digest
  4. Dashboard page shows spending summary, category breakdown chart, recent transactions, and AI question alerts
  5. Transaction, Subscription, Savings, Tax, Connect, Settings, and AI Questions pages all function with real API data, matching the reference dashboard design
  6. All shared components work (PlaidLinkButton, SpendingChart, TransactionRow, SubscriptionCard, RecommendationCard, QuestionCard, ExportModal, ConfirmDialog)
**Plans**: 3 plans

Plans:
- [ ] 04-01: Build event-driven architecture (events, listeners, job dispatching) and notification system
- [ ] 04-02: Build frontend pages -- Dashboard, Transactions, Connect, Settings, AI Questions
- [ ] 04-03: Build frontend pages -- Subscriptions, Savings, Tax, shared components

### Phase 5: Testing & Deployment
**Goal**: All critical flows are covered by automated tests, model factories exist for all 16 models, and a CI/CD pipeline runs lint, build, and test on every push
**Depends on**: Phase 4
**Requirements**: TEST-01, TEST-02, TEST-03, TEST-04, TEST-05, TEST-06, TEST-07, TEST-08, TEST-09, TEST-10, TEST-11, TEST-12, TEST-13, DEPLOY-01, DEPLOY-02
**Success Criteria** (what must be TRUE):
  1. `php artisan test` runs all tests and passes (feature tests for auth, Plaid, transactions, AI questions, subscriptions, savings, tax, account deletion)
  2. Unit tests verify TransactionCategorizerService confidence routing, SubscriptionDetectorService recurrence detection, TaxExportService Schedule C mapping, and CaptchaService thresholds
  3. Model factories exist for all 16 models and can generate valid test data
  4. GitHub Actions CI pipeline installs dependencies, builds frontend assets, runs full test suite, and reports pass/fail on every push
  5. Production .env template documents all required environment variables
**Plans**: 2 plans

Plans:
- [ ] 05-01: Create model factories and write all feature and unit tests
- [ ] 05-02: Set up GitHub Actions CI pipeline and production environment template

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4 -> 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Scaffolding & API Architecture | 2/2 | ✓ Complete | 2026-02-11 |
| 2. Auth & Bank Integration | 3/3 | ✓ Complete | 2026-02-11 |
| 3. AI Intelligence & Financial Features | 0/3 | Not started | - |
| 4. Events, Notifications & Frontend | 0/3 | Not started | - |
| 5. Testing & Deployment | 0/2 | Not started | - |
