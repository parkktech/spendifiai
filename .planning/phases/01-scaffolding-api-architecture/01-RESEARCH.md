# Phase 1: Project Scaffolding & API Architecture - Research

**Researched:** 2026-02-10
**Domain:** Laravel 12 project scaffolding, code integration, controller decomposition, API Resources
**Confidence:** HIGH

## Summary

This phase creates a Laravel 12 project using the official React starter kit, integrates ~60% of existing backend code from `existing-code/`, splits a 939-line monolithic SpendWiseController into 10 focused controllers, and creates API Resources and Form Requests for all endpoints. The existing code is well-structured and largely compatible with Laravel 12 conventions. The main technical challenges are: (1) merging the existing User model (which adds HasApiTokens, MustVerifyEmail, and custom fields) with the starter kit's User model, (2) merging custom migrations with the starter kit's default migrations (users, cache, jobs tables), (3) creating a missing SavingsProgress model, and (4) adding missing import statements in the SpendWiseController before splitting it.

The development environment has PHP 8.3, Node 20, PostgreSQL 17, Redis, and Composer 2.8 -- all compatible. The Laravel installer is NOT globally installed, so project creation must use `composer create-project` or install the installer first.

**Primary recommendation:** Use `composer create-project laravel/laravel` then install the React starter kit via `laravel/react-starter-kit` package per official docs. Merge existing code file-by-file with conflict resolution for User model, migrations, and provider registrations.

## Standard Stack

### Core (from React Starter Kit)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^12.0 | Core framework | Project foundation |
| inertiajs/inertia-laravel | ^2.0 | Server-side Inertia adapter | React starter kit default |
| laravel/fortify | ^1.30 | Auth backend (2FA, password reset) | Starter kit ships with it |
| laravel/tinker | ^2.10.1 | REPL | Starter kit default |
| laravel/wayfinder | ^0.1.9 | Type-safe routes in TS | Starter kit default |
| @inertiajs/react | ^2.3.7 | Client-side Inertia adapter | Starter kit default |
| react / react-dom | ^19.2.0 | UI framework | Starter kit default |
| tailwindcss | ^4.0.0 | CSS framework | Starter kit default |
| vite | ^7.0.4 | Build tool | Starter kit default |

### Additional (from existing composer.json)
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| laravel/sanctum | ^4.0 | API token auth + SPA cookies | Existing code uses HasApiTokens |
| laravel/socialite | ^5.16 | Google OAuth | Social login |
| pragmarx/google2fa-laravel | ^2.2 | TOTP 2FA | Two-factor auth |
| bacon/bacon-qr-code | ^3.0 | QR code generation | 2FA setup |
| webklex/laravel-imap | ^5.0 | Email parsing | Gmail receipt parsing |
| google/apiclient | ^2.16 | Google API client | Gmail OAuth |
| predis/predis | ^2.3 | Redis PHP client | Queue, cache, session |
| guzzlehttp/guzzle | ^7.9 | HTTP client | Plaid API calls |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| predis/predis | phpredis extension | phpredis is faster but requires C extension; predis is pure PHP, already in composer.json |
| laravel/wayfinder | Manual route definitions | Wayfinder comes free with starter kit; adds type-safe routes in TS |

**Installation approach:**
```bash
# Step 1: Create project (installer not globally available)
composer create-project laravel/laravel spendwise
cd spendwise

# Step 2: Install React starter kit
composer require laravel/react-starter-kit --dev
# OR use the laravel installer if installed:
# laravel new spendwise  (select React during prompt)

# Step 3: Install additional PHP dependencies
composer require laravel/sanctum laravel/socialite pragmarx/google2fa-laravel bacon/bacon-qr-code webklex/laravel-imap google/apiclient predis/predis

# Step 4: Install frontend dependencies
npm install
```

## Architecture Patterns

