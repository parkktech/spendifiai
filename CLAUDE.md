# AGENTS.md — SpendifiAI

> AI-powered personal finance platform that connects to your bank via Plaid, parses bank statements
> and email receipts with Claude AI, automatically categorizes transactions, detects subscriptions,
> generates savings recommendations, and exports tax deductions — all through an interactive
> React dashboard with real-time financial insights.

---

## MANDATORY SAFETY RULES — READ FIRST

> **These rules are NON-NEGOTIABLE. Violating any of them can destroy real user data and financial records. There are NO exceptions.**

### Data Protection — NEVER Destroy Production Data

1. **NEVER run `migrate:fresh`, `migrate:reset`, `migrate:rollback`, or `db:wipe`** — these destroy all existing user data, bank connections, transactions, subscriptions, and financial records. This is a live application with real users.
2. **NEVER run database seeders (`db:seed`)** on the production database — seeders are for local development and CI only. Running them in production can corrupt or overwrite real data.
3. **NEVER run `php artisan migrate:fresh --seed`** or any variation that resets the database — this is the single most destructive command possible.
4. **ONLY run `php artisan migrate`** (forward-only migrations) — new migrations must ADD columns/tables, never DROP existing ones unless explicitly instructed by the project owner.
5. **NEVER delete, truncate, or bulk-update existing database records** unless explicitly instructed with the exact scope specified.
6. **NEVER drop columns or tables in migrations** — if a column is no longer needed, leave it. Only the project owner decides when to remove schema elements.
7. **Test migrations locally first** — always verify migration SQL is additive (ADD COLUMN, CREATE TABLE) not destructive (DROP, TRUNCATE, DELETE).

### Scope Discipline — NEVER Assume Intent

8. **NEVER alter, refactor, rename, or "improve" existing code** that is not directly related to the feature being implemented. If you see code that looks wrong or suboptimal, leave it alone unless explicitly asked to fix it.
9. **NEVER change existing API response formats, database column types, or model relationships** unless the current task specifically requires it. Backwards compatibility with all existing features is mandatory.
10. **NEVER remove, rename, or reorganize existing files, routes, components, or CSS classes** — other parts of the system depend on them. Only ADD new things or MODIFY the specific lines needed for the task.
11. **NEVER change existing test assertions or skip failing tests** to make new code pass — fix the new code to be compatible instead.
12. **NEVER modify `.env`, `.env.example`, or environment configuration** unless explicitly asked. Do not add, remove, or change environment variables without instruction.
13. **Always ask before making changes that affect shared infrastructure** — middleware stacks, service providers, route groups, config files, scheduled tasks, queue configuration.

### Development Workflow

14. **All migrations must be forward-only and additive** — use `$table->addColumn()` patterns, never `$table->dropColumn()` or `Schema::dropIfExists()`.
15. **Run `php artisan test --compact` after every change** to verify nothing is broken.
16. **Run `vendor/bin/pint --dirty` before any commit** to maintain code style.
17. **When in doubt, ASK** — it is always better to ask for clarification than to make an assumption that breaks existing functionality.

---

## TECH STACK

- **Backend:** Laravel 12, PHP 8.3, PostgreSQL 15+, Redis 7+
- **Frontend:** React 19 + Inertia 2 + TypeScript + Tailwind CSS v4
- **AI:** Anthropic Claude Sonnet (`claude-sonnet-4-20250514`) for categorization, savings analysis, statement parsing, email receipt extraction
- **Bank Integration:** Plaid API (sandbox) + manual bank statement upload (PDF via spatie/pdf-to-text, CSV)
- **Email Integration:** Gmail OAuth + Outlook OAuth + IMAP (Yahoo, iCloud, AOL, Fastmail, custom)
- **Auth:** Laravel Sanctum (bearer token + secure cookie), Google OAuth (Socialite), optional TOTP 2FA
- **Email Delivery:** SendGrid SMTP for transactional email (verification, password reset, tax export)
- **Charts:** recharts v3.7 (BarChart, PieChart, stacked bars, line overlays)
- **Testing:** Pest PHP 3 (131 tests, 459 assertions across 27 test files)
- **Queue:** Redis-backed Laravel queues with 8 scheduled tasks
- **Code Style:** Laravel Pint
- **PDF:** barryvdh/laravel-dompdf + spatie/pdf-to-text
- **Excel:** phpoffice/phpspreadsheet

## PROJECT STATUS: ~90% COMPLETE

### What's Built and Working

- Full authentication system (register, login, 2FA, Google OAuth, password reset, email verify)
- Token-based auth persistence (localStorage + secure cookie + ExtractTokenFromCookie middleware)
- Google OAuth login button on auth pages (basic scopes only — no Gmail at login)
- Plaid bank linking (sandbox), manual bank statement upload (PDF via spatie/pdf-to-text, CSV)
- AI-powered transaction categorization with confidence thresholds (auto/review/question routing)
- AI question system (multiple-choice + free-text chat with AI for low-confidence transactions)
- Subscription detection from transaction patterns (weekly/monthly/quarterly/annual)
- Frequency-based "stopped billing" detection (2x billing cycle gap)
- Savings recommendations via Claude AI analysis with difficulty ratings
- Interactive savings response system (cancel/reduce/keep with projected savings tracking)
- Savings target with AI-generated action plans and monthly progress tracking
- Dashboard with Budget Waterfall, Monthly Bills, Home Affordability, Where to Cut, Top Stores, Primary vs Extra income
- Conditional API calls — pages skip requests when bank not connected (`useApi` `enabled` option)
- 8 full Inertia pages + 6 marketing/legal pages + auth pages
- Tax export (Excel + PDF + CSV + TXF + QuickBooks + OFX) with IRS Schedule C mapping
- Email connection system (Gmail OAuth + Outlook OAuth + IMAP) with receipt parsing pipeline
- Order-to-transaction reconciliation with confidence scoring
- 80+ cancellation providers with verified URLs, phone numbers, difficulty ratings
- Environment-aware CSP middleware (permissive in dev, strict in production)
- Admin panel for managing cancellation providers
- Demo account seeder with 6 months of realistic financial data
- Cron scheduler + Redis queue worker configured
- 131 Pest tests passing across all features

### What's Remaining

- Notifications (unused subscription alerts, budget threshold, weekly digest)
- Deployment config (Docker, CI/CD)
- Production Plaid credentials
- Google OAuth verification for Gmail restricted scopes (gmail.readonly)

