<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Transaction;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns tax summary with correct numeric types', function () {
    ['user' => $user, 'account' => $account] = createUserWithBankAndProfile();

    // Create a deductible transaction
    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'ai_category' => 'Office Supplies',
        'amount' => 150.50,
        'transaction_date' => now(),
    ]);

    // Create a deductible order item
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'order_date' => now(),
    ]);

    OrderItem::factory()->create([
        'user_id' => $user->id,
        'order_id' => $order->id,
        'ai_category' => 'Software & Digital Services',
        'tax_deductible' => true,
        'total_price' => 99.99,
    ]);

    $response = $this->getJson('/api/v1/tax/summary?year='.now()->year);

    $response->assertOk();
    $data = $response->json();

    expect($data['year'])->toBe(now()->year);
    expect($data['total_deductible'])->toBeFloat();
    expect($data['estimated_tax_savings'])->toBeFloat();
    expect($data['effective_rate_used'])->toBeFloat();

    // Transaction categories should have numeric totals
    expect($data['transaction_categories'])->toHaveCount(1);
    expect($data['transaction_categories'][0]['category'])->toBe('Office Supplies');
    expect($data['transaction_categories'][0]['total'])->toBeFloat();
    expect($data['transaction_categories'][0]['item_count'])->toBeInt();

    // Order item categories
    expect($data['order_item_categories'])->toHaveCount(1);
    expect($data['order_item_categories'][0]['category'])->toBe('Software & Digital Services');
    expect($data['order_item_categories'][0]['total'])->toBeFloat();
    expect($data['order_item_categories'][0]['item_count'])->toBeInt();

    // Totals add up
    $expectedTotal = 150.50 + 99.99;
    expect($data['total_deductible'])->toBe($expectedTotal);

    // Line item details returned
    expect($data['transaction_details'])->toHaveCount(1);
    expect($data['transaction_details'][0]['source'])->toBe('bank');
    expect($data['transaction_details'][0]['amount'])->toBeFloat();

    expect($data['order_item_details'])->toHaveCount(1);
    expect($data['order_item_details'][0]['source'])->toBe('email');

    // Schedule C map present
    expect($data['schedule_c_map'])->toBeArray();
    expect($data['schedule_c_map'])->toHaveKey('Office Supplies');
});

it('returns empty categories when no deductible data exists', function () {
    createUserWithBankAndProfile();

    $response = $this->getJson('/api/v1/tax/summary?year='.now()->year);

    $response->assertOk();
    $data = $response->json();

    expect((float) $data['total_deductible'])->toBe(0.0);
    expect((float) $data['estimated_tax_savings'])->toBe(0.0);
    expect($data['transaction_categories'])->toBeEmpty();
    expect($data['order_item_categories'])->toBeEmpty();
});

it('uses financial profile tax bracket for savings estimate', function () {
    ['user' => $user, 'account' => $account, 'profile' => $profile] = createUserWithBankAndProfile();

    $profile->update(['estimated_tax_bracket' => 32]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'ai_category' => 'Office Supplies',
        'amount' => 1000.00,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/v1/tax/summary?year='.now()->year);

    $response->assertOk();
    $data = $response->json();

    expect($data['effective_rate_used'])->toBe(0.32);
    expect((float) $data['estimated_tax_savings'])->toBe(320.0);
});

it('merges same categories across transactions and order items', function () {
    ['user' => $user, 'account' => $account] = createUserWithBankAndProfile();

    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'ai_category' => 'Software & Digital Services',
        'amount' => 50.00,
        'transaction_date' => now(),
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'order_date' => now(),
    ]);

    OrderItem::factory()->create([
        'user_id' => $user->id,
        'order_id' => $order->id,
        'ai_category' => 'Software & Digital Services',
        'tax_deductible' => true,
        'total_price' => 100.00,
    ]);

    $response = $this->getJson('/api/v1/tax/summary?year='.now()->year);

    $response->assertOk();
    $data = $response->json();

    expect($data['transaction_categories'])->toHaveCount(1);
    expect($data['order_item_categories'])->toHaveCount(1);
    expect((float) $data['total_deductible'])->toBe(150.0);
});

it('filters by year correctly', function () {
    ['user' => $user, 'account' => $account] = createUserWithBankAndProfile();

    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'ai_category' => 'Office Supplies',
        'amount' => 200.00,
        'transaction_date' => now(),
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'ai_category' => 'Office Supplies',
        'amount' => 500.00,
        'transaction_date' => now()->subYear(),
    ]);

    $response = $this->getJson('/api/v1/tax/summary?year='.now()->year);
    $response->assertOk();
    expect((float) $response->json('total_deductible'))->toBe(200.0);

    $response = $this->getJson('/api/v1/tax/summary?year='.now()->subYear()->year);
    $response->assertOk();
    expect((float) $response->json('total_deductible'))->toBe(500.0);
});
