<?php

use App\Models\Transaction;

it('can get tax summary', function () {
    ['user' => $user, 'account' => $account] = createUserWithBankAndProfile();

    // Create deductible transactions with tax_category
    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'tax_category' => 'Office Supplies',
        'amount' => 100.00,
        'transaction_date' => '2026-01-15',
    ]);

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'tax_category' => 'Software & SaaS',
        'amount' => 50.00,
        'transaction_date' => '2026-01-20',
    ]);

    $response = $this->getJson('/api/v1/tax/summary?year=2026');

    $response->assertOk()
        ->assertJsonStructure([
            'year',
            'total_deductible',
            'estimated_tax_savings',
            'transaction_categories',
        ]);

    // 3*100 + 2*50 = 400
    expect((float) $response->json('total_deductible'))->toBe(400.0);
});

it('tax summary requires financial profile', function () {
    // createUserWithBank does NOT create a profile -> profile.complete middleware blocks
    createUserWithBank();

    $response = $this->getJson('/api/v1/tax/summary');

    $response->assertForbidden();
});