---

## ARCHITECTURE

### Models (25) — `app/Models/`

| Model | Purpose | Key Fields |
|-------|---------|------------|
| User | Auth hub, relationships to all financial data | google_id, two_factor_secret (encrypted), failed_login_attempts, locked_until, is_admin |
| BankConnection | Plaid or manual bank link | plaid_access_token (encrypted), status (ConnectionStatus enum), sync_cursor, error_code |
| BankAccount | Checking/savings/credit/investment | purpose (AccountPurpose enum), ein (encrypted), current_balance, available_balance |
| Transaction | Core financial data, 8 query scopes | amount, ai_category, ai_confidence, user_category, expense_type, review_status, is_subscription, matched_order_id |
| Subscription | Detected recurring charges | amount, frequency, status (SubscriptionStatus), response_type, charge_history (JSON), cancellation_provider_id |
| AIQuestion | Low-confidence categorization questions | question_type (QuestionType), options (JSON), ai_confidence, ai_best_guess, status (QuestionStatus) |
| StatementUpload | Uploaded PDF/CSV bank statements | file_path, status, total_extracted, duplicates_found, transactions_imported |
| EmailConnection | Gmail/Outlook/IMAP credentials | provider, connection_type (oauth/imap), access_token (encrypted), refresh_token (encrypted), imap_host/port/encryption |
| ParsedEmail | Claude-parsed email receipts | email_message_id, parse_status, search_source, retry_count |
| Order | Matched email orders linked to transactions | merchant, order_number, total, matched_transaction_id, is_reconciled |
| OrderItem | Individual line items with AI category | product_name, unit_price, total_price, ai_category, tax_deductible, tax_deductible_confidence |
| ExpenseCategory | 50+ IRS Schedule C mapped categories | slug, tax_schedule_line, irs_category, keywords (JSON), is_essential, is_typically_deductible |
| SavingsRecommendation | AI-generated savings tips | monthly_savings, annual_savings, difficulty, action_steps (JSON), related_merchants (JSON), response_type |
| SavingsTarget | User savings goals | monthly_target, motivation, goal_total, target_start_date, is_active |
| SavingsPlanAction | Individual actions within a plan | title, monthly_savings, current_spending, recommended_spending, difficulty, priority, status |
| SavingsProgress | Monthly savings tracking by target | month, income, total_spending, actual_savings, target_savings, gap, cumulative_saved |
| SavingsLedger | Monthly savings actions (claimed vs verified) | source_type, source_id, action_taken, monthly_savings, previous_amount, new_amount, status |
| BudgetGoal | Per-category monthly limits | category_slug, monthly_limit, notify_at_80_pct, notify_at_100_pct |
| UserFinancialProfile | Tax context | employment_type, business_type, has_home_office, housing_status, tax_filing_status, monthly_income (encrypted) |
| UserFinancialOverride | Income/expense classification overrides | override_type (income_source/expense_category), override_key, classification (primary/extra) |
| CancellationProvider | 80+ known subscriptions with cancel links | company_name, aliases (JSON), cancellation_url, cancellation_phone, difficulty (easy/medium/hard) |
| ReconciliationCandidate | Pending order-to-transaction matches | transaction_id, order_id, confidence, status (pending/confirmed/rejected) |
| MerchantAlias | Bank name → normalized merchant mapping | bank_name, normalized_name, email_domain, match_count |
| SeoPage | SEO landing pages | slug, title, meta_description, content, faq_items (JSON), is_published |
| PlaidWebhookLog | Webhook idempotency | webhook_type, webhook_code, item_id, payload (JSON), status |

### API Controllers (12) — `app/Http/Controllers/Api/`

| Controller | Key Methods | Details |
|-----------|-------------|---------|
| DashboardController | index(), storeDetail(), classify() | Single composite endpoint (~700 lines): budget waterfall, monthly bills, home affordability, cost of living, income breakdown, top stores, savings projections, spending trends. Cached per user+view+period (60s TTL). storeDetail() returns per-store transaction/order breakdown. classify() handles income/expense overrides. |
| PlaidController | createLinkToken(), exchangeToken(), sync(), disconnect() | Plaid Link integration. Exchange dispatches CategorizePendingTransactions job. Sync fetches new transactions via cursor-based pagination. |
| PlaidWebhookController | handle() | Processes SYNC_UPDATES_AVAILABLE, LOGIN_REQUIRED, PENDING_EXPIRATION. JWT signature verification. Idempotency via PlaidWebhookLog. |
| BankAccountController | index(), updatePurpose() | List accounts with balances. Purpose change cascades to all account transactions and invalidates dashboard cache. |
| StatementUploadController | upload(), import(), history() | PDF parsed via spatie/pdf-to-text + Claude AI extraction. CSV parsed directly. Shows duplicates before import. |
| TransactionController | index(), updateCategory(), categorize() | Paginated listing with filters (date, category, account_purpose, search). Category updates propagate to matching merchants. categorize() dispatches AI batch job. |
| AIQuestionController | index(), answer(), chat(), bulkAnswer() | Pending questions for low-confidence transactions. chat() sends free-text to Claude and returns category suggestion with explanation. bulkAnswer() processes multiple at once. |
| SubscriptionController | index(), detect(), respond(), update(), dismiss(), alternatives() | Grid view with active/unused/cancelled tabs. detect() runs SubscriptionDetectorService. respond() records cancel/reduce/keep + updates SavingsLedger. alternatives() generates AI cheaper options (7-day cache). |
| SavingsController | recommendations(), analyze(), respond(), alternatives(), projected(), tracking(), setTarget(), getTarget(), regeneratePlan(), respondToAction(), pulseCheck() | Full savings lifecycle. analyze() sends 90-day spending to Claude. projected() aggregates all response savings. pulseCheck() gives quick AI assessment. |
| TaxController | summary(), export(), sendToAccountant(), download() | Year-based deduction summary with Schedule C/A mapping. Generates 6 export formats (Excel, PDF, CSV, TXF, QuickBooks, OFX). Email to accountant via TaxPackageMail. |
| EmailConnectionController | index(), connect(), connectImap(), testConnection(), setupInstructions(), callback(), sync(), disconnect() | Multi-provider email setup. Gmail/Outlook use OAuth. Others use IMAP with app passwords. Provider-specific setup instructions. sync() dispatches ProcessOrderEmails job. |
| UserProfileController | updateFinancial(), showFinancial(), deleteAccount() | Financial profile for tax context. deleteAccount() is GDPR/CCPA compliant (requires password confirmation). |

