<?php

use App\Models\Subscription;
use App\Models\Transaction;

it('can list subscriptions', function () {
    ['user' => $user] = createUserWithBank();

    Subscription::factory()->count(3)->create([
        'user_id' => $user->id,
    ]);

    $response = $this->getJson('/api/v1/subscriptions');

    $response->assertOk()
        ->assertJsonStructure(['subscriptions', 'total_monthly', 'total_annual']);

    expect($response->json('subscriptions'))->toHaveCount(3);
});

it('can detect subscriptions from transaction patterns', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    // Create recurring transactions for "NETFLIX" with monthly intervals
    $dates = [
        '2025-09-15', '2025-10-15', '2025-11-15', '2025-12-15',
    ];

    foreach ($dates as $date) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'bank_account_id' => $account->id,
            'merchant_name' => 'NETFLIX',
            'amount' => 15.99,
            'transaction_date' => $date,
        ]);
    }

    $response = $this->postJson('/api/v1/subscriptions/detect');

    $response->assertOk();
    expect($response->json('detected'))->toBeGreaterThanOrEqual(1);
    expect(Subscription::where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(1);
});
