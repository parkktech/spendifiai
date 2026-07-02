<?php

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\IncomeOptimizationProfile;
use App\Models\TaxDocument;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Services\IncomeOptimizerDataAssemblerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->service = app(IncomeOptimizerDataAssemblerService::class);
    $this->taxYear = 2025;
});

// ─── Helper: create a Ready TaxDocument with extracted_data ──────────────────

function makeReadyDoc(int $userId, TaxDocumentCategory $category, array $extractedData, int $year = 2025): TaxDocument
{
    return TaxDocument::create([
        'user_id' => $userId,
        'original_filename' => 'test.pdf',
        'stored_path' => 'tax-vault/'.$userId.'/'.$year.'/test.pdf',
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

// ─── Helper: create an income Transaction (negative amount = money in) ────────

function makeIncomeTransaction(
    int $userId,
    int $bankAccountId,
    string $date,
    float $dollars,
    string $plaidCategory = 'INCOME',
    string $aiCategory = 'Salary & Wages'
): void {
    \App\Models\Transaction::create([
        'user_id' => $userId,
        'bank_account_id' => $bankAccountId,
        'plaid_transaction_id' => 'txn_'.uniqid('', true),
        'merchant_name' => 'EMPLOYER PAYROLL',
        'amount' => -$dollars,   // negative = income
        'transaction_date' => $date,
        'payment_channel' => 'other',
        'plaid_category' => $plaidCategory,
        'plaid_detailed_category' => 'INCOME_WAGES',
        'ai_category' => $aiCategory,
        'review_status' => 'auto_categorized',
        'expense_type' => 'personal',
        'account_purpose' => 'personal',
        'tax_deductible' => false,
        'is_subscription' => false,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// CTX-01: W-2 wages dollar string → encrypted cent round-trip
// ─────────────────────────────────────────────────────────────────────────────

it('CTX-01: W2 wages 72500.00 dollars stored as 7250000 cents and round-trips via encrypted cast', function () {
    $user = User::factory()->create();

    makeReadyDoc($user->id, TaxDocumentCategory::W2, [
        'wages' => '72500.00',
    ]);

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // w2_wages column must be stored and round-trip correctly
    expect($profile->w2_wages)->not->toBeNull();

    // Cast reads back the encrypted string — cast to int to get cents
    $cents = (int) $profile->w2_wages;
    expect($cents)->toBe(7_250_000);  // $72,500.00 × 100 = 7,250,000 cents
});

it('CTX-01: encryption hides w2_wages from toArray() response', function () {
    $user = User::factory()->create();

    makeReadyDoc($user->id, TaxDocumentCategory::W2, ['wages' => '50000.00']);

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // $hidden ensures money columns do not appear in toArray()/toJson() (API responses)
    $arr = $profile->toArray();
    expect($arr)->not->toHaveKey('w2_wages');
    expect($arr)->not->toHaveKey('bank_deposit_total');
    expect($arr)->not->toHaveKey('mortgage_interest');
});

// ─────────────────────────────────────────────────────────────────────────────
// CTX-02: Profile row with answerable flags + staleness detection
// ─────────────────────────────────────────────────────────────────────────────

it('CTX-02: buildProfile persists answerable flags from UserFinancialProfile', function () {
    $user = User::factory()->create();

    UserFinancialProfile::factory()->create([
        'user_id' => $user->id,
        'tax_filing_status' => 'single',
        'has_hsa' => true,
        'has_ira' => true,
        'ira_type' => 'traditional',
        'has_home_office' => true,
        'employment_type' => 'employed',
    ]);

    $profile = $this->service->buildProfile($user, $this->taxYear);

    expect($profile)->toBeInstanceOf(IncomeOptimizationProfile::class)
        ->and($profile->filing_status)->toBe('single')
        ->and($profile->has_hsa_eligible_plan)->toBeTrue()
        ->and($profile->has_ira)->toBeTrue()
        ->and($profile->ira_type)->toBe('traditional')
        ->and($profile->has_home_office)->toBeTrue()
        ->and($profile->employment_type)->toBe('employed');
});

it('CTX-02: profile_hash changes and isStale() returns true after a new doc is added', function () {
    $user = User::factory()->create();

    // Build initial profile with one doc
    $doc1 = makeReadyDoc($user->id, TaxDocumentCategory::W2, ['wages' => '60000.00']);
    $profile1 = $this->service->buildProfile($user, $this->taxYear);
    $hash1 = $profile1->profile_hash;

    // isStale should be false immediately after building
    expect($this->service->isStale($profile1))->toBeFalse();

    // Add a second Ready doc
    makeReadyDoc($user->id, TaxDocumentCategory::INT_1099, ['interest_income' => '500.00']);

    // isStale should now be true (doc set changed)
    expect($this->service->isStale($profile1))->toBeTrue();

    // Rebuild — hash changes
    $profile2 = $this->service->buildProfile($user, $this->taxYear);
    expect($profile2->profile_hash)->not->toBe($hash1);

    // isStale false again
    expect($this->service->isStale($profile2))->toBeFalse();
});

it('CTX-02: multiple Ready docs sum correctly into single fields (multi-employer W-2)', function () {
    $user = User::factory()->create();

    // Two W-2s from different employers
    makeReadyDoc($user->id, TaxDocumentCategory::W2, ['wages' => '40000.00']);
    makeReadyDoc($user->id, TaxDocumentCategory::W2, ['wages' => '35000.00']);

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // Should sum both W-2s: 75,000 * 100 = 7,500,000 cents
    expect((int) $profile->w2_wages)->toBe(7_500_000);
});

// ─────────────────────────────────────────────────────────────────────────────
// Pitfall 3 guard: calendar-year window vs rolling window
// ─────────────────────────────────────────────────────────────────────────────

it('Pitfall3: bank_deposit_total covers full Jan 1–Dec 31 even when called mid-year', function () {
    // Freeze time to mid-year — a rolling 3-month window from here would miss
    // the January and February income deposits
    Carbon::setTestNow(Carbon::create(2025, 7, 15));

    $user = User::factory()->create();
    $connection = BankConnection::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Enums\ConnectionStatus::Active,
    ]);
    $account = BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
    ]);

    // Spread income across the full year, including months OUTSIDE a 3-month window from July 2025
    $incomeByMonth = [
        '2025-01-15' => 3000.00,  // January — outside 3-month rolling window from July
        '2025-02-15' => 3000.00,  // February — outside 3-month rolling window
        '2025-03-15' => 3000.00,  // March — outside 3-month rolling window
        '2025-04-15' => 3000.00,  // April — on the edge
        '2025-05-15' => 3000.00,  // May
        '2025-06-15' => 3000.00,  // June
        '2025-07-01' => 3000.00,  // July (within last 3 months)
        // Future months not seeded (test is frozen at July 15)
    ];

    foreach ($incomeByMonth as $date => $amount) {
        makeIncomeTransaction($user->id, $account->id, $date, $amount);
    }

    $profile = $this->service->buildProfile($user, 2025);

    $totalCents = (int) $profile->bank_deposit_total;

    // 7 months × $3,000 = $21,000 = 2,100,000 cents
    $expectedCents = 7 * 3000 * 100;
    expect($totalCents)->toBe($expectedCents);

    // A rolling 3-month window from July 2025 would only capture
    // May/June/July = $9,000 = 900,000 cents — assert that's NOT what we got
    $rollingWindowCents = 3 * 3000 * 100;
    expect($totalCents)->not->toBe($rollingWindowCents);

    Carbon::setTestNow(); // reset
});

it('Pitfall3: transactions from a DIFFERENT year are excluded from the snapshot', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Enums\ConnectionStatus::Active,
    ]);
    $account = BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
    ]);

    // 2025 income (should be included)
    makeIncomeTransaction($user->id, $account->id, '2025-06-01', 5000.00);

    // 2024 income (should be excluded from 2025 snapshot)
    makeIncomeTransaction($user->id, $account->id, '2024-12-01', 10000.00);

    $profile = $this->service->buildProfile($user, 2025);

    // Only the 2025 transaction: $5,000 = 500,000 cents
    expect((int) $profile->bank_deposit_total)->toBe(500_000);
});

