# CLAUDE.md — SpendifiAI

> AI-powered personal finance platform with Plaid bank integration, bank statement uploads,
> Claude AI categorization, subscription detection, savings recommendations, and tax export.

## TECH STACK

- **Backend:** Laravel 12, PHP 8.3, PostgreSQL 15+, Redis 7+
- **Frontend:** React 19 + Inertia 2 + TypeScript + Tailwind CSS v4
- **AI:** Anthropic Claude API (Sonnet) for transaction categorization, savings analysis, statement parsing
- **Bank Integration:** Plaid API (sandbox) + manual bank statement upload (PDF/CSV)
- **Auth:** Laravel Sanctum (bearer token + secure cookie), Google OAuth (Socialite), optional TOTP 2FA
- **Email:** SendGrid SMTP for transactional email (verification, password reset, tax export)
- **Testing:** Pest PHP 3 (131 tests, 459 assertions)
- **Queue:** Redis-backed Laravel queues with cron scheduler
- **Code Style:** Laravel Pint

## PROJECT STATUS: ~90% COMPLETE

### What's Built and Working

- Full authentication system (register, login, 2FA, Google OAuth, password reset, email verify)
- Token-based auth persistence (localStorage + secure cookie + ExtractTokenFromCookie middleware)
- Google OAuth login button on auth pages (basic scopes only — no Gmail at login)
- Plaid bank linking (sandbox), manual bank statement upload (PDF via spatie/pdf-to-text, CSV)
- AI-powered transaction categorization with confidence thresholds
- AI question system (multiple-choice + free-text chat with AI for low-confidence transactions)
- Subscription detection from transaction patterns (weekly/monthly/quarterly/annual)
- Frequency-based "stopped billing" detection (2x billing cycle gap)
- Savings recommendations via Claude AI analysis
- Interactive savings response system (cancel/reduce/keep with projected savings tracking)
- Dashboard with Budget Waterfall, Monthly Bills, Home Affordability, Where to Cut
- Conditional API calls — pages skip requests when bank not connected (`useApi` `enabled` option)
- 8 full Inertia pages + 6 marketing/legal pages + auth pages (login, register, callback, verify, reset)
- Tax export (Excel + PDF + CSV) with IRS Schedule C mapping
- Email connection system (Google OAuth + IMAP) with separate Gmail scope flow
- Environment-aware CSP middleware (permissive in dev, strict in production)
- Cron scheduler + Redis queue worker configured
- 131 Pest tests passing across all features

### What's Remaining

- Email receipt parsing pipeline (IMAP fetch → Claude parse → transaction matching)
- Notifications (unused subscription alerts, budget threshold, weekly digest)
- Deployment config (Docker, CI/CD)
- Production Plaid credentials
- Google OAuth verification for Gmail restricted scopes (gmail.readonly)

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

### Auth Controllers (6) — `app/Http/Controllers/Auth/`

| Controller | Purpose |
|-----------|---------|
| AuthController | register, login, logout, me (API token-based) |
| AuthenticatedSessionController | Renders Login/Register pages via Inertia |
| SocialAuthController | Google OAuth redirect (basic scopes) + callback with auto-login |
| TwoFactorController | enable, confirm, disable, status, regenerateRecoveryCodes |
| PasswordResetController | sendResetLink, resetPassword, changePassword |
| EmailVerificationController | verify, resend |

### Middleware — `app/Http/Middleware/`

| Middleware | Purpose |
|-----------|---------|
| ExtractTokenFromCookie | Reads `auth_token` cookie and sets Authorization header for Sanctum on SSR requests |
| SecurityHeaders | X-Frame-Options, HSTS, CSP (environment-aware: permissive in dev, strict in production) |
| HandleInertiaRequests | Shares `auth.hasBankConnected` prop for conditional API calls |

