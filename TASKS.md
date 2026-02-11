# GSD Task Plan — SpendWise

## PHASE 1: SCAFFOLD [run first]

### Task 1.1: Create Laravel 12 Project
```
Create a fresh Laravel 12 project with the React starter kit:
  laravel new spendwise --react
Then install additional composer dependencies from existing-code/composer.json:
  laravel/socialite, pragmarx/google2fa-laravel, bacon/bacon-qr-code, webklex/laravel-imap
Copy the .env from existing-code/ and run: php artisan key:generate
```

### Task 1.2: Copy Existing Code Into Project
```
Copy all files from existing-code/ into the Laravel project, preserving directory structure:
  - app/Models/ (16 models)
  - app/Http/Controllers/Auth/ (5 auth controllers)
  - app/Http/Controllers/Api/SpendWiseController.php (will be split later)
  - app/Http/Middleware/ (4 middleware)
  - app/Http/Requests/Auth/ (2 form requests)
  - app/Policies/ (4 policies)
  - app/Actions/Fortify/ (3 actions)
  - app/Providers/ (2 providers — merge with starter kit providers if conflicts)
  - app/Services/ (7 services)
  - app/Enums/ (7 enums)
  - app/Jobs/ (1 job)
  - app/Mail/ (1 mailable)
  - config/spendwise.php, config/services.php, config/fortify.php (merge, don't overwrite)
  - routes/api.php, routes/web.php, routes/console.php (merge with starter kit routes)
  - database/migrations/ (5 migrations)
  - database/seeders/ExpenseCategorySeeder.php
  - resources/scripts/ (Python tax export scripts)
  - resources/views/emails/ (blade email template)
  - bootstrap/app.php (merge middleware config)

DO NOT overwrite the starter kit's auth pages, layouts, or base config files.
MERGE route files and providers carefully.
```

### Task 1.3: Database Setup
```
Run migrations and seed:
  php artisan migrate
  php artisan db:seed --class=ExpenseCategorySeeder
Verify all 14+ tables created successfully.
Test: php artisan tinker → User::factory()->create() works.
```

---

## PHASE 2: SPLIT CONTROLLERS [run after Phase 1]

### Task 2.1: Create 10 API Controllers
```
Split SpendWiseController.php into 10 focused controllers.
Each should inject its required service(s) via constructor DI.
Reference routes/api.php for the exact method → controller mapping.

Create these in app/Http/Controllers/Api/:
  1. DashboardController — dashboard()
  2. PlaidController — createLinkToken(), exchangeToken(), sync(), disconnect()
  3. BankAccountController — index(), updatePurpose()
  4. TransactionController — index(), updateCategory()
  5. AIQuestionController — index(), answer(), bulkAnswer()
  6. SubscriptionController — index(), detect()
  7. SavingsController — recommendations(), analyze(), dismiss(), apply(),
     setTarget(), getTarget(), regeneratePlan(), respondToAction(), pulseCheck()
  8. TaxController — summary(), export(), sendToAccountant(), download()
  9. EmailConnectionController — connect(), callback(), sync(), disconnect()
  10. UserProfileController — updateFinancial(), showFinancial(), deleteAccount()

After creating, delete SpendWiseController.php.
Test: php artisan route:list --path=api should show all routes resolving.
```

### Task 2.2: Create API Resources
```
Create in app/Http/Resources/:
  - TransactionResource (include category accessor, hide plaid internals)
  - TransactionCollection (with pagination meta)
  - BankAccountResource (hide plaid_account_id, include relationship to connection)
  - BankConnectionResource (hide all plaid tokens/cursors)
  - SubscriptionResource (include annual_cost, next_charge_at)
  - AIQuestionResource (include transaction snippet for context)
  - SavingsRecommendationResource
  - SavingsTargetResource (include actions)
  - DashboardResource (composite: spending_summary, recent_transactions, pending_questions_count, active_subscriptions_count)

Update all controllers to return Resources instead of raw model JSON.
```

