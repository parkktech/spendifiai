<?php

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Events\OptimizationProfileBuilt;
use App\Jobs\BuildIncomeOptimizationProfile;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\TaxDocument;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Services\CrossSourceReviewService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

// ─── Local helpers ───────────────────────────────────────────────────────────

/**
 * Create a TaxDocument in Ready status with the given extracted data.
 */
function makeReviewTestDoc(int $userId, TaxDocumentCategory $category, array $extractedData, int $year = 2025): TaxDocument
{
    return TaxDocument::create([
        'user_id' => $userId,
        'original_filename' => 'test.pdf',
        'stored_path' => "tax-vault/{$userId}/{$year}/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', uniqid('', true)),
        'tax_year' => $year,
        'category' => $category->value,
        'status' => DocumentStatus::Ready->value,
        'extracted_data' => $extractedData,
    ]);
}

/**
 * Create an income transaction (negative amount = money in) with INCOME plaid category.
 */
function makeReviewTestIncomeTransaction(int $userId, int $bankAccountId, string $date, float $dollars): void
{
    \App\Models\Transaction::create([
        'user_id' => $userId,
        'bank_account_id' => $bankAccountId,
        'plaid_transaction_id' => 'txn_'.uniqid('', true),
        'merchant_name' => 'EMPLOYER PAYROLL',
        'amount' => -$dollars,
        'transaction_date' => $date,
        'payment_channel' => 'other',
        'plaid_category' => 'INCOME',
        'plaid_detailed_category' => 'INCOME_WAGES',
        'ai_category' => 'Salary & Wages',
        'review_status' => 'auto_categorized',
        'expense_type' => 'personal',
        'account_purpose' => 'personal',
        'tax_deductible' => false,
        'is_subscription' => false,
    ]);
}

/**
 * Create a user with a bank connection and account ready for transaction insertion.
 */
function makeReviewTestUserWithBank(): array
{
    $user = User::factory()->create();
    $connection = BankConnection::factory()->create(['user_id' => $user->id]);
    $account = BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
    ]);

    return compact('user', 'connection', 'account');
}

beforeEach(function () {
    Storage::fake('local');
});

// ─────────────────────────────────────────────────────────────────────────────
// CTX-03: CrossSourceReviewService direct service tests
// ─────────────────────────────────────────────────────────────────────────────

it('CTX-03: review() emits w2_deposit_mismatch when gap exceeds 15%', function () {
    $user = User::factory()->create();

    // w2_wages = $100,000 (10,000,000 cents), bank = $70,000 (7,000,000 cents)
    // gap = |100k - 70k| / max(100k, 70k) = 30k/100k = 0.30 → exceeds 0.15
    $profile = IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'w2_wages' => '10000000',        // $100,000.00
        'bank_deposit_total' => '7000000', // $70,000.00
        'self_employment_income' => null,
    ]);

    $service = app(CrossSourceReviewService::class);
    $findings = $service->review($profile, $user, 2025);

    expect($findings)->toHaveCount(1);
    expect($findings[0]['key'])->toBe('w2_deposit_mismatch');
    expect($findings[0]['finding_type'])->toBe('income_discrepancy');
    expect($findings[0]['details']['gap_pct'])->toBeGreaterThan(CrossSourceReviewService::W2_DEPOSIT_TOLERANCE);
    expect($findings[0]['details']['w2_cents'])->toBe(10_000_000);
    expect($findings[0]['details']['bank_cents'])->toBe(7_000_000);
    expect($findings[0]['description'])->toBeNull(); // Phase 11 fills this
});

it('CTX-03: review() emits NO finding when W-2/deposit gap is within 15%', function () {
    $user = User::factory()->create();

    // w2_wages = $100,000, bank = $92,000 (8% gap → within 15%)
    $profile = IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'w2_wages' => '10000000',         // $100,000
        'bank_deposit_total' => '9200000', // $92,000 → 8% gap
        'self_employment_income' => null,
    ]);

    $service = app(CrossSourceReviewService::class);
    $findings = $service->review($profile, $user, 2025);

    expect($findings)->toBeEmpty();
});

it('CTX-03: review() emits NO finding when w2_wages is null/zero (insufficient data)', function () {
    $user = User::factory()->create();

    $profile = IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'w2_wages' => null,
        'bank_deposit_total' => '8000000',
        'self_employment_income' => null,
    ]);

    $service = app(CrossSourceReviewService::class);
    $findings = $service->review($profile, $user, 2025);

    expect($findings)->toBeEmpty();
});

it('CTX-03: review() emits NO finding when bank_deposit_total is null/zero (insufficient data)', function () {
    $user = User::factory()->create();

    $profile = IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'w2_wages' => '10000000',
        'bank_deposit_total' => null,
        'self_employment_income' => null,
    ]);

    $service = app(CrossSourceReviewService::class);
    $findings = $service->review($profile, $user, 2025);

    expect($findings)->toBeEmpty();
});