// ─────────────────────────────────────────────────────────────────────────────
// CTX-04: answerableFields() skip-logic map
// ─────────────────────────────────────────────────────────────────────────────

it('CTX-04: answerableFields() reports filing_status as answerable when set', function () {
    $user = User::factory()->create();
    UserFinancialProfile::factory()->create([
        'user_id' => $user->id,
        'tax_filing_status' => 'single',
        'has_ira' => true,
        'has_home_office' => true,
    ]);

    $profile = $this->service->buildProfile($user, $this->taxYear);
    $answerable = $profile->answerableFields();

    expect($answerable['filing_status'])->toBeTrue()
        ->and($answerable['has_ira'])->toBeTrue()
        ->and($answerable['has_home_office'])->toBeTrue();
});

it('CTX-04: answerableFields() reports fields as not answerable when not set', function () {
    $user = User::factory()->create();
    // No UserFinancialProfile — no flags set

    $profile = $this->service->buildProfile($user, $this->taxYear);
    $answerable = $profile->answerableFields();

    expect($answerable['filing_status'])->toBeFalse()
        ->and($answerable['has_ira'])->toBeFalse()
        ->and($answerable['has_home_office'])->toBeFalse()
        ->and($answerable['has_hsa_eligible_plan'])->toBeFalse();
});

