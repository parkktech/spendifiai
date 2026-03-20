<?php

use App\Models\CancellationProvider;
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

    // Seed a known provider so the detector recognizes NETFLIX without AI
    CancellationProvider::create([
        'company_name' => 'Netflix',
        'slug' => 'netflix',
        'aliases' => ['netflix'],
        'category' => 'Streaming',
        'difficulty' => 'easy',
    ]);

    // Create recurring transactions for "NETFLIX" with monthly intervals (within 6-month lookback)
    $dates = [
        now()->subMonths(4)->format('Y-m-d'),
        now()->subMonths(3)->format('Y-m-d'),
        now()->subMonths(2)->format('Y-m-d'),
        now()->subMonths(1)->format('Y-m-d'),
    ];

    foreach ($dates as $date) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'bank_account_id' => $account->id,
            'merchant_name' => 'NETFLIX',
            'amount' => 15.99,
            'transaction_date' => $date,
            'plaid_category' => 'ENTERTAINMENT',
        ]);
    }

    $response = $this->postJson('/api/v1/subscriptions/detect');

    $response->assertOk();
    expect($response->json('detected'))->toBeGreaterThanOrEqual(1);
    expect(Subscription::where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(1);
});
