# Getting Started — Local Development Setup

Complete guide to get the app running from a fresh clone.

---

## Prerequisites

| Tool | Version | Install |
|------|---------|---------|
| PHP | 8.2+ | `sudo apt install php8.2` or Homebrew |
| Composer | 2.7+ | https://getcomposer.org/download/ |
| PostgreSQL | 15+ | `sudo apt install postgresql` or Homebrew |
| Redis | 7+ | `sudo apt install redis-server` or Homebrew |
| Node.js | 20+ | https://nodejs.org/ (for frontend) |
| Git | 2.40+ | `sudo apt install git` |

---

## Step 1: Clone & Install Dependencies

```bash
git clone git@github.com:youruser/spendwise.git
cd spendwise

# PHP dependencies
composer install

# Frontend dependencies (when frontend is built)
# npm install
```

---

## Step 2: Environment Configuration

```bash
# Copy the example env
cp .env.example .env

# Generate the APP_KEY (master encryption key)
php artisan key:generate
```

**This generates a 256-bit AES key in your `.env`.** All encrypted database fields depend on this key. If you lose it, encrypted data is unrecoverable.

---

## Step 3: Database Setup

```bash
# Create the PostgreSQL database and user
sudo -u postgres psql

# In the psql shell:
CREATE USER spendwise WITH PASSWORD 'your-secure-password';
CREATE DATABASE spendwise OWNER spendwise;
GRANT ALL PRIVILEGES ON DATABASE spendwise TO spendwise;
\q
```

Update `.env`:
```env
DB_DATABASE=spendwise
DB_USERNAME=spendwise
DB_PASSWORD=your-secure-password
```

Run migrations and seed:
```bash
php artisan migrate
php artisan db:seed --class=ExpenseCategorySeeder
```

### Migration Order

The migrations run in order:

| # | Migration | What It Creates |
|---|-----------|----------------|
| 1 | `000001_create_spendwise_tables` | All 14 core tables (users, bank_connections, transactions, etc.) |
| 2 | `000002_add_account_purpose` | Business/personal account tagging columns |
| 3 | `000003_create_savings_targets` | Savings target + plan action tables |
| 4 | `000004_add_auth_columns` | Google OAuth, 2FA, account lockout columns on users |
| 5 | `000005_encrypt_sensitive_columns` | Changes column types (json→text, decimal→text) for encrypted fields |

---

## Step 4: Redis

Make sure Redis is running:
```bash
# Check status
redis-cli ping
# Should return: PONG

# Start if not running
sudo systemctl start redis
# or on macOS:
brew services start redis
```

---

## Step 5: Plaid Setup (Bank Integration)

### Sandbox (Local Development)

Your Plaid sandbox credentials are already in `.env`:
```env
PLAID_CLIENT_ID=698bd031bad71b00222707bc
PLAID_SECRET=f83f51a1f0286db0c82598619dab1d
PLAID_ENV=sandbox
```

**Sandbox test credentials** (use these in the Plaid Link modal):

| Username | Password | Behavior |
|----------|----------|----------|
| `user_good` | `pass_good` | Normal account — checking + savings |
| `user_good` | `credential_good` | Triggers MFA (enter code: `1234`) |
| `user_good` | `mfa_device` | Device-based MFA flow |
| `user_good` | `no_checking` | Returns savings only, no checking |

### How Plaid Link Works

1. **Frontend** calls `POST /api/v1/plaid/link-token` → backend creates a Plaid Link token
2. **Frontend** opens the Plaid Link modal with that token
3. **User** selects their bank, logs in with sandbox credentials above
4. **Plaid Link** returns a `public_token` to the frontend
5. **Frontend** sends `public_token` to `POST /api/v1/plaid/exchange`
6. **Backend** exchanges it for a permanent `access_token` (encrypted at rest)
7. **Backend** fetches account details and syncs initial transactions

### Testing the Plaid Flow (API only, no frontend needed)

```bash
# 1. Register a user
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@test.com","password":"password123","password_confirmation":"password123"}'

# Save the token from the response
TOKEN="your-token-here"

# 2. Create a Plaid Link token
curl -X POST http://localhost:8000/api/v1/plaid/link-token \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json"

# This returns a link_token — in production, the frontend passes this to Plaid Link.
# In sandbox, you can use Plaid's sandbox endpoints directly:

# 3. Create a sandbox public_token (skip Plaid Link UI)
curl -X POST https://sandbox.plaid.com/sandbox/public_token/create \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "698bd031bad71b00222707bc",
    "secret": "f83f51a1f0286db0c82598619dab1d",
    "institution_id": "ins_109508",
    "initial_products": ["transactions"]
  }'

# Save the public_token from response

# 4. Exchange it through your API
curl -X POST http://localhost:8000/api/v1/plaid/exchange \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"public_token":"public-sandbox-xxxxxxx"}'

# 5. Sync transactions
curl -X POST http://localhost:8000/api/v1/plaid/sync \
  -H "Authorization: Bearer $TOKEN"

# 6. View transactions
curl http://localhost:8000/api/v1/transactions \
  -H "Authorization: Bearer $TOKEN"
```

