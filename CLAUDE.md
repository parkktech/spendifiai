# CLAUDE.md — SpendWise (Name TBD)

> AI-powered expense tracker with Plaid bank integration, Claude AI categorization,
> email receipt parsing, subscription detection, savings recommendations, and tax export.

## TECH STACK

- **Backend:** Laravel 12, PHP 8.2+, PostgreSQL 15+, Redis 7+
- **Frontend:** React 19 + Inertia 2 + TypeScript + shadcn/ui (Laravel starter kit)
- **AI:** Anthropic Claude API (Sonnet) for transaction categorization + savings analysis
- **Bank Integration:** Plaid API (sandbox credentials included in .env)
- **Auth:** Laravel Sanctum + Fortify, Google OAuth (Socialite), optional TOTP 2FA
- **Testing:** Pest PHP
- **Queue:** Redis-backed Laravel queues

## PROJECT STATUS: ~60% COMPLETE

**What exists:** All backend service logic, all 16 Eloquent models, full auth system (5 controllers),
5 database migrations, 7 PHP enums, 4 middleware, 4 policies, routes, configs, seeders.

**What's missing:** Split API controllers (monolithic SpendWiseController needs decomposition),
frontend (React/Inertia pages), Plaid webhook handler, email parsing pipeline, events/listeners,
notifications, tests, factories, API Resources, deployment config.

## CRITICAL ARCHITECTURE DECISIONS (ALREADY MADE)

### Encryption
Every sensitive field uses Laravel model casts — **never call encrypt()/decrypt() manually**.
See `docs/SECURITY_AND_ENCRYPTION_PROCEDURES.md` for the full inventory. Key encrypted fields:
- `BankConnection.plaid_access_token` → `'encrypted'`
- `BankAccount.ein` → `'encrypted'`
- `EmailConnection.access_token/refresh_token` → `'encrypted'`
- `Transaction.plaid_metadata` → `'encrypted:array'`
- `ParsedEmail.raw_parsed_data` → `'encrypted:array'`
- `UserFinancialProfile.monthly_income` → `'encrypted'`
- `UserFinancialProfile.custom_rules` → `'encrypted:array'`
- `User.two_factor_secret` → `'encrypted'`
- `User.two_factor_recovery_codes` → `'encrypted:array'`
- `User.password` → `'hashed'` (bcrypt)

Encrypted fields MUST be TEXT columns in PostgreSQL (not JSON, VARCHAR, or DECIMAL).

### $hidden on Models
Every model with sensitive data has `$hidden` to prevent API leakage. Check each model's
`$hidden` array before adding new JSON serialization.

### AI Categorization Confidence Thresholds
```
≥ 0.85 → Auto-categorize silently
0.60–0.84 → Categorize but flag for review
0.40–0.59 → Generate multiple-choice question for user
< 0.40 → Generate open-ended question
```
Config in `config/spendwise.php` under `ai.confidence_thresholds`.

### Account Purpose (Business/Personal)
Bank accounts are tagged with purpose (personal/business/mixed/investment).
This is the **strongest signal** for AI categorization. It cascades:
account purpose → denormalized to transactions.account_purpose → AI prompt context.

### OAuth Redirect Security
Google OAuth callback uses URL **fragment** (`#token=`) not query param (`?token=`).
Fragments never reach the server, preventing token leakage in logs/history/Referrer headers.

## EXISTING FILE MAP

### Models (16) — `app/Models/`
All complete with typed casts, relationships, scopes, $hidden, $fillable.

| Model | Key Features |
|-------|-------------|
| User | MustVerifyEmail, HasApiTokens, TwoFactorAuthenticatable, relationships to all tables |
| BankConnection | Encrypted plaid_access_token, ConnectionStatus enum |
| BankAccount | Encrypted EIN, AccountPurpose enum, business/personal scopes |
| Transaction | Encrypted plaid_metadata, 8 query scopes, category accessor |
| Subscription | SubscriptionStatus enum, charge_history JSON, annual_cost calculation |
| AIQuestion | QuestionType + QuestionStatus enums, pending/answered scopes |
| EmailConnection | Encrypted OAuth tokens |
| ParsedEmail | Encrypted raw_parsed_data |
| Order | Linked to ParsedEmail + matched Transaction |
| OrderItem | Individual products with AI category + tax deductibility |
| ExpenseCategory | IRS Schedule C mapping, tax_line, keywords array |
| SavingsRecommendation | AI-generated tips with action_steps array |
| SavingsTarget | User goal with deadline + monthly target |
| SavingsPlanAction | Individual actions within a savings plan |
| BudgetGoal | Per-category monthly limits with alert thresholds |
| UserFinancialProfile | Encrypted income, employment type, tax context |

