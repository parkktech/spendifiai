# CLAUDE.md — SpendifiAI

> AI-powered personal finance platform with Plaid bank integration, bank statement uploads,
> Claude AI categorization, subscription detection, savings recommendations, and tax export.

## TECH STACK

- **Backend:** Laravel 12, PHP 8.3, PostgreSQL 15+, Redis 7+
- **Frontend:** React 19 + Inertia 2 + TypeScript + Tailwind CSS v4
- **AI:** Anthropic Claude API (Sonnet) for transaction categorization, savings analysis, statement parsing
- **Bank Integration:** Plaid API (sandbox) + manual bank statement upload (PDF/CSV)
- **Auth:** Laravel Sanctum + Fortify, Google OAuth (Socialite), optional TOTP 2FA
- **Testing:** Pest PHP 3 (131 tests, 459 assertions)
- **Queue:** Redis-backed Laravel queues
- **Code Style:** Laravel Pint

## PROJECT STATUS: ~85% COMPLETE

### What's Built and Working

- Full authentication system (register, login, 2FA, Google OAuth, password reset, email verify)
- Plaid bank linking (sandbox), manual bank statement upload (PDF via spatie/pdf-to-text, CSV)
- AI-powered transaction categorization with confidence thresholds
- AI question system (multiple-choice + free-text chat with AI for low-confidence transactions)
- Subscription detection from transaction patterns (weekly/monthly/quarterly/annual)
- Frequency-based "stopped billing" detection (2x billing cycle gap)
- Savings recommendations via Claude AI analysis
- Interactive savings response system (cancel/reduce/keep with projected savings tracking)
- Dashboard with Budget Waterfall, Monthly Bills, Home Affordability, Where to Cut
- 8 full Inertia pages + 6 marketing/legal pages
- Tax export (Excel + PDF + CSV) with IRS Schedule C mapping
- Email connection system (Google OAuth + IMAP)
- 131 Pest tests passing across all features

### What's Remaining

- Email receipt parsing pipeline (IMAP fetch → Claude parse → transaction matching)
- Notifications (unused subscription alerts, budget threshold, weekly digest)
- Deployment config (Docker, CI/CD)
- Production Plaid credentials

## ARCHITECTURE

### Models (18) — `app/Models/`

| Model | Purpose |
|-------|---------|
| User | Auth, relationships to all financial data, hasBankConnected(), hasTwoFactorEnabled() |
| BankConnection | Plaid or manual bank link, encrypted plaid_access_token |
| BankAccount | Checking/savings/credit/investment, AccountPurpose enum, encrypted EIN |
| Transaction | Core financial data, 8 query scopes, AI categorization fields |
| Subscription | Detected recurring charges, SubscriptionStatus enum, response tracking |
| AIQuestion | Low-confidence categorization questions, QuestionType/Status enums |
| StatementUpload | Uploaded PDF/CSV bank statements with processing status |
| EmailConnection | Gmail OAuth / IMAP credentials, encrypted tokens |
| ParsedEmail | Claude-parsed email receipts |
| Order | Matched email orders linked to transactions |
| OrderItem | Individual line items with AI category + tax deductibility |
| ExpenseCategory | 50+ IRS Schedule C mapped categories with tax_line |
| SavingsRecommendation | AI-generated savings tips with action_steps, response tracking |
| SavingsTarget | User savings goals with deadline + monthly target |
| SavingsPlanAction | Individual actions within a savings plan |
| SavingsLedger | Monthly savings tracking (claimed vs verified) |
| BudgetGoal | Per-category monthly limits with alert thresholds |
| UserFinancialProfile | Encrypted income, employment type, tax context |

### API Controllers (12) — `app/Http/Controllers/Api/`

