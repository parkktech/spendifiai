# Requirements: SpendifiAI

**Defined:** 2026-02-10
**Core Value:** Users connect their bank and immediately get intelligent, automatic categorization of every transaction with business/personal separation, tax deduction flagging, and AI-generated questions when confidence is low.

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases.

### Project Foundation

- [ ] **FNDN-01**: Laravel 12 project created with React starter kit (Inertia 2 + React 19 + TypeScript + shadcn/ui)
- [ ] **FNDN-02**: All existing code from existing-code/ integrated into Laravel project structure (models, services, controllers, migrations, configs, routes)
- [ ] **FNDN-03**: Database migrations run successfully creating all 14+ tables
- [ ] **FNDN-04**: ExpenseCategorySeeder populates 50+ IRS-mapped categories
- [ ] **FNDN-05**: All composer and npm dependencies installed and working

### Authentication

- [ ] **AUTH-01**: User can register with name, email, and password
- [ ] **AUTH-02**: User receives email verification after signup
- [ ] **AUTH-03**: User can log in with email and password and receive a bearer token
- [ ] **AUTH-04**: User session persists across browser refresh via Sanctum token
- [ ] **AUTH-05**: User can log out and token is revoked
- [ ] **AUTH-06**: User can reset password via email link
- [ ] **AUTH-07**: User can change password while logged in
- [ ] **AUTH-08**: User can log in via Google OAuth and receive token via URL fragment
- [ ] **AUTH-09**: User can disconnect Google account
- [ ] **AUTH-10**: User can enable TOTP 2FA with QR code
- [ ] **AUTH-11**: User can verify 2FA code during login
- [ ] **AUTH-12**: User can disable 2FA
- [ ] **AUTH-13**: User can regenerate 2FA recovery codes
- [ ] **AUTH-14**: Account locks after repeated failed login attempts
- [ ] **AUTH-15**: reCAPTCHA v3 protects registration and login endpoints

### Bank Integration (Plaid)

- [ ] **PLAID-01**: User can create a Plaid Link token to start bank connection
- [ ] **PLAID-02**: User can exchange Plaid public token to establish persistent bank connection
- [ ] **PLAID-03**: System syncs transactions from connected bank (up to 12 months or beginning of prior year)
- [ ] **PLAID-04**: User can view list of connected bank accounts with balances
- [ ] **PLAID-05**: User can disconnect a bank connection
- [ ] **PLAID-06**: User can tag bank account purpose (personal/business/mixed/investment)
- [ ] **PLAID-07**: Account purpose cascades to all transactions from that account
- [ ] **PLAID-08**: System fetches account balances from Plaid

### Plaid Webhooks

- [ ] **HOOK-01**: System receives and verifies Plaid webhook signatures
- [ ] **HOOK-02**: SYNC_UPDATES_AVAILABLE webhook triggers automatic transaction sync
- [ ] **HOOK-03**: ITEM_LOGIN_REQUIRED webhook marks connection as error and notifies user
- [ ] **HOOK-04**: PENDING_EXPIRATION webhook notifies user to re-authenticate
- [ ] **HOOK-05**: TRANSACTIONS_REMOVED webhook deletes removed transactions
- [ ] **HOOK-06**: USER_PERMISSION_REVOKED webhook disconnects the bank
- [ ] **HOOK-07**: All webhooks are logged and handled idempotently

### AI Categorization

- [ ] **AICAT-01**: System batches uncategorized transactions and sends to Claude API for categorization
- [ ] **AICAT-02**: Transactions with confidence >= 0.85 are auto-categorized silently
- [ ] **AICAT-03**: Transactions with confidence 0.60-0.84 are categorized but flagged for review
- [ ] **AICAT-04**: Transactions with confidence 0.40-0.59 generate multiple-choice questions for user
- [ ] **AICAT-05**: Transactions with confidence < 0.40 generate open-ended questions for user
- [ ] **AICAT-06**: Account purpose (business/personal) is included in AI prompt context
- [ ] **AICAT-07**: User can manually override transaction category

### AI Questions