### Existing Code Structure (to be integrated)
```
existing-code/
├── app/
│   ├── Actions/Fortify/          # 3 files: CreateNewUser, ResetUserPassword, UpdateUserPassword
│   ├── Enums/                    # 7 PHP 8.2 backed enums
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/SpendWiseController.php  # 939-line monolith to SPLIT
│   │   │   └── Auth/             # 5 complete auth controllers
│   │   ├── Middleware/           # 4 custom middleware
│   │   └── Requests/Auth/       # 2 form requests (Login, Register)
│   ├── Jobs/                    # 1 job (CategorizePendingTransactions)
│   ├── Mail/                    # 1 mailable (TaxPackageMail)
│   ├── Models/                  # 16 models (15 files + 1 MISSING: SavingsProgress)
│   ├── Policies/                # 4 policies
│   ├── Providers/               # 2 providers (AppServiceProvider, FortifyServiceProvider)
│   └── Services/                # 7 services (3 in AI/ subdirectory)
├── bootstrap/app.php            # Laravel 12 style config
├── config/                      # 3 config files (spendwise, services, fortify)
├── database/
│   ├── migrations/              # 5 migrations (14+ tables)
│   └── seeders/                 # 1 seeder (ExpenseCategorySeeder)
├── resources/
│   ├── scripts/                 # 2 Python scripts (tax export)
│   └── views/emails/            # 1 blade template
├── routes/                      # 3 route files (api, web, console)
└── expense-parser-module/       # Separate module (integrate later, Phase 3)
```

### Target Controller Decomposition (10 controllers from SpendWiseController)
```
app/Http/Controllers/Api/
├── DashboardController.php          # dashboard() - Lines 143-277
├── PlaidController.php              # createLinkToken(), exchangeToken(), sync(), disconnect()
├── BankAccountController.php        # index(), updatePurpose() - Lines 39-137
├── TransactionController.php        # index(), updateCategory() - Lines 391-438
├── AIQuestionController.php         # index(), answer(), bulkAnswer() - Lines 334-385
├── SubscriptionController.php       # index(), detect() - Lines 445-464
├── SavingsController.php            # recommendations(), analyze(), dismiss(), apply(), setTarget(), getTarget(), regeneratePlan(), respondToAction(), pulseCheck() - Lines 470-754
├── TaxController.php                # summary(), export(), sendToAccountant(), download() - Lines 760-911
├── EmailConnectionController.php    # connect(), callback(), sync(), disconnect() - NEW (not in SpendWiseController)
└── UserProfileController.php        # updateFinancial(), showFinancial(), deleteAccount() - Lines 917-938
```

### Pattern 1: Controller Decomposition with Constructor DI
**What:** Each controller receives services via constructor injection, delegates to services, returns API Resources.
**When to use:** Every controller in this project.
**Example:**
```php
// Source: Existing code pattern + Laravel 12 conventions
class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionCategorizerService $categorizer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // Existing query logic from SpendWiseController::transactions()
        $transactions = Transaction::where('user_id', auth()->id())
            ->with('bankAccount:id,name,mask,purpose,nickname')
            ->orderByDesc('transaction_date')
            ->paginate($request->input('per_page', 50));

        return TransactionResource::collection($transactions);
    }

    public function updateCategory(UpdateTransactionCategoryRequest $request, Transaction $transaction): TransactionResource
    {
        $this->authorize('update', $transaction);
        $transaction->update([...]);
        return new TransactionResource($transaction->fresh());
    }
}
```

### Pattern 2: API Resources for Consistent JSON Output
**What:** Every model exposed via API uses a JsonResource class.
**When to use:** All controller responses returning model data.
**Example:**
```php
// Source: Laravel 12 official docs
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'merchant'        => $this->merchant_normalized ?? $this->merchant_name,
            'amount'          => $this->amount,
            'date'            => $this->transaction_date->format('Y-m-d'),
            'category'        => $this->category,  // Uses accessor
            'review_status'   => $this->review_status,
            'expense_type'    => $this->expense_type,
            'account_purpose' => $this->account_purpose,
            'tax_deductible'  => $this->tax_deductible,
            'is_subscription' => $this->is_subscription,
            'account'         => new BankAccountResource($this->whenLoaded('bankAccount')),
        ];
    }
}
```