### Auth Controllers (5) — `app/Http/Controllers/Auth/`
All complete and production-ready.

| Controller | Endpoints |
|-----------|-----------|
| AuthController | register, login, logout, me, verifyTwoFactorCode helper |
| SocialAuthController | redirectToGoogle, handleGoogleCallback, disconnectGoogle |
| TwoFactorController | status, enable, confirm, disable, regenerateRecoveryCodes |
| PasswordResetController | sendResetLink, resetPassword, changePassword |
| EmailVerificationController | verify, resend |

### Services (7) — `app/Services/`

| Service | What It Does |
|---------|-------------|
| PlaidService | Link token creation, token exchange, transaction sync, balance fetch, item removal, disconnect |
| TransactionCategorizerService | Batches uncategorized transactions → Claude API → confidence-based categorization + question generation |
| SavingsAnalyzerService | Analyzes 90-day spending → Claude API → savings recommendations |
| SavingsTargetPlannerService | Takes savings goal → Claude API → personalized action plan |
| SubscriptionDetectorService | Scans transaction patterns → detects recurring charges → flags unused |
| TaxExportService | Generates Excel (5 tabs) + PDF cover sheet + CSV → emails to accountant |
| CaptchaService | Server-side reCAPTCHA v3 verification |

### The Monolithic Controller — `app/Http/Controllers/Api/SpendWiseController.php`
This is a ~939-line god controller with ALL endpoint logic. It MUST be split into
10 focused controllers. The logic is correct — it just needs to be moved into:

| Target Controller | Methods to Extract |
|------------------|-------------------|
| DashboardController | dashboard() |
| PlaidController | createLinkToken(), exchangeToken(), sync(), disconnect() |
| BankAccountController | listAccounts(), updateAccountPurpose() |
| TransactionController | listTransactions(), updateCategory() |
| AIQuestionController | listQuestions(), answerQuestion(), bulkAnswer() |
| SubscriptionController | listSubscriptions(), detectSubscriptions() |
| SavingsController | recommendations(), analyze(), dismiss(), apply(), setTarget(), getTarget(), regeneratePlan(), respondToAction(), pulseCheck() |
| TaxController | taxSummary(), exportTaxPackage(), sendToAccountant(), downloadExport() |
| EmailConnectionController | connectEmail(), emailCallback(), syncEmails() |
| UserProfileController | updateFinancialProfile(), showFinancialProfile(), deleteAccount() |

The routes file (`routes/api.php`) already references these target controllers — they just
need to be created with the logic extracted from SpendWiseController.

### Migrations (5) — `database/migrations/`
Run in order. All complete.

1. `000001_create_spendwise_tables` — 14 core tables
2. `000002_add_account_purpose` — Business/personal tagging on bank_accounts + transactions
3. `000003_create_savings_targets` — Savings goals + plan actions
4. `000004_add_auth_columns` — Google OAuth, 2FA, account lockout on users
5. `000005_encrypt_sensitive_columns` — Column type changes (json→text, decimal→text) for encryption

### Other Existing Files
- `app/Enums/` — 7 PHP 8.2 backed enums
- `app/Http/Middleware/` — VerifyCaptcha, EnsureBankConnected, EnsureProfileComplete, Enforce2FA
- `app/Http/Requests/Auth/` — RegisterRequest, LoginRequest
- `app/Policies/` — Transaction, BankAccount, AIQuestion, Subscription
- `app/Actions/Fortify/` — CreateNewUser, ResetUserPassword, UpdateUserPassword
- `app/Providers/` — AppServiceProvider (policies, middleware aliases, route bindings), FortifyServiceProvider
- `app/Jobs/` — CategorizePendingTransactions
- `app/Mail/` — TaxPackageMail
- `bootstrap/app.php` — Laravel 12 style app configuration
- `config/` — spendwise.php, services.php, fortify.php
- `routes/` — api.php (full route definitions), web.php, console.php (scheduled tasks)
- `database/seeders/` — ExpenseCategorySeeder (50+ IRS-mapped categories)
- `resources/scripts/` — generate_tax_excel.py, generate_tax_pdf.py
- `resources/views/emails/` — tax-package.blade.php
- `composer.json` — All dependencies defined
- `.env` — Plaid sandbox creds configured, ready to use
- `.env.example` — Documented template