it('CTX-04: answerableFields() reports 401k contributions as answerable when hsa_ytd cents > 0', function () {
    $user = User::factory()->create();
    UserFinancialProfile::factory()->create([
        'user_id' => $user->id,
        'has_hsa' => true,
    ]);

    makeReadyDoc($user->id, TaxDocumentCategory::HSA_5498, ['contributions_made' => '1200.00']);

    $profile = $this->service->buildProfile($user, $this->taxYear);
    $answerable = $profile->answerableFields();

    // hsa_ytd > 0 → has_hsa_contributions answerable
    expect($answerable['has_hsa_contributions'])->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// Document category mapping
// ─────────────────────────────────────────────────────────────────────────────

it('maps 1099-NEC nonemployee_compensation to self_employment_income in cents', function () {
    $user = User::factory()->create();

    makeReadyDoc($user->id, TaxDocumentCategory::NEC_1099, [
        'nonemployee_compensation' => '18500.50',
    ]);

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // $18,500.50 × 100 = 1,850,050 cents
    expect((int) $profile->self_employment_income)->toBe(1_850_050);
});

it('maps 1098-E student loan interest to student_loan_interest in cents', function () {
    $user = User::factory()->create();

    makeReadyDoc($user->id, TaxDocumentCategory::E_1098, ['interest' => '2400.00']);

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // $2,400.00 × 100 = 240,000 cents
    expect((int) $profile->student_loan_interest)->toBe(240_000);
});

it('maps 1098 mortgage interest to mortgage_interest in cents', function () {
    $user = User::factory()->create();

    makeReadyDoc($user->id, TaxDocumentCategory::Mortgage_1098, [
        'mortgage_interest_received' => '12500.00',
    ]);

    $profile = $this->service->buildProfile($user, $this->taxYear);

    expect((int) $profile->mortgage_interest)->toBe(1_250_000);
});

it('transfer-type bank transactions are excluded from bank_deposit_total', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Enums\ConnectionStatus::Active,
    ]);
    $account = BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
    ]);

    // Real income
    makeIncomeTransaction($user->id, $account->id, '2025-03-01', 5000.00, 'INCOME', 'Salary & Wages');

    // Transfer (Zelle) — should be excluded
    \App\Models\Transaction::create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'plaid_transaction_id' => 'txn_transfer_'.uniqid('', true),
        'merchant_name' => 'ZELLE PAYMENT',
        'amount' => -2000.00,
        'transaction_date' => '2025-03-15',
        'payment_channel' => 'other',
        'plaid_category' => 'TRANSFER_IN',
        'plaid_detailed_category' => 'TRANSFER_IN_TRANSFER_IN_FROM_APPS',
        'ai_category' => null,
        'review_status' => 'auto_categorized',
        'expense_type' => 'personal',
        'account_purpose' => 'personal',
        'tax_deductible' => false,
        'is_subscription' => false,
    ]);

    $profile = $this->service->buildProfile($user, 2025);

    // Only $5,000 income; $2,000 transfer excluded → 500,000 cents
    expect((int) $profile->bank_deposit_total)->toBe(500_000);
});