### Pattern 3: Form Request Validation
**What:** Dedicated FormRequest classes for every write endpoint.
**When to use:** All POST/PATCH/PUT/DELETE endpoints.
**Example:**
```php
// Source: Laravel 12 validation docs
class UpdateAccountPurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account'));
    }

    public function rules(): array
    {
        return [
            'purpose'               => 'required|in:personal,business,mixed,investment',
            'nickname'              => 'nullable|string|max:100',
            'business_name'         => 'nullable|string|max:200',
            'tax_entity_type'       => 'nullable|in:sole_prop,llc,s_corp,c_corp,partnership,personal',
            'ein'                   => 'nullable|string|max:20',
            'include_in_spending'   => 'nullable|boolean',
            'include_in_tax_tracking' => 'nullable|boolean',
        ];
    }
}
```

### Anti-Patterns to Avoid
- **Inline validation in controllers:** All validation rules from SpendWiseController MUST be extracted into FormRequest classes. The existing code has `$request->validate([...])` in several methods.
- **Direct JSON construction in controllers:** The existing code does `->map(fn($a) => [...])` to build JSON. Replace with API Resources.
- **Missing model imports:** SpendWiseController uses `BankAccount::` without importing it (line 41, 272-274). Fix before splitting.
- **Referencing non-existent model:** SpendWiseController imports `App\Models\SavingsProgress` (line 24) but no model file exists. Must create it.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Project scaffolding | Manual Laravel setup | `laravel new` or `composer create-project` | Starter kit handles Vite, TS, tailwind, shadcn, auth pages |
| API response format | Manual `response()->json()` | `JsonResource` classes | Consistent format, pagination, conditional attributes |
| Request validation | Inline `$request->validate()` | `FormRequest` classes | Reusable, testable, authorization in one place |
| Route model binding | Manual `::findOrFail()` | Laravel route model binding | Already configured in AppServiceProvider |
| Auth middleware | Custom auth checks | `auth:sanctum` middleware | Already in routes/api.php |
| Policy authorization | Manual `user_id` checks | `$this->authorize()` + policies | Already have 4 policies registered |

**Key insight:** The existing code already follows many Laravel conventions (model casts, enums, policies, middleware). The main gap is the controller layer -- it needs API Resources and Form Requests.

## Common Pitfalls

### Pitfall 1: User Model Conflict
**What goes wrong:** The React starter kit ships its own User model with `HasFactory, Notifiable, TwoFactorAuthenticatable` traits. The existing User model adds `HasApiTokens, MustVerifyEmail` and has 15+ relationship methods, custom helpers, and additional casts (encrypted:array for 2FA).
**Why it happens:** Both the starter kit and existing code define `app/Models/User.php`.
**How to avoid:** Start from the existing User model, add any missing traits from the starter kit version. The existing model already has all the starter kit traits plus extras.
**Warning signs:** Missing `HasApiTokens` causes Sanctum token creation to fail. Missing `MustVerifyEmail` skips email verification.

### Pitfall 2: Migration Ordering Conflicts
**What goes wrong:** The starter kit creates `0001_01_01_000000_create_users_table.php` (users, password_resets). The existing code has `2026_02_10_000004_add_auth_columns.php` which adds columns to users. If both create the users table, migration fails with "table already exists."
**Why it happens:** The starter kit migrations and existing migrations both touch the users table.
**How to avoid:** Keep the starter kit's users table migration (it creates users + password_resets + sessions). Remove conflicting parts from migration 000004. The existing migration 000004 adds google_id, avatar_url, 2FA columns, failed_login_attempts, locked_until -- these should be added via the existing migration AFTER the starter kit migration runs. Verify 2FA columns aren't duplicated (starter kit now includes `add_two_factor_columns_to_users_table` migration).
**Warning signs:** "Column already exists" errors during `php artisan migrate`.

### Pitfall 3: Missing SavingsProgress Model
**What goes wrong:** `SpendWiseController` (line 24, 568) and `SavingsTargetPlannerService` both import and use `App\Models\SavingsProgress`, but no model file exists in `existing-code/app/Models/`.
**Why it happens:** The model was likely written separately and not included in the code export. The migration `000003_create_savings_targets.php` DOES create the `savings_progress` table.
**How to avoid:** Create the SavingsProgress model with appropriate fillable, casts, and relationships based on the migration schema and usage in SpendWiseController/SavingsTargetPlannerService.
**Warning signs:** Class not found errors when testing savings target endpoints.

