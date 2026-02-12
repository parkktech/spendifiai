# LedgerIQ

AI-powered personal finance platform that connects to your bank accounts, automatically categorizes transactions using Claude AI, detects subscriptions, recommends savings opportunities, and generates tax-ready export packages.

## Features

### Bank Integration
- **Plaid Connect** — Securely link bank accounts for automatic transaction syncing
- **Statement Upload** — Upload PDF or CSV bank statements for manual import with AI-powered extraction
- **Dual Path** — Users can use Plaid, statement uploads, or both

### AI-Powered Categorization
- Automatic transaction categorization via Claude AI with confidence scoring
- High-confidence transactions auto-categorized silently
- Low-confidence transactions generate interactive questions (multiple-choice or free-text chat)
- Business vs. personal expense classification based on account purpose

### Subscription Detection
- Scans transaction patterns to identify recurring charges (weekly, monthly, quarterly, annual)
- Known merchant matching (Netflix, Spotify, utilities, insurance, etc.)
- Frequency-based "stopped billing" detection when charges stop arriving
- Essential vs. non-essential classification

### Savings Intelligence
- AI-analyzed 90-day spending patterns generate personalized savings recommendations
- Interactive response system: cancel, reduce, or keep each recommendation
- AI-suggested cheaper alternatives for subscriptions and services
- Projected savings tracking with monthly history charts
- Custom savings goals with AI-generated action plans

### Dashboard
- **Budget Waterfall** — Visual income-to-expenses flow showing where money goes
- **Monthly Bills** — Essential and non-essential recurring charges
- **Home Affordability** — Income-based housing affordability calculator
- **Where to Cut** — Actionable savings recommendations with one-click responses

### Tax Export
- IRS Schedule C category mapping for 50+ expense categories
- Excel workbook (5 tabs), PDF cover sheet, and CSV export
- Direct email-to-accountant with tax package attachment
- Business/personal expense separation

### Email Connections
- Google OAuth and IMAP email connection support
- Provider-specific setup instructions (Gmail, Outlook, Yahoo, iCloud)

### Security
- AES-256-CBC encryption for all sensitive data (Plaid tokens, EINs, OAuth credentials)
- TOTP two-factor authentication with QR code setup
- Google OAuth sign-in
- reCAPTCHA v3 on registration and login
- Rate limiting on all auth and sensitive endpoints
- GDPR/CCPA-compliant account deletion

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12, PHP 8.3 |
| Frontend | React 19, Inertia.js 2, TypeScript |
| Styling | Tailwind CSS v4 |
| Database | PostgreSQL 15+ |
| Cache/Queue | Redis 7+ |
| AI | Anthropic Claude API (Sonnet) |
| Banking | Plaid API + manual statement upload |
| Auth | Laravel Sanctum, Fortify, Socialite |
| Testing | Pest PHP 3 |
| Code Style | Laravel Pint |

## Getting Started

### Prerequisites

- PHP 8.3+
- PostgreSQL 15+
- Redis 7+
- Node.js 20+
- Composer 2+
- `poppler-utils` (for PDF statement parsing — provides `pdftotext`)

### Installation

```bash
# Clone the repository
git clone <repository-url> ledgeriq
cd ledgeriq

# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Configure your .env file:
# - DB_DATABASE, DB_USERNAME, DB_PASSWORD (PostgreSQL)
# - ANTHROPIC_API_KEY (from console.anthropic.com)
# - Plaid credentials are pre-set for sandbox

# Run migrations and seed
php artisan migrate
php artisan db:seed --class=ExpenseCategorySeeder

# Build frontend
npm run build
```

### Development

```bash
# Start the development servers (3 terminals)
php artisan serve          # Laravel backend
npm run dev                # Vite dev server
php artisan queue:work     # Background job processing

# Or use the combined dev command
composer run dev
```

### Testing

```bash
# Run all tests (131 tests, 459 assertions)
php artisan test --compact

# Run a specific test file
php artisan test --compact --filter=StatementUpload

# Run with coverage
php artisan test --coverage
```

### Code Style

```bash
# Fix formatting
vendor/bin/pint

# Check only changed files
vendor/bin/pint --dirty
```