- [ ] **AIQST-01**: User can view list of pending AI questions with transaction context
- [ ] **AIQST-02**: User can answer a single AI question (multiple-choice or free-text)
- [ ] **AIQST-03**: User can bulk-answer multiple AI questions at once
- [ ] **AIQST-04**: Answering a question updates the transaction's category
- [ ] **AIQST-05**: Unanswered questions expire after 7 days

### Subscriptions

- [ ] **SUBS-01**: System detects recurring charges from transaction patterns
- [ ] **SUBS-02**: User can view list of detected subscriptions with status (active/unused/cancelled)
- [ ] **SUBS-03**: Each subscription shows charge amount, frequency, and last charge date
- [ ] **SUBS-04**: System flags unused subscriptions
- [ ] **SUBS-05**: User can view total monthly and annual subscription costs

### Savings

- [ ] **SAVE-01**: System analyzes 90-day spending patterns via Claude API and generates savings recommendations
- [ ] **SAVE-02**: User can view savings recommendations with action steps
- [ ] **SAVE-03**: User can dismiss a savings recommendation
- [ ] **SAVE-04**: User can apply a savings recommendation
- [ ] **SAVE-05**: User can set a savings target with goal name, amount, deadline
- [ ] **SAVE-06**: System generates AI-powered personalized action plan for savings target
- [ ] **SAVE-07**: User can view savings target progress
- [ ] **SAVE-08**: User can regenerate a savings plan
- [ ] **SAVE-09**: User can respond to individual plan actions (completed/skipped)
- [ ] **SAVE-10**: User can check savings pulse (progress summary)

### Tax

- [ ] **TAX-01**: User can view tax summary with deductions grouped by IRS Schedule C line
- [ ] **TAX-02**: System separates business and personal spending for tax purposes
- [ ] **TAX-03**: User can export tax package as Excel workbook (5 tabs)
- [ ] **TAX-04**: User can export tax package as PDF cover sheet
- [ ] **TAX-05**: User can export tax package as CSV
- [ ] **TAX-06**: User can email tax package directly to their accountant
- [ ] **TAX-07**: User can download previously generated tax exports

### Email Parsing

- [ ] **EMAIL-01**: User can connect Gmail account via OAuth
- [ ] **EMAIL-02**: System syncs and parses email receipts using Claude AI
- [ ] **EMAIL-03**: Parsed receipts create Order + OrderItem records with product details
- [ ] **EMAIL-04**: System reconciles bank transactions with email orders (match by amount, date, merchant)
- [ ] **EMAIL-05**: OrderItems include AI-determined category and tax deductibility

### Controller Architecture

- [ ] **CTRL-01**: SpendWiseController split into 10 focused API controllers (Dashboard, Plaid, BankAccount, Transaction, AIQuestion, Subscription, Savings, Tax, EmailConnection, UserProfile)
- [ ] **CTRL-02**: API Resources created for all public-facing models (Transaction, BankAccount, BankConnection, Subscription, AIQuestion, SavingsRecommendation, SavingsTarget, Dashboard)
- [ ] **CTRL-03**: Form Request validation classes created for all write endpoints
- [ ] **CTRL-04**: All controllers use policy authorization
- [ ] **CTRL-05**: All controllers inject services via constructor DI

### User Profile

- [ ] **PROF-01**: User can view and update financial profile (employment type, business type, tax filing status, income)
- [ ] **PROF-02**: User can delete their entire account with cascading data removal

### Events & Background Processing