### Services (12) — `app/Services/`

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
| Email/GmailService | Gmail OAuth flow, email fetching, receipt search (separate from login OAuth) |
| Email/ImapEmailService | IMAP email connection, provider detection, setup instructions |

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
| Auth/Login | /login | Email/password + Google OAuth login |
| Auth/Register | /register | Registration + Google OAuth signup |
| Auth/GoogleCallback | /auth/callback | Handles Google OAuth redirect, stores token |
| Auth/VerifyEmail | /email-verification-notice | Email verification prompt |
| Auth/ForgotPassword | /forgot-password | Password reset request |
| Auth/ResetPassword | /reset-password | New password form |
| Welcome | / | Marketing landing page |
| Features, HowItWorks, About, FAQ, Contact | /features etc. | Marketing pages |
| Legal/* | /privacy, /terms, /data-retention, /security-policy | Legal pages (Plaid-required) |

### Key Frontend Components — `resources/js/Components/`

**SpendifiAI/**: ActionResponsePanel, Badge, ConfirmDialog, ConnectionMethodChooser, FileDropZone,
PlaidLinkButton, ProcessingStatus, ProjectedSavingsBanner, QuestionCard, SavingsTrackingChart,
StatCard, StatementUploadWizard, StepIndicator, TransactionReviewTable, UploadHistory

**Auth/Shared**: GoogleLoginButton (official Google brand colors), ConnectBankPrompt (shown when no bank connected)

### Custom Hooks — `resources/js/hooks/`

- `useApi` — GET requests with loading/error/refresh + `enabled` option for conditional fetching
- `useApiPost` — POST requests with loading/error states

## CRITICAL ARCHITECTURE DECISIONS

### Token-Based Authentication
Login/Register use API endpoints that return Sanctum bearer tokens. Tokens are stored in:
1. `localStorage` — for JavaScript `Authorization` header on API calls
2. Secure cookie (`auth_token`) — for server-side Inertia requests (hard refresh, initial page load)

`ExtractTokenFromCookie` middleware reads the cookie and sets the Authorization header before Sanctum processes the request. This ensures authentication persists across page refreshes without relying on Laravel sessions.

### Conditional API Calls
Pages that need bank data check `auth.hasBankConnected` (shared via `HandleInertiaRequests`) and pass `enabled: false` to `useApi` when no bank is connected. This prevents 403 errors and unnecessary API calls. Pages show `ConnectBankPrompt` instead.

### Google OAuth Scopes
Login requests **basic scopes only** (`openid`, `email`, `profile`) to avoid Google's "unverified app" warning for restricted scopes. Gmail API access (`gmail.readonly`) is requested separately via the Email Connection flow on the Connect page through `GmailService`, which has its own OAuth flow.

### Content Security Policy
`SecurityHeaders` middleware applies environment-aware CSP:
- **Development** (`local`, `development`): Permissive CSP to allow Vite dev server HMR
- **Production**: Strict CSP — `default-src 'self'`, whitelisted fonts.bunny.net, images

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
- Sanctum bearer token auth on API routes (token in localStorage + cookie)
- Tailwind CSS v4 with `sw-*` custom theme tokens
- `Number()` wrapping for all decimal values in TypeScript arithmetic
- Environment-aware CSP (permissive in dev, strict in production)
- Google OAuth login uses basic scopes only; Gmail scopes via separate email connection flow
- `useApi` hook `enabled` option to skip API calls when preconditions not met (e.g., no bank connected)
- Production assets via `npm run build`; never run Vite dev server in production

## COMMANDS

```bash
# Development
php artisan serve                           # Start dev server
npm run dev                                 # Vite dev server (dev only — stop before production)
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
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — Used for both login (basic scopes) and email connection (Gmail scopes)
- `MAIL_*` — SendGrid SMTP for transactional email
- `CACHE_STORE=redis` / `QUEUE_CONNECTION=redis` — Redis for cache and queues

## TEST COVERAGE (131 tests)

- **Auth:** Registration, login, logout, 2FA enable/confirm/disable, password reset, email verify
- **Plaid:** Link token, exchange, sync, disconnect, webhook handling
- **Transactions:** Categorization, filtering, category updates
- **Subscriptions:** Detection (weekly/monthly), stopped billing, response handling
- **Savings:** Recommendations, respond (cancel/reduce/keep), projected totals, tracking history
- **Statement Upload:** Upload, import, history, validation, authorization
- **Dashboard:** Financial blocks (waterfall, bills, affordability)
- **AI Questions:** Answer, bulk answer, chat