### React Dashboard Prototype — `dashboard.jsx`
A single-file React prototype with 5 pages (Dashboard, Transactions, Subscriptions, Savings, Tax).
Uses mock data. Should be used as design reference when building real Inertia pages but NOT
imported directly — rebuild with TypeScript + Inertia + real API calls.

### Email Parsing Module — `expense-parser/`
Partially built email receipt parsing system. Contains:
- `GmailService` — OAuth + IMAP email fetching
- `EmailParserService` — Claude-powered receipt data extraction
- `ReconciliationService` — Match bank transactions to email orders
- `ProcessOrderEmails` — Background job
- Migration for expense-related tables (overlaps with main migration)
This needs to be integrated into the main app structure.

## WHAT NEEDS TO BE BUILT (IN ORDER)

### Phase 1: Laravel Project Scaffolding
```bash
# Create Laravel 12 project with React starter kit
laravel new spendwise --react
cd spendwise

# Install additional dependencies (see composer.json)
composer require laravel/socialite pragmarx/google2fa-laravel bacon/bacon-qr-code webklex/laravel-imap

# Copy all existing files into the new project structure
# Run migrations
php artisan migrate
php artisan db:seed --class=ExpenseCategorySeeder
```

GSD should:
1. Create the Laravel 12 project
2. Copy all existing files from `existing-code/` into proper locations
3. Verify all files are compatible with the starter kit structure
4. Run `composer install`, generate APP_KEY, run migrations

### Phase 2: Split SpendWiseController → 10 Controllers
Extract each method group from SpendWiseController into its target controller.
Each controller should:
- Inject required services via constructor
- Use policy authorization (`$this->authorize()`)
- Return API Resources (create these too)
- Keep controllers thin — logic stays in services

Create these API Resource classes:
- `TransactionResource` / `TransactionCollection`
- `BankAccountResource`
- `SubscriptionResource`
- `AIQuestionResource`
- `SavingsRecommendationResource`
- `SavingsTargetResource`
- `DashboardResource`

Create these Form Request classes:
- `UpdateAccountPurposeRequest`
- `AnswerQuestionRequest`
- `BulkAnswerRequest`
- `UpdateTransactionCategoryRequest`
- `ExportTaxRequest`
- `SendToAccountantRequest`
- `SetSavingsTargetRequest`
- `UpdateFinancialProfileRequest`

### Phase 3: Plaid Webhook Handler
Create `app/Http/Controllers/Api/PlaidWebhookController.php`:
- Verify Plaid webhook signatures (see Plaid docs)
- Handle `SYNC_UPDATES_AVAILABLE` → dispatch SyncBankTransactions job
- Handle `ITEM_LOGIN_REQUIRED` → mark connection error, notify user
- Handle `PENDING_EXPIRATION` → notify user to re-auth
- Handle `TRANSACTIONS_REMOVED` → delete removed transactions
- Idempotency via webhook_id tracking

Add route: `POST /api/v1/webhooks/plaid` (exempt from auth + CSRF)

### Phase 4: Events & Listeners
```
BankConnected → TriggerInitialSync, DispatchCategorizationJob
TransactionsImported → DispatchCategorizationJob
TransactionCategorized → UpdateSubscriptionDetection
UserAnsweredQuestion → UpdateTransactionCategory
SavingsAnalysisComplete → NotifyUser
SubscriptionDetectedUnused → NotifyUser
BudgetThresholdReached → NotifyUser
```

### Phase 5: Frontend (Inertia 2 + React 19 + TypeScript)
Build these pages using the starter kit's auth pages as reference:

| Page | Data Source | Key Components |
|------|-----------|----------------|
| Dashboard | GET /api/v1/dashboard | SpendingChart (Chart.js), CategoryBreakdown, RecentTransactions, AIQuestionAlert |
| Transactions | GET /api/v1/transactions | FilterBar (date, category, account, business/personal), TransactionTable, InlineCategoryEdit |
| Subscriptions | GET /api/v1/subscriptions | SubscriptionCard grid, unused warning badges, annual cost totals |
| Savings | GET /api/v1/savings | RecommendationCards, SavingsTarget gauge, ActionPlanChecklist |
| Tax | GET /api/v1/tax/summary | DeductionSummary, ScheduleCMapping, ExportModal, SendToAccountantModal |
| Connect | POST /api/v1/plaid/link-token | PlaidLink button (react-plaid-link), ConnectedAccountsList, EmailConnectionFlow |
| Settings | GET /api/v1/profile/financial | FinancialProfileForm, SecuritySettings (2FA), DeleteAccount |
| AI Questions | GET /api/v1/questions | QuestionCard with multiple-choice or free-text, BulkAnswerMode |