| Controller | Key Methods |
|-----------|-------------|
| DashboardController | index() — budget waterfall, bills, affordability, savings, spending |
| PlaidController | createLinkToken(), exchangeToken(), sync(), disconnect() |
| PlaidWebhookController | handle() — SYNC_UPDATES_AVAILABLE, LOGIN_REQUIRED, EXPIRATION |
| BankAccountController | index(), updatePurpose() |
| StatementUploadController | upload(), import(), history() |
| TransactionController | index(), updateCategory(), categorize() |
| AIQuestionController | index(), answer(), chat(), bulkAnswer() |
| SubscriptionController | index(), detect(), respond(), alternatives() |
| SavingsController | recommendations(), analyze(), respond(), alternatives(), projected(), savingsHistory(), setTarget(), getTarget(), regeneratePlan(), respondToAction(), pulseCheck() |
| TaxController | summary(), export(), sendToAccountant(), download() |
| EmailConnectionController | index(), connect(), connectImap(), testConnection(), setupInstructions(), callback(), sync(), disconnect() |
| UserProfileController | updateFinancial(), showFinancial(), deleteAccount() |

### Auth Controllers (5) — `app/Http/Controllers/Auth/`

| Controller | Purpose |
|-----------|---------|
| AuthController | register, login, logout, me |
| SocialAuthController | Google OAuth redirect + callback |
| TwoFactorController | enable, confirm, disable, status, regenerateRecoveryCodes |
| PasswordResetController | sendResetLink, resetPassword, changePassword |
| EmailVerificationController | verify, resend |

### Services (10) — `app/Services/`

| Service | Purpose |
|---------|---------|
| PlaidService | Plaid API wrapper (link tokens, exchange, sync, balances) |
| TransactionCategorizerService | Batch AI categorization with confidence routing + question generation + chat |
| BankStatementParserService | PDF (spatie/pdf-to-text) + CSV parsing via Claude AI extraction |
| SubscriptionDetectorService | Pattern detection from transactions, frequency-based unused detection |
| SavingsAnalyzerService | 90-day spending analysis via Claude AI |
| SavingsTargetPlannerService | Personalized action plans via Claude AI |
| SavingsTrackingService | Record savings, projected totals, history by month |
| TaxExportService | Excel + PDF + CSV generation, email to accountant |
| CaptchaService | reCAPTCHA v3 server-side verification |
| AI/AlternativeSuggestionService | AI-powered cheaper alternatives for subscriptions/expenses (7-day cache) |

### Frontend Pages — `resources/js/Pages/`