## Project Structure

```
app/
  Enums/              # 7 PHP 8.2 backed enums
  Events/             # BankConnected event
  Http/
    Controllers/
      Api/            # 12 API controllers
      Auth/           # 5 auth controllers
    Middleware/        # VerifyCaptcha, EnsureBankConnected, EnsureProfileComplete, Enforce2FA
    Requests/         # 20 Form Request classes
    Resources/        # 8 API Resource classes
  Jobs/               # CategorizePendingTransactions
  Mail/               # TaxPackageMail
  Models/             # 18 Eloquent models
  Policies/           # Transaction, BankAccount, AIQuestion, Subscription
  Services/           # 10 service classes (business logic layer)
config/
  spendwise.php       # AI thresholds, Plaid settings, app config
database/
  factories/          # Model factories for all 18 models
  migrations/         # 11 migrations
  seeders/            # ExpenseCategorySeeder (50+ IRS categories)
resources/
  js/
    Components/SpendWise/  # 15+ reusable React components
    Layouts/               # AppLayout, AuthLayout, PublicLayout, GuestLayout
    Pages/                 # 28 Inertia page components
    hooks/                 # useApi, useApiPost custom hooks
    types/                 # TypeScript interfaces (spendwise.d.ts)
routes/
  api.php             # 50+ API routes (auth, v1, webhooks)
  web.php             # 14 Inertia page routes + marketing + legal
tests/
  Feature/            # 120+ feature tests
  Unit/               # 11+ unit tests
```

## API Overview

All API routes are prefixed with `/api`. Auth routes use `/api/auth/*`, app routes use `/api/v1/*`.

### Public
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Create account |
| POST | `/api/auth/login` | Sign in |
| POST | `/api/auth/forgot-password` | Password reset email |
| GET | `/api/auth/google/redirect` | Google OAuth redirect |

### Authenticated (no bank required)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard` | Main dashboard data |
| GET | `/api/v1/accounts` | Bank accounts list |
| POST | `/api/v1/statements/upload` | Upload bank statement (PDF/CSV) |
| POST | `/api/v1/statements/import` | Import parsed transactions |
| GET | `/api/v1/statements/history` | Upload history |

### Authenticated (bank connection required)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/transactions` | Transaction list with filters |
| GET | `/api/v1/subscriptions` | Detected subscriptions |
| POST | `/api/v1/subscriptions/detect` | Trigger subscription scan |
| GET | `/api/v1/savings` | Savings recommendations |
| POST | `/api/v1/savings/analyze` | Trigger AI savings analysis |
| GET | `/api/v1/tax/summary` | Tax deduction summary |
| POST | `/api/v1/tax/export` | Generate tax export package |
| GET | `/api/v1/questions` | AI categorization questions |

## Plaid Sandbox Testing

The app comes pre-configured with Plaid sandbox credentials. To test:

1. Navigate to the Connect page
2. Click "Connect Your Bank"
3. Select **First Platypus Bank**
4. Username: `user_good`, Password: `pass_good`
5. Verification code: any 6 digits (e.g., `123456`)

## Environment Variables

See `.env.example` for the full documented list. Key variables:

| Variable | Description |
|----------|-------------|
| `ANTHROPIC_API_KEY` | Claude AI API key from console.anthropic.com |
| `PLAID_CLIENT_ID` | Plaid client ID (pre-set for sandbox) |
| `PLAID_SECRET` | Plaid secret key (pre-set for sandbox) |
| `GOOGLE_CLIENT_ID` | Optional: Google OAuth client ID |
| `GOOGLE_CLIENT_SECRET` | Optional: Google OAuth client secret |
| `RECAPTCHA_SITE_KEY` | Optional: reCAPTCHA v3 site key |

## Documentation

- `CLAUDE.md` — AI assistant context (architecture, conventions, file map)
- `docs/GETTING_STARTED.md` — Full setup guide
- `docs/SECURITY_AND_ENCRYPTION_PROCEDURES.md` — Security documentation
- `docs/PLAID_SECURITY_QUESTIONNAIRE_DRAFT.md` — Plaid production access answers

## License

Proprietary. All rights reserved.