### Pitfall 4: SpendWiseController Missing Imports
**What goes wrong:** The SpendWiseController uses `BankAccount::` (lines 41, 272-274) without importing `App\Models\BankAccount`. This causes runtime errors.
**Why it happens:** The controller was likely edited incrementally and an import was missed.
**How to avoid:** When splitting the controller, ensure each new controller imports all models it references. Specifically:
  - `BankAccountController` needs `use App\Models\BankAccount;`
  - `DashboardController` needs `use App\Models\BankAccount;`

### Pitfall 5: Sanctum Not in Starter Kit by Default
**What goes wrong:** The React starter kit does NOT include `laravel/sanctum` or `HasApiTokens` in its default User model. The existing code requires Sanctum for API token auth.
**Why it happens:** The starter kit uses session-based auth via Fortify. Sanctum must be added separately for API token support.
**How to avoid:** Install `laravel/sanctum` explicitly, add `HasApiTokens` trait to User model, and verify the `personal_access_tokens` migration exists (Sanctum publishes it).
**Warning signs:** Routes with `auth:sanctum` middleware return 401 even with valid tokens.

### Pitfall 6: Config Key Mismatch Between services.php and PlaidService
**What goes wrong:** The `PlaidService` constructor reads from `config('services.plaid.client_id')` but the `spendwise.php` config also defines plaid settings under `config('spendwise.plaid.*')`. Dual config locations cause confusion.
**Why it happens:** Both `config/services.php` and `config/spendwise.php` define Plaid settings.
**How to avoid:** Verify which config path each service class reads from. `PlaidService` uses `services.plaid.*`. The `spendwise.plaid.*` config is used by other code (link token params). Both must be populated.
**Warning signs:** Null client_id/secret when calling Plaid API.

### Pitfall 7: Zone.Identifier Files from Windows
**What goes wrong:** Every file in `existing-code/` has a companion `.php:Zone.Identifier` file (Windows NTFS alternate data stream marker). If copied blindly, these create invalid PHP files.
**Why it happens:** Files were downloaded on Windows, which adds Zone.Identifier metadata.
**How to avoid:** When copying files, exclude `*:Zone.Identifier` files. Use `find ... -name "*:Zone.Identifier" -delete` after copying.
**Warning signs:** Autoloader errors, "class not found" for files that appear to exist.

### Pitfall 8: Subscription Model Column Name Mismatches
**What goes wrong:** The `Subscription` model has `last_charged_at` and `next_charge_at` in $fillable, but the migration creates `last_charge_date` and `next_expected_date` columns. These names don't match.
**Why it happens:** Model and migration were likely written at different times.
**How to avoid:** Audit all model $fillable arrays against their migration column names. Fix either the model or migration to match. This needs resolution during integration.
**Warning signs:** Mass assignment silently fails -- data doesn't save to these columns.

### Pitfall 9: SavingsTarget Model Mismatch with SpendWiseController
**What goes wrong:** The `SavingsTarget` model defines fillable as `['user_id','goal_name','target_amount','monthly_target','current_savings','deadline','status']` but the SpendWiseController creates records with fields from the migration: `monthly_target, motivation, goal_total, target_start_date, is_active`. The model fillable doesn't include these migration fields.
**Why it happens:** Model was written before the migration was finalized, or was templated separately.
**How to avoid:** Update SavingsTarget model $fillable to match the actual migration columns: `user_id, monthly_target, motivation, target_start_date, target_end_date, goal_total, is_active`.
**Warning signs:** MassAssignmentException or silently dropped data when setting savings targets.

## Code Examples