// ─────────────────────────────────────────────────────────────────────────────
// INT-03: answerableFields() durable-fact extension (Task 2 / 11-02)
// ─────────────────────────────────────────────────────────────────────────────

it('INT-03: answerableFields() returns true for a confirmed durable fact key', function () {
    $user = User::factory()->create();

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // Record a confirmed interview_answer fact for 'employer.match_pct'
    \App\Models\UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.match_pct',
        value: '50',
        sourceType: 'interview_answer',
    );

    $answerable = $profile->answerableFields(new \App\Models\UserTaxFact());

    // The confirmed durable fact must make the key answerable
    expect($answerable['employer.match_pct'])->toBeTrue();
});

it('INT-03: answerableFields() returns false for an unconfirmed document_extraction proposal', function () {
    $user = User::factory()->create();

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // Write an unconfirmed document_extraction proposal (is_current=false)
    \App\Models\UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'employer.match_pct',
        value: '50',
        sourceType: 'document_extraction',
    );

    $answerable = $profile->answerableFields(new \App\Models\UserTaxFact());

    // Unconfirmed proposal must NOT count as answerable (confirm gate)
    expect($answerable['employer.match_pct'] ?? false)->toBeFalse();
});

it('INT-03: answerableFields() with no proxy still returns base 9 keys', function () {
    $user = User::factory()->create();

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // Without proxy, existing 9 base keys must still be present
    $answerable = $profile->answerableFields();

    expect($answerable)->toHaveKey('filing_status')
        ->toHaveKey('has_hsa_eligible_plan')
        ->toHaveKey('has_ira')
        ->toHaveKey('ira_type')
        ->toHaveKey('has_home_office')
        ->toHaveKey('has_self_employment')
        ->toHaveKey('has_401k_contributions')
        ->toHaveKey('has_hsa_contributions')
        ->toHaveKey('employment_type');
});

// ─────────────────────────────────────────────────────────────────────────────
// FACT-CHECK FIX (INT-03): readProfileFlags() must expose business_type + housing_status
// ─────────────────────────────────────────────────────────────────────────────

it('FACT-CHECK-FIX: readProfileFlags returns business_type and housing_status when profile exists', function () {
    $user = User::factory()->create();

    UserFinancialProfile::factory()->create([
        'user_id' => $user->id,
        'business_type' => 'sole_proprietor',
        'housing_status' => 'own',
    ]);

    $profile = $this->service->buildProfile($user, $this->taxYear);

    // These keys must be present on the built profile
    // (They are plain string columns, not encrypted)
    // We verify via the snapshot object's fillable/readable columns
    expect($profile->business_type)->toBe('sole_proprietor');
    expect($profile->housing_status)->toBe('own');
});

it('FACT-CHECK-FIX: readProfileFlags includes business_type=null when no profile exists', function () {
    $user = User::factory()->create();
    // No UserFinancialProfile — defaults must include the two new keys as null

    $profile = $this->service->buildProfile($user, $this->taxYear);

    expect($profile->business_type)->toBeNull();
    expect($profile->housing_status)->toBeNull();
});
