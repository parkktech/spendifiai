# SpendWise — Laravel 12 Production Build Checklist

## Current Status: ~35% Complete

We have the **brain** (services, AI logic, data schema) but are missing the
**skeleton** (Laravel project structure, models, auth, middleware, validation,
tests, frontend build) that makes it a shippable app.

---

## ✅ WHAT WE HAVE (Built)

### Database Layer
- [x] Full migration: 14+ tables (transactions, subscriptions, bank accounts, orders, etc.)
- [x] Account purpose migration (personal/business/mixed)
- [x] Savings targets & plan actions migration
- [x] Expense category seeder with IRS Schedule C mappings (50+ categories)

### Service Layer (Business Logic)
- [x] `PlaidService` — Link token, exchange, transaction sync, balances
- [x] `TransactionCategorizerService` — AI batch categorization with confidence thresholds + question generation
- [x] `SavingsAnalyzerService` — AI spending analysis + recommendation generation
- [x] `SavingsTargetPlannerService` — AI savings goal planning with action items
- [x] `SubscriptionDetectorService` — Recurring charge detection, unused service flagging
- [x] `TaxExportService` — Full tax package generation (Excel + PDF + CSV + email)

### Jobs
- [x] `CategorizePendingTransactions` — Background AI categorization queue job

### Mail
- [x] `TaxPackageMail` — Mailable with 3 attachments
- [x] `tax-package.blade.php` — HTML email template

### Scripts
- [x] `generate_tax_excel.py` — Multi-tab Excel workbook (openpyxl)
- [x] `generate_tax_pdf.py` — PDF summary cover sheet (reportlab)

### Controller
- [x] `SpendWiseController` — All endpoints (but needs to be split up)

### Frontend
- [x] `dashboard.jsx` — React prototype with 5 pages (mock data only)

---

## ❌ WHAT'S MISSING (Must Build)

### 1. Laravel 12 Project Foundation
```
Priority: CRITICAL — Nothing runs without this
```
- [ ] `composer create-project laravel/laravel spendwise` (Laravel 12)
- [ ] `composer.json` with all dependencies
- [ ] `.env.example` with all required vars
- [ ] `config/services.php` — Plaid, Anthropic, Google OAuth entries
- [ ] `config/spendwise.php` — App-specific config (confidence thresholds, sync intervals, etc.)
- [ ] PHP 8.2+ requirement enforced

### 2. Eloquent Models (We have ZERO models)
```
Priority: CRITICAL — Every query depends on these
```
- [ ] `User` — Extended with relationships to all SpendWise tables
- [ ] `BankConnection` — Belongs to User, has many BankAccounts
- [ ] `BankAccount` — Belongs to BankConnection, has many Transactions
- [ ] `Transaction` — Scopes: deductible(), business(), personal(), needsReview(), byCategory()
- [ ] `Subscription` — Scopes: active(), unused(), essential()
- [ ] `AIQuestion` — Belongs to Transaction, scopes: pending(), answered()
- [ ] `EmailConnection` — OAuth token management
- [ ] `ParsedEmail` — Email parsing status tracking
- [ ] `Order` — Belongs to ParsedEmail, has many OrderItems
- [ ] `OrderItem` — Individual products from email receipts
- [ ] `ExpenseCategory` — System defaults + user customs
- [ ] `SavingsRecommendation` — AI-generated savings tips
- [ ] `SavingsTarget` — User savings goals
- [ ] `SavingsPlanAction` — Individual action items within a plan
- [ ] `BudgetGoal` — Per-category monthly limits
- [ ] `UserFinancialProfile` — Tax/employment context for AI

Each model needs:
- `$fillable` / `$guarded`
- `$casts` (dates, decimals, booleans, JSON columns, enums)
- Relationships (`belongsTo`, `hasMany`, `hasOne`)
- Scopes (query scopes for common filters)
- Accessors/mutators where needed
- Laravel 12: Consider `$casts` as methods (new syntax)
- Laravel 12: Use `HasUuids` or `HasUlids` if switching from auto-increment

### 3. Authentication & Authorization
```
Priority: CRITICAL — App is useless without login
```
- [ ] Laravel Sanctum setup (API token auth for SPA)
- [ ] OR: Laravel starter kit (React + Inertia 2 + TypeScript + shadcn)
- [ ] Registration with email verification
- [ ] Login / logout
- [ ] Password reset flow
- [ ] Social login (Google OAuth — we already need Google for Gmail)
- [ ] `AuthController` or starter kit equivalent
- [ ] **Policies** for authorization:
  - [ ] `TransactionPolicy` — User can only see/edit their own
  - [ ] `BankAccountPolicy` — User can only manage their own accounts
  - [ ] `AIQuestionPolicy` — User can only answer their own questions
  - [ ] `SavingsRecommendationPolicy` — User can only see their own
  - [ ] `SubscriptionPolicy`
  - [ ] `OrderPolicy` / `OrderItemPolicy`