### Auth Controllers (6) — `app/Http/Controllers/Auth/`

| Controller | Purpose |
|-----------|---------|
| AuthController | register, login, logout, me — API token-based, returns Sanctum bearer token |
| AuthenticatedSessionController | Renders Login/Register Inertia pages |
| SocialAuthController | Google OAuth redirect (basic scopes only) + callback with auto-login + token creation |
| TwoFactorController | enable, confirm, disable, status, regenerateRecoveryCodes — TOTP via pragmarx/google2fa |
| PasswordResetController | sendResetLink, resetPassword, changePassword — email-based with encrypted tokens |
| EmailVerificationController | verify, resend — email verification with signed URLs |

### Middleware (9) — `app/Http/Middleware/`

| Middleware | Purpose |
|-----------|---------|
| ExtractTokenFromCookie | Reads `auth_token` cookie, validates Sanctum token format (`id\|hash`), sets Authorization header for SSR requests. Handles legacy encrypted cookie migration. |
| SecurityHeaders | X-Frame-Options: DENY, HSTS (1yr + preload), CSP (environment-aware), Permissions-Policy (camera/mic/geo disabled). Whitelists: cdn.plaid.com, fonts.bunny.net, Google APIs, Unsplash. |
| HandleInertiaRequests | Shares auth props: user, hasBankConnected, hasEmailConnected, isAdmin, plaid_env |
| EnsureBankConnected | Returns 403 if no linked bank — guards questions, subscriptions, savings, tax routes |
| EnsureProfileComplete | Returns 403 if financial profile incomplete — guards tax export routes |
| Enforce2FA | Returns 403 if 2FA not enabled (when required) |
| VerifyCaptcha | Validates reCAPTCHA v3 token on registration/login |
| EnsureAdmin | Returns 403 if user is not admin |
| RemoveTrailingSlash | URL normalization |

### Services (19) — `app/Services/`

| Service | Purpose |
|---------|---------|
| PlaidService | Plaid API wrapper — link tokens, exchange, cursor-based sync, balance refresh |
| AI/TransactionCategorizerService | Batch AI categorization (25 per batch, 500ms rate limit). Sends merchant, amount, date, account purpose, Plaid categories to Claude. Routes by confidence: ≥0.85 auto, 0.60–0.84 review, 0.40–0.59 multiple-choice, <0.40 open-ended. Also handles chat() for free-text AI dialogue. |
| BankStatementParserService | PDF → text via spatie/pdf-to-text → Claude AI extraction. CSV direct parsing. Returns structured transactions with confidence scores. |
| SubscriptionDetectorService | Scans 6 months of transactions. Groups by normalized merchant. Known subscriptions: min 2 charges, 15% amount tolerance. Unknown: min 3 charges, exact penny match, interval CV < 0.25. Detects frequency (weekly/monthly/quarterly/annual). Marks "unused" if no charge in 2x billing cycle. |
| SavingsAnalyzerService | Gathers 90-day spending + subscriptions + financial profile → Claude AI generates recommendations with title, description, monthly/annual savings, difficulty, action_steps, related_merchants |
| SavingsTargetPlannerService | Takes savings target + spending data → Claude generates personalized action plan with priorities, difficulty, how-to instructions |
| SavingsTrackingService | Records savings in SavingsLedger. Projects monthly totals from all cancel/reduce responses. Tracks claimed vs verified savings by month. |
| TaxExportService | Generates 6 export formats (Excel, PDF, CSV, TXF, QuickBooks, OFX). Aggregates deductible transactions + order items by IRS category. Calculates estimated tax savings by bracket. |
| CaptchaService | reCAPTCHA v3 server-side verification |
| AI/AlternativeSuggestionService | Claude-powered cheaper alternatives for subscriptions/expenses. 7-day cache per item. |
| IncomeDetectorService | Analyzes 3 months of deposits. Classifies primary (regular payroll) vs extra (irregular/1099). Users can override via UserFinancialOverride. |
| Email/GmailService | Gmail OAuth flow (gmail.readonly scope), email fetching, receipt search |
| Email/ImapEmailService | IMAP connection for Yahoo/iCloud/AOL/Fastmail/custom. Provider detection, auto-config, setup instructions |
| Email/OutlookService | Microsoft OAuth flow, email fetching |
| Email/EmailParserService | Sends email content to Claude → extracts merchant, order number, date, line items with categories + tax deductibility |
| Email/TransactionGuidedSearchService | Finds emails matching unreconciled transactions by merchant + date + amount. Min $10, max 20 merchants, 90-day lookback. |
| ReconciliationService | Matches orders to transactions by amount + date + merchant name. Generates confidence scores. Creates ReconciliationCandidate records. |
| HousingDetectionService | Multi-pass algorithm: 1) Find loan/housing transactions 2) Check recurring patterns ≥$500 with ≤5% deviation 3) Check single large charges on 1st-5th 4) Subscription fallback 5) Merge servicers |
| DashboardCacheService | Cache key: `dashboard:{user_id}:{view}:{period_start}:{period_end}`. TTL 60s. Invalidated on: category update, purpose change, subscription response, savings response, classification override. |

### Jobs (5) — `app/Jobs/`

| Job | Trigger | Details |
|-----|---------|---------|
| CategorizePendingTransactions | Bank sync, statement import, profile update | Chunks 25 at a time, 500ms between batches. Calls TransactionCategorizerService then SubscriptionDetectorService. Fires TransactionCategorized event. $tries=3, $timeout=120s |
| ProcessOrderEmails | Manual email sync | Fetches emails from Gmail/Outlook/IMAP. Transaction-guided search for additional matches. Claude parses each receipt. Creates Order + OrderItem records. Dispatches ReconcileOrders. $tries=3, $timeout=1800s |
| ReconcileOrders | After ProcessOrderEmails | Matches orders to transactions via ReconciliationService. $tries=3, $timeout=120s |
| SyncBankTransactions | Scheduled every 4 hours | Syncs all active bank connections via Plaid cursor-based pagination |
| RetryFailedEmails | Scheduled daily 4am | Retries failed email parsing with exponential backoff |

### Events & Listeners — `app/Events/`, `app/Listeners/`