it('CTX-03: review() emits se_income_deposit_mismatch when SE gap exceeds 20%', function () {
    $user = User::factory()->create();

    // se_income = $80,000, bank = $50,000 → gap = 30k/80k = 37.5% > 20%
    $profile = IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'w2_wages' => null,
        'self_employment_income' => '8000000', // $80,000
        'bank_deposit_total' => '5000000',     // $50,000
    ]);

    $service = app(CrossSourceReviewService::class);
    $findings = $service->review($profile, $user, 2025);

    expect($findings)->toHaveCount(1);
    expect($findings[0]['key'])->toBe('se_income_deposit_mismatch');
    expect($findings[0]['details']['gap_pct'])->toBeGreaterThan(CrossSourceReviewService::SE_INCOME_TOLERANCE);
    expect($findings[0]['description'])->toBeNull();
});

it('CTX-03: review() emits NO se_income finding when gap is within 20%', function () {
    $user = User::factory()->create();

    // se_income = $80,000, bank = $67,000 → gap = 13k/80k = 16.25% < 20%
    $profile = IncomeOptimizationProfile::factory()->create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'w2_wages' => null,
        'self_employment_income' => '8000000', // $80,000
        'bank_deposit_total' => '6700000',     // $67,000
    ]);

    $service = app(CrossSourceReviewService::class);
    $findings = $service->review($profile, $user, 2025);

    expect($findings)->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// CTX-03: Full job pipeline — assembler → cross-source → findings → event
// ─────────────────────────────────────────────────────────────────────────────

it('CTX-03: job creates w2_deposit_mismatch finding when W-2/deposit gap exceeds 15%', function () {
    Event::fake([OptimizationProfileBuilt::class]);
    Storage::fake('local');

    ['user' => $user, 'account' => $account] = makeReviewTestUserWithBank();

    // W-2 doc: wages = $100,000
    makeReviewTestDoc($user->id, TaxDocumentCategory::W2, ['wages' => '100000.00'], 2025);

    // Bank income: $70,000 total (30% gap vs $100k W-2 → exceeds 15%)
    makeReviewTestIncomeTransaction($user->id, $account->id, '2025-03-15', 35000.00);
    makeReviewTestIncomeTransaction($user->id, $account->id, '2025-09-15', 35000.00);

    // Run job synchronously (tests use sync queue driver)
    (new BuildIncomeOptimizationProfile($user->id, 2025))
        ->handle(
            app(\App\Services\IncomeOptimizerDataAssemblerService::class),
            app(CrossSourceReviewService::class),
        );

    // Finding must exist in DB
    $finding = OptimizationFinding::where('user_id', $user->id)
        ->where('tax_year', 2025)
        ->where('finding_key', 'w2_deposit_mismatch')
        ->first();

    expect($finding)->not->toBeNull();
    expect($finding->finding_type)->toBe('income_discrepancy');
    expect($finding->status)->toBe('open');
    expect($finding->description)->toBeNull(); // Phase 11 fills this

    // OptimizationProfileBuilt event fired with findingCount = 1
    Event::assertDispatched(OptimizationProfileBuilt::class, function ($event) use ($user) {
        return $event->userId === $user->id
            && $event->taxYear === 2025
            && $event->findingCount === 1;
    });
});

it('CTX-03: job creates NO finding when W-2/deposit gap is within 15%', function () {
    Event::fake([OptimizationProfileBuilt::class]);
    Storage::fake('local');

    ['user' => $user, 'account' => $account] = makeReviewTestUserWithBank();

    // W-2 doc: wages = $100,000
    makeReviewTestDoc($user->id, TaxDocumentCategory::W2, ['wages' => '100000.00'], 2025);

    // Bank income: $92,000 total (8% gap vs $100k W-2 → within 15%)
    makeReviewTestIncomeTransaction($user->id, $account->id, '2025-03-15', 46000.00);
    makeReviewTestIncomeTransaction($user->id, $account->id, '2025-09-15', 46000.00);

    (new BuildIncomeOptimizationProfile($user->id, 2025))
        ->handle(
            app(\App\Services\IncomeOptimizerDataAssemblerService::class),
            app(CrossSourceReviewService::class),
        );

    $findingCount = OptimizationFinding::where('user_id', $user->id)
        ->where('tax_year', 2025)
        ->where('finding_key', 'w2_deposit_mismatch')
        ->count();

    expect($findingCount)->toBe(0);

    // Event fires with 0 findings
    Event::assertDispatched(OptimizationProfileBuilt::class, function ($event) use ($user) {
        return $event->userId === $user->id
            && $event->taxYear === 2025
            && $event->findingCount === 0;
    });
});