### 4. Form Requests (Validation)
```
Priority: HIGH — Controller has inline validation, needs extraction
```
- [ ] `StoreBankAccountPurposeRequest`
- [ ] `AnswerAIQuestionRequest`
- [ ] `BulkAnswerQuestionsRequest`
- [ ] `UpdateTransactionCategoryRequest`
- [ ] `ExportTaxPackageRequest`
- [ ] `SendToAccountantRequest`
- [ ] `SetSavingsTargetRequest`
- [ ] `UpdateFinancialProfileRequest`
- [ ] `PlaidExchangeTokenRequest`
- [ ] `TransactionFilterRequest` (query params validation)

### 5. API Resources (Response Formatting)
```
Priority: HIGH — API responses need consistent structure
```
- [ ] `TransactionResource` / `TransactionCollection`
- [ ] `SubscriptionResource` / `SubscriptionCollection`
- [ ] `AIQuestionResource`
- [ ] `SavingsRecommendationResource`
- [ ] `BankAccountResource`
- [ ] `DashboardResource` (composite)
- [ ] `TaxSummaryResource`
- [ ] `SavingsTargetResource`
- [ ] Laravel 12: Use `#[UseResource]` attribute on models

### 6. Controller Refactoring
```
Priority: HIGH — 939-line god controller must be split
```
SpendWiseController needs to become:
- [ ] `DashboardController` — Overview stats, spending trends
- [ ] `PlaidController` — Bank linking, sync, webhooks
- [ ] `TransactionController` — CRUD, filtering, categorization
- [ ] `SubscriptionController` — Detection, management
- [ ] `SavingsController` — Recommendations, targets, plans, pulse check
- [ ] `TaxController` — Summary, export, send to accountant, download
- [ ] `AIQuestionController` — Get pending, answer, bulk answer
- [ ] `BankAccountController` — List, update purpose, balances
- [ ] `EmailConnectionController` — OAuth flow, sync
- [ ] `UserProfileController` — Financial profile, preferences

### 7. Routes File (Proper)
```
Priority: HIGH — Currently inline comments, not real routes
```
- [ ] `routes/api.php` — All API routes with proper grouping
- [ ] `routes/web.php` — Plaid OAuth callback, email OAuth callback
- [ ] Route model binding for `{transaction}`, `{question}`, etc.
- [ ] Rate limiting middleware on sync/export endpoints
- [ ] API versioning (`/api/v1/...`)

### 8. Middleware
```
Priority: MEDIUM
```
- [ ] `EnsureBankConnected` — Gate certain routes behind having a linked bank
- [ ] `EnsureProfileComplete` — Prompt to fill financial profile before tax features
- [ ] Plaid webhook signature verification middleware
- [ ] Rate limiting: AI categorization (prevent spam), exports, syncs
- [ ] CORS configuration for SPA frontend

### 9. Events & Listeners (Decouple Side Effects)
```
Priority: MEDIUM — Currently everything is synchronous in controllers
```
- [ ] `BankConnected` → Trigger initial sync + categorization
- [ ] `TransactionsImported` → Trigger AI categorization job
- [ ] `TransactionCategorized` → Update subscription detection
- [ ] `UserAnsweredQuestion` → Update transaction, retrain patterns
- [ ] `SavingsAnalysisComplete` → Notify user of new recommendations
- [ ] `TaxPackageGenerated` → Log export, notify user
- [ ] `SubscriptionDetectedUnused` → Notify user
- [ ] `BudgetThresholdReached` → Notify user (80%, 100%)

### 10. Notifications
```
Priority: MEDIUM
```
- [ ] `NewAIQuestionsNotification` — "3 transactions need your input"
- [ ] `UnusedSubscriptionNotification` — "You're paying $33/mo for unused services"
- [ ] `BudgetAlertNotification` — "You've hit 80% of your dining budget"
- [ ] `SavingsInsightNotification` — Weekly savings digest
- [ ] `TaxExportReadyNotification` — "Your tax package is ready to download"
- [ ] Database + email channels (optionally push)