- [ ] **EVNT-01**: BankConnected event triggers initial transaction sync
- [ ] **EVNT-02**: TransactionsImported event dispatches AI categorization job
- [ ] **EVNT-03**: TransactionCategorized event triggers subscription detection check
- [ ] **EVNT-04**: UserAnsweredQuestion event updates transaction category
- [ ] **EVNT-05**: Background job: SyncBankTransactions (per-connection, dispatched by webhook/scheduler)
- [ ] **EVNT-06**: Background job: CategorizePendingTransactions (AI batch processing)
- [ ] **EVNT-07**: Background job: DetectSubscriptions (per-user scan)
- [ ] **EVNT-08**: Background job: GenerateSavingsAnalysis (per-user analysis)
- [ ] **EVNT-09**: Background job: ProcessOrderEmails (email sync + Claude parsing)
- [ ] **EVNT-10**: Background job: ReconcileTransactionsWithOrders (match bank charges to email orders)
- [ ] **EVNT-11**: Scheduled: Sync all bank connections every 4 hours
- [ ] **EVNT-12**: Scheduled: AI categorize every 2 hours
- [ ] **EVNT-13**: Scheduled: Detect subscriptions daily at 2:00 AM
- [ ] **EVNT-14**: Scheduled: Savings analysis weekly Monday at 6:00 AM
- [ ] **EVNT-15**: Scheduled: Expire unanswered AI questions daily at 3:00 AM

### Notifications

- [ ] **NOTF-01**: User receives notification when AI questions are ready for review
- [ ] **NOTF-02**: User receives notification when unused subscriptions are detected
- [ ] **NOTF-03**: User receives notification when budget threshold is reached (80%, 100%)
- [ ] **NOTF-04**: User receives weekly savings digest summary
- [ ] **NOTF-05**: Notifications delivered via database + email channels

### Frontend

- [ ] **UI-01**: Dashboard page with spending summary cards, category breakdown donut chart, recent transactions, AI question alert banner, connected accounts summary
- [ ] **UI-02**: Transactions page with filter bar (date range, category, business/personal, search), transaction table, inline category edit, pagination
- [ ] **UI-03**: Subscriptions page with card grid, status badges, unused warnings, monthly/annual cost totals
- [ ] **UI-04**: Savings page with target progress gauge, recommendation cards (dismiss/apply), set target form, pulse check summary
- [ ] **UI-05**: Tax page with year selector, deduction summary by Schedule C line, business vs personal chart, export modal, send-to-accountant modal
- [ ] **UI-06**: Connect page with Plaid Link button (react-plaid-link), connected accounts list with status, email connection flow, disconnect buttons
- [ ] **UI-07**: Settings page with financial profile form, security settings (password change, 2FA toggle), delete account with confirmation
- [ ] **UI-08**: AI Questions page with pending question list, transaction context per question, multiple-choice/free-text input, bulk answer mode
- [ ] **UI-09**: Shared components: PlaidLinkButton, SpendingChart, TransactionRow, SubscriptionCard, RecommendationCard, QuestionCard, ViewModeToggle, ExportModal, ConfirmDialog
- [ ] **UI-10**: Frontend design matches reference-dashboard.jsx prototype closely

### Testing

- [ ] **TEST-01**: Model factories created for all 16 models
- [ ] **TEST-02**: Feature test: Auth flow (register, verify, login, 2FA, logout)
- [ ] **TEST-03**: Feature test: Plaid flow (link token, exchange, sync, disconnect)
- [ ] **TEST-04**: Feature test: Transaction list with filters and category update
- [ ] **TEST-05**: Feature test: AI question answer and bulk answer
- [ ] **TEST-06**: Feature test: Subscription list and detection
- [ ] **TEST-07**: Feature test: Savings recommendations, set target, respond to action
- [ ] **TEST-08**: Feature test: Tax summary, export, send to accountant
- [ ] **TEST-09**: Feature test: Account deletion cascades properly
- [ ] **TEST-10**: Unit test: TransactionCategorizerService confidence routing
- [ ] **TEST-11**: Unit test: SubscriptionDetectorService recurrence detection
- [ ] **TEST-12**: Unit test: TaxExportService Schedule C mapping
- [ ] **TEST-13**: Unit test: CaptchaService score thresholds

### Deployment

- [ ] **DEPLOY-01**: GitHub Actions CI pipeline (install, build, test, audit)
- [ ] **DEPLOY-02**: Production .env template with all required variables documented

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Monetization
- **BILL-01**: Subscription tier system (free/paid)
- **BILL-02**: Feature gating based on tier
- **BILL-03**: Stripe integration for payments

