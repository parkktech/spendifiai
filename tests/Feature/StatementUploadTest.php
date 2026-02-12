<?php

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\StatementUpload;
use App\Models\Transaction;
use App\Services\BankStatementParserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('rejects upload without a file', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/statements/upload', [
        'bank_name' => 'Chase',
        'account_type' => 'checking',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

it('rejects upload with invalid file type', function () {
    createAuthenticatedUser();
    Storage::fake('local');

    $file = UploadedFile::fake()->create('statement.exe', 100);

    $response = $this->postJson('/api/v1/statements/upload', [
        'file' => $file,
        'bank_name' => 'Chase',
        'account_type' => 'checking',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

it('rejects upload with missing bank_name', function () {
    createAuthenticatedUser();
    Storage::fake('local');

    $file = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

    $response = $this->postJson('/api/v1/statements/upload', [
        'file' => $file,
        'account_type' => 'checking',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['bank_name']);
});

it('rejects upload with invalid account_type', function () {
    createAuthenticatedUser();
    Storage::fake('local');

    $file = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

    $response = $this->postJson('/api/v1/statements/upload', [
        'file' => $file,
        'bank_name' => 'Chase',
        'account_type' => 'invalid_type',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['account_type']);
});

it('uploads a CSV and extracts transactions', function () {
    $user = createAuthenticatedUser();
    Storage::fake('local');
    Queue::fake();

    $mockTransactions = [
        [
            'row_index' => 0,
            'date' => '2026-01-15',
            'description' => 'AMAZON PURCHASE',
            'amount' => 49.99,
            'merchant_name' => 'Amazon',
            'is_income' => false,
            'is_duplicate' => false,
            'confidence' => 0.90,
            'original_text' => '01/15/2026,AMAZON PURCHASE,49.99',
        ],
        [
            'row_index' => 1,
            'date' => '2026-01-20',
            'description' => 'PAYROLL DEPOSIT',
            'amount' => 3000.00,
            'merchant_name' => 'Payroll',
            'is_income' => true,
            'is_duplicate' => false,
            'confidence' => 0.90,
            'original_text' => '01/20/2026,PAYROLL DEPOSIT,-3000.00',
        ],
    ];

    $this->mock(BankStatementParserService::class, function ($mock) use ($mockTransactions) {
        $mock->shouldReceive('parseFile')
            ->once()
            ->andReturn([
                'transactions' => $mockTransactions,
                'processing_notes' => [],
            ]);

        $mock->shouldReceive('detectDuplicates')
            ->once()
            ->andReturn([
                'transactions' => $mockTransactions,
                'duplicates_found' => 0,
                'notes' => [],
            ]);
    });

    $file = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

    $response = $this->postJson('/api/v1/statements/upload', [
        'file' => $file,
        'bank_name' => 'Chase',
        'account_type' => 'checking',
        'nickname' => 'My Checking',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'upload_id',
            'file_name',
            'total_extracted',
            'duplicates_found',
            'transactions',
            'date_range' => ['from', 'to'],
            'processing_notes',
        ]);

    expect($response->json('total_extracted'))->toBe(2);
    expect($response->json('duplicates_found'))->toBe(0);

    // Verify database records
    expect(StatementUpload::where('user_id', $user->id)->count())->toBe(1);
    expect(BankConnection::where('user_id', $user->id)->whereNull('plaid_item_id')->count())->toBe(1);
    expect(BankAccount::where('user_id', $user->id)->count())->toBe(1);
});

it('imports transactions from upload', function () {
    $user = createAuthenticatedUser();
    Queue::fake();

    // Create manual connection and account
    $connection = BankConnection::factory()->create([
        'user_id' => $user->id,
        'plaid_item_id' => null,
        'plaid_access_token' => null,
        'institution_id' => null,
        'institution_name' => 'Chase',
    ]);

    $account = BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
        'plaid_account_id' => null,
    ]);

    $upload = StatementUpload::create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'file_name' => 'test.csv',
        'original_file_name' => 'statement.csv',
        'file_path' => 'statements/test.csv',
        'file_type' => 'csv',
        'bank_name' => 'Chase',
        'account_type' => 'checking',
        'status' => 'complete',
        'total_extracted' => 2,
    ]);

    $response = $this->postJson('/api/v1/statements/import', [
        'upload_id' => $upload->id,
        'transactions' => [
            [
                'date' => '2026-01-15',
                'description' => 'AMAZON PURCHASE',
                'amount' => 49.99,
                'merchant_name' => 'Amazon',
                'is_income' => false,
            ],
            [
                'date' => '2026-01-20',
                'description' => 'PAYROLL DEPOSIT',
                'amount' => 3000.00,
                'merchant_name' => 'Payroll',
                'is_income' => true,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonStructure(['imported', 'skipped', 'errors', 'message']);

    expect($response->json('imported'))->toBe(2);

    // Verify transactions in DB
    $transactions = Transaction::where('user_id', $user->id)->get();
    expect($transactions)->toHaveCount(2);

    // Verify spending transaction (positive amount)
    $spending = $transactions->firstWhere('merchant_name', 'Amazon');
    expect((float) $spending->amount)->toBe(49.99);
    expect($spending->review_status->value)->toBe('pending_ai');

    // Verify income transaction (negative amount)
    $income = $transactions->firstWhere('merchant_name', 'Payroll');
    expect((float) $income->amount)->toBe(-3000.00);

    // Verify upload record updated
    $upload->refresh();
    expect($upload->transactions_imported)->toBe(2);
});

it('prevents importing to another users upload', function () {
    $userA = createAuthenticatedUser();
    $otherUser = \App\Models\User::factory()->create();

    $upload = StatementUpload::create([
        'user_id' => $otherUser->id,
        'file_name' => 'test.csv',
        'original_file_name' => 'statement.csv',
        'file_path' => 'statements/test.csv',
        'file_type' => 'csv',
        'bank_name' => 'Chase',
        'account_type' => 'checking',
        'status' => 'complete',
    ]);

    $response = $this->postJson('/api/v1/statements/import', [
        'upload_id' => $upload->id,
        'transactions' => [
            [
                'date' => '2026-01-15',
                'description' => 'Test',
                'amount' => 10.00,
                'merchant_name' => 'Test',
                'is_income' => false,
            ],
        ],
    ]);

    $response->assertNotFound();
});

it('returns upload history', function () {
    $user = createAuthenticatedUser();

    StatementUpload::create([
        'user_id' => $user->id,
        'file_name' => 'test1.csv',
        'original_file_name' => 'jan_statement.csv',
        'file_path' => 'statements/test1.csv',
        'file_type' => 'csv',
        'bank_name' => 'Chase',
        'account_type' => 'checking',
        'status' => 'complete',
        'transactions_imported' => 15,
        'duplicates_found' => 2,
        'date_range_from' => '2026-01-01',
        'date_range_to' => '2026-01-31',
    ]);

    StatementUpload::create([
        'user_id' => $user->id,
        'file_name' => 'test2.csv',
        'original_file_name' => 'feb_statement.csv',
        'file_path' => 'statements/test2.csv',
        'file_type' => 'csv',
        'bank_name' => 'Chase',
        'account_type' => 'checking',
        'status' => 'error',
    ]);

    $response = $this->getJson('/api/v1/statements/history');

    $response->assertOk();

    // Only completed uploads should appear
    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['file_name'])->toBe('jan_statement.csv');
    expect($data[0]['transactions_imported'])->toBe(15);
});

it('does not require bank.connected middleware for statement endpoints', function () {
    // A user with NO bank connections should still be able to upload
    createAuthenticatedUser();

    $response = $this->getJson('/api/v1/statements/history');

    $response->assertOk();
});

it('counts completed statement uploads for hasBankConnected', function () {
    $user = createAuthenticatedUser();

    // Before upload, user has no bank connected
    expect($user->hasBankConnected())->toBeFalse();

    // Add a completed statement upload
    StatementUpload::create([
        'user_id' => $user->id,
        'file_name' => 'test.csv',
        'original_file_name' => 'statement.csv',
        'file_path' => 'statements/test.csv',
        'file_type' => 'csv',
        'bank_name' => 'Chase',
        'account_type' => 'checking',
        'status' => 'complete',
    ]);

    // Now user should be considered as having a bank connected
    expect($user->hasBankConnected())->toBeTrue();
});

it('rejects import with empty transactions array', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/statements/import', [
        'upload_id' => 1,
        'transactions' => [],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['transactions']);
});