### 11. Scheduled Tasks (Console Kernel / Laravel 12 style)
```
Priority: HIGH — The app relies on automated syncs
```
- [ ] `routes/console.php` or `app/Console/Kernel.php`:
  - Every 4 hours: Sync all active bank connections
  - Every 6 hours: Sync email accounts for new order confirmations
  - Daily: Detect subscriptions, expire old AI questions
  - Weekly: Run savings analysis for all users
  - Monthly: Generate spending reports

### 12. Additional Jobs
```
Priority: MEDIUM
```
- [ ] `ProcessOrderEmails` — Email sync + Claude parsing (partially built)
- [ ] `SyncBankTransactions` — Per-connection sync job
- [ ] `GenerateSavingsAnalysis` — Per-user savings analysis
- [ ] `DetectSubscriptions` — Per-user subscription scan
- [ ] `ReconcileTransactionsWithOrders` — Match bank charges to email orders
- [ ] `GenerateMonthlyReport` — Monthly spending summary
- [ ] Job batching for initial onboarding (sync + categorize + detect subs)

### 13. Frontend (Production)
```
Priority: HIGH — Current JSX is a prototype with mock data
```

**Option A: Laravel 12 React Starter Kit (Recommended)**
- [ ] `npx laravel new spendwise --react` (Inertia 2 + React 19 + TypeScript + shadcn)
- [ ] Pages (Inertia):
  - [ ] `Dashboard.tsx`
  - [ ] `Transactions/Index.tsx` + filters
  - [ ] `Subscriptions/Index.tsx`
  - [ ] `Savings/Index.tsx` + target planner
  - [ ] `Tax/Index.tsx` + export modal
  - [ ] `Connect/Index.tsx` — Plaid Link + email OAuth
  - [ ] `Settings/Profile.tsx` — Financial profile
  - [ ] `AIQuestions/Index.tsx` — Review queue
- [ ] Components:
  - [ ] `SpendingChart`, `CategoryBreakdown`, `TransactionList`
  - [ ] `AIQuestionCard`, `SavingsRecommendationCard`
  - [ ] `SubscriptionCard`, `AccountCard`
  - [ ] `PlaidLinkButton` (uses `react-plaid-link`)
  - [ ] `TaxExportModal`, `SendToAccountantModal`
  - [ ] `ViewModeToggle` (Personal / Business / All)
- [ ] Plaid Link integration (`react-plaid-link` npm package)
- [ ] Real-time updates (Laravel Echo + Reverb or Pusher)

**Option B: Separate SPA (Standalone React/Vue)**
- [ ] Vite + React + Tailwind + shadcn
- [ ] Sanctum SPA authentication
- [ ] Axios/fetch API client with token management

### 14. Testing
```
Priority: HIGH for production
```
- [ ] **Feature Tests:**
  - [ ] Auth flow (register, login, token)
  - [ ] Plaid connection flow (mocked)
  - [ ] Transaction CRUD + filtering
  - [ ] AI question answer flow
  - [ ] Tax export generation
  - [ ] Account purpose switching + re-categorization
  - [ ] Savings target + plan actions
- [ ] **Unit Tests:**
  - [ ] `TransactionCategorizerService` — confidence thresholds, question generation
  - [ ] `SubscriptionDetectorService` — recurrence detection
  - [ ] `TaxExportService` — data gathering, Schedule C mapping
  - [ ] `ReconciliationService` — match scoring
  - [ ] Merchant normalization
- [ ] **Factories:**
  - [ ] `TransactionFactory`, `SubscriptionFactory`, `BankAccountFactory`
  - [ ] `AIQuestionFactory`, `SavingsRecommendationFactory`
- [ ] Pest PHP (Laravel 12 default) over PHPUnit

### 15. Plaid Webhook Handler
```
Priority: HIGH — Production Plaid requires webhooks
```
- [ ] `HandlePlaidWebhook` controller/job
- [ ] Verify Plaid webhook signatures
- [ ] Handle events:
  - `SYNC_UPDATES_AVAILABLE` → trigger transaction sync
  - `ITEM_LOGIN_REQUIRED` → mark connection as `error`, notify user
  - `PENDING_EXPIRATION` → warn user to re-authenticate
  - `DEFAULT_UPDATE` → process normally
  - `TRANSACTIONS_REMOVED` → delete removed transactions
- [ ] Webhook retry/idempotency handling

