<?php

use App\Models\Transaction;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('dashboard includes charitable_giving with donation data', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    Transaction::factory()->donation()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => 500.00,
        'merchant_name' => 'FIRST BAPTIST CHURCH',
        'transaction_date' => now(),
        'donation_note' => 'Monthly tithe - 501(c)(3)',
    ]);

    Transaction::factory()->donation()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => 100.00,
        'merchant_name' => 'RED CROSS',
        'transaction_date' => now(),
        'donation_note' => 'Disaster relief',
    ]);

    $response = $this->getJson('/api/v1/dashboard');

    $response->assertOk();
    $data = $response->json('charitable_giving');

    expect($data)->not->toBeNull();
    expect((float) $data['period_total'])->toBe(600.0);
    expect($data['transaction_count'])->toBe(2);
    expect((float) $data['ytd_total'])->toBeGreaterThanOrEqual(600.0);
    expect((float) $data['estimated_tax_savings'])->toBeGreaterThan(0);
    expect($data['top_recipients'])->toHaveCount(2);
    expect($data['recent_donations'])->toHaveCount(2);
});

it('dashboard charitable_giving shows zeros when no donations', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    // Non-donation transaction
    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => 50.00,
        'ai_category' => 'Food & Groceries',
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/v1/dashboard');

    $response->assertOk();
    $data = $response->json('charitable_giving');

    expect($data)->not->toBeNull();
    expect((float) $data['period_total'])->toBe(0.0);
    expect($data['transaction_count'])->toBe(0);
    expect($data['top_recipients'])->toBeEmpty();
    expect($data['recent_donations'])->toBeEmpty();
});

it('saves donation_note when updating category to Charity & Donations', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'ai_category' => 'Shopping (General)',
        'amount' => 250.00,
    ]);

    $response = $this->patchJson("/api/v1/transactions/{$tx->id}/category", [
        'category' => 'Charity & Donations',
        'donation_note' => 'Salvation Army - Clothing Drive',
    ]);

    $response->assertOk();

    $tx->refresh();
    expect($tx->user_category)->toBe('Charity & Donations');
    expect($tx->donation_note)->toBe('Salvation Army - Clothing Drive');
    expect($tx->tax_deductible)->toBeTrue();
});

it('clears donation_note when category changes away from Charity & Donations', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    $tx = Transaction::factory()->donation()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'donation_note' => 'Church donation',
    ]);

    $response = $this->patchJson("/api/v1/transactions/{$tx->id}/category", [
        'category' => 'Shopping (General)',
    ]);

    $response->assertOk();

    $tx->refresh();
    expect($tx->user_category)->toBe('Shopping (General)');
    expect($tx->donation_note)->toBeNull();
});

it('does not cascade donation_note to matching merchants', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    $tx1 = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'FIRST CHURCH',
        'merchant_normalized' => 'first church',
        'amount' => 200.00,
    ]);

    $tx2 = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'FIRST CHURCH',
        'merchant_normalized' => 'first church',
        'amount' => 200.00,
    ]);

    $this->patchJson("/api/v1/transactions/{$tx1->id}/category", [
        'category' => 'Charity & Donations',
        'donation_note' => 'Sunday offering - 501(c)(3)',
    ]);

    // tx2 should have the category cascaded but NOT the donation_note
    $tx2->refresh();
    expect($tx2->user_category)->toBe('Charity & Donations');
    expect($tx2->tax_deductible)->toBeTrue();
    expect($tx2->donation_note)->toBeNull();
});

it('includes donation_note in tax summary transaction details', function () {
    ['user' => $user, 'account' => $account] = createUserWithBankAndProfile();

    Transaction::factory()->donation()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => 250.00,
        'transaction_date' => now(),
        'donation_note' => 'First Baptist Church - EIN 58-1234567',
    ]);

    $response = $this->getJson('/api/v1/tax/summary?year='.now()->year);

    $response->assertOk();
    $data = $response->json();

    expect((float) $data['schedule_a_total'])->toBe(250.0);
    expect($data['transaction_details'])->toHaveCount(1);
    expect($data['transaction_details'][0]['donation_note'])->toBe('First Baptist Church - EIN 58-1234567');
});

it('auto-sets tax_deductible when category is Charity & Donations', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => false,
    ]);

    $response = $this->patchJson("/api/v1/transactions/{$tx->id}/category", [
        'category' => 'Charity & Donations',
    ]);

    $response->assertOk();

    $tx->refresh();
    expect($tx->tax_deductible)->toBeTrue();
});

it('includes donation_note in transaction resource', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    Transaction::factory()->donation()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'donation_note' => 'Test Church Note',
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/v1/transactions');

    $response->assertOk();
    $txData = $response->json('data.0');
    expect($txData['donation_note'])->toBe('Test Church Note');
});