| Page | Route | Description |
|------|-------|-------------|
| Dashboard | /dashboard | Budget waterfall, monthly bills, home affordability, savings actions |
| Transactions/Index | /transactions | Filterable table with inline category editing |
| Subscriptions/Index | /subscriptions | Grid of detected subscriptions with status badges |
| Savings/Index | /savings | AI recommendations, savings target, action plans |
| Tax/Index | /tax | Deduction summary, Schedule C mapping, export |
| Connect/Index | /connect | Plaid link + statement upload wizard + email connections |
| Questions/Index | /questions | AI categorization questions with chat |
| Settings/Index | /settings | Financial profile, 2FA, account deletion |
| Welcome | / | Marketing landing page |
| Features, HowItWorks, About, FAQ, Contact | /features etc. | Marketing pages |
| Legal/* | /privacy, /terms, /data-retention, /security-policy | Legal pages (Plaid-required) |

### Key Frontend Components — `resources/js/Components/SpendifiAI/`

ActionResponsePanel, Badge, ConfirmDialog, ConnectionMethodChooser, FileDropZone,
PlaidLinkButton, ProcessingStatus, ProjectedSavingsBanner, QuestionCard, SavingsTrackingChart,
StatCard, StatementUploadWizard, StepIndicator, TransactionReviewTable, UploadHistory

### Custom Hooks — `resources/js/hooks/`

- `useApi` — GET requests with loading/error/refresh
- `useApiPost` — POST requests with loading/error states

## CRITICAL ARCHITECTURE DECISIONS

### Encryption
Every sensitive field uses Laravel model casts — **never call encrypt()/decrypt() manually**.
Key encrypted fields:
- `BankConnection.plaid_access_token` → `'encrypted'`
- `BankAccount.ein` → `'encrypted'`
- `EmailConnection.access_token/refresh_token` → `'encrypted'`
- `Transaction.plaid_metadata` → `'encrypted:array'`
- `User.two_factor_secret` → `'encrypted'`
- `User.two_factor_recovery_codes` → `'encrypted:array'`

Encrypted fields MUST be TEXT columns in PostgreSQL.

### $hidden on Models
Every model with sensitive data has `$hidden` to prevent API leakage.

### AI Categorization Confidence Thresholds
```
>= 0.85 → Auto-categorize silently
0.60-0.84 → Categorize but flag for review
0.40-0.59 → Generate multiple-choice question
< 0.40 → Generate open-ended question
```

### Account Purpose (Business/Personal)
Bank accounts tagged with purpose (personal/business/mixed/investment).
Strongest signal for AI categorization. Cascades: account → transactions → AI prompt.

### Subscription "Stopped Billing" Detection
Compares last_charge_date to 2x the billing cycle:
- Weekly: >21 days = stopped
- Monthly: >60 days = stopped
- Quarterly: >180 days = stopped
- Annual: >400 days = stopped

### Decimal Serialization
Laravel's `decimal:2` cast serializes as JSON strings ("12.99" not 12.99).
Always wrap with `Number()` in TypeScript arithmetic: `Number(b.amount)`.

## MIGRATIONS (11) — `database/migrations/`

1. `000001_create_spendwise_tables` — 14 core tables
2. `000002_add_account_purpose` — Business/personal tagging
3. `000003_create_savings_targets` — Savings goals + plan actions
4. `000004_add_auth_columns` — Google OAuth, 2FA, account lockout
5. `000005_encrypt_sensitive_columns` — Column type changes for encryption
6. `000006_add_performance_indexes` — Query performance indexes
7. `000007_create_plaid_webhook_logs` — Webhook idempotency
8. `000008_add_missing_savings_columns` — Additional savings fields
9. `add_imap_fields` — IMAP email connection support
10. `add_statement_uploads` — Statement upload table + nullable Plaid columns
11. `add_action_response_columns` — Savings response + ledger tables

## ENUMS — `app/Enums/`

AccountPurpose, ActionResponseType, ConnectionStatus, QuestionStatus, QuestionType,
SavingsLedgerStatus, SubscriptionStatus

## CONVENTIONS

- PHP 8.2 backed enums for all status/type fields
- `protected function casts(): array` (Laravel 12 method syntax)
- Form Request validation — never inline `$request->validate()`
- Policy authorization — never manual `$model->user_id !== auth()->id()`
- Service layer for business logic (controllers call services)
- `'encrypted'` / `'encrypted:array'` model casts — NEVER manual encrypt/decrypt
- `$hidden` on every model with sensitive fields
- TEXT columns for any encrypted field
- Rate limiting on auth endpoints
- Sanctum bearer token auth on API routes
- Tailwind CSS v4 with `sw-*` custom theme tokens
- `Number()` wrapping for all decimal values in TypeScript arithmetic

## COMMANDS

```bash
# Development
php artisan serve                           # Start dev server
npm run dev                                 # Vite dev server
php artisan queue:work redis --tries=3      # Process jobs
php artisan schedule:work                   # Scheduled tasks

# Database
php artisan migrate                         # Run migrations
php artisan migrate:fresh --seed            # Reset + seed
php artisan db:seed --class=ExpenseCategorySeeder

# Testing
php artisan test --compact                  # All tests
php artisan test --compact --filter=Name    # Specific test
vendor/bin/pint --dirty --format agent      # Code style

# Build
npm run build                               # Production build

# Debug
php artisan tinker
php artisan route:list --path=api
```

## ENVIRONMENT VARIABLES

Key vars (see `.env.example`):
- `APP_KEY` — `php artisan key:generate`
- `DB_PASSWORD` — PostgreSQL password
- `ANTHROPIC_API_KEY` — console.anthropic.com
- `PLAID_CLIENT_ID` / `PLAID_SECRET` — Pre-set for sandbox
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — Optional, for OAuth

## TEST COVERAGE (131 tests)

- **Auth:** Registration, login, logout, 2FA enable/confirm/disable, password reset, email verify
- **Plaid:** Link token, exchange, sync, disconnect, webhook handling
- **Transactions:** Categorization, filtering, category updates
- **Subscriptions:** Detection (weekly/monthly), stopped billing, response handling
- **Savings:** Recommendations, respond (cancel/reduce/keep), projected totals, tracking history
- **Statement Upload:** Upload, import, history, validation, authorization
- **Dashboard:** Financial blocks (waterfall, bills, affordability)
- **AI Questions:** Answer, bulk answer, chat