### 16. Email Parsing Pipeline (Partially Built)
```
Priority: MEDIUM — Core differentiator but works without it
```
- [ ] `EmailReaderService` using `webklex/laravel-imap`
- [ ] Gmail OAuth flow (connect + callback)
- [ ] Outlook OAuth flow
- [ ] `EmailParserService` — Claude-powered receipt extraction
- [ ] `ProcessOrderEmails` job (partially exists)
- [ ] `ReconciliationService` — match bank tx to email orders (designed but not coded)

### 17. Database Indexes & Performance
```
Priority: MEDIUM
```
- [ ] Audit all queries in controllers for N+1 (Laravel 12 auto eager loading helps)
- [ ] Add composite indexes for common filter combos
- [ ] Database query caching for dashboard stats
- [ ] Redis caching for category lists, user profiles
- [ ] Pagination on all list endpoints (partially done)

### 18. Security
```
Priority: HIGH
```
- [ ] Plaid access tokens encrypted at rest (using `encrypt()` — done in PlaidService)
- [ ] Email OAuth tokens encrypted at rest
- [ ] Rate limiting on all API endpoints
- [ ] CSRF protection on web routes
- [ ] Input sanitization on all user inputs
- [ ] Content Security Policy headers
- [ ] Webhook signature verification (Plaid)
- [ ] API authentication on every endpoint (Sanctum)

### 19. DevOps / Deployment
```
Priority: LOW (for now)
```
- [ ] `Dockerfile` / `docker-compose.yml`
- [ ] Queue worker configuration (Redis + Horizon recommended)
- [ ] Cron job for scheduled tasks
- [ ] `.env.production` template
- [ ] Database backup strategy
- [ ] Error monitoring (Sentry or Laravel Telescope)
- [ ] Logging configuration (daily rotation)
- [ ] SSL/HTTPS enforcement

### 20. Documentation
```
Priority: LOW
```
- [ ] API documentation (OpenAPI/Swagger or Scribe)
- [ ] README with setup instructions
- [ ] `.env.example` with all variables documented
- [ ] Architecture decision records

---

## RECOMMENDED BUILD ORDER

### Phase 1: Foundation (Days 1-2)
1. Create Laravel 12 project with React starter kit
2. Build all Eloquent models with relationships + casts
3. Run migrations + seeder
4. Set up Sanctum auth (starter kit handles this)
5. Create policies for all models

### Phase 2: Core Backend (Days 3-5)
6. Split controller into 10 focused controllers
7. Extract Form Requests for validation
8. Create API Resources for all models
9. Write proper `routes/api.php`
10. Wire up existing services to new controllers
11. Set up job dispatching + scheduled tasks

### Phase 3: Plaid Integration (Days 6-7)
12. Plaid Link frontend integration
13. Webhook handler
14. Transaction sync flow (end-to-end test with sandbox)
15. Account purpose UI + re-categorization flow

### Phase 4: AI Pipeline (Days 8-9)
16. Transaction categorization job (end-to-end)
17. AI question display + answer flow
18. Subscription detection
19. Savings analysis

### Phase 5: Frontend (Days 10-14)
20. Dashboard with real data (Inertia)
21. Transaction list with filters
22. Subscription management page
23. Savings recommendations + target planner
24. Tax writeoffs page + export modal
25. Account management + connect page

### Phase 6: Polish (Days 15-17)
26. Email parsing pipeline
27. Notifications
28. Testing (feature + unit)
29. Error handling + edge cases
30. Performance optimization

### Phase 7: Ship (Days 18-20)
31. Plaid production access application
32. Deployment setup
33. Monitoring + logging
34. Documentation
35. Beta testing

---

## LARAVEL 12 SPECIFIC BEST PRACTICES TO FOLLOW

1. **Starter Kit**: Use `laravel new spendwise --react` for Inertia 2 + React 19 + TypeScript + shadcn
2. **Automatic Eager Loading** (12.8+): Reduces N+1 queries automatically
3. **`#[UseResource]` Attribute**: Bind API Resources directly to models
4. **Enum Casts**: Use PHP 8.2 enums for `expense_type`, `review_status`, `account_purpose`
5. **`$casts` as Method**: New syntax `protected function casts(): array`
6. **Route Attributes**: Consider PHP attribute-based routing for cleaner controllers
7. **Pest PHP**: Default test framework in Laravel 12
8. **Typed Properties**: Use PHP 8.2 typed properties throughout
9. **`readonly` Classes**: For DTOs and value objects
10. **Session Cache**: New in Laravel 12 — use for per-user dashboard caching
11. **Job Batching**: Use `Bus::batch()` for onboarding flow (sync + categorize + detect)
12. **Health Checks**: Built-in health endpoint for monitoring