### Creating an API Resource
```php
// php artisan make:resource TransactionResource
// Source: Laravel 12 official docs (https://laravel.com/docs/12.x/eloquent-resources)

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'merchant'        => $this->merchant_normalized ?? $this->merchant_name,
            'amount'          => (float) $this->amount,
            'date'            => $this->transaction_date->format('Y-m-d'),
            'category'        => $this->category,
            'review_status'   => $this->review_status,
            'expense_type'    => $this->expense_type,
            'account_purpose' => $this->account_purpose,
            'tax_deductible'  => $this->tax_deductible,
            'is_subscription' => $this->is_subscription,
            'account'         => new BankAccountResource($this->whenLoaded('bankAccount')),
            'ai_question'     => new AIQuestionResource($this->whenLoaded('aiQuestion')),
        ];
    }
}
```

### Creating a Form Request
```php
// php artisan make:request UpdateTransactionCategoryRequest
// Source: Laravel 12 official docs (https://laravel.com/docs/12.x/validation)

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('transaction'));
    }

    public function rules(): array
    {
        return [
            'category'       => 'required|string|max:100',
            'expense_type'   => 'nullable|in:personal,business,mixed',
            'tax_deductible' => 'nullable|boolean',
        ];
    }
}
```

### Controller with DI, Resource, and FormRequest
```php
// Source: Extracted from SpendWiseController + Laravel 12 patterns

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTransactionCategoryRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Transaction::where('user_id', auth()->id())
            ->with('bankAccount:id,name,mask,purpose,nickname');

        // Apply filters (from existing SpendWiseController::transactions)
        if ($request->filled('purpose'))   $query->where('account_purpose', $request->purpose);
        if ($request->filled('category'))  $query->byCategory($request->category);
        if ($request->filled('search'))    $query->where('merchant_name', 'ILIKE', "%{$request->search}%");
        if ($request->filled('from'))      $query->where('transaction_date', '>=', $request->from);
        if ($request->filled('to'))        $query->where('transaction_date', '<=', $request->to);

        return TransactionResource::collection(
            $query->orderByDesc('transaction_date')
                  ->paginate($request->input('per_page', 50))
        );
    }

    public function updateCategory(
        UpdateTransactionCategoryRequest $request,
        Transaction $transaction
    ): TransactionResource {
        $transaction->update([
            'user_category'  => $request->category,
            'expense_type'   => $request->expense_type ?? $transaction->expense_type,
            'tax_deductible' => $request->tax_deductible ?? $transaction->tax_deductible,
            'review_status'  => 'user_confirmed',
        ]);

        return new TransactionResource($transaction->fresh());
    }
}
```

