# Phase 10: Foundation: Tax Rules Engine & Cross-Source Snapshot — Pattern Map

**Mapped:** 2026-07-01
**Files analyzed:** 8 new artifacts
**Analogs found:** 8 / 8

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `config/tax-rules.php` | config | static/read | `config/spendifiai.php` | exact |
| `app/Services/TaxRulesEngineService.php` | service | transform (pure PHP) | `app/Services/IncomeDetectorService.php` | exact |
| `app/Services/IncomeOptimizerDataAssemblerService.php` | service | CRUD/aggregate | `app/Services/TaxDeductionFinderService.php` | exact |
| `app/Services/CrossSourceReviewService.php` | service | transform/compare | `app/Services/TaxDeductionFinderService.php` | role-match |
| `app/Models/IncomeOptimizationProfile.php` | model | CRUD | `app/Models/UserFinancialProfile.php` | exact |
| New migration: `create_income_optimization_profiles_table` | migration | DDL | `2026_03_30_000001_create_tax_documents_table.php` | exact |
| `app/Jobs/BuildIncomeOptimizationSnapshot.php` | job | batch/async | `app/Jobs/CategorizePendingTransactions.php` | exact |
| `tests/Unit/Services/TaxRulesEngineServiceTest.php` | test | unit | `tests/Unit/Services/SubscriptionDetectorServiceTest.php` | exact |

---

## Pattern Assignments

### `config/tax-rules.php` (config, static)

**Analog:** `config/spendifiai.php`

**File structure** (lines 1–184 of `config/spendifiai.php`):
```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | [Section name]
    |--------------------------------------------------------------------------
    */
    'key' => [
        'nested_key' => env('ENV_VAR', 'default'),
        'numeric_key' => 22,
    ],

];
```

**Year-versioned config pattern — use nested arrays keyed by year:**
```php
'brackets' => [
    2024 => [
        'single' => [
            ['min' => 0,      'max' => 11600,  'rate' => 0.10],
            ['min' => 11601,  'max' => 47150,  'rate' => 0.12],
            // ...
        ],
    ],
    2025 => [ /* ... */ ],
],
```

**Gotcha:** No `env()` calls needed for static tax data. Use plain PHP values. Numeric keys (year integers) are valid PHP array keys.

---

### `app/Services/TaxRulesEngineService.php` (service, transform — pure PHP, NO Claude)

**Analog:** `app/Services/IncomeDetectorService.php` (lines 1–374)

**Namespace + imports** (lines 1–8):
```php
<?php

namespace App\Services;

use App\Models\UserFinancialProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
```

**Class shape — protected lookup tables + public entry method + protected helpers:**
```php
class TaxRulesEngineService
{
    protected array $bracketMap = [
        2024 => ['single' => [...], 'married_filing_jointly' => [...]],
        2025 => [...],
    ];

    /**
     * Calculate effective and marginal tax rates for a user-year.
     *
     * @return array{effective_rate: float, marginal_rate: float, estimated_tax: float, ...}
     */
    public function calculate(int $userId, int $taxYear): array
    {
        // ...
    }

    protected function applyBrackets(float $income, string $filingStatus, int $year): array
    {
        // ...
    }
}
```