### Task 2.3: Create Form Requests
```
Create in app/Http/Requests/Api/:
  - UpdateAccountPurposeRequest (purpose: required, in:personal,business,mixed,investment)
  - AnswerQuestionRequest (answer: required|string)
  - BulkAnswerRequest (answers: required|array, answers.*.question_id, answers.*.answer)
  - UpdateTransactionCategoryRequest (category: required|string)
  - ExportTaxRequest (year: required|integer, include_personal: boolean)
  - SendToAccountantRequest (email: required|email, cc_self: boolean, message: nullable|string)
  - SetSavingsTargetRequest (goal_name, target_amount, deadline, monthly_target)
  - UpdateFinancialProfileRequest (employment_type, business_type, tax_filing_status, etc.)

Update controllers to use these instead of inline validation.
```

---

## PHASE 3: PLAID WEBHOOK + EVENTS [run after Phase 2]

### Task 3.1: Plaid Webhook Handler
```
Create app/Http/Controllers/Api/PlaidWebhookController.php:
  - POST /api/v1/webhooks/plaid (exempt from auth + CSRF)
  - Verify Plaid webhook signature (Plaid-Verification header)
  - Handle webhook types:
    TRANSACTIONS → SYNC_UPDATES_AVAILABLE: dispatch SyncBankTransactions for the item
    TRANSACTIONS → DEFAULT_UPDATE: dispatch SyncBankTransactions
    TRANSACTIONS → TRANSACTIONS_REMOVED: delete matching transactions
    ITEM → ERROR: mark BankConnection status = error, log error
    ITEM → PENDING_EXPIRATION: notify user to re-authenticate
    ITEM → USER_PERMISSION_REVOKED: call PlaidService::disconnect()
  - Log all webhooks
  - Return 200 OK always (Plaid retries on non-200)

Add route in routes/api.php (outside auth middleware).
Add webhook URL to Plaid dashboard config.
```

### Task 3.2: Events & Listeners
```
Create events and listeners:

Events (app/Events/):
  - BankConnected (connection_id, user_id)
  - TransactionsImported (user_id, count, connection_id)
  - TransactionCategorized (transaction_id, category, confidence)
  - UserAnsweredQuestion (question_id, answer)

Listeners (app/Listeners/):
  - TriggerInitialSync → listens BankConnected → dispatches SyncBankTransactions
  - DispatchCategorization → listens TransactionsImported → dispatches CategorizePendingTransactions
  - UpdateSubscriptionDetection → listens TransactionCategorized → checks if recurring
  - ApplyCategoryFromAnswer → listens UserAnsweredQuestion → updates transaction

Register in EventServiceProvider.
Fire events from appropriate service methods and controllers.
```

### Task 3.3: Additional Jobs
```
Create in app/Jobs/:
  - SyncBankTransactions (accepts BankConnection, calls PlaidService::syncTransactions)
  - DetectSubscriptions (accepts User, calls SubscriptionDetectorService)
  - GenerateSavingsAnalysis (accepts User, calls SavingsAnalyzerService)
  - ReconcileTransactionsWithOrders (accepts User, calls ReconciliationService)

All jobs should: use Redis queue, implement ShouldQueue, have tries=3, timeout=120.
```

---

## PHASE 4: FRONTEND [run after Phase 3]

### Task 4.1: Install Frontend Dependencies
```
npm install react-plaid-link chart.js react-chartjs-2
```