### Common Sandbox Institution IDs

| Institution | ID | Notes |
|------------|-----|-------|
| First Platypus Bank | `ins_109508` | Default sandbox bank |
| First Gingham CU | `ins_109509` | Credit union |
| Tattersall Federal CU | `ins_109510` | Federal credit union |
| Tartan Bank | `ins_109511` | Standard bank |
| Houndstooth Bank | `ins_109512` | Has investment accounts |

### Moving to Development / Production

| Environment | What Changes |
|------------|-------------|
| **Sandbox** → **Development** | Real banks, 100-user limit, requires Plaid application approval |
| **Development** → **Production** | Unlimited users, requires completed security questionnaire + approval |

1. Apply at https://dashboard.plaid.com/overview/production
2. Complete the security questionnaire (see `PLAID_SECURITY_QUESTIONNAIRE_DRAFT.md`)
3. Get approved
4. Update `.env`:
```env
PLAID_ENV=production
PLAID_WEBHOOK_URL=https://yourdomain.app/api/v1/webhooks/plaid
```

---

## Step 6: Anthropic API Setup (AI Categorization)

1. Go to https://console.anthropic.com/
2. Create an API key
3. Add to `.env`:
```env
ANTHROPIC_API_KEY=sk-ant-api03-your-key-here
ANTHROPIC_MODEL=claude-sonnet-4-20250514
```

**Cost estimate:** ~$0.003 per batch of 25 transactions categorized (Sonnet pricing). A typical user with 100 transactions/month costs roughly $0.01/month in AI categorization.

---

## Step 7: Google OAuth Setup (Optional for MVP)

Only needed if you want Google social login and/or Gmail email parsing.

1. Go to https://console.cloud.google.com/apis/credentials
2. Create a new project (or select existing)
3. Go to **APIs & Services → Credentials**
4. Click **Create Credentials → OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Authorized redirect URI: `http://localhost:8000/auth/google/callback`
7. Copy Client ID and Client Secret to `.env`:
```env
GOOGLE_CLIENT_ID=xxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxx
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```
8. Enable these APIs in **APIs & Services → Library**:
   - Google Identity (for OAuth login)
   - Gmail API (for email parsing — if using that feature)

---

## Step 8: reCAPTCHA v3 Setup (Optional in Dev)

Captcha is auto-disabled when keys are not set. To enable:

1. Go to https://www.google.com/recaptcha/admin
2. Create a new site → **reCAPTCHA v3** (score-based)
3. Add domain: `localhost`
4. Add to `.env`:
```env
RECAPTCHA_SITE_KEY=6Lxxxxxxxx
RECAPTCHA_SECRET_KEY=6Lxxxxxxxx
```

---

## Step 9: Run the Application

```bash
# Terminal 1: Laravel dev server
php artisan serve
# → http://localhost:8000

# Terminal 2: Queue worker (processes background jobs)
php artisan queue:work redis --tries=3 --timeout=90

# Terminal 3: Scheduler (runs cron tasks locally)
php artisan schedule:work

# Terminal 4: Frontend dev server (when frontend is built)
# npm run dev
# → http://localhost:5173
```

---

## Step 10: Verify Everything Works

### Health Check
```bash
curl http://localhost:8000/up
# Should return 200 OK
```

### Register + Login
```bash
# Register
curl -s -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jason",
    "email": "jason@test.com",
    "password": "password123",
    "password_confirmation": "password123"
  }' | jq .

# Login
curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jason@test.com",
    "password": "password123"
  }' | jq .

# Check auth
TOKEN="paste-token-here"
curl -s http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer $TOKEN" | jq .
```

### Plaid Sandbox Quick Test
```bash
# Create sandbox public token (bypasses Plaid Link UI)
PUBLIC_TOKEN=$(curl -s -X POST https://sandbox.plaid.com/sandbox/public_token/create \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "698bd031bad71b00222707bc",
    "secret": "f83f51a1f0286db0c82598619dab1d",
    "institution_id": "ins_109508",
    "initial_products": ["transactions"]
  }' | jq -r '.public_token')

echo "Public token: $PUBLIC_TOKEN"

# Exchange through your API
curl -s -X POST http://localhost:8000/api/v1/plaid/exchange \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"public_token\": \"$PUBLIC_TOKEN\"}" | jq .

# Sync transactions
curl -s -X POST http://localhost:8000/api/v1/plaid/sync \
  -H "Authorization: Bearer $TOKEN" | jq .

# View transactions
curl -s http://localhost:8000/api/v1/transactions \
  -H "Authorization: Bearer $TOKEN" | jq .

# View accounts
curl -s http://localhost:8000/api/v1/accounts \
  -H "Authorization: Bearer $TOKEN" | jq .
```

---

## API Endpoint Reference (Quick)