| Event | Listeners |
|-------|-----------|
| BankConnected | TriggerInitialSync → syncs transactions from new connection |
| TransactionCategorized | UpdateSubscriptionDetection → re-detect subscriptions; CheckBudgetThresholds → check budget alerts; NotifyQuestionsReady → notify if questions generated |
| TransactionsImported | DispatchCategorizationJob → queue AI categorization |
| UserAnsweredQuestion | UpdateTransactionCategory → apply answer to all matching merchants |

### Scheduled Tasks — `routes/console.php`

```
Every 4 hours    → Sync bank transactions (all active connections)
Every 2 hours    → Categorize pending transactions
Every 6 hours    → Sync email accounts for orders
Daily 2:00 AM    → Detect subscriptions + notify unused
Daily 3:00 AM    → Expire old AI questions (7-day default)
Daily 4:00 AM    → Retry failed email parses
Weekly Mon 6 AM  → Generate savings recommendations
Weekly Mon 7 AM  → Send weekly savings digest
```

---

## FRONTEND

### Design System — `resources/css/app.css`

Tailwind v4 `@theme` block with `sw-*` custom tokens:

**Colors:**
- Backgrounds: `sw-bg` (#f8fafc), `sw-sidebar` (#fff), `sw-card` (#fff), `sw-surface` (#f1f5f9)
- Borders: `sw-border` (#e2e8f0), `sw-border-strong` (#cbd5e1)
- Primary: `sw-accent` (#2563eb), `sw-accent-hover` (#1d4ed8), `sw-accent-light` (#eff6ff)
- Success: `sw-success` (#059669), `sw-success-light` (#ecfdf5)
- Status: `sw-danger` (#dc2626), `sw-warning` (#d97706), `sw-info` (#7c3aed) + light variants
- Text: `sw-text` (#0f172a), `sw-text-secondary` (#334155), `sw-muted` (#64748b), `sw-dim` (#94a3b8)

**Font:** Inter (self-hosted WOFF2, weights 300-800)

### Layout — `resources/js/Layouts/`

**AuthenticatedLayout** — Main app shell with collapsible sidebar, 8 nav routes (Dashboard, Transactions, Subscriptions, Savings, Tax, Connect, Settings, Questions + Admin), badge counts on nav items, email connection banner (dismissable), dark mode toggle (localStorage), user menu dropdown.

**GuestLayout** — Centered card for auth pages.

### Pages — `resources/js/Pages/`

#### Dashboard (`/dashboard`) — ~1300 lines
The command center showing comprehensive financial overview:
1. **Smart Greeting** — Dynamic headline based on financial health (surplus vs deficit)
2. **Hero Metrics** — This month income/spending, monthly surplus/deficit with visual badges
3. **Sync Status** — Last synced timestamp from connected bank
4. **Budget Reality Check** — Visual waterfall bar: income → bills → subscriptions → other → surplus. Color-coded (emerald savings, red bills, amber subscriptions). Toggle percent/dollar view. Verdict box (green if can save, red if deficit).
5. **Monthly Bills** — Recurring charges with essential vs non-essential split. "Stopped billing" detection. Next expected date. Expandable list (top 8 by default).
6. **Home Affordability** — DTI-based mortgage calculator. Max home price, monthly payment, rate, term. Color-coded DTI (green <28%, yellow 28-36%, red >36%).
7. **Primary vs Extra** — Can you live on your paycheck? Primary income vs expenses coverage %.
8. **Cost of Living** — Essential expenses by category with top merchants. Override UI for reclassification.
9. **Top Stores** — Where you spend the most. Total spent, transaction count, avg per visit. Expandable for order items.
10. **Where to Cut** — Action feed with 3 tabs (Quick Wins, This Week, This Month). Unused subscriptions, AI recommendations (easy/medium/hard), overspending alerts. Each card expandable for ActionResponsePanel (cancelled/reduced/kept).
11. **Savings Progress & Goal** — Monthly tracking chart + goal progress bar.
12. **Spending Chart** — Monthly trend chart (income vs expenses bar chart).
13. **Recent Activity** — Last 8 transactions with inline details.

**State:** Timeline filter (period_start, period_end, avg_mode, display_mode), active action tab, expanded card tracking, responded cards map, toast notifications.

#### Transactions (`/transactions`)
Filterable transaction table with date range, category, account purpose (personal/business), and search filters. Paginated (25/page). Auto-triggers AI categorization if pending transactions detected. Inline category editing with search dropdown — changes propagate to matching merchants ("Also updated X matching transactions"). Review status highlighting for moderate-confidence items. Summary stats (total, amount, business/personal split).

#### Subscriptions (`/subscriptions`)
Grid view with tabs (All, Cancellable, Essential Bills) and view modes (All/Personal/Business). Stat cards: monthly cost, annual cost, unused count + wasted amount. Per-card: status badge, frequency, last/next charge, annual cost, edit notes, cancel difficulty indicator, cancellation URL/phone. Action menu: cancel, reduce, mark kept.

#### Savings (`/savings`)
AI recommendations grouped by difficulty with monthly/annual savings projections. Savings target form (amount, motivation, deadline) with progress bar and time-to-goal projection. AI-generated action plans with accept/reject per action. "Pulse Check" quick analysis button. Projected savings banner aggregating all responses.

#### Tax Center (`/tax`)
Year selector (current + previous). Stat cards: total deductible, Schedule C, Schedule A, estimated tax savings. Top 10 categories chart (horizontal bars). Expandable table by category or IRS line with color badges (blue business, green personal). Expand to see individual transactions with date/merchant/amount + source (bank vs email receipt). Export modal: choose format (Excel/PDF/CSV) or email to accountant.

#### Connect (`/connect`)
Stats: connected accounts, uploaded statements, email connections. Smart UX: equal-weight Plaid vs Upload choice for first-time users, compact actions for returning users. Plaid section with Link button, sync button, sandbox test credentials. Connected accounts list with balance, purpose dropdown (personal/business/mixed/investment), disconnect. Upload history with stats. Email connections: Gmail/Outlook (OAuth), Yahoo/iCloud/AOL/Fastmail/custom (IMAP with app passwords). Provider-specific setup instructions. Test connection button.

#### Questions (`/questions`)
AI categorization questions in single or bulk mode. Single: expandable cards with transaction context. Bulk: table with submit-all button. Question types: multiple-choice (select dropdown) or free text. Chat with AI option for suggestions with explanation. Auto-removal on answer.

#### Settings (`/settings`)
Financial profile form (employment, filing status, income, business type, housing, home office). Security: change password, 2FA toggle, Google connection status. Danger zone: delete account with confirmation.

#### Auth Pages
- Login (`/login`): Email/password, Google OAuth button, remember me, forgot password link
- Register (`/register`): Email/password/confirm, terms agreement, Google OAuth
- ForgotPassword (`/forgot-password`): Email entry
- ResetPassword (`/reset-password`): New password + confirm
- VerifyEmail (`/email-verification-notice`): Resend button
- GoogleCallback (`/auth/callback`): Handles OAuth redirect, stores token

#### Marketing & Legal
Welcome (`/`), Features, HowItWorks, About, FAQ, Contact, Privacy, Terms, Data Retention, Security Policy

### Key Components — `resources/js/Components/`

**SpendifiAI/:**
- `ActionResponsePanel` — 3-option inline response (cancelled/reduced/kept) with savings projection
- `TransactionRow` — Compact display with inline category editing + review badges
- `SubscriptionCard` — Grid card with status, frequency, actions, cancel difficulty
- `RecommendationCard` — Difficulty-colored card with action steps + apply/dismiss
- `QuestionCard` — Transaction context + multiple-choice or free-text + AI chat
- `StatCard` — Icon, title, large value, subtitle — used across all pages
- `SpendingChart` — recharts BarChart monthly income vs expenses
- `SavingsTrackingChart` — Stacked bars (actual/verified/recommendation savings) + projected line
- `TimelineFilter` — Period selector (presets + custom dates) + display mode toggle
- `PlaidLinkButton` — Fetches link token, opens Plaid modal, exchanges public token
- `StatementUploadWizard` — Multi-step: file drop → processing status → review parsed → import summary
- `ConnectionMethodChooser` — Equal-weight Plaid vs Upload choice UX
- `FileDropZone` — Drag/drop or click for PDF/CSV
- `ProjectedSavingsBanner` — Monthly/annual savings breakdown by source + verification stats
- `CostOfLivingBreakdown` — Category cards with monthly avg, top merchants, override UI
- `PrimaryVsExtraCard` — Income sufficiency analysis (paycheck coverage %)
- `TopStoresSection` — Store cards with spend totals, expandable for order items
- `ExportModal` — Tax export format choice + accountant email
- `ConfirmDialog` — Modal confirmation for destructive actions
- `Badge` — Variants: success, danger, warning, info, neutral
- `FilterBar` — Date/category/purpose/search filters for transactions
- `ViewModeToggle` — All/Personal/Business filter buttons
- `UploadHistory` — Previous statement uploads table

**Auth/Shared:**
- `GoogleLoginButton` — Official Google brand colors and icon
- `ConnectBankPrompt` — CTA when no bank connected, links to Connect page

### Hooks — `resources/js/hooks/`

- `useApi<T>` — GET requests with `{ data, loading, error, refresh, mutate }`. Options: `immediate` (fetch on mount), `enabled` (skip if false for no-bank state). Silently ignores 403. Mounted ref prevents state updates on unmounted components.
- `useApiPost<T, D>` — POST/PATCH/DELETE with `{ submit, loading, error, data }`. `submit(payload?, config?)` returns Promise.

### Types — `resources/js/types/spendifiai.d.ts`

Core domain types: Transaction, BankAccount, Subscription, AIQuestion, SavingsRecommendation, SavingsTarget, DashboardData (composite with summary, waterfall, affordability, cost_of_living, income_sources, top_stores, recurring_bills, projected_savings), TaxSummary, StatementUploadResult, BudgetWaterfall, HomeAffordability, CostOfLivingData, IncomeBreakdown, TimelinePeriod, ActionResponseType.

---

## CRITICAL ARCHITECTURE DECISIONS

### Token-Based Authentication
Login/Register return Sanctum bearer tokens stored in:
1. `localStorage` — for JavaScript `Authorization` header on API calls
2. Secure cookie (`auth_token`) — for server-side Inertia requests (hard refresh, initial page load)

`ExtractTokenFromCookie` middleware reads the cookie, validates token format (`id|hash`), and sets the Authorization header before Sanctum processes the request. Handles duplicate cookies (legacy encrypted + new plain-text) by validating format.

### Conditional API Calls
Pages that need bank data check `auth.hasBankConnected` (shared via `HandleInertiaRequests`) and pass `enabled: false` to `useApi` when no bank is connected. This prevents 403 errors and unnecessary API calls. Pages show `ConnectBankPrompt` instead.

### Google OAuth Scopes
Login requests **basic scopes only** (`openid`, `email`, `profile`) to avoid Google's "unverified app" warning for restricted scopes. Gmail API access (`gmail.readonly`) is requested separately via the Email Connection flow on the Connect page through `GmailService`, which has its own OAuth flow.

### Content Security Policy
`SecurityHeaders` middleware applies environment-aware CSP:
- **Development** (`local`, `development`): Permissive CSP to allow Vite dev server HMR
- **Production**: Strict CSP — `default-src 'self'`, whitelisted cdn.plaid.com (scripts), fonts.bunny.net (fonts/styles), Google APIs, Unsplash images. Frame sources: Plaid Link, Google OAuth.

### Encryption
Every sensitive field uses Laravel model casts — **never call encrypt()/decrypt() manually**.
Key encrypted fields:
- `BankConnection.plaid_access_token` → `'encrypted'`
- `BankAccount.ein` → `'encrypted'`
- `EmailConnection.access_token/refresh_token` → `'encrypted'`
- `Transaction.plaid_metadata` → `'encrypted:array'`
- `User.two_factor_secret` → `'encrypted'`
- `User.two_factor_recovery_codes` → `'encrypted:array'`
- `UserFinancialProfile.monthly_income` → `'encrypted'`
- `UserFinancialProfile.custom_rules` → `'encrypted'`
- `ParsedEmail.raw_parsed_data` → `'encrypted'` (TEXT)

Encrypted fields MUST be TEXT columns in PostgreSQL.

### $hidden on Models
Every model with sensitive data has `$hidden` to prevent API leakage:
- User: password, 2FA secret, recovery codes, Google ID
- BankConnection: Plaid token, item ID, sync cursor, error details
- EmailConnection: OAuth tokens
- Transaction: Plaid transaction ID, raw metadata, Plaid categories, bank_account_id

### AI Categorization Confidence Thresholds
```
>= 0.85 → Auto-categorize silently (auto_accept)
0.60-0.84 → Categorize but flag for review (flag_review)
0.40-0.59 → Generate multiple-choice question (ask_question)
< 0.40 → Generate open-ended question
```
Configured in `config/spendifiai.php`.

### Account Purpose (Business/Personal)
Bank accounts tagged with AccountPurpose enum (personal/business/mixed/investment).
Strongest signal for AI categorization. Cascades: account → transactions → AI prompt.
Purpose changes invalidate dashboard cache and update all account transactions.

### Subscription Detection Algorithm
1. Scan 6 months of transactions, group by normalized merchant
2. Known subscription merchants: min 2 charges, 15% amount tolerance
3. Unknown merchants: min 3 charges, exact penny match, interval std dev check (CV < 0.25)
4. Detect frequency: weekly (~7d), monthly (~30d), quarterly (~90d), annual (~365d)
5. "Stopped billing" if no charge in 2x billing cycle (weekly >21d, monthly >60d, quarterly >180d, annual >400d)

### Housing Detection Algorithm (Multi-Pass)
1. Find all loan/housing-related transactions
2. Check AI-tagged "Mortgage" transactions
3. Recurring patterns: ≥2 charges, consistent amount (≤5% deviation), ≥$500 each
4. Single large charges (≥$1000) on 1st-5th of month matching known housing amount (within 1%)
5. Subscription fallback: look for rent/mortgage subscriptions
6. Merge servicers: combine similar amounts on consistent dates

### Dashboard Caching
- Cache key: `dashboard:{user_id}:{view}:{period_start}:{period_end}`
- TTL: 60 seconds
- Invalidated on: transaction category update, account purpose change, subscription response, savings response, income/expense override

### Decimal Serialization
Laravel's `decimal:2` cast serializes as JSON strings ("12.99" not 12.99).
Always wrap with `Number()` in TypeScript arithmetic: `Number(b.amount)`.
PHP: Use `(float)` cast instead — `Number()` doesn't exist in PHP.

### Merchant Normalization
- Lowercase, trim whitespace, remove common suffixes (#123, XXXXX)
- ILIKE partial matching for flexible queries
- Payment processors (PayPal, Venmo, Cash App) extract actual merchant from description
- MerchantAlias table maps bank transaction names to normalized names

---

## DATABASE

### Migrations (28) — `database/migrations/`

| Migration | Tables/Changes |
|-----------|---------------|
| `000001_create_spendwise_tables` | 14 core tables: email_connections, bank_connections, bank_accounts, transactions, subscriptions, ai_questions, parsed_emails, orders, order_items, expense_categories, savings_recommendations, budget_goals, user_financial_profiles |
| `000002_add_account_purpose` | Adds purpose, nickname, business_name, tax_entity_type, ein to bank_accounts; account_purpose to transactions |
| `000003_create_savings_targets` | savings_targets, savings_plan_actions, savings_progress tables |
| `000004_add_auth_columns` | google_id, avatar_url, 2FA fields, failed_login_attempts, locked_until on users |
| `000005_encrypt_sensitive_columns` | Changes sensitive columns to TEXT for encryption compatibility |
| `000006_add_performance_indexes` | Composite indexes on transactions, subscriptions, questions + partial PostgreSQL indexes |
| `000007_create_plaid_webhook_logs` | plaid_webhook_logs table for idempotency |
| `000008_add_missing_savings_columns` | action_steps, related_merchants on savings_recommendations |
| `add_imap_fields` | connection_type, imap_host/port/encryption, status on email_connections |
| `add_statement_uploads` | statement_uploads table; makes plaid_item_id/plaid_account_id/plaid_transaction_id nullable |
| `add_action_response_columns` | response_type/data/savings on subscriptions + savings_recommendations; savings_ledger table |
| `create_seo_pages` | seo_pages table for SEO landing pages |
| `add_housing_status` | housing_status on user_financial_profiles |
| `add_description_to_subscriptions` | description field on subscriptions |
| `add_user_notes` | user_notes on subscriptions |
| `add_user_financial_overrides` | user_financial_overrides table for income/expense classification |
| `create_cancellation_providers` | cancellation_providers table; cancellation_provider_id on subscriptions; is_admin on users |
| `add_email_search_fields` | retry_count, last_retry_at, search_source on parsed_emails |
| `create_reconciliation` | reconciliation_candidates table |
| `create_merchant_aliases` | merchant_aliases table |
| Standard Laravel | users, personal_access_tokens, notifications, cache, jobs, sessions, password_reset_tokens |

### Key Indexes
- `[user_id, transaction_date]` — date range queries
- `[user_id, ai_category]` — category filtering
- `[user_id, review_status]` — pending categorization
- `[user_id, account_purpose, transaction_date]` — business/personal filtering
- Partial: `idx_txn_pending_categorization` WHERE review_status IN ('pending_ai', 'needs_review')
- Partial: `idx_txn_merchant_spending` WHERE amount > 0
- Partial: `idx_subs_active` WHERE status = 'active'
- Partial: `idx_questions_pending` WHERE status = 'pending'

### Seeders — `database/seeders/`

| Seeder | Details |
|--------|---------|
| DatabaseSeeder | Test user: test@example.com / password |
| DemoAccountSeeder | demo@spendifiai.com / Demo1234! — 2 bank connections, 2 accounts, 300+ transactions (6 months), 6 subscriptions, 35 parsed emails, 19 orders, 42 line items, AI questions, savings targets |
| ExpenseCategorySeeder | 50+ categories with IRS Schedule C mappings, icons, colors, keywords, parent-child relationships |
| CancellationProviderSeeder | 80+ companies (Netflix, Spotify, Adobe, etc.) with cancellation URLs, phone numbers, difficulty ratings, aliases |
| MerchantAliasSeeder | Bank name → normalized merchant mappings |
| SeoPageSeeder | SEO landing pages for comparison/alternatives/guides |

### Factories (18) — `database/factories/`

User, Transaction, Subscription, BankConnection, BankAccount, AIQuestion, SavingsRecommendation, SavingsTarget, SavingsPlanAction, SavingsProgress, Order, OrderItem, ParsedEmail, PlaidWebhookLog, EmailConnection, BudgetGoal, UserFinancialProfile, ExpenseCategory

---

## ENUMS — `app/Enums/` (9 enums)

| Enum | Values | Methods |
|------|--------|---------|
| AccountPurpose | Personal, Business, Mixed, Investment | defaultExpenseType(), defaultTaxDeductible(), label() |
| ExpenseType | Personal, Business, Mixed | — |
| ReviewStatus | PendingAI, NeedsReview, UserConfirmed, AIUncertain, AutoCategorized | isResolved() |
| QuestionType | Category, BusinessPersonal, Split, Confirm | — |
| QuestionStatus | Pending, Answered, Skipped, Expired | — |
| SubscriptionStatus | Active, Unused, Cancelled | — |
| ActionResponseType | Cancel, Reduce, Keep | — |
| ConnectionStatus | Active, Error, PendingReauth | — |
| SavingsLedgerStatus | Claimed, Verified | — |

---

## ROUTES — `routes/api.php` (50+ endpoints)

### Public (no auth)
```
POST /api/auth/register          (rate: 5/min, CAPTCHA)
POST /api/auth/login             (rate: 10/min, CAPTCHA)
POST /api/auth/forgot-password   (rate: 3/min)
POST /api/auth/reset-password    (rate: 5/min)
GET  /api/auth/google/redirect
GET  /api/auth/captcha-config
POST /api/v1/webhooks/plaid      (JWT signature verified)
GET  /api/v1/email/callback/outlook
```

### Authenticated (auth:sanctum, throttle: 120/min)
```
# Auth management
POST /api/auth/logout
GET  /api/auth/me
POST /api/auth/change-password
POST /api/auth/email/resend
POST /api/auth/google/disconnect
POST /api/auth/two-factor/{enable,confirm,disable,recovery-codes}

# Dashboard
GET  /api/v1/dashboard           (filters: view, period_start, period_end, avg_mode)
GET  /api/v1/dashboard/store/{name}
POST /api/v1/dashboard/classify

# Plaid
POST /api/v1/plaid/link-token
POST /api/v1/plaid/exchange
POST /api/v1/plaid/sync          (rate: 5/min)
DELETE /api/v1/plaid/{connection}

# Accounts
GET  /api/v1/accounts
PATCH /api/v1/accounts/{account}/purpose

# Statements (no bank.connected required)
POST /api/v1/statements/upload
POST /api/v1/statements/import
GET  /api/v1/statements/history

# Categories
GET  /api/v1/categories

# Email
GET  /api/v1/email/connections
POST /api/v1/email/connect/{provider}
POST /api/v1/email/connect-imap
POST /api/v1/email/test
POST /api/v1/email/setup-instructions
POST /api/v1/email/sync
DELETE /api/v1/email/{connection}

# Profile
POST /api/v1/profile/financial
GET  /api/v1/profile/financial
DELETE /api/v1/account           (GDPR/CCPA, requires password)
```

### Requires bank.connected middleware
```
# Questions
GET  /api/v1/questions
POST /api/v1/questions/{q}/answer
POST /api/v1/questions/{q}/chat
POST /api/v1/questions/bulk-answer

# Transactions
GET  /api/v1/transactions        (filters: date, category, purpose, search, pagination)
PATCH /api/v1/transactions/{tx}/category
POST /api/v1/transactions/categorize  (rate: 5/min)

# Subscriptions
GET  /api/v1/subscriptions
POST /api/v1/subscriptions/detect     (rate: 5/min)
PATCH /api/v1/subscriptions/{sub}
POST /api/v1/subscriptions/{sub}/respond
DELETE /api/v1/subscriptions/{sub}
GET  /api/v1/subscriptions/{sub}/alternatives

# Savings
GET  /api/v1/savings/
POST /api/v1/savings/analyze          (rate: 5/min)
POST /api/v1/savings/{rec}/dismiss
POST /api/v1/savings/{rec}/apply
POST /api/v1/savings/{rec}/respond
GET  /api/v1/savings/{rec}/alternatives
GET  /api/v1/savings/projected
GET  /api/v1/savings/tracking
POST /api/v1/savings/target
GET  /api/v1/savings/target
POST /api/v1/savings/target/regenerate (rate: 5/min)
POST /api/v1/savings/plan/{action}/respond
GET  /api/v1/savings/pulse            (rate: 10/min)

# Order items
PATCH /api/v1/order-items/{item}/expense-type

# Tax (requires profile.complete)
GET  /api/v1/tax/summary
POST /api/v1/tax/export               (rate: 10/min)
POST /api/v1/tax/send-to-accountant   (rate: 5/min)
GET  /api/v1/tax/download/{year}/{type}

# Reconciliation
GET  /api/v1/reconciliation/candidates
POST /api/v1/reconciliation/candidates/{c}/confirm
POST /api/v1/reconciliation/candidates/{c}/reject
```

### Admin (admin middleware)
```
GET  /api/admin/stats
GET/POST /api/admin/providers
GET/PATCH/DELETE /api/admin/providers/{p}
POST /api/admin/providers/bulk-import
POST /api/admin/providers/{p}/find-link
```

---

## POLICIES (9) — `app/Policies/`

All follow `user->id === resource->user_id` pattern:
Transaction, Subscription, AIQuestion, BankConnection, BankAccount, SavingsRecommendation, SavingsPlanAction, OrderItem, CancellationProvider (admin only)

## FORM REQUESTS (20) — `app/Http/Requests/`

All authorize via `$user->can('action', $resource)` using policies. Key validations:
- UpdateTransactionCategoryRequest: category (required, max 100), expense_type, tax_deductible
- RespondToSubscriptionRequest: response_type (cancelled/reduced/kept), new_amount (required_if reduced)
- SetSavingsTargetRequest: monthly_target (decimal), motivation, goal_total
- StatementUploadRequest: file (required, mimes: pdf/csv), bank_name, account_type
- ExportTaxRequest: year (required, integer)

---

## CONFIGURATION

### `config/spendifiai.php`
AI categorization confidence thresholds (≥0.85 auto-accept, 0.60–0.84 flag for review, 0.40–0.59 ask question, <0.40 open-ended) are **class constants** in `TransactionCategorizerService`, not config keys. Batch size and rate limiting are also hardcoded in the service (25 txns per batch, 500ms rate limit). Sync cadence (4h bank, 6h email, daily subscriptions, weekly savings) is hardcoded in `routes/console.php`.

Remaining config keys:
- `ai.model` — Claude model (env-driven, default: claude-sonnet-4-6)
- `ai.api_key` — Anthropic API key (env-driven)
- `ai.max_tokens` — Token ceiling (default 8000)
- `ai.alternatives.cache_days` — Cheaper alternatives cache TTL (7 days)
- `ai.alternatives.max_per_item` — Max alternatives per item (4)
- `ai.extraction_thresholds.classification_gate` — Tax doc classification confidence gate (0.70)
- `plaid.*` — Plaid API config (env-driven)
- `sync_digest.*` — Sync digest email settings (enabled, min interval 24h, min 1 txn)
- `sync.{question_expiry_days, active_sync_days, inactive_sync_days, active_threshold_days}` — Sync logic thresholds
- `captcha.*` — reCAPTCHA v3 config (env-driven)
- `two_factor.*` — TOTP settings (6 digits, 30s period, 8 recovery codes)
- `tax_deadlines` — Filing deadlines for accountant dashboard
- `vault.*` — Tax document vault storage (local or S3)
- `intelligence.*` — Cross-reference analysis (income/expense document mapping)
- `consent.*` — Cookie consent & analytics (GTM container ID, GA4 measurement ID)

### `config/email-search.php`
- 25+ subject patterns (order, receipt, invoice, confirmation, etc.)
- 11 sender prefixes (orders@, noreply@, billing@, etc.)
- Transaction-guided search: min $10, max 20 merchants/query, 90-day lookback

---

## SECURITY

1. **Encryption:** Model casts handle all encrypt/decrypt — never manual
2. **$hidden:** Sensitive data excluded from API responses
3. **Policies:** All write operations authorized via policy classes
4. **CSRF:** Inertia + Laravel CSRF on web routes; Sanctum bearer on API routes
5. **CSP:** Environment-aware (dev permissive, prod strict with whitelist)
6. **Account Lockout:** 5 failed logins → 15-minute lockout
7. **2FA:** Optional TOTP (Google Authenticator) + 8 recovery codes
8. **Rate Limiting:** Auth 5-10/min, API 120/min, sensitive actions 5/min
9. **reCAPTCHA v3:** Required on registration and login
10. **HSTS:** max-age 31536000 with preload + includeSubDomains
11. **Permissions-Policy:** camera, microphone, geolocation all disabled
12. **Password Reset:** Email-based with encrypted token, rate-limited

---

## CONVENTIONS

- PHP 8.2 backed enums for all status/type fields
- `protected function casts(): array` (Laravel 12 method syntax)
- Form Request validation — never inline `$request->validate()`
- Policy authorization — never manual `$model->user_id !== auth()->id()`
- Service layer for business logic (controllers call services)
- `'encrypted'` / `'encrypted:array'` model casts — NEVER manual encrypt/decrypt
- `$hidden` on every model with sensitive fields
- TEXT columns for any encrypted field
- Rate limiting on auth + sensitive endpoints
- Sanctum bearer token auth on API routes (token in localStorage + cookie)
- Tailwind CSS v4 with `sw-*` custom theme tokens
- `Number()` wrapping for all decimal values in TypeScript arithmetic
- PHP: `(float)` cast instead of `Number()` — PHP doesn't have `Number()`
- Environment-aware CSP (permissive in dev, strict in production)
- Google OAuth login uses basic scopes only; Gmail scopes via separate email connection flow
- `useApi` hook `enabled` option to skip API calls when preconditions not met
- Production assets via `npm run build`; never run Vite dev server in production
- recharts Tooltip `formatter` expects `number | undefined` — always handle undefined
- Carbon `diffInMonths()` direction matters: `$earlier->diffInMonths($later)` returns positive
- Pint auto-fixes style; run `vendor/bin/pint --dirty` before committing

---

## COMMANDS

```bash
# Development
php artisan serve                           # Start dev server
npm run dev                                 # Vite dev server (dev only)
php artisan queue:work redis --tries=3      # Process jobs
php artisan schedule:work                   # Scheduled tasks

# Database
php artisan migrate                         # Run migrations
php artisan migrate:fresh --seed            # Reset + seed
php artisan db:seed --class=ExpenseCategorySeeder
php artisan db:seed --class=DemoAccountSeeder
php artisan db:seed --class=CancellationProviderSeeder

# Plaid
php artisan plaid:backfill [--user=ID] [--sync]  # Re-fetch transaction history

# Testing
php artisan test --compact                  # All 131 tests
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
- `ANTHROPIC_API_KEY` — console.anthropic.com (for Claude Sonnet)
- `PLAID_CLIENT_ID` / `PLAID_SECRET` — Pre-set for sandbox
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — Login (basic scopes) + Email connection (Gmail scopes)
- `MAIL_*` — SendGrid SMTP for transactional email
- `CACHE_STORE=redis` / `QUEUE_CONNECTION=redis` — Redis for cache and queues
- `RECAPTCHA_SITE_KEY` / `RECAPTCHA_SECRET_KEY` — reCAPTCHA v3

## TEST COVERAGE (131 tests, 459 assertions, 27 files)

| Area | Tests |
|------|-------|
| Auth | Registration, login, logout, 2FA enable/confirm/disable, password reset, email verify, Google OAuth |
| Plaid | Link token, exchange, sync, disconnect, webhook handling (SYNC_UPDATES, LOGIN_REQUIRED, EXPIRATION) |
| Transactions | Categorization, filtering, category updates, authorization |
| Subscriptions | Detection (weekly/monthly), stopped billing, response handling, alternatives |
| Savings | Recommendations, respond (cancel/reduce/keep), projected totals, tracking history, targets |
| Statement Upload | Upload, import, history, validation, authorization |
| Dashboard | Financial blocks (waterfall, bills, affordability, cost of living) |
| Tax | Export, summary, deduction mapping, IRS line grouping |
| AI Questions | Answer, bulk answer, chat |
| Email | Connection, sync, order parsing |
| Profile | Financial profile, account deletion |

**Test helpers** (Pest.php): `createAuthenticatedUser()`, `createUserWithBank()`, `createUserWithBankAndProfile()`

## DEPENDENCIES

### PHP (composer.json)
Laravel 12, Inertia 2, Sanctum 4, Fortify, Socialite, google/apiclient, webklex/laravel-imap, barryvdh/laravel-dompdf, spatie/pdf-to-text, phpoffice/phpspreadsheet, phpoffice/phpword, pragmarx/google2fa-laravel, firebase/php-jwt, guzzlehttp/guzzle, predis/predis, sendgrid/sendgrid

### Node (package.json)
React 19, Inertia (React), Vite 7, TypeScript 5, Tailwind CSS 4, Headless UI, recharts 3.7, react-plaid-link 4.1, lucide-react, Playwright (E2E)