### Task 4.2: Build Inertia Pages
```
Create TypeScript React pages in resources/js/Pages/:

Dashboard.tsx:
  - Spending summary cards (total this month, vs last month %)
  - Category breakdown donut chart (Chart.js)
  - Recent transactions list (last 10)
  - Pending AI questions alert banner
  - Connected accounts summary

Transactions/Index.tsx:
  - Filter bar: date range, category dropdown, business/personal toggle, search
  - Transaction table with columns: date, merchant, amount, category, account, status
  - Inline category edit (click category → dropdown)
  - Pagination

Subscriptions/Index.tsx:
  - Grid of subscription cards
  - Each card: merchant, amount, frequency, status badge, last charged
  - "Unused" warning badge on inactive subscriptions
  - Total monthly/annual cost summary

Savings/Index.tsx:
  - Savings target progress gauge
  - Recommendation cards (dismissible, with action steps)
  - "Set a Target" form
  - Pulse check summary

Tax/Index.tsx:
  - Tax year selector
  - Deduction summary by Schedule C line
  - Business vs personal spending chart
  - Export button → modal with format options
  - "Send to Accountant" button → email form modal

Connect/Index.tsx:
  - "Connect Bank" button using react-plaid-link
  - List of connected accounts with status
  - "Connect Email" button for receipt parsing
  - Disconnect buttons

Settings/Profile.tsx:
  - Financial profile form (employment type, business type, tax status, income)
  - Security settings (change password, enable/disable 2FA)
  - "Delete Account" with confirmation modal

Questions/Index.tsx:
  - List of pending AI questions
  - Each question shows transaction context (merchant, amount, date)
  - Multiple choice or free text answer input
  - Bulk answer mode
```

### Task 4.3: Shared Components
```
Create in resources/js/Components/:
  - PlaidLinkButton.tsx — Wraps react-plaid-link, handles token creation + exchange
  - SpendingChart.tsx — Chart.js donut/bar chart for spending by category
  - TransactionRow.tsx — Table row with inline category editing
  - SubscriptionCard.tsx — Card with status badge and cost info
  - RecommendationCard.tsx — Savings recommendation with dismiss/apply
  - QuestionCard.tsx — AI question with answer options
  - ViewModeToggle.tsx — Personal / Business / All tabs
  - ExportModal.tsx — Tax export format selection
  - ConfirmDialog.tsx — Reusable confirmation modal
```

---

## PHASE 5: TESTING [run after Phase 4]

### Task 5.1: Create Factories
```
Create in database/factories/:
  - UserFactory (already exists from starter kit — extend with financial profile)
  - BankConnectionFactory
  - BankAccountFactory (with purpose variations)
  - TransactionFactory (with realistic merchant names, amounts, categories)
  - SubscriptionFactory
  - AIQuestionFactory
  - SavingsRecommendationFactory
  - OrderFactory + OrderItemFactory
```

### Task 5.2: Feature Tests
```
Create in tests/Feature/:
  - AuthTest — register, login, logout, 2FA enable/verify, Google OAuth mock
  - PlaidTest — link token, exchange (mock Plaid API), sync, disconnect
  - TransactionTest — list with filters, update category, policy enforcement
  - AIQuestionTest — list pending, answer, bulk answer
  - SubscriptionTest — list, detect
  - SavingsTest — recommendations, set target, respond to action
  - TaxTest — summary, export, send to accountant
  - AccountDeletionTest — delete account cascades properly

Mock external APIs (Plaid, Anthropic) using Http::fake().
```

### Task 5.3: Unit Tests
```
Create in tests/Unit/:
  - TransactionCategorizerServiceTest — confidence threshold routing, batch processing
  - SubscriptionDetectorServiceTest — recurrence pattern detection
  - TaxExportServiceTest — Schedule C mapping accuracy
  - CaptchaServiceTest — score threshold, action validation
```

---

## PHASE 6: DEPLOYMENT [run last]

### Task 6.1: Docker Setup
```
Create:
  - Dockerfile (PHP 8.2 + extensions + Node for asset building)
  - docker-compose.yml (app, postgres, redis, queue-worker, scheduler)
  - .dockerignore
  - nginx/default.conf (with TLS config, security headers)
```

### Task 6.2: CI/CD
```
Create .github/workflows/ci.yml:
  - Run: composer install, npm install, npm run build
  - Run: php artisan test
  - Run: composer audit, npm audit
  - Deploy on push to main
```
