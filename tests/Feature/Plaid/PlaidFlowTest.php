<?php

use App\Events\BankConnected;
use App\Jobs\CategorizePendingTransactions;
use App\Models\BankConnection;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Set Plaid config so the PlaidService constructor doesn't throw
    config([
        'services.plaid.client_id' => 'test_client_id',
        'services.plaid.secret' => 'test_secret',
        'services.plaid.env' => 'sandbox',
    ]);

    Http::fake([
        'sandbox.plaid.com/link/token/create' => Http::response([
            'link_token' => 'link-sandbox-test',
            'expiration' => '2026-12-31T00:00:00Z',
        ]),
        'sandbox.plaid.com/item/public_token/exchange' => Http::response([
            'access_token' => 'access-sandbox-test',
            'item_id' => 'item-sandbox-test',
        ]),
        'sandbox.plaid.com/item/get' => Http::response([
            'item' => ['institution_id' => 'ins_109508'],
        ]),
        'sandbox.plaid.com/institutions/get_by_id' => Http::response([
            'institution' => ['name' => 'Chase', 'institution_id' => 'ins_109508'],
        ]),
        'sandbox.plaid.com/accounts/get' => Http::response([
            'accounts' => [[
                'account_id' => 'acc_test_123',
                'name' => 'Checking',
                'type' => 'depository',
                'subtype' => 'checking',
                'mask' => '1234',
                'official_name' => null,
                'balances' => ['current' => 1000.00, 'available' => 900.00],
            ]],
        ]),
        'sandbox.plaid.com/transactions/sync' => Http::response([
            'added' => [[
                'transaction_id' => 'txn_test_1',
                'account_id' => 'acc_test_123',
                'name' => 'WHOLE FOODS',
                'merchant_name' => 'Whole Foods',
                'amount' => 85.47,
                'date' => '2026-01-15',
                'authorized_date' => '2026-01-15',
                'payment_channel' => 'in store',
                'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_GROCERIES'],
            ]],
            'modified' => [],
            'removed' => [],
            'next_cursor' => 'cursor_123',
            'has_more' => false,
        ]),
        'sandbox.plaid.com/item/remove' => Http::response(['request_id' => 'req_test']),
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => '[]']],
        ]),
    ]);
});

it('can create link token', function () {
    $user = createAuthenticatedUser();

    $response = $this->postJson('/api/v1/plaid/link-token');

    $response->assertOk()
        ->assertJsonPath('link_token', 'link-sandbox-test');
});

it('can exchange public token and connect bank', function () {
    Event::fake([BankConnected::class]);

    $user = createAuthenticatedUser();

    $response = $this->postJson('/api/v1/plaid/exchange', [
        'public_token' => 'public-sandbox-test',
    ]);

    $response->assertOk()
        ->assertJsonPath('institution', 'Chase');

    // Verify BankConnection was created
    expect(BankConnection::where('user_id', $user->id)->count())->toBe(1);

    // Verify BankAccount was created
    expect(BankAccount::where('user_id', $user->id)->count())->toBe(1);

    Event::assertDispatched(BankConnected::class);
});

it('can sync transactions', function () {
    Queue::fake([CategorizePendingTransactions::class]);

    ['user' => $user, 'connection' => $connection, 'account' => $account] = createUserWithBank();

    // The account needs a plaid_account_id matching the fake response
    $account->update(['plaid_account_id' => 'acc_test_123']);

    $response = $this->postJson('/api/v1/plaid/sync');

    $response->assertOk()
        ->assertJsonPath('added', 1);

    expect(Transaction::where('user_id', $user->id)->count())->toBe(1);

    Queue::assertPushed(CategorizePendingTransactions::class);
});

it('can disconnect bank', function () {
    ['user' => $user, 'connection' => $connection] = createUserWithBank();

    $response = $this->deleteJson("/api/v1/plaid/{$connection->id}");

    $response->assertOk();

    // Connection should be deleted
    expect(BankConnection::find($connection->id))->toBeNull();
});
