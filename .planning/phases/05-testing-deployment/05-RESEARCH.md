# Phase 5: Testing & Deployment - Research

**Researched:** 2026-02-11
**Domain:** Pest PHP testing, Laravel model factories, GitHub Actions CI, PostgreSQL test infrastructure
**Confidence:** HIGH

## Summary

Phase 5 covers creating model factories for all 18 models (16 original + PlaidWebhookLog + SavingsProgress), writing feature tests for 8 critical flows and unit tests for 4 services, setting up GitHub Actions CI, and creating a production environment template.

The project already has Pest PHP 3.7 with the Laravel plugin installed, a working `Pest.php` configuration binding `RefreshDatabase` to feature tests, an existing `UserFactory`, and basic Breeze auth tests. The existing `phpunit.xml` is configured for SQLite in-memory testing, which will NOT work with this project because migration `000005_encrypt_sensitive_columns` uses `->change()` to convert JSON/DECIMAL columns to TEXT -- SQLite does not support native column modification. The test database configuration MUST be switched to PostgreSQL.

**Primary recommendation:** Switch phpunit.xml from SQLite to PostgreSQL (via `.env.testing`), add `HasFactory` trait to all 17 non-User models, create all 17 missing factories using the existing enum values, write tests using `Http::fake()` and `Mockery` to isolate external services (Plaid API, Anthropic API, reCAPTCHA), and set up GitHub Actions with PostgreSQL + Redis service containers.

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| pestphp/pest | ^3.7 | Test framework | Already installed; clean syntax, Laravel-native |
| pestphp/pest-plugin-laravel | ^3.1 | Laravel bindings for Pest | Already installed; provides `actingAs`, Artisan helpers |
| mockery/mockery | ^1.6 | Mock/spy/stub external dependencies | Already installed; standard Laravel mocking library |
| fakerphp/faker | ^1.23 | Generate realistic test data | Already installed; used by factories |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| laravel/pint | ^1.24 | Code style linting | Already installed; run in CI pipeline |
| Http::fake() | (built-in) | Mock HTTP calls to Plaid/Anthropic/reCAPTCHA | Every test touching external APIs |
| Mail::fake() | (built-in) | Assert emails sent without SMTP | Tax export send-to-accountant tests |
| Queue::fake() | (built-in) | Assert jobs dispatched without processing | Tests checking job dispatch |
| Event::fake() | (built-in) | Assert events fired without listeners | Tests checking event dispatch |
| Notification::fake() | (built-in) | Assert notifications queued | Future notification tests |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Pest | PHPUnit | PHPUnit already present; Pest wraps it. Project chose Pest -- stick with it |
| Http::fake() | Mockery for service classes | Http::fake is simpler for HTTP mocking; use Mockery only when injecting service mocks |
| PostgreSQL test DB | SQLite in-memory | SQLite cannot run ->change() migrations in this project; PostgreSQL is mandatory |

**Installation:**
No additional packages needed. All testing dependencies are already in `composer.json`. Only infrastructure configuration is required.

## Architecture Patterns

### Recommended Test Structure
```
tests/
├── Pest.php                      # Already exists: binds RefreshDatabase to Feature
├── TestCase.php                  # Already exists: base test case
├── Feature/
│   ├── Auth/                     # Already has basic Breeze tests
│   │   ├── ApiAuthTest.php       # NEW: API auth (register/login/logout/2FA via /api/auth)
│   │   └── ...                   # Keep existing Breeze tests
│   ├── Plaid/
│   │   └── PlaidFlowTest.php     # NEW: link token, exchange, sync, disconnect
│   ├── Transaction/
│   │   └── TransactionTest.php   # NEW: list with filters, category update
│   ├── AIQuestion/
│   │   └── AIQuestionTest.php    # NEW: list, answer, bulk answer
│   ├── Subscription/
│   │   └── SubscriptionTest.php  # NEW: list, detect
│   ├── Savings/
│   │   └── SavingsTest.php       # NEW: recommendations, set target, respond to action
│   ├── Tax/
│   │   └── TaxTest.php           # NEW: summary, export, send to accountant
│   └── Account/
│       └── AccountDeletionTest.php # NEW: cascade deletion verification
├── Unit/
│   ├── Services/
│   │   ├── TransactionCategorizerServiceTest.php  # Confidence routing
│   │   ├── SubscriptionDetectorServiceTest.php    # Recurrence detection
│   │   ├── TaxExportServiceTest.php               # Schedule C mapping
│   │   └── CaptchaServiceTest.php                 # Score thresholds
│   └── ExampleTest.php           # Already exists
database/
└── factories/
    ├── UserFactory.php            # Already exists; needs enhancement for 2FA/Google fields
    ├── BankConnectionFactory.php  # NEW
    ├── BankAccountFactory.php     # NEW
    ├── TransactionFactory.php     # NEW
    ├── SubscriptionFactory.php    # NEW
    ├── AIQuestionFactory.php      # NEW
    ├── EmailConnectionFactory.php # NEW
    ├── ParsedEmailFactory.php     # NEW
    ├── OrderFactory.php           # NEW
    ├── OrderItemFactory.php       # NEW
    ├── ExpenseCategoryFactory.php # NEW
    ├── SavingsRecommendationFactory.php # NEW
    ├── SavingsTargetFactory.php   # NEW
    ├── SavingsPlanActionFactory.php     # NEW
    ├── SavingsProgressFactory.php       # NEW
    ├── BudgetGoalFactory.php      # NEW
    ├── UserFinancialProfileFactory.php  # NEW
    └── PlaidWebhookLogFactory.php       # NEW
```