### Admin
- **ADMIN-01**: Admin panel for user management
- **ADMIN-02**: System health dashboard
- **ADMIN-03**: Usage analytics

### Real-time
- **RT-01**: WebSocket push for real-time transaction updates
- **RT-02**: Live notification delivery without page refresh

### Mobile
- **MOB-01**: Progressive Web App (PWA) support
- **MOB-02**: Mobile-optimized responsive layouts

### Multi-currency
- **CURR-01**: Support for non-USD currencies
- **CURR-02**: Currency conversion for reporting

## Out of Scope

| Feature | Reason |
|---------|--------|
| Native mobile app (iOS/Android) | Web-first strategy; mobile comes after v1 validation |
| Real-time chat/messaging | Not relevant to expense tracking domain |
| Video content | No use case in financial tracking |
| Multi-currency support | USD only for v1; international users deferred |
| Admin panel | Build when multi-user management becomes necessary |
| Billing/payments | Free for now; monetization strategy comes after value validation |
| WebSocket push notifications | Polling and page refresh sufficient for v1 financial data |
| Outlook email parsing | Gmail-first; Outlook support in v2 |
| Investment tracking | Account purpose supports "investment" tag but no dedicated investment features |
| Budget creation UI | BudgetGoal model exists but creating/managing budgets deferred -- focus on detection and alerts |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| FNDN-01 | Phase 1 | Pending |
| FNDN-02 | Phase 1 | Pending |
| FNDN-03 | Phase 1 | Pending |
| FNDN-04 | Phase 1 | Pending |
| FNDN-05 | Phase 1 | Pending |
| AUTH-01 | Phase 2 | Pending |
| AUTH-02 | Phase 2 | Pending |
| AUTH-03 | Phase 2 | Pending |
| AUTH-04 | Phase 2 | Pending |
| AUTH-05 | Phase 2 | Pending |
| AUTH-06 | Phase 2 | Pending |
| AUTH-07 | Phase 2 | Pending |
| AUTH-08 | Phase 2 | Pending |
| AUTH-09 | Phase 2 | Pending |
| AUTH-10 | Phase 2 | Pending |
| AUTH-11 | Phase 2 | Pending |
| AUTH-12 | Phase 2 | Pending |
| AUTH-13 | Phase 2 | Pending |
| AUTH-14 | Phase 2 | Pending |
| AUTH-15 | Phase 2 | Pending |
| PLAID-01 | Phase 2 | Pending |
| PLAID-02 | Phase 2 | Pending |
| PLAID-03 | Phase 2 | Pending |
| PLAID-04 | Phase 2 | Pending |
| PLAID-05 | Phase 2 | Pending |
| PLAID-06 | Phase 2 | Pending |
| PLAID-07 | Phase 2 | Pending |
| PLAID-08 | Phase 2 | Pending |
| HOOK-01 | Phase 2 | Pending |
| HOOK-02 | Phase 2 | Pending |
| HOOK-03 | Phase 2 | Pending |
| HOOK-04 | Phase 2 | Pending |
| HOOK-05 | Phase 2 | Pending |
| HOOK-06 | Phase 2 | Pending |
| HOOK-07 | Phase 2 | Pending |
| AICAT-01 | Phase 3 | Pending |
| AICAT-02 | Phase 3 | Pending |
| AICAT-03 | Phase 3 | Pending |
| AICAT-04 | Phase 3 | Pending |
| AICAT-05 | Phase 3 | Pending |
| AICAT-06 | Phase 3 | Pending |
| AICAT-07 | Phase 3 | Pending |
| AIQST-01 | Phase 3 | Pending |
| AIQST-02 | Phase 3 | Pending |
| AIQST-03 | Phase 3 | Pending |
| AIQST-04 | Phase 3 | Pending |
| AIQST-05 | Phase 3 | Pending |
| SUBS-01 | Phase 3 | Pending |
| SUBS-02 | Phase 3 | Pending |
| SUBS-03 | Phase 3 | Pending |
| SUBS-04 | Phase 3 | Pending |
| SUBS-05 | Phase 3 | Pending |
| SAVE-01 | Phase 3 | Pending |
| SAVE-02 | Phase 3 | Pending |
| SAVE-03 | Phase 3 | Pending |
| SAVE-04 | Phase 3 | Pending |
| SAVE-05 | Phase 3 | Pending |
| SAVE-06 | Phase 3 | Pending |
| SAVE-07 | Phase 3 | Pending |
| SAVE-08 | Phase 3 | Pending |
| SAVE-09 | Phase 3 | Pending |
| SAVE-10 | Phase 3 | Pending |
| TAX-01 | Phase 3 | Pending |
| TAX-02 | Phase 3 | Pending |
| TAX-03 | Phase 3 | Pending |
| TAX-04 | Phase 3 | Pending |
| TAX-05 | Phase 3 | Pending |
| TAX-06 | Phase 3 | Pending |
| TAX-07 | Phase 3 | Pending |
| EMAIL-01 | Phase 3 | Pending |
| EMAIL-02 | Phase 3 | Pending |
| EMAIL-03 | Phase 3 | Pending |
| EMAIL-04 | Phase 3 | Pending |
| EMAIL-05 | Phase 3 | Pending |
| CTRL-01 | Phase 1 | Pending |
| CTRL-02 | Phase 1 | Pending |
| CTRL-03 | Phase 1 | Pending |
| CTRL-04 | Phase 1 | Pending |
| CTRL-05 | Phase 1 | Pending |
| PROF-01 | Phase 2 | Pending |
| PROF-02 | Phase 2 | Pending |
| EVNT-01 | Phase 4 | Pending |
| EVNT-02 | Phase 4 | Pending |
| EVNT-03 | Phase 4 | Pending |
| EVNT-04 | Phase 4 | Pending |
| EVNT-05 | Phase 4 | Pending |
| EVNT-06 | Phase 4 | Pending |
| EVNT-07 | Phase 4 | Pending |
| EVNT-08 | Phase 4 | Pending |
| EVNT-09 | Phase 4 | Pending |
| EVNT-10 | Phase 4 | Pending |
| EVNT-11 | Phase 4 | Pending |
| EVNT-12 | Phase 4 | Pending |
| EVNT-13 | Phase 4 | Pending |
| EVNT-14 | Phase 4 | Pending |
| EVNT-15 | Phase 4 | Pending |
| NOTF-01 | Phase 4 | Pending |
| NOTF-02 | Phase 4 | Pending |
| NOTF-03 | Phase 4 | Pending |
| NOTF-04 | Phase 4 | Pending |
| NOTF-05 | Phase 4 | Pending |
| UI-01 | Phase 4 | Pending |
| UI-02 | Phase 4 | Pending |
| UI-03 | Phase 4 | Pending |
| UI-04 | Phase 4 | Pending |
| UI-05 | Phase 4 | Pending |
| UI-06 | Phase 4 | Pending |
| UI-07 | Phase 4 | Pending |
| UI-08 | Phase 4 | Pending |
| UI-09 | Phase 4 | Pending |
| UI-10 | Phase 4 | Pending |
| TEST-01 | Phase 5 | Pending |
| TEST-02 | Phase 5 | Pending |
| TEST-03 | Phase 5 | Pending |
| TEST-04 | Phase 5 | Pending |
| TEST-05 | Phase 5 | Pending |
| TEST-06 | Phase 5 | Pending |
| TEST-07 | Phase 5 | Pending |
| TEST-08 | Phase 5 | Pending |
| TEST-09 | Phase 5 | Pending |
| TEST-10 | Phase 5 | Pending |
| TEST-11 | Phase 5 | Pending |
| TEST-12 | Phase 5 | Pending |
| TEST-13 | Phase 5 | Pending |
| DEPLOY-01 | Phase 5 | Pending |
| DEPLOY-02 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 126 total
- Mapped to phases: 126
- Unmapped: 0

---
*Requirements defined: 2026-02-10*
*Last updated: 2026-02-10 after roadmap creation*
