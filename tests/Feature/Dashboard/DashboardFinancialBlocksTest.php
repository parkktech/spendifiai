<?php

use App\Models\Subscription;
use App\Models\Transaction;

it('returns recurring_bills, budget_waterfall, and home_affordability in dashboard response', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    // Create income transaction this month (negative amount = income)
    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => -5000,
        'transaction_date' => now()->startOfMonth()->addDays(1),
        'plaid_category' => 'INCOME',
    ]);

    // Create spending transactions this month
    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => 200,
        'transaction_date' => now()->startOfMonth()->addDays(2),
        'plaid_category' => 'FOOD_AND_DRINK',
    ]);

    // Create essential subscription (e.g., rent/utilities)
    Subscription::factory()->essential()->create([
        'user_id' => $user->id,
        'merchant_name' => 'Electric Company',
        'amount' => 150,
        'status' => 'active',
    ]);

    // Create non-essential subscription
    Subscription::factory()->create([
        'user_id' => $user->id,
        'merchant_name' => 'Netflix',
        'amount' => 15.99,
        'status' => 'active',
        'is_essential' => false,
    ]);

    // Create unused subscription
    Subscription::factory()->unused()->create([
        'user_id' => $user->id,
        'merchant_name' => 'Old Gym',
        'amount' => 30,
        'is_essential' => false,
    ]);

    $response = $this->getJson('/api/v1/dashboard');

    $response->assertOk();

    // Verify recurring_bills block exists with all subscriptions (active + unused)
    $response->assertJsonStructure([
        'recurring_bills' => [
            '*' => ['id', 'merchant_name', 'amount', 'frequency', 'status', 'is_essential'],
        ],
        'total_monthly_bills',
        'budget_waterfall' => [
            'monthly_income',
            'essential_bills',
            'non_essential_subscriptions',
            'discretionary_spending',
            'total_spending',
            'monthly_surplus',
            'can_save',
            'savings_rate',
        ],
        'home_affordability' => [
            'monthly_income',
            'monthly_debt',
            'current_dti',
            'down_payment',
            'interest_rate',
            'max_monthly_payment',
            'max_loan_amount',
            'max_home_price',
            'estimated_monthly_mortgage',
            'loan_term_years',
        ],
    ]);

    // Should include all 3 subscriptions (2 active + 1 unused)
    expect($response->json('recurring_bills'))->toHaveCount(3);

    // Total monthly bills = 150 + 15.99 + 30 = 195.99
    expect($response->json('total_monthly_bills'))->toBe(195.99);

    // Budget waterfall income should be 5000
    expect($response->json('budget_waterfall.monthly_income'))->toEqual(5000);

    // Essential bills = 150
    expect($response->json('budget_waterfall.essential_bills'))->toEqual(150);

    // Can save should be true (5000 income - 200 spending = positive surplus)
    expect($response->json('budget_waterfall.can_save'))->toBeTrue();

    // Home affordability should have the default $100k down payment
    expect($response->json('home_affordability.down_payment'))->toBe(100000);

    // Interest rate should be 6.85%
    expect($response->json('home_affordability.interest_rate'))->toBe(6.85);

    // Loan term should be 30 years
    expect($response->json('home_affordability.loan_term_years'))->toBe(30);

    // Max home price should be greater than the down payment
    expect($response->json('home_affordability.max_home_price'))->toBeGreaterThan(100000);
});

it('returns zero home affordability when there is no income', function () {
    ['user' => $user] = createUserWithBank();

    $response = $this->getJson('/api/v1/dashboard');

    $response->assertOk();

    expect($response->json('home_affordability.max_loan_amount'))->toBe(0);
    expect($response->json('home_affordability.max_home_price'))->toBe(100000);
    expect($response->json('home_affordability.max_monthly_payment'))->toEqual(0);
    expect($response->json('budget_waterfall.can_save'))->toBeFalse();
});

it('shows budget waterfall deficit when spending exceeds income', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    // Small income
    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => -1000,
        'transaction_date' => now()->startOfMonth()->addDay(),
        'plaid_category' => 'INCOME',
    ]);

    // Large spending
    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => 2000,
        'transaction_date' => now()->startOfMonth()->addDays(2),
        'plaid_category' => 'SHOPPING',
    ]);

    $response = $this->getJson('/api/v1/dashboard');

    $response->assertOk();

    // Monthly surplus should be negative
    expect($response->json('budget_waterfall.monthly_surplus'))->toBeLessThan(0);
    expect($response->json('budget_waterfall.can_save'))->toBeFalse();
    expect($response->json('budget_waterfall.savings_rate'))->toBeLessThan(0);
});
