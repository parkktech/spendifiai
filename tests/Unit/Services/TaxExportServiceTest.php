<?php

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Services\TaxExportService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function getMapToScheduleC(): ReflectionMethod
{
    $reflection = new ReflectionMethod(TaxExportService::class, 'mapToScheduleC');
    $reflection->setAccessible(true);
    return $reflection;
}

function getGatherTaxData(): ReflectionMethod
{
    $reflection = new ReflectionMethod(TaxExportService::class, 'gatherTaxData');
    $reflection->setAccessible(true);
    return $reflection;
}

it('maps Marketing & Advertising to Schedule C Line 8', function () {
    $service = new TaxExportService();
    $method = getMapToScheduleC();

    $result = $method->invoke($service, [
        ['tax_category' => 'Marketing & Advertising', 'total' => 500, 'item_count' => 5],
    ]);

    expect($result[0]['line'])->toBe('8');
    expect($result[0]['label'])->toBe('Advertising');
    expect($result[0]['total'])->toBe(500);
});

it('maps Gas & Fuel to Schedule C Line 9', function () {
    $service = new TaxExportService();
    $method = getMapToScheduleC();

    $result = $method->invoke($service, [
        ['tax_category' => 'Gas & Fuel', 'total' => 300, 'item_count' => 10],
    ]);

    expect($result[0]['line'])->toBe('9');
    expect($result[0]['label'])->toBe('Car and truck expenses');
});

it('maps Office Supplies to Schedule C Line 18', function () {
    $service = new TaxExportService();
    $method = getMapToScheduleC();

    $result = $method->invoke($service, [
        ['tax_category' => 'Office Supplies', 'total' => 200, 'item_count' => 8],
    ]);

    expect($result[0]['line'])->toBe('18');
    expect($result[0]['label'])->toBe('Office expense');
});

it('maps unknown category to Line 27a (Other expenses)', function () {
    $service = new TaxExportService();
    $method = getMapToScheduleC();

    $result = $method->invoke($service, [
        ['tax_category' => 'Some Random Category', 'total' => 100, 'item_count' => 2],
    ]);

    expect($result[0]['line'])->toBe('27a');
    expect($result[0]['label'])->toBe('Other expenses');
});

it('aggregates multiple categories to same line', function () {
    $service = new TaxExportService();
    $method = getMapToScheduleC();

    $result = $method->invoke($service, [
        ['tax_category' => 'Gas & Fuel', 'total' => 300, 'item_count' => 10],
        ['tax_category' => 'Auto Maintenance', 'total' => 200, 'item_count' => 5],
    ]);

    // Both map to Line 9
    expect($result)->toHaveCount(1);
    expect($result[0]['line'])->toBe('9');
    expect($result[0]['total'])->toBe(500);
    expect($result[0]['categories'])->toHaveCount(2);
});

it('gatherTaxData returns correct deductible totals', function () {
    $user = User::factory()->create();
    UserFinancialProfile::factory()->create([
        'user_id' => $user->id,
        'employment_type' => 'self_employed',
    ]);
    $connection = BankConnection::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);
    $account = BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
    ]);

    // Create deductible transactions for year 2026
    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'tax_category' => 'Office Supplies',
        'amount' => 100.00,
        'transaction_date' => '2026-03-15',
    ]);

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'tax_deductible' => true,
        'tax_category' => 'Software & SaaS',
        'amount' => 50.00,
        'transaction_date' => '2026-06-10',
    ]);

    $service = new TaxExportService();
    $method = getGatherTaxData();
    $data = $method->invoke($service, $user, 2026);

    // 3 * 100 + 2 * 50 = 400
    expect((float) $data['summary']['total_deductible_transactions'])->toBe(400.0);
    expect($data['year'])->toBe(2026);
});