Install `react-plaid-link` for the Plaid Link modal integration.

### Phase 6: Additional Jobs
- `SyncBankTransactions` — Per-connection sync (dispatched by webhook or scheduler)
- `DetectSubscriptions` — Per-user subscription scan
- `GenerateSavingsAnalysis` — Per-user savings analysis
- `ReconcileTransactionsWithOrders` — Match bank charges to email orders
- `ProcessOrderEmails` — Email sync + Claude parsing (partially built in expense-parser/)

### Phase 7: Notifications
- `NewAIQuestionsNotification` — "3 transactions need your input"
- `UnusedSubscriptionAlert` — "You're paying $33/mo for unused services"
- `BudgetThresholdReached` — "You've hit 80% of your dining budget"
- `WeeklySavingsDigest` — Weekly summary
- Database + email channels

### Phase 8: Testing
Use Pest PHP. Create factories for all models first.

**Feature tests:**
- Auth flow (register → verify → login → 2FA → logout)
- Plaid flow (link token → exchange → sync → view transactions)
- AI question flow (generate → answer → category updated)
- Tax export flow (generate → download → send to accountant)
- Account purpose switch (personal → business → transactions re-categorized)

**Unit tests:**
- TransactionCategorizerService — confidence routing, batch processing
- SubscriptionDetectorService — recurrence detection patterns
- TaxExportService — Schedule C mapping, deduction calculations
- CaptchaService — score thresholds, action validation

### Phase 9: Deployment
- Dockerfile + docker-compose.yml (app, postgres, redis, queue worker)
- Queue worker config (consider Laravel Horizon)
- Cron entry for `php artisan schedule:run`
- Nginx config with TLS 1.2+, security headers
- GitHub Actions CI (lint, test, deploy)

## ENVIRONMENT VARIABLES

All defined in `.env` with Plaid sandbox credentials pre-filled.
See `.env.example` for the full documented list.

Key vars that need to be set:
- `APP_KEY` — Generate with `php artisan key:generate`
- `DB_PASSWORD` — Your PostgreSQL password
- `ANTHROPIC_API_KEY` — Get from console.anthropic.com
- `PLAID_CLIENT_ID` / `PLAID_SECRET` — Already set for sandbox
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — Optional, for OAuth

## CONVENTIONS

- PHP 8.2 backed enums for all status/type fields
- `protected function casts(): array` (Laravel 12 method syntax, not property)
- Form Request validation (never inline `$request->validate()` in controllers)
- Policy authorization (never manual `if ($model->user_id !== auth()->id())` checks)
- Service layer for business logic (controllers call services, services call models)
- `'encrypted'` / `'encrypted:array'` model casts for sensitive data — NEVER manual encrypt/decrypt
- `$hidden` on every model with sensitive fields
- TEXT columns for any encrypted field (encrypted ciphertext is ~200+ chars)
- Rate limiting on all auth endpoints
- Sanctum bearer token auth on all API routes

## COMMANDS

```bash
# Development
php artisan serve                           # Start dev server
php artisan queue:work redis --tries=3      # Process background jobs
php artisan schedule:work                   # Run scheduled tasks locally

# Database
php artisan migrate                         # Run migrations
php artisan migrate:fresh --seed            # Reset + seed (dev only)
php artisan db:seed --class=ExpenseCategorySeeder

# Testing
php artisan test                            # Run all tests
php artisan test --filter=AuthTest          # Run specific test

# Debugging
php artisan tinker                          # REPL
php artisan route:list --path=api           # Show all API routes
php artisan model:show Transaction          # Show model details
```

## PLAID SANDBOX TESTING

Skip the Plaid Link UI by creating a sandbox public token directly:
```bash
curl -X POST https://sandbox.plaid.com/sandbox/public_token/create \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "698bd031bad71b00222707bc",
    "secret": "f83f51a1f0286db0c82598619dab1d",
    "institution_id": "ins_109508",
    "initial_products": ["transactions"]
  }'
```
Then exchange through your API: `POST /api/v1/plaid/exchange {"public_token": "..."}`

## DOCUMENTATION

- `docs/GETTING_STARTED.md` — Full setup guide with step-by-step instructions
- `docs/SECURITY_AND_ENCRYPTION_PROCEDURES.md` — Complete security documentation
- `docs/PLAID_SECURITY_QUESTIONNAIRE_DRAFT.md` — Plaid production access answers
- `docs/SPENDWISE_BUILD_CHECKLIST.md` — Original build checklist (some items now complete)
- `DIRECTORY_STRUCTURE.md` — Full file map of the architecture