### Pattern 1: Authenticated API Test with Sanctum
**What:** Test pattern for all API endpoints that require auth:sanctum middleware
**When to use:** Every feature test hitting `/api/v1/*` or `/api/auth/*` (authenticated) routes
**Example:**
```php
// Source: Laravel Sanctum docs + project's auth setup
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('can list transactions', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Create prerequisite: active bank connection (satisfies bank.connected middleware)
    $connection = BankConnection::factory()->for($user)->active()->create();
    $account = BankAccount::factory()->for($user)->for($connection)->create();
    Transaction::factory()->for($user)->for($account)->count(5)->create();

    $response = $this->getJson('/api/v1/transactions');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['id', 'merchant_name', 'amount']]]);
});
```

### Pattern 2: Mocking External HTTP with Http::fake
**What:** Prevent real API calls to Plaid, Anthropic, or Google reCAPTCHA during tests
**When to use:** Any test that exercises code paths calling external APIs
**Example:**
```php
// Source: Laravel HTTP Client docs
use Illuminate\Support\Facades\Http;

test('can exchange plaid token', function () {
    Http::fake([
        'sandbox.plaid.com/item/public_token/exchange' => Http::response([
            'access_token' => 'access-sandbox-test',
            'item_id' => 'item-sandbox-test',
        ]),
        'sandbox.plaid.com/item/get' => Http::response([
            'item' => ['institution_id' => 'ins_109508'],
        ]),
        'sandbox.plaid.com/institutions/get_by_id' => Http::response([
            'institution' => ['name' => 'Chase'],
        ]),
        'sandbox.plaid.com/accounts/get' => Http::response([
            'accounts' => [
                [
                    'account_id' => 'acc_test_123',
                    'name' => 'Checking',
                    'type' => 'depository',
                    'subtype' => 'checking',
                    'mask' => '1234',
                    'balances' => ['current' => 1000, 'available' => 900],
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/plaid/exchange', [
        'public_token' => 'public-sandbox-test',
    ]);

    $response->assertOk()
        ->assertJsonFragment(['institution' => 'Chase']);
});
```

### Pattern 3: Factory States with Enums
**What:** Use factory states to create models in specific statuses matching the PHP backed enums
**When to use:** When tests need models in specific states (active subscription, pending question, etc.)
**Example:**
```php
// Source: Laravel Eloquent Factories docs
use App\Enums\ConnectionStatus;
use App\Enums\AccountPurpose;

class BankConnectionFactory extends Factory
{
    protected $model = BankConnection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plaid_item_id' => 'item_' . fake()->uuid(),
            'plaid_access_token' => 'access-sandbox-' . fake()->uuid(),
            'institution_name' => fake()->randomElement(['Chase', 'Bank of America', 'Wells Fargo']),
            'institution_id' => 'ins_' . fake()->randomNumber(6),
            'status' => ConnectionStatus::Active,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => ConnectionStatus::Active, 'last_synced_at' => now()]);
    }

    public function error(): static
    {
        return $this->state([
            'status' => ConnectionStatus::Error,
            'error_code' => 'ITEM_LOGIN_REQUIRED',
            'error_message' => 'User login required',
        ]);
    }
}
```

