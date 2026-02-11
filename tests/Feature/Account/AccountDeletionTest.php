<?php

use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'sandbox.plaid.com/item/remove' => Http::response(['request_id' => 'req_test']),
    ]);
});

it('can delete own account', function () {
    ['user' => $user, 'connection' => $connection, 'account' => $account] = createUserWithBank();

    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
    ]);

    $userId = $user->id;

    $response = $this->deleteJson('/api/v1/account', [
        'password' => 'password',
    ]);

    $response->assertNoContent();

    expect(User::find($userId))->toBeNull();
    expect(BankConnection::where('user_id', $userId)->count())->toBe(0);
});

it('delete account requires correct password', function () {
    createUserWithBank();

    $response = $this->deleteJson('/api/v1/account', [
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
});

it('delete account revokes all tokens', function () {
    $user = createAuthenticatedUser();

    // Create additional tokens
    $user->createToken('device-2');
    $user->createToken('device-3');

    $userId = $user->id;

    $response = $this->deleteJson('/api/v1/account', [
        'password' => 'password',
    ]);

    $response->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $userId,
    ]);
});
