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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3.29
- inertiajs/inertia-laravel (INERTIA) - v2
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/socialite (SOCIALITE) - v5
- tightenco/ziggy (ZIGGY) - v2
- laravel/breeze (BREEZE) - v2
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v3
- phpunit/phpunit (PHPUNIT) - v11
- @inertiajs/react (INERTIA) - v2
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `pest-testing` — Tests applications using the Pest 3 PHP framework. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, architecture testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, or needs to verify functionality works.
- `inertia-react-development` — Develops Inertia.js v2 React client-side applications. Activates when creating React pages, forms, or navigation; using &lt;Link&gt;, &lt;Form&gt;, useForm, or router; working with deferred props, prefetching, or polling; or when user mentions React with Inertia, React pages, React forms, or React navigation.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

=== inertia-laravel/v2 rules ===

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scrolling (merging props + `WhenVisible`), lazy loading on scroll, polling, prefetching.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.
- CRITICAL: ALWAYS use `search-docs` tool for version-specific Pest documentation and updated code examples.
- IMPORTANT: Activate `pest-testing` every time you're working with a Pest or testing-related task.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>