### Missing SavingsProgress Model (must create)
```php
// Based on migration 000003 savings_progress table schema and usage in SavingsTargetPlannerService

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsProgress extends Model
{
    protected $table = 'savings_progress';

    protected $fillable = [
        'user_id', 'savings_target_id', 'month',
        'income', 'total_spending', 'actual_savings', 'target_savings',
        'gap', 'cumulative_saved', 'cumulative_target',
        'target_met', 'category_breakdown', 'plan_adherence',
    ];

    protected function casts(): array
    {
        return [
            'income'             => 'decimal:2',
            'total_spending'     => 'decimal:2',
            'actual_savings'     => 'decimal:2',
            'target_savings'     => 'decimal:2',
            'gap'                => 'decimal:2',
            'cumulative_saved'   => 'decimal:2',
            'cumulative_target'  => 'decimal:2',
            'target_met'         => 'boolean',
            'category_breakdown' => 'array',
            'plan_adherence'     => 'array',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function savingsTarget(): BelongsTo { return $this->belongsTo(SavingsTarget::class); }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Breeze starter kit | React/Vue/Livewire starter kits (separate repos) | Laravel 12 (Feb 2025) | Starter kits are now standalone packages, not `--breeze` flag |
| `laravel new --breeze --stack=react` | `laravel new` (interactive prompt) | Laravel 12 | Interactive selection during project creation |
| Fortify views | Starter kit ships complete auth pages | Laravel 12 | No need to build auth UI from scratch |
| `$casts` property | `casts()` method | Laravel 12 | Must use method syntax, not array property |
| Tailwind 3 | Tailwind 4 | Starter kit uses v4 | Different config format |

**Deprecated/outdated:**
- Laravel Breeze: Replaced by the new starter kit approach. Don't `composer require laravel/breeze`.
- `$casts` property: Use `protected function casts(): array` method instead (Laravel 12 convention).
- `--react` flag on `laravel new`: Now uses interactive prompt or `--using` flag for custom starter kits.

## Environment Verification

| Tool | Version Found | Required | Status |
|------|--------------|----------|--------|
| PHP | 8.3.29 | ^8.2 | OK |
| Node.js | 20.19.6 | ^18 | OK |
| npm | 11.7.0 | ^9 | OK |
| Composer | 2.8.8 | ^2.7 | OK |
| PostgreSQL | 17.7 | ^15 | OK |
| Redis | Running (PONG) | ^7 | OK |
| Laravel installer | NOT installed | Needed for `laravel new` | MUST INSTALL or use composer create-project |

**PHP extensions verified:** bcmath, curl, gd, libxml, mbstring, openssl, pdo_pgsql, pgsql, redis, xml, zip -- all present.

## Integration Risks and Resolutions

### Risk 1: Starter Kit File Conflicts
**Files that will conflict when merging existing code:**
| File | Starter Kit Version | Existing Version | Resolution |
|------|-------------------|-----------------|------------|
| `app/Models/User.php` | Basic (3 traits, 3 fillable) | Extended (4 traits, 6 fillable, 15 relationships) | Use existing, verify no starter kit features lost |
| `app/Providers/AppServiceProvider.php` | Empty boot() | Route bindings, middleware aliases, policies | Use existing |
| `bootstrap/app.php` | Default | Custom CSRF exceptions, statefulApi | Use existing |
| `config/fortify.php` | Starter kit default | Existing with customizations | Use existing |
| `database/migrations/*_create_users_table.php` | Starter kit creates users + password_resets | Existing code expects these + adds custom columns | Keep starter kit migration, adjust existing 000004 |
| `routes/web.php` | Starter kit Inertia routes | OAuth callback, verification, health check | Merge both |

### Risk 2: SavingsTarget Model Mismatch
The SavingsTarget model's $fillable fields don't match the migration columns. The model lists `goal_name, target_amount, current_savings, deadline, status` but the migration creates `monthly_target, motivation, target_start_date, target_end_date, goal_total, is_active`. The SpendWiseController uses the migration-based fields. Resolution: rewrite the SavingsTarget model $fillable to match the migration.

### Risk 3: Subscription Model Column Name Mismatch
The Subscription model lists `last_charged_at, next_charge_at` in $fillable but the migration column names are `last_charge_date, next_expected_date`. Resolution: update the model to use the migration column names.

## API Resources to Create (8)

| Resource | Model | Key Fields | Special Handling |
|----------|-------|------------|-----------------|
| TransactionResource | Transaction | id, merchant, amount, date, category, review_status | Uses `category` accessor, conditionally loads bankAccount |
| BankAccountResource | BankAccount | id, name, type, subtype, mask, purpose, balance | Respects $hidden (excludes plaid_account_id, ein) |
| BankConnectionResource | BankConnection | id, institution_name, status, last_synced_at | Minimal -- most fields are $hidden |
| SubscriptionResource | Subscription | id, merchant, amount, frequency, status, annual_cost | Includes charge_history array |
| AIQuestionResource | AIQuestion | id, question, options, question_type, status, confidence | Includes transaction context via whenLoaded |
| SavingsRecommendationResource | SavingsRecommendation | id, title, description, monthly/annual savings, difficulty | Includes action_steps array |
| SavingsTargetResource | SavingsTarget | id, monthly_target, motivation, goal_total, is_active | Nested plan actions |
| DashboardResource | N/A (composite) | summary, categories, questions, recent, trend | Not a model resource -- custom composite response |

## Form Requests to Create (8)

| Request | Controller Method | Validation Rules (from SpendWiseController) |
|---------|------------------|---------------------------------------------|
| UpdateAccountPurposeRequest | BankAccountController@updatePurpose | purpose (required, in:4 values), nickname, business_name, tax_entity_type, ein, include_in_spending, include_in_tax_tracking |
| AnswerQuestionRequest | AIQuestionController@answer | answer (required, string, max:200) |
| BulkAnswerRequest | AIQuestionController@bulkAnswer | answers (required array), answers.*.question_id (required, exists:ai_questions), answers.*.answer (required, string, max:200) |
| UpdateTransactionCategoryRequest | TransactionController@updateCategory | category (required, string, max:100), expense_type (nullable, in:3 values), tax_deductible (nullable, boolean) |
| ExportTaxRequest | TaxController@export | year (required, integer, min:2020, max:current year) |
| SendToAccountantRequest | TaxController@sendToAccountant | year, accountant_email (required, email), accountant_name (nullable), message (nullable, max:1000) |
| SetSavingsTargetRequest | SavingsController@setTarget | monthly_target (required, numeric, min:1, max:100000), motivation (nullable), goal_total (nullable) |
| UpdateFinancialProfileRequest | UserProfileController@updateFinancial | employment_type, business_type, has_home_office, tax_filing_status, estimated_tax_bracket, monthly_income, monthly_savings_goal |

## Open Questions

1. **EmailConnectionController has no existing implementation**
   - What we know: Routes are defined in api.php for connect, callback, sync, disconnect. The expense-parser-module has related services (GmailService, EmailParserService).
   - What's unclear: Whether to create a minimal stub controller or pull logic from the expense-parser-module now.
   - Recommendation: Create a minimal stub controller with empty methods that return placeholder JSON responses. Full implementation belongs in Phase 3 (EMAIL-01 through EMAIL-05).

2. **PlaidController disconnect method**
   - What we know: The route `DELETE /api/v1/plaid/{connection}` exists. The `PlaidService::disconnect()` method exists. But SpendWiseController has no explicit disconnect method -- only exchangeToken, createLinkToken, and sync.
   - What's unclear: Whether disconnect was intended but not yet written.
   - Recommendation: Create the disconnect method in PlaidController using PlaidService::disconnect(). It's a straightforward delegation.

3. **DashboardResource as composite response**
   - What we know: The dashboard endpoint returns a complex composite JSON with data from 5+ models (transactions, questions, subscriptions, savings, bank connections).
   - What's unclear: Whether to use a JsonResource or keep as manual JSON construction.
   - Recommendation: Use a plain `JsonResponse` or create a dedicated `DashboardData` DTO class. API Resources work best for single-model transformations; the dashboard is a composite aggregation.

## Sources

### Primary (HIGH confidence)
- [Laravel 12 Starter Kits Documentation](https://laravel.com/docs/12.x/starter-kits) - Installation process, features, structure
- [Laravel React Starter Kit GitHub](https://github.com/laravel/react-starter-kit) - Actual dependencies, migrations, User model
- [Laravel 12 Eloquent API Resources](https://laravel.com/docs/12.x/eloquent-resources) - JsonResource patterns
- [Laravel 12 Validation](https://laravel.com/docs/12.x/validation) - FormRequest patterns
- [Laravel 12 Sanctum](https://laravel.com/docs/12.x/sanctum) - API token + SPA cookie auth
- Direct code analysis of all 70+ files in existing-code/ directory

### Secondary (MEDIUM confidence)
- [Laravel Starter Kits Blog Post](https://laravel.com/blog/laravel-starter-kits-a-new-beginning-for-your-next-project) - Starter kit philosophy
- [Laravel 12 API Best Practices](https://benjamincrozat.com/laravel-restful-api-best-practices) - Controller patterns

### Tertiary (LOW confidence)
- None -- all findings verified against code or official docs

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Verified against official starter kit repo, composer.json, and existing code
- Architecture: HIGH - Based on direct analysis of 939-line SpendWiseController and all existing code
- Pitfalls: HIGH - Discovered through line-by-line code analysis (missing model, import issues, column mismatches)
- Integration risks: HIGH - Verified by comparing starter kit migrations/User model with existing code

**Research date:** 2026-02-10
**Valid until:** 2026-03-10 (stable -- Laravel 12 and its starter kits are established)