**Return shape convention** (from `IncomeDetectorService::analyze()` lines 64–189):
- Always return a typed array with documented `@return` PHPDoc shape
- Round all monetary values: `round($value, 2)`
- Use `(float)` cast when reading decimal DB values, never `Number()` (that's JS-only)

**Gotcha:** `decimal:2` DB casts serialize as JSON strings — always cast with `(float)` before arithmetic.

---

### `app/Services/IncomeOptimizerDataAssemblerService.php` (service, CRUD/aggregate)

**Analog:** `app/Services/TaxDeductionFinderService.php` (lines 1–58)

**Imports + class shape** (lines 1–17):
```php
<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use Illuminate\Support\Facades\DB;

class TaxDeductionFinderService
{
    public function findDeductions(User $user, int $taxYear): array
    {
        // Calls multiple private gather methods, merges results
        $transactionResults = $this->scanTransactions($user, $taxYear);
        $profileResults     = $this->matchProfile($user, $taxYear);
        // ...
        return [
            'deductions'     => $grouped,
            'total_estimated_savings' => round($totalEstimated, 2),
        ];
    }
```

**Pattern to follow:** Single public method (e.g. `assemble(int $userId, int $taxYear): array`), multiple protected gather helpers, combine and return one structured array. No Claude calls — pure DB queries and collection operations.

**Household-aware query pattern** (lines 23–24 of TaxDeductionFinderService):
```php
$userIds = $user->householdUserIds();
// then: ->whereIn('user_id', $userIds)
```
Use this if the snapshot must span household members.

---

### `app/Services/CrossSourceReviewService.php` (service, compare/transform)

**Analog:** `app/Services/TaxDeductionFinderService.php` + `app/Services/IncomeDetectorService.php`

**Pattern:** Deterministic comparison — no Claude. Accepts two structured data arrays (e.g. bank-derived vs document-derived), returns discrepancies and a confidence score.

```php
<?php

namespace App\Services;

class CrossSourceReviewService
{
    /**
     * Compare bank transaction income with document-extracted income.
     *
     * @return array{matches: array, discrepancies: array, confidence: float}
     */
    public function compare(array $bankData, array $documentData, float $tolerance = 0.05): array
    {
        // ...
    }

    protected function withinTolerance(float $a, float $b, float $tolerance): bool
    {
        if ($a == 0 && $b == 0) return true;
        $base = max(abs($a), abs($b));
        return abs($a - $b) / $base <= $tolerance;
    }
}
```

**Tolerance constant to reference** (`config/spendifiai.php` line 155):
```php
'intelligence' => [
    'anomaly_tolerance' => 0.05,    // 5% variance threshold
    'min_income_threshold' => 600,  // IRS 1099 reporting threshold ($600)
],
```
Read this from config rather than hardcoding: `config('spendifiai.intelligence.anomaly_tolerance')`.

---

### `app/Models/IncomeOptimizationProfile.php` (model, CRUD)

**Analog:** `app/Models/UserFinancialProfile.php` (lines 1–74)

**Full model pattern:**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFinancialProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'employment_type', /* ... */
        'monthly_income',   // encrypted field
        'custom_rules',     // encrypted:array field
    ];

    protected $hidden = [
        'monthly_income',   // hide encrypted fields from API responses
        'custom_rules',
    ];

    protected function casts(): array
    {
        return [
            'monthly_income'        => 'encrypted',        // TEXT column in DB
            'custom_rules'          => 'encrypted:array',  // TEXT column in DB
            'monthly_savings_goal'  => 'decimal:2',
            'has_home_office'       => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Float accessor for encrypted decimal fields
    public function getMonthlyIncomeDecimalAttribute(): ?float
    {
        return $this->monthly_income ? (float) $this->monthly_income : null;
    }
}
```

**Critical rules:**
1. Use `protected function casts(): array` (Laravel 12 method syntax — NOT `protected $casts = [...]`).
2. Encrypted fields must be `'encrypted'` or `'encrypted:array'` cast — NEVER call `encrypt()`/`decrypt()` manually.
3. Every encrypted field must be a `TEXT` column in the migration (not `string`/`varchar`).
4. Add a float accessor for any encrypted decimal so PHP arithmetic works: `(float) $this->field`.
5. Include `$hidden` for all sensitive/encrypted fields.
6. `decimal:2` cast serializes as string in JSON — frontend must wrap in `Number()`, PHP must cast with `(float)`.

---

### New migration: `create_income_optimization_profiles_table` (migration, DDL)

**Analog:** `database/migrations/2026_03_30_000001_create_tax_documents_table.php` (lines 1–38)

**Full migration pattern:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_optimization_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('snapshot_year')->index();
            // Encrypted fields MUST be TEXT (not string):
            $table->text('snapshot_data')->nullable();      // encrypted:array
            $table->text('assembler_output')->nullable();   // encrypted
            // Non-sensitive fields can use normal types:
            $table->string('status', 20)->default('pending');
            $table->decimal('completeness_score', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'snapshot_year']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_optimization_profiles');
    }
};
```

**Critical rules:**
1. Migration MUST be forward-only and additive — `Schema::create()` only, never `dropColumn`, `dropTable`, or `Schema::dropIfExists()` on existing tables.
2. Encrypted fields use `$table->text()` — TEXT is required for Laravel's encrypted cast.
3. Add composite indexes on `[user_id, snapshot_year]` and `[user_id, status]` matching the project's index pattern (see `000006_add_performance_indexes`).
4. File naming: `YYYY_MM_DD_HHMMSS_create_income_optimization_profiles_table.php`.

**Additive-only migration pattern** (for future add-column migrations, ref `2026_04_29_004712_add_recharged_at_to_subscriptions.php`):
```php
public function up(): void
{
    Schema::table('income_optimization_profiles', function (Blueprint $table) {
        $table->timestamp('last_reviewed_at')->nullable()->after('built_at');
    });
}
```

---

### `app/Jobs/BuildIncomeOptimizationSnapshot.php` (job, batch/async)

**Analog:** `app/Jobs/CategorizePendingTransactions.php` (lines 1–78)

**Full job pattern:**
```php
<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\IncomeOptimizerDataAssemblerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CategorizePendingTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;   // seconds — tune based on expected duration

    public function __construct(
        protected int $userId,
        protected int $taxYear,          // pass primitives, not Models
    ) {}

    public function handle(
        IncomeOptimizerDataAssemblerService $assembler,
        // inject other services as needed
    ): void {
        Log::info("Building income optimization snapshot for user {$this->userId} year {$this->taxYear}");

        $result = $assembler->assemble($this->userId, $this->taxYear);

        Log::info('Snapshot build complete', ['user_id' => $this->userId, 'year' => $this->taxYear]);
    }
}
```

**Dispatch pattern** (used by controllers/other jobs):
```php
BuildIncomeOptimizationSnapshot::dispatch($user->id, $taxYear);
```

**Critical rules:**
1. Pass primitive scalars (`int $userId`) in the constructor, not Eloquent models — `SerializesModels` can cause stale model issues with large payloads.
2. `public int $tries = 3; public int $timeout = 600;` — always set both.
3. Inject services via `handle()` method parameters (Laravel's IoC resolves them automatically).
4. Use `Log::info()` at start and end of `handle()`.
5. `usleep(500000)` between batch sub-iterations if rate-limiting is needed (see `CategorizePendingTransactions` line 65).

---

### `tests/Unit/Services/TaxRulesEngineServiceTest.php` (test, unit)

**Analog:** `tests/Unit/Services/SubscriptionDetectorServiceTest.php` (lines 1–60)

**File header + setup pattern:**
```php
<?php

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TaxRulesEngineService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createRulesEngineTestData(): array
{
    $user = User::factory()->create();
    // create supporting fixtures...
    return compact('user');
}
```

**Individual test pattern:**
```php
it('calculates correct marginal rate for single filer in 22% bracket', function () {
    // Arrange
    ['user' => $user] = createRulesEngineTestData();

    // Act
    $service = new TaxRulesEngineService;
    $result  = $service->calculate($user->id, 2024);

    // Assert
    expect($result['marginal_rate'])->toBe(0.22)
        ->and($result['effective_rate'])->toBeLessThan(0.22);
});
```

**Pest.php bootstrap rules (lines 19–21 of `tests/Pest.php`):**
```php
// Unit/Services tests need the Laravel app for models, config, Http::fake, etc.
pest()->extend(Tests\TestCase::class)
    ->in('Unit/Services');
```
- Tests in `tests/Unit/Services/` automatically extend `Tests\TestCase` — no `uses()` call needed for that.
- `RefreshDatabase` must be opted-in explicitly with `uses(\Illuminate\Foundation\Testing\RefreshDatabase::class)` at the top of each service test file.
- Available global helpers: `createAuthenticatedUser()`, `createUserWithBank()`, `createUserWithBankAndProfile()`.

**Http::fake pattern** (for any AI-calling paths, from `tests/Feature/Savings/SavingsTest.php` lines 29–46):
```php
Http::fake([
    'api.anthropic.com/*' => Http::response([
        'content' => [['text' => json_encode([/* structured response */])]],
    ]),
]);
```
TaxRulesEngineService is pure PHP (no Claude), so `Http::fake()` is not needed in its tests.

---

## Shared Patterns

### Encryption — applies to `IncomeOptimizationProfile` and its migration

**Source:** `app/Models/UserFinancialProfile.php` lines 28–54 + CLAUDE.md "Encryption" section

```php
// Model cast (method syntax — Laravel 12):
protected function casts(): array
{
    return [
        'sensitive_field'       => 'encrypted',         // -> TEXT column
        'sensitive_array_field' => 'encrypted:array',   // -> TEXT column
        'decimal_field'         => 'decimal:2',         // -> serializes as string in JSON
    ];
}

// Migration (TEXT not string for encrypted fields):
$table->text('sensitive_field')->nullable();
$table->text('sensitive_array_field')->nullable();

// Float accessor for encrypted decimal fields:
public function getSensitiveDecimalAttribute(): ?float
{
    return $this->sensitive_field ? (float) $this->sensitive_field : null;
}
```

**Never** call `encrypt()` / `decrypt()` manually — the model cast handles this.

### $hidden on Models — applies to `IncomeOptimizationProfile`

**Source:** CLAUDE.md "Architecture" + `app/Models/UserFinancialProfile.php` lines 28–32

```php
protected $hidden = [
    'sensitive_field',      // Prevent leakage in API responses / toArray()
    'sensitive_array_field',
];
```

All sensitive and encrypted fields must appear in `$hidden`.

### (float) cast for PHP arithmetic — applies to all services reading decimal DB values

**Source:** CLAUDE.md "Decimal Serialization" convention

```php
// CORRECT — PHP:
$amount = (float) $transaction->amount;

// WRONG — PHP does not have Number():
// $amount = Number($transaction->amount);  ← JavaScript only
```

### Service class conventions — applies to all new services

**Source:** `app/Services/IncomeDetectorService.php` lines 1–374

- Namespace: `App\Services` (or `App\Services\AI` only for Claude-calling services)
- No constructor injection of Eloquent models — accept primitive IDs in public method signatures
- Business logic returns typed associative arrays, never raw collections to callers
- Round all monetary outputs: `round($value, 2)`
- Protected lookup tables as class properties (`protected array $bracketMap = [...]`)
- PHPDoc `@return array{key: type, ...}` shapes on public methods

---

## No Analog Found

All 8 artifacts have close analogs. No files require falling back to RESEARCH.md patterns exclusively.

---

## Metadata

**Analog search scope:** `app/Services/`, `app/Models/`, `database/migrations/`, `app/Jobs/`, `tests/Unit/Services/`, `tests/Feature/`, `config/`, `tests/Pest.php`
**Files scanned:** 12
**Pattern extraction date:** 2026-07-01
