<?php

use App\Models\Transaction;
use App\Models\User;

it('can list transactions', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    Transaction::factory()->count(5)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
    ]);

    $response = $this->getJson('/api/v1/transactions');

    $response->assertOk()
        ->assertJsonStructure(['data']);

    expect($response->json('data'))->toHaveCount(5);
});

it('can filter transactions by account_purpose', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'account_purpose' => 'business',
    ]);

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'account_purpose' => 'personal',
    ]);

    $response = $this->getJson('/api/v1/transactions?purpose=business');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('can filter transactions by date range', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'transaction_date' => '2026-01-15',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'transaction_date' => '2026-03-15',
    ]);

    $response = $this->getJson('/api/v1/transactions?from=2026-01-01&to=2026-01-31');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('can update transaction category', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
    ]);

    $response = $this->patchJson("/api/v1/transactions/{$transaction->id}/category", [
        'category' => 'Office Supplies',
    ]);

    $response->assertOk();

    $transaction->refresh();
    expect($transaction->user_category)->toBe('Office Supplies');
    expect($transaction->review_status->value)->toBe('user_confirmed');
});

it('cannot access other users transactions', function () {
    // Create user A with a transaction
    ['user' => $userA, 'account' => $accountA] = createUserWithBank();
    $transactionA = Transaction::factory()->create([
        'user_id' => $userA->id,
        'bank_account_id' => $accountA->id,
    ]);

    // Create user B (now acts as the authenticated user)
    $data = createUserWithBank();

    // User B tries to update User A's transaction
    $response = $this->patchJson("/api/v1/transactions/{$transactionA->id}/category", [
        'category' => 'Hacked',
    ]);

    $response->assertForbidden();
});
