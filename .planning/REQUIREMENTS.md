# Requirements: LedgerIQ

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
| Budget creation UI | BudgetGoal model exists but creating/managing budgets deferred — focus on detection and alerts |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| FNDN-01 | TBD | Pending |
| FNDN-02 | TBD | Pending |
| FNDN-03 | TBD | Pending |
| FNDN-04 | TBD | Pending |
| FNDN-05 | TBD | Pending |
| AUTH-01 | TBD | Pending |
| AUTH-02 | TBD | Pending |
| AUTH-03 | TBD | Pending |
| AUTH-04 | TBD | Pending |
| AUTH-05 | TBD | Pending |
| AUTH-06 | TBD | Pending |
| AUTH-07 | TBD | Pending |
| AUTH-08 | TBD | Pending |
| AUTH-09 | TBD | Pending |
| AUTH-10 | TBD | Pending |
| AUTH-11 | TBD | Pending |
| AUTH-12 | TBD | Pending |
| AUTH-13 | TBD | Pending |
| AUTH-14 | TBD | Pending |
| AUTH-15 | TBD | Pending |
| PLAID-01 | TBD | Pending |
| PLAID-02 | TBD | Pending |
| PLAID-03 | TBD | Pending |
| PLAID-04 | TBD | Pending |
| PLAID-05 | TBD | Pending |
| PLAID-06 | TBD | Pending |
| PLAID-07 | TBD | Pending |
| PLAID-08 | TBD | Pending |
| HOOK-01 | TBD | Pending |
| HOOK-02 | TBD | Pending |
| HOOK-03 | TBD | Pending |
| HOOK-04 | TBD | Pending |
| HOOK-05 | TBD | Pending |
| HOOK-06 | TBD | Pending |
| HOOK-07 | TBD | Pending |
| AICAT-01 | TBD | Pending |
| AICAT-02 | TBD | Pending |
| AICAT-03 | TBD | Pending |
| AICAT-04 | TBD | Pending |
| AICAT-05 | TBD | Pending |
| AICAT-06 | TBD | Pending |
| AICAT-07 | TBD | Pending |
| AIQST-01 | TBD | Pending |
| AIQST-02 | TBD | Pending |
| AIQST-03 | TBD | Pending |
| AIQST-04 | TBD | Pending |
| AIQST-05 | TBD | Pending |
| SUBS-01 | TBD | Pending |
| SUBS-02 | TBD | Pending |
| SUBS-03 | TBD | Pending |
| SUBS-04 | TBD | Pending |
| SUBS-05 | TBD | Pending |
| SAVE-01 | TBD | Pending |
| SAVE-02 | TBD | Pending |
| SAVE-03 | TBD | Pending |
| SAVE-04 | TBD | Pending |
| SAVE-05 | TBD | Pending |
| SAVE-06 | TBD | Pending |
| SAVE-07 | TBD | Pending |
| SAVE-08 | TBD | Pending |
| SAVE-09 | TBD | Pending |
| SAVE-10 | TBD | Pending |
| TAX-01 | TBD | Pending |
| TAX-02 | TBD | Pending |
| TAX-03 | TBD | Pending |
| TAX-04 | TBD | Pending |
| TAX-05 | TBD | Pending |
| TAX-06 | TBD | Pending |
| TAX-07 | TBD | Pending |
| EMAIL-01 | TBD | Pending |
| EMAIL-02 | TBD | Pending |
| EMAIL-03 | TBD | Pending |
| EMAIL-04 | TBD | Pending |
| EMAIL-05 | TBD | Pending |
| CTRL-01 | TBD | Pending |
| CTRL-02 | TBD | Pending |
| CTRL-03 | TBD | Pending |
| CTRL-04 | TBD | Pending |
| CTRL-05 | TBD | Pending |
| PROF-01 | TBD | Pending |
| PROF-02 | TBD | Pending |
| EVNT-01 | TBD | Pending |
| EVNT-02 | TBD | Pending |
| EVNT-03 | TBD | Pending |
| EVNT-04 | TBD | Pending |
| EVNT-05 | TBD | Pending |
| EVNT-06 | TBD | Pending |
| EVNT-07 | TBD | Pending |
| EVNT-08 | TBD | Pending |
| EVNT-09 | TBD | Pending |
| EVNT-10 | TBD | Pending |
| EVNT-11 | TBD | Pending |
| EVNT-12 | TBD | Pending |
| EVNT-13 | TBD | Pending |
| EVNT-14 | TBD | Pending |
| EVNT-15 | TBD | Pending |
| NOTF-01 | TBD | Pending |
| NOTF-02 | TBD | Pending |
| NOTF-03 | TBD | Pending |
| NOTF-04 | TBD | Pending |
| NOTF-05 | TBD | Pending |
| UI-01 | TBD | Pending |
| UI-02 | TBD | Pending |
| UI-03 | TBD | Pending |
| UI-04 | TBD | Pending |
| UI-05 | TBD | Pending |
| UI-06 | TBD | Pending |
| UI-07 | TBD | Pending |
| UI-08 | TBD | Pending |
| UI-09 | TBD | Pending |
| UI-10 | TBD | Pending |
| TEST-01 | TBD | Pending |
| TEST-02 | TBD | Pending |
| TEST-03 | TBD | Pending |
| TEST-04 | TBD | Pending |
| TEST-05 | TBD | Pending |
| TEST-06 | TBD | Pending |
| TEST-07 | TBD | Pending |
| TEST-08 | TBD | Pending |
| TEST-09 | TBD | Pending |
| TEST-10 | TBD | Pending |
| TEST-11 | TBD | Pending |
| TEST-12 | TBD | Pending |
| TEST-13 | TBD | Pending |
| DEPLOY-01 | TBD | Pending |
| DEPLOY-02 | TBD | Pending |

**Coverage:**
- v1 requirements: 98 total
- Mapped to phases: 0
- Unmapped: 98 (pending roadmap creation)

---
*Requirements defined: 2026-02-10*
*Last updated: 2026-02-10 after initial definition*
