<?php

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SubscriptionDetectorService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createDetectorTestData(): array
{
    $user = User::factory()->create();
    $connection = BankConnection::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);
    $account = BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
    ]);

    return compact('user', 'connection', 'account');
}

it('detects monthly subscriptions from recurring charges', function () {
    ['user' => $user, 'account' => $account] = createDetectorTestData();

    // Create 4 monthly NETFLIX charges
    $dates = ['2025-09-15', '2025-10-15', '2025-11-15', '2025-12-15'];
    foreach ($dates as $date) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'bank_account_id' => $account->id,
            'merchant_name' => 'NETFLIX',
            'amount' => 15.99,
            'transaction_date' => $date,
        ]);
    }

    $service = new SubscriptionDetectorService;
    $result = $service->detectSubscriptions($user->id);

    expect($result['detected'])->toBeGreaterThanOrEqual(1);

    $sub = Subscription::where('user_id', $user->id)
        ->where('merchant_normalized', 'Netflix')
        ->first();
    expect($sub)->not->toBeNull();
    expect($sub->frequency)->toBe('monthly');
});

it('detects weekly subscriptions from frequent charges', function () {
    ['user' => $user, 'account' => $account] = createDetectorTestData();

    // Create 5 weekly charges
    $dates = ['2025-12-01', '2025-12-08', '2025-12-15', '2025-12-22', '2025-12-29'];
    foreach ($dates as $date) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'bank_account_id' => $account->id,
            'merchant_name' => 'MEAL PREP CO',
            'amount' => 29.99,
            'transaction_date' => $date,
        ]);
    }

    $service = new SubscriptionDetectorService;
    $result = $service->detectSubscriptions($user->id);

    expect($result['detected'])->toBeGreaterThanOrEqual(1);

    $sub = Subscription::where('user_id', $user->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->frequency)->toBe('weekly');
});

it('does not detect subscription from inconsistent charges', function () {
    ['user' => $user, 'account' => $account] = createDetectorTestData();

    // 2 transactions with wildly different amounts at irregular intervals
    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'RANDOM STORE',
        'amount' => 15.00,
        'transaction_date' => '2025-09-05',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'RANDOM STORE',
        'amount' => 150.00,
        'transaction_date' => '2025-12-20',
    ]);

    $service = new SubscriptionDetectorService;
    $result = $service->detectSubscriptions($user->id);

    expect($result['detected'])->toBe(0);
});

it('does not detect subscription from single charge', function () {
    ['user' => $user, 'account' => $account] = createDetectorTestData();

    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'ONE TIME SHOP',
        'amount' => 49.99,
        'transaction_date' => '2025-11-15',
    ]);

    $service = new SubscriptionDetectorService;
    $result = $service->detectSubscriptions($user->id);

    expect($result['detected'])->toBe(0);
});

it('marks subscriptions as unused when no charge in over 2x billing cycle', function () {
    ['user' => $user] = createDetectorTestData();

    // Create a monthly subscription with last_charge_date 65 days ago (> 2× monthly = 60 days)
    Subscription::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'is_essential' => false,
        'frequency' => 'monthly',
        'last_charge_date' => now()->subDays(65),
    ]);

    $service = new SubscriptionDetectorService;
    $service->detectSubscriptions($user->id);

    $sub = Subscription::where('user_id', $user->id)->first();
    expect($sub->status->value)->toBe('unused');
});

it('keeps subscription active when charge is within expected interval', function () {
    ['user' => $user] = createDetectorTestData();

    // Create a monthly subscription charged 40 days ago (< 2× monthly = 60 days)
    Subscription::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'is_essential' => false,
        'frequency' => 'monthly',
        'last_charge_date' => now()->subDays(40),
    ]);

    $service = new SubscriptionDetectorService;
    $service->detectSubscriptions($user->id);

    $sub = Subscription::where('user_id', $user->id)->first();
    expect($sub->status->value)->toBe('active');
});