it('CTX-03: job upserts findings idempotently on re-run (updateOrCreate)', function () {
    Event::fake([OptimizationProfileBuilt::class]);
    Storage::fake('local');

    ['user' => $user, 'account' => $account] = makeReviewTestUserWithBank();

    makeReviewTestDoc($user->id, TaxDocumentCategory::W2, ['wages' => '100000.00'], 2025);
    makeReviewTestIncomeTransaction($user->id, $account->id, '2025-06-01', 60000.00); // 40% gap

    $runJob = fn () => (new BuildIncomeOptimizationProfile($user->id, 2025))
        ->handle(
            app(\App\Services\IncomeOptimizerDataAssemblerService::class),
            app(CrossSourceReviewService::class),
        );

    $runJob();
    $runJob(); // Run twice — should still be only 1 finding row

    $count = OptimizationFinding::where('user_id', $user->id)
        ->where('tax_year', 2025)
        ->where('finding_key', 'w2_deposit_mismatch')
        ->count();

    expect($count)->toBe(1);
});

it('CTX-03: OptimizationProfileBuilt event carries userId, taxYear, and findingCount', function () {
    Event::fake([OptimizationProfileBuilt::class]);
    Storage::fake('local');

    ['user' => $user, 'account' => $account] = makeReviewTestUserWithBank();

    // No TaxDocuments → profile has null w2_wages → no finding
    (new BuildIncomeOptimizationProfile($user->id, 2026))
        ->handle(
            app(\App\Services\IncomeOptimizerDataAssemblerService::class),
            app(CrossSourceReviewService::class),
        );

    Event::assertDispatched(OptimizationProfileBuilt::class, function ($event) use ($user) {
        return $event->userId === $user->id
            && $event->taxYear === 2026
            && is_int($event->findingCount);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// CTX-04: Snapshot answerableFields() — Phase 11 interview skip-logic
// ─────────────────────────────────────────────────────────────────────────────

it('CTX-04: answerableFields() reports filing_status as answerable when set', function () {
    $profile = IncomeOptimizationProfile::factory()->create([
        'filing_status' => 'single',
        'has_hsa_eligible_plan' => false,
        'has_ira' => false,
    ]);

    $answerable = $profile->answerableFields();
    expect($answerable['filing_status'])->toBeTrue();
});

it('CTX-04: answerableFields() reports filing_status as not answerable when null', function () {
    $profile = IncomeOptimizationProfile::factory()->create([
        'filing_status' => null,
    ]);

    $answerable = $profile->answerableFields();
    expect($answerable['filing_status'])->toBeFalse();
});

it('CTX-04: answerableFields() reports HSA plan and IRA flags correctly', function () {
    $profile = IncomeOptimizationProfile::factory()->create([
        'has_hsa_eligible_plan' => true,
        'has_ira' => true,
        'ira_type' => 'roth',
    ]);

    $answerable = $profile->answerableFields();
    expect($answerable['has_hsa_eligible_plan'])->toBeTrue();
    expect($answerable['has_ira'])->toBeTrue();
    expect($answerable['ira_type'])->toBeTrue();
});

it('CTX-04: answerableFields() reports 401k contributions as answerable when ytd > 0', function () {
    $profile = IncomeOptimizationProfile::factory()->create([
        'traditional_401k_ytd' => '250000', // $2,500 in cents
    ]);

    $answerable = $profile->answerableFields();
    expect($answerable['has_401k_contributions'])->toBeTrue();
});

it('CTX-04: answerableFields() reports 401k contributions as not answerable when ytd is null', function () {
    $profile = IncomeOptimizationProfile::factory()->create([
        'traditional_401k_ytd' => null,
    ]);

    $answerable = $profile->answerableFields();
    expect($answerable['has_401k_contributions'])->toBeFalse();
});

it('CTX-04: answerableFields() reports employment_type as answerable when set on snapshot built by job', function () {
    Event::fake([OptimizationProfileBuilt::class]);
    Storage::fake('local');

    $user = User::factory()->create();

    // Financial profile with employment_type and filing status
    UserFinancialProfile::factory()->create([
        'user_id' => $user->id,
        'employment_type' => 'self_employed',
        'tax_filing_status' => 'single',
        'has_hsa' => true,
        'has_ira' => false,
        'ira_type' => null,
        'has_home_office' => true,
    ]);

    (new BuildIncomeOptimizationProfile($user->id, 2025))
        ->handle(
            app(\App\Services\IncomeOptimizerDataAssemblerService::class),
            app(CrossSourceReviewService::class),
        );

    $profile = IncomeOptimizationProfile::forUser($user->id)->where('tax_year', 2025)->first();
    expect($profile)->not->toBeNull();

    $answerable = $profile->answerableFields();
    expect($answerable['employment_type'])->toBeTrue();
    expect($answerable['filing_status'])->toBeTrue();
    expect($answerable['has_hsa_eligible_plan'])->toBeTrue();
    expect($answerable['has_home_office'])->toBeTrue();
});