### Pattern 4: Unit Testing Services with Mockery
**What:** Test service logic in isolation by mocking database and HTTP dependencies
**When to use:** Unit tests for TransactionCategorizerService, SubscriptionDetectorService, etc.
**Example:**
```php
// Source: Pest mocking docs + Mockery
use App\Services\AI\TransactionCategorizerService;

test('auto-categorizes high confidence transactions', function () {
    // The service calls Claude API -- we mock Http::fake to return controlled results
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([
                [
                    'id' => 1,
                    'category' => 'Food & Groceries',
                    'confidence' => 0.92,
                    'expense_type' => 'personal',
                    'tax_deductible' => false,
                    'tax_category' => null,
                    'is_subscription' => false,
                    'merchant_normalized' => 'Whole Foods',
                    'reasoning' => 'Grocery store',
                    'uncertain_about' => null,
                    'suggested_question' => null,
                    'question_type' => null,
                    'question_options' => null,
                ],
            ])]],
        ]),
    ]);

    // Create real DB records for the service to use
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $account = BankAccount::factory()->for($user)->for($connection)->create();
    $tx = Transaction::factory()->for($user)->for($account)->create([
        'merchant_name' => 'WHOLE FOODS MKT',
        'amount' => 85.47,
        'review_status' => 'pending_ai',
    ]);

    $service = new TransactionCategorizerService();
    $result = $service->categorizeBatch(collect([$tx]), $user->id);

    expect($result['auto_categorized'])->toBe(1);
    expect($result['needs_review'])->toBe(0);
    expect($tx->fresh()->ai_category)->toBe('Food & Groceries');
    expect($tx->fresh()->review_status)->toBe('auto_categorized');
});
```

### Pattern 5: Testing with Middleware Bypass for Focused Tests
**What:** Disable certain middleware (captcha, bank.connected) in focused tests
**When to use:** When testing controller logic and middleware is separately tested
**Example:**
```php
// Disable captcha for auth tests since captcha is tested separately
test('can register via API', function () {
    // Captcha is disabled in testing (config returns false)
    config(['spendwise.captcha.enabled' => false]);

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['token', 'user']);
});
```

### Anti-Patterns to Avoid
- **Hitting real external APIs:** NEVER make real Plaid, Anthropic, or Google API calls in tests. Always use Http::fake().
- **Testing implementation details:** Test behavior and outcomes, not internal method calls.
- **Massive test setup in every test:** Use factory states and helper functions in Pest.php to reduce duplication.
- **Using SQLite for this project:** Migration 000005 uses ->change() which is incompatible with SQLite. Use PostgreSQL.
- **Testing encrypted values directly:** Don't assert raw encrypted ciphertext in the database. Test through model accessors that auto-decrypt.
- **Skipping middleware tests:** The `bank.connected` and `profile.complete` middleware are business-critical guards -- test them explicitly.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HTTP mocking | Custom HTTP interceptor | `Http::fake()` | Built into Laravel, handles all edge cases |
| Auth testing | Manual token creation | `Sanctum::actingAs($user)` | Official Sanctum testing helper |
| DB cleanup | Manual truncation | `RefreshDatabase` trait | Already configured in Pest.php |
| Mail assertion | SMTP test server | `Mail::fake()` + `Mail::assertSent()` | Built-in, zero config |
| Queue assertion | Queue monitoring | `Queue::fake()` + `Queue::assertPushed()` | Built-in, zero config |
| Factory sequences | Manual counter tracking | `Factory::sequence()` | Built into Eloquent factories |

**Key insight:** Laravel's testing infrastructure is extremely mature. Every external service (HTTP, mail, queue, events, notifications, storage) has a built-in fake/mock. Use them.

## Common Pitfalls