### Auth
| Method | Endpoint | Auth | Description |
|--------|---------|------|-------------|
| POST | `/api/auth/register` | No | Create account |
| POST | `/api/auth/login` | No | Login (returns token) |
| POST | `/api/auth/logout` | Yes | Revoke token |
| GET | `/api/auth/me` | Yes | Current user info |
| POST | `/api/auth/forgot-password` | No | Send reset email |
| POST | `/api/auth/reset-password` | No | Reset with token |
| POST | `/api/auth/change-password` | Yes | Change password |
| POST | `/api/auth/email/resend` | Yes | Resend verification |
| GET | `/api/auth/google/redirect` | No | Google OAuth URL |
| POST | `/api/auth/google/disconnect` | Yes | Unlink Google |
| GET | `/api/auth/two-factor/status` | Yes | 2FA status |
| POST | `/api/auth/two-factor/enable` | Yes | Start 2FA setup |
| POST | `/api/auth/two-factor/confirm` | Yes | Activate 2FA |
| POST | `/api/auth/two-factor/disable` | Yes | Remove 2FA |
| POST | `/api/auth/two-factor/recovery-codes` | Yes | Regenerate codes |

### App (v1)
| Method | Endpoint | Auth | Description |
|--------|---------|------|-------------|
| GET | `/api/v1/dashboard` | Yes | Dashboard data |
| POST | `/api/v1/plaid/link-token` | Yes | Create Plaid Link token |
| POST | `/api/v1/plaid/exchange` | Yes | Exchange public token |
| POST | `/api/v1/plaid/sync` | Yes | Sync transactions |
| DELETE | `/api/v1/plaid/{connection}` | Yes | Disconnect bank |
| GET | `/api/v1/accounts` | Yes | List bank accounts |
| PATCH | `/api/v1/accounts/{id}/purpose` | Yes | Set business/personal |
| GET | `/api/v1/transactions` | Yes | List transactions |
| PATCH | `/api/v1/transactions/{id}/category` | Yes | Override category |
| GET | `/api/v1/questions` | Yes | Pending AI questions |
| POST | `/api/v1/questions/{id}/answer` | Yes | Answer question |
| GET | `/api/v1/subscriptions` | Yes | Detected subscriptions |
| GET | `/api/v1/savings` | Yes | Savings recommendations |
| POST | `/api/v1/savings/analyze` | Yes | Run savings analysis |
| GET | `/api/v1/tax/summary` | Yes | Tax summary |
| POST | `/api/v1/tax/export` | Yes | Generate tax export |
| DELETE | `/api/v1/account` | Yes | Delete entire account |

---

## Plaid Environments Explained

```
┌─────────────────────────────────────────────────────────────┐
│                    PLAID ENVIRONMENTS                       │
├──────────────┬──────────────────────────────────────────────┤
│              │                                              │
│   SANDBOX    │  Fake data, free, no approval needed         │
│   (current)  │  Test creds: user_good / pass_good           │
│              │  Base URL: sandbox.plaid.com                  │
│              │  Use for: Local development + testing         │
│              │                                              │
├──────────────┼──────────────────────────────────────────────┤
│              │                                              │
│  DEVELOPMENT │  Real banks, 100 user limit                  │
│              │  Requires: Plaid application approval         │
│              │  Base URL: development.plaid.com              │
│              │  Use for: Beta testing with real users        │
│              │                                              │
├──────────────┼──────────────────────────────────────────────┤
│              │                                              │
│  PRODUCTION  │  Real banks, unlimited users                 │
│              │  Requires: Security questionnaire + approval  │
│              │  Base URL: production.plaid.com               │
│              │  Use for: Live app with paying customers      │
│              │  Also requires: Webhook URL, Privacy Policy   │
│              │                                              │
└──────────────┴──────────────────────────────────────────────┘
```

---

## Scheduled Tasks

These run automatically when `php artisan schedule:work` is active:

| Schedule | Task | What It Does |
|----------|------|-------------|
| Every 4 hours | Sync bank transactions | Pulls new transactions from Plaid for all active connections |
| Every 2 hours | AI categorize | Runs Claude AI on uncategorized transactions |
| Daily 2:00 AM | Detect subscriptions | Analyzes transaction patterns for recurring charges |
| Weekly Monday 6:00 AM | Savings analysis | Generates spending reduction recommendations |
| Daily 3:00 AM | Expire questions | Marks unanswered AI questions as expired (7-day window) |

---

## Troubleshooting

### "Connection refused" on database
```bash
# Check PostgreSQL is running
sudo systemctl status postgresql
# Start it
sudo systemctl start postgresql
```

### "Class not found" errors
```bash
composer dump-autoload
php artisan config:clear
php artisan cache:clear
```

### Plaid "INVALID_CREDENTIALS" in sandbox
Make sure you're using the exact test credentials — `user_good` and `pass_good` (not your real bank credentials).

### Queue jobs not processing
```bash
# Make sure Redis is running
redis-cli ping

# Restart queue worker
php artisan queue:restart
php artisan queue:work redis --tries=3
```

### Encrypted field errors after migration
If you see "The payload is invalid" errors, it means old unencrypted data exists in columns that now have encrypted casts. Fix:
```bash
# If starting fresh (dev only):
php artisan migrate:fresh --seed
```

### APP_KEY missing
```bash
php artisan key:generate
# NEVER do this on production if data already exists — it breaks all encrypted fields
```