### Pitfall 1: SQLite Cannot Run ->change() Migrations
**What goes wrong:** Running `php artisan test` with SQLite will fail on migration `000005_encrypt_sensitive_columns` because SQLite does not support native column type modification (JSON to TEXT, DECIMAL to TEXT).
**Why it happens:** The phpunit.xml defaults to `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Laravel 12 dropped Doctrine DBAL dependency but did not add native SQLite column modification support.
**How to avoid:** Create `.env.testing` that configures PostgreSQL as the test database. Update `phpunit.xml` to remove the SQLite overrides and let `.env.testing` take precedence.
**Warning signs:** `SQLSTATE[HY000]: General error: 1 near "MODIFY"` or similar migration error during test run.

### Pitfall 2: Captcha Middleware Blocking Test Requests
**What goes wrong:** The `RegisterRequest` and `LoginRequest` conditionally require `captcha_token` and verify it through `CaptchaService`. Tests will fail unless captcha is disabled or faked.
**Why it happens:** Config `spendwise.captcha.enabled` reads from `.env` -- if `RECAPTCHA_SITE_KEY` is set, captcha is enabled.
**How to avoid:** In `.env.testing`, do NOT set `RECAPTCHA_SITE_KEY` (leaving captcha disabled). Alternatively, set `config(['spendwise.captcha.enabled' => false])` in test setup.
**Warning signs:** Validation error "The captcha_token field is required" in auth tests.

### Pitfall 3: Encrypted Fields in Factories
**What goes wrong:** Factories must provide plain values for encrypted fields (e.g., `plaid_access_token`, `monthly_income`, `custom_rules`). The model cast handles encryption automatically.
**Why it happens:** Model casts `'encrypted'` and `'encrypted:array'` auto-encrypt on write and auto-decrypt on read. Factories just provide the plaintext value.
**How to avoid:** In factories, set encrypted fields to plain values: `'plaid_access_token' => 'access-sandbox-' . fake()->uuid()`, `'monthly_income' => '5000.00'`, `'custom_rules' => ['rule1' => 'value1']`.
**Warning signs:** Garbled values or double-encryption when reading factory-created models.

### Pitfall 4: bank.connected Middleware on Most API Routes
**What goes wrong:** Most `/api/v1/*` endpoints (transactions, questions, subscriptions, savings, tax) are behind the `bank.connected` middleware. Tests hitting these endpoints without creating an active BankConnection get 403 "Please connect a bank account first."
**Why it happens:** The middleware checks `$request->user()->hasBankConnected()` which queries `bank_connections` for `status = 'active'`.
**How to avoid:** In feature tests, always create a `BankConnection::factory()->for($user)->active()->create()` before hitting protected endpoints.
**Warning signs:** 403 response with `{"message":"Please connect a bank account first.","action":"connect_bank"}`.

### Pitfall 5: profile.complete Middleware on Tax Routes
**What goes wrong:** Tax endpoints (`/api/v1/tax/*`) are behind the `profile.complete` middleware. Tests fail with 403 unless the user has a `UserFinancialProfile` with `employment_type` set.
**Why it happens:** The middleware checks `$request->user()->hasProfileComplete()` which queries `user_financial_profiles` for non-null `employment_type`.
**How to avoid:** Create `UserFinancialProfile::factory()->for($user)->create(['employment_type' => 'self_employed'])` before tax tests.
**Warning signs:** 403 response with `{"message":"Please complete your financial profile..."}`.

### Pitfall 6: Foreign Key Constraints in Factory Order
**What goes wrong:** Creating a `Transaction` requires `user_id` AND `bank_account_id`. Creating a `BankAccount` requires `bank_connection_id`. Creating an `AIQuestion` requires both `user_id` and `transaction_id`. Factories must chain correctly.
**Why it happens:** PostgreSQL enforces foreign key constraints strictly.
**How to avoid:** Define factory relationships using `User::factory()`, `BankConnection::factory()`, etc. in the factory definition. Use `->for()` to bind specific parents.
**Warning signs:** `SQLSTATE[23503]: Foreign key violation` during test data creation.

### Pitfall 7: Enum Values in Factories
**What goes wrong:** Transaction `expense_type`, `review_status`, `account_purpose` etc. are backed by PHP enums. Setting string values in factories that don't match enum cases causes errors.
**Why it happens:** Models use enum casts (e.g., `'expense_type' => ExpenseType::class`). The database stores the string backing value, but Laravel expects the enum case.
**How to avoid:** Use the actual enum values in factories: `'expense_type' => ExpenseType::Personal`, `'status' => ConnectionStatus::Active`, etc.
**Warning signs:** `ValueError: "invalid_value" is not a valid backing value for enum`.

### Pitfall 8: Testing TaxExportService Calls Python Scripts
**What goes wrong:** The `TaxExportService::generateExcel()` and `generatePDF()` methods shell out to Python scripts (`generate_tax_excel.py`, `generate_tax_pdf.py`). These will fail in CI unless Python + dependencies are installed.
**Why it happens:** The service uses `shell_exec('python3 ...')` to generate XLSX and PDF files.
**How to avoid:** For unit tests of `TaxExportService`, test only `gatherTaxData()` and `mapToScheduleC()` (the data gathering and mapping logic). Mock or skip the file generation methods. In the CI pipeline, either install Python + openpyxl + reportlab, or focus tests on the data logic not file output.
**Warning signs:** `RuntimeException: Excel generation failed` or `RuntimeException: PDF generation failed`.

### Pitfall 9: HasFactory Trait Missing on 17 Models (CONFIRMED)
**What goes wrong:** Calling `Model::factory()` on any model without the `HasFactory` trait throws `BadMethodCallException: Call to undefined method Model::factory()`.
**Why it happens:** Only `User` model has `use HasFactory`. All other 17 models (BankConnection, BankAccount, Transaction, Subscription, AIQuestion, EmailConnection, ParsedEmail, Order, OrderItem, ExpenseCategory, SavingsRecommendation, SavingsTarget, SavingsPlanAction, SavingsProgress, BudgetGoal, UserFinancialProfile, PlaidWebhookLog) do NOT have the `HasFactory` trait.
**How to avoid:** Add `use Illuminate\Database\Eloquent\Factories\HasFactory;` import and `use HasFactory;` trait to each of the 17 models BEFORE creating their factory files.
**Warning signs:** `BadMethodCallException: Call to undefined method App\Models\BankConnection::factory()`.

## Code Examples

### Helper Functions for Pest.php
```php
// Add to tests/Pest.php for reuse across tests
function createAuthenticatedUser(array $attrs = []): \App\Models\User
{
    $user = \App\Models\User::factory()->create($attrs);
    \Laravel\Sanctum\Sanctum::actingAs($user);
    return $user;
}

function createUserWithBank(array $userAttrs = []): array
{
    $user = createAuthenticatedUser($userAttrs);
    $connection = \App\Models\BankConnection::factory()->for($user)->active()->create();
    $account = \App\Models\BankAccount::factory()->for($user)->for($connection)->create();
    return compact('user', 'connection', 'account');
}

function createUserWithBankAndProfile(array $userAttrs = []): array
{
    $data = createUserWithBank($userAttrs);
    $profile = \App\Models\UserFinancialProfile::factory()->for($data['user'])->create([
        'employment_type' => 'self_employed',
    ]);
    return array_merge($data, ['profile' => $profile]);
}
```

### Transaction Factory with States
```php
// database/factories/TransactionFactory.php
use App\Enums\AccountPurpose;
use App\Enums\ExpenseType;
use App\Enums\ReviewStatus;

class TransactionFactory extends Factory
{
    protected $model = \App\Models\Transaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_account_id' => BankAccount::factory(),
            'plaid_transaction_id' => 'txn_' . fake()->uuid(),
            'merchant_name' => fake()->company(),
            'merchant_normalized' => null,
            'description' => fake()->sentence(4),
            'amount' => fake()->randomFloat(2, 1, 500),
            'transaction_date' => fake()->dateTimeBetween('-6 months'),
            'payment_channel' => fake()->randomElement(['online', 'in store', 'other']),
            'plaid_category' => fake()->randomElement(['FOOD_AND_DRINK', 'SHOPPING', 'TRANSPORTATION']),
            'expense_type' => ExpenseType::Personal,
            'account_purpose' => AccountPurpose::Personal,
            'review_status' => ReviewStatus::PendingAI,
            'tax_deductible' => false,
            'is_subscription' => false,
        ];
    }

    public function categorized(): static
    {
        return $this->state([
            'ai_category' => fake()->randomElement(['Food & Groceries', 'Restaurant & Dining', 'Shopping (General)']),
            'ai_confidence' => fake()->randomFloat(2, 0.85, 0.99),
            'review_status' => ReviewStatus::AutoCategorized,
        ]);
    }

    public function needsReview(): static
    {
        return $this->state([
            'ai_category' => 'Uncategorized',
            'ai_confidence' => fake()->randomFloat(2, 0.30, 0.59),
            'review_status' => ReviewStatus::NeedsReview,
        ]);
    }

    public function business(): static
    {
        return $this->state([
            'expense_type' => ExpenseType::Business,
            'account_purpose' => AccountPurpose::Business,
            'tax_deductible' => true,
        ]);
    }

    public function deductible(): static
    {
        return $this->state([
            'tax_deductible' => true,
            'tax_category' => fake()->randomElement(['Office Supplies', 'Software & SaaS', 'Business Meals']),
        ]);
    }

    public function subscription(): static
    {
        return $this->state([
            'is_subscription' => true,
            'merchant_name' => fake()->randomElement(['NETFLIX', 'SPOTIFY', 'ADOBE']),
        ]);
    }
}
```

### GitHub Actions CI Workflow
```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
    branches: [main, master]
  pull_request:
    branches: [main, master]

jobs:
  tests:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgres:15
        env:
          POSTGRES_USER: spendwise_test
          POSTGRES_PASSWORD: password
          POSTGRES_DB: spendwise_test
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

      redis:
        image: redis:7
        ports:
          - 6379:6379
        options: >-
          --health-cmd "redis-cli ping"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    env:
      APP_ENV: testing
      APP_KEY: base64:dGVzdGtleWZvcmNpMTIzNDU2Nzg5MDEyMzQ1Njc4OTA=
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_DATABASE: spendwise_test
      DB_USERNAME: spendwise_test
      DB_PASSWORD: password
      CACHE_STORE: array
      QUEUE_CONNECTION: sync
      SESSION_DRIVER: array
      MAIL_MAILER: array
      BROADCAST_CONNECTION: log

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, dom, fileinfo, pgsql, redis
          tools: composer:v2
          coverage: none

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install PHP dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install Node dependencies
        run: npm ci

      - name: Build frontend assets
        run: npm run build

      - name: Run Laravel Pint (lint)
        run: vendor/bin/pint --test

      - name: Run migrations
        run: php artisan migrate --force

      - name: Run tests
        run: vendor/bin/pest --ci
```

### .env.testing Configuration
```bash
APP_NAME=SpendWise
APP_ENV=testing
APP_KEY=base64:dGVzdGtleWZvcmNpMTIzNDU2Nzg5MDEyMzQ1Njc4OTA=
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=spendwise_test
DB_USERNAME=spendwise
DB_PASSWORD=

CACHE_STORE=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
MAIL_MAILER=array

# Captcha DISABLED for tests (no RECAPTCHA_SITE_KEY = disabled)
# External APIs: use Http::fake() in tests instead
```

## Critical SQLite Incompatibility

**MUST change phpunit.xml** -- Remove these lines:
```xml
<!-- REMOVE THESE: -->
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Replace with:
```xml
<!-- Use .env.testing instead (PostgreSQL) -->
```

**Reason:** Migration `2026_02_10_000005_encrypt_sensitive_columns.php` uses `->change()` to convert `plaid_metadata` (JSON->TEXT), `raw_parsed_data` (JSON->TEXT), `monthly_income` (DECIMAL->TEXT), and `custom_rules` (JSON->TEXT). SQLite does not support column type modification via `ALTER TABLE ... MODIFY COLUMN`. PostgreSQL handles this natively.

## Test Database Setup

The CI pipeline and local testing both need a PostgreSQL `spendwise_test` database.

**Local setup:**
```bash
createdb spendwise_test
# or
psql -c "CREATE DATABASE spendwise_test;"
```

**CI setup:** Handled by the PostgreSQL service container in GitHub Actions (see workflow above).

## All 18 Models Needing Factories

| # | Model | Factory Exists | HasFactory Trait | Key Fields | Dependencies |
|---|-------|---------------|-----------------|------------|--------------|
| 1 | User | YES (needs enhancement) | YES | name, email, password | None |
| 2 | BankConnection | NO | **MISSING** | user_id, plaid_item_id, plaid_access_token (encrypted), institution_name, status | User |
| 3 | BankAccount | NO | **MISSING** | user_id, bank_connection_id, plaid_account_id, name, type, purpose | User, BankConnection |
| 4 | Transaction | NO | **MISSING** | user_id, bank_account_id, plaid_transaction_id, merchant_name, amount, transaction_date, expense_type, review_status, account_purpose | User, BankAccount |
| 5 | Subscription | NO | **MISSING** | user_id, merchant_name, merchant_normalized, amount, frequency, status | User |
| 6 | AIQuestion | NO | **MISSING** | user_id, transaction_id, question, options (array), question_type, ai_confidence, status | User, Transaction |
| 7 | EmailConnection | NO | **MISSING** | user_id, provider, email_address, access_token (encrypted), refresh_token (encrypted), status | User |
| 8 | ParsedEmail | NO | **MISSING** | user_id, email_connection_id, email_message_id, raw_parsed_data (encrypted:array) | User, EmailConnection |
| 9 | Order | NO | **MISSING** | user_id, parsed_email_id, merchant, order_date, total | User, ParsedEmail |
| 10 | OrderItem | NO | **MISSING** | user_id, order_id, product_name, quantity, unit_price, total_price | User, Order |
| 11 | ExpenseCategory | NO | **MISSING** | name, slug, is_system, is_typically_deductible, keywords (array) | None (user_id nullable) |
| 12 | SavingsRecommendation | NO | **MISSING** | user_id, title, description, monthly_savings, annual_savings, difficulty, impact, category, status, generated_at | User |
| 13 | SavingsTarget | NO | **MISSING** | user_id, monthly_target, target_start_date, is_active | User |
| 14 | SavingsPlanAction | NO | **MISSING** | user_id, savings_target_id, title, description, how_to, monthly_savings, current_spending, recommended_spending, category, difficulty, impact, priority, status | User, SavingsTarget |
| 15 | SavingsProgress | NO | **MISSING** | user_id, savings_target_id, month, income, total_spending, actual_savings, target_savings | User, SavingsTarget |
| 16 | BudgetGoal | NO | **MISSING** | user_id, category, monthly_limit | User |
| 17 | UserFinancialProfile | NO | **MISSING** | user_id, employment_type, has_home_office, monthly_income (encrypted), custom_rules (encrypted:array) | User |
| 18 | PlaidWebhookLog | NO | **MISSING** | webhook_type, webhook_code, item_id, payload (array), status | None |

## Enum Reference for Factories

All factories MUST use the actual enum values, not raw strings:

| Enum | Cases | Used By |
|------|-------|---------|
| `ConnectionStatus` | Active, Error, Disconnected, Pending | BankConnection.status, EmailConnection.status |
| `AccountPurpose` | Personal, Business, Mixed, Investment | BankAccount.purpose, Transaction.account_purpose |
| `ExpenseType` | Personal, Business, Mixed | Transaction.expense_type |
| `ReviewStatus` | PendingAI, NeedsReview, UserConfirmed, AIUncertain, AutoCategorized | Transaction.review_status |
| `QuestionType` | Category, BusinessPersonal, Split, Confirm | AIQuestion.question_type |
| `QuestionStatus` | Pending, Answered, Skipped, Expired | AIQuestion.status |
| `SubscriptionStatus` | Active, Unused, Cancelled | Subscription.status |

## Feature Test Requirements Map

| Requirement | Test File | Endpoints Tested | Key Assertions |
|-------------|-----------|------------------|----------------|
| TEST-02 | Feature/Auth/ApiAuthTest.php | POST /api/auth/register, POST /api/auth/login, POST /api/auth/logout, GET /api/auth/me | Token returned on register/login, 2FA prompt when enabled, lockout after 5 failures, logout revokes token |
| TEST-03 | Feature/Plaid/PlaidFlowTest.php | POST /api/v1/plaid/link-token, POST /api/v1/plaid/exchange, POST /api/v1/plaid/sync, DELETE /api/v1/plaid/{connection} | Link token created, bank connected, transactions synced, connection deleted; ALL use Http::fake for Plaid |
| TEST-04 | Feature/Transaction/TransactionTest.php | GET /api/v1/transactions, PATCH /api/v1/transactions/{id}/category | Filters (purpose, category, date, search), pagination, user_category update sets review_status=user_confirmed |
| TEST-05 | Feature/AIQuestion/AIQuestionTest.php | GET /api/v1/questions, POST /api/v1/questions/{id}/answer, POST /api/v1/questions/bulk-answer | Pending questions listed, answer updates transaction, bulk processes multiple, skip leaves needs_review |
| TEST-06 | Feature/Subscription/SubscriptionTest.php | GET /api/v1/subscriptions, POST /api/v1/subscriptions/detect | Subscriptions listed with totals, detection finds recurring charges in test data |
| TEST-07 | Feature/Savings/SavingsTest.php | GET /api/v1/savings, POST /api/v1/savings/target, POST /api/v1/savings/plan/{action}/respond | Recommendations listed, target created, action accept/reject |
| TEST-08 | Feature/Tax/TaxTest.php | GET /api/v1/tax/summary | Tax summary returns deductible totals grouped by category (skip export/send which require Python) |
| TEST-09 | Feature/Account/AccountDeletionTest.php | DELETE /api/v1/account | User deleted, bank connections gone, transactions still exist (check cascade behavior), tokens revoked |

## Unit Test Requirements Map

| Requirement | Test File | What to Test | Key Assertions |
|-------------|-----------|-------------|----------------|
| TEST-10 | Unit/Services/TransactionCategorizerServiceTest.php | processCategorizationResults() confidence routing | >= 0.85: auto_categorized + no question; 0.60-0.84: needs_review + question; 0.40-0.59: needs_review + question with options; < 0.40: needs_review + open question; handleUserAnswer() for each question_type |
| TEST-11 | Unit/Services/SubscriptionDetectorServiceTest.php | analyzeRecurrence() pattern detection | Monthly (25-35 day intervals): returns 'monthly'; Weekly (5-10 day): returns 'weekly'; Inconsistent amounts (>20% variance with <3 charges): returns null; Single charge: returns null |
| TEST-12 | Unit/Services/TaxExportServiceTest.php | mapToScheduleC() line mapping | 'Marketing & Advertising' -> Line 8; 'Gas & Fuel' -> Line 9; 'Office Supplies' -> Line 18; Unknown category -> Line 27a |
| TEST-13 | Unit/Services/CaptchaServiceTest.php | verify() score logic | Disabled config -> always true; Score below threshold -> false; Score above threshold -> true; Action mismatch -> false; API failure -> false |

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| PHPUnit class-based tests | Pest 3 closure-based tests | Pest 3 (2024) | Less boilerplate, better readability |
| Doctrine DBAL for column changes | Laravel native column modification | Laravel 11 (2024) | No DBAL dependency needed -- but SQLite still unsupported |
| `$this->actingAs($user)` | `Sanctum::actingAs($user)` | Laravel Sanctum 3+ | Required for API token auth testing |
| Separate test DB config in phpunit.xml | `.env.testing` file | Laravel 8+ | Cleaner separation of concerns |
| `pest()->extends()` with `uses()` | `pest()->extend()` with `->use()` | Pest 3 | Updated API in Pest.php |

**Deprecated/outdated:**
- doctrine/dbal: Removed from Laravel 11+; not needed and not installed in this project
- `RefreshDatabase` in individual test files: Already configured globally in `tests/Pest.php` for Feature tests

## Open Questions

1. **Python Dependencies in CI**
   - What we know: TaxExportService shells out to Python scripts for Excel/PDF generation
   - What's unclear: Whether CI needs Python + openpyxl + reportlab installed for tax export tests
   - Recommendation: Do NOT install Python in CI. Test `gatherTaxData()` and `mapToScheduleC()` only (data logic). File generation is a Python concern, not a PHP test concern.

2. **Test Coverage for Events/Listeners**
   - What we know: Events exist (BankConnected, TransactionsImported, TransactionCategorized, UserAnsweredQuestion). Controllers dispatch them.
   - What's unclear: Whether event listeners exist or were built in Phase 4
   - Recommendation: Use `Event::fake()` in feature tests to assert events ARE dispatched, without testing listener behavior. If listeners exist, they get separate tests.

3. **Google OAuth Testing**
   - What we know: SocialAuthController handles Google OAuth redirect/callback
   - What's unclear: How deep to test OAuth flow -- it involves browser redirect
   - Recommendation: Test the callback handler with a mocked Socialite response. Skip the redirect test (it just returns a redirect URL).

4. **Existing Breeze Auth Tests**
   - What we know: Tests exist in `tests/Feature/Auth/` for web-based auth (registration, login, password, etc.)
   - What's unclear: Whether they will conflict with or be redundant alongside the new API auth tests
   - Recommendation: Keep existing Breeze tests (they test web routes like `/login`, `/register`). New API auth tests target `/api/auth/*` routes. They test different things.

## Sources

### Primary (HIGH confidence)
- Codebase analysis: All 18 models, 7 enums, 10 API controllers, 7 services, 13 migrations, routes, middleware, policies -- read directly from `/var/www/html/ledgeriq/`
- Grep verification: Only `User` model has `HasFactory` trait -- all other 17 models confirmed missing via `grep -r HasFactory app/Models/`
- [Laravel 12.x Testing Docs](https://laravel.com/docs/12.x/testing) - Testing framework setup
- [Laravel 12.x Mocking Docs](https://laravel.com/docs/12.x/mocking) - Http::fake, Mail::fake, etc.
- [Laravel 12.x Eloquent Factories Docs](https://laravel.com/docs/12.x/eloquent-factories) - Factory definition, states, relationships
- [Pest PHP Official Docs - CI](https://pestphp.com/docs/continuous-integration) - GitHub Actions setup
- [Pest PHP Official Docs - Mocking](https://pestphp.com/docs/mocking) - Mockery integration

### Secondary (MEDIUM confidence)
- [shivammathur/setup-php Laravel + PostgreSQL example](https://github.com/shivammathur/setup-php/blob/master/examples/laravel-postgres.yml) - CI workflow template
- [Kirschbaum Development - Laravel GitHub Actions](https://kirschbaumdevelopment.com/insights/laravel-github-actions) - CI best practices
- [Laravel Daily - Mocking External APIs](https://laraveldaily.com/post/laravel-testing-mocking-faking-external-api) - Http::fake patterns

### Tertiary (LOW confidence)
- [TechSolutionStuff - Laravel 11 Remove Doctrine DBAL](https://techsolutionstuff.com/post/laravel-11-remove-doctrine-dbal-what-you-need-to-know) - Confirms SQLite limitation

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All packages already installed in composer.json; verified versions
- Architecture: HIGH - Based on direct codebase analysis of all 18 models, 10 controllers, routes, middleware
- Pitfalls: HIGH - SQLite incompatibility confirmed by migration analysis + multiple sources; HasFactory missing confirmed by grep; middleware issues confirmed by direct code reading
- CI pipeline: MEDIUM - Based on official examples and community patterns, not project-specific testing

**Research date:** 2026-02-11
**Valid until:** 2026-03-11 (stable domain; Pest 3 and Laravel 12 are mature)
