<?php

use App\Models\SavingsLedger;
use App\Models\SavingsRecommendation;
use App\Models\Subscription;

it('responds to recommendation as cancelled and records full savings', function () {
    ['user' => $user, 'connection' => $connection, 'account' => $account] = createUserWithBank();

    $rec = SavingsRecommendation::factory()->create([
        'user_id' => $user->id,
        'monthly_savings' => 50.00,
        'annual_savings' => 600.00,
        'status' => 'active',
    ]);

    $response = $this->postJson("/api/v1/savings/{$rec->id}/respond", [
        'response_type' => 'cancelled',
    ]);

    $response->assertOk()
        ->assertJsonPath('recommendation.status', 'applied')
        ->assertJsonPath('recommendation.response_type', 'cancelled');

    expect((float) $response->json('recommendation.actual_monthly_savings'))->toBe(50.00);

    $rec->refresh();
    expect($rec->status)->toBe('applied');
    expect($rec->response_type)->toBe('cancelled');
    expect((float) $rec->actual_monthly_savings)->toBe(50.00);
    expect($rec->applied_at)->not->toBeNull();

    // Verify ledger entry
    $ledger = SavingsLedger::where('user_id', $user->id)
        ->where('source_type', 'recommendation')
        ->where('source_id', $rec->id)
        ->first();
    expect($ledger)->not->toBeNull();
    expect((float) $ledger->monthly_savings)->toBe(50.00);
});

it('responds to recommendation as reduced with partial savings', function () {
    ['user' => $user] = createUserWithBank();

    $rec = SavingsRecommendation::factory()->create([
        'user_id' => $user->id,
        'monthly_savings' => 100.00,
        'annual_savings' => 1200.00,
        'status' => 'active',
    ]);

    $response = $this->postJson("/api/v1/savings/{$rec->id}/respond", [
        'response_type' => 'reduced',
        'new_amount' => 30.00,
    ]);

    $response->assertOk()
        ->assertJsonPath('recommendation.status', 'applied')
        ->assertJsonPath('recommendation.response_type', 'reduced');

    expect((float) $response->json('recommendation.actual_monthly_savings'))->toBe(70.00);

    $rec->refresh();
    expect((float) $rec->actual_monthly_savings)->toBe(70.00);
    expect((float) $rec->response_data['new_amount'])->toBe(30.00);

    // Verify ledger entry
    $ledger = SavingsLedger::where('source_type', 'recommendation')
        ->where('source_id', $rec->id)
        ->first();
    expect($ledger)->not->toBeNull();
    expect((float) $ledger->monthly_savings)->toBe(70.00);
});

it('responds to recommendation as kept with reason and dismisses it', function () {
    ['user' => $user] = createUserWithBank();

    $rec = SavingsRecommendation::factory()->create([
        'user_id' => $user->id,
        'monthly_savings' => 25.00,
        'status' => 'active',
    ]);

    $response = $this->postJson("/api/v1/savings/{$rec->id}/respond", [
        'response_type' => 'kept',
        'reason' => 'I need this service for work.',
    ]);

    $response->assertOk()
        ->assertJsonPath('recommendation.status', 'dismissed')
        ->assertJsonPath('recommendation.response_type', 'kept');

    // actual_monthly_savings is 0 for kept (not null), but resource returns null for 0
    expect($response->json('recommendation.actual_monthly_savings'))->toBeIn([0, null]);

    $rec->refresh();
    expect($rec->status)->toBe('dismissed');
    expect($rec->dismissed_at)->not->toBeNull();
    expect((float) $rec->actual_monthly_savings)->toBe(0.00);
    expect($rec->response_data['reason'])->toBe('I need this service for work.');

    // No ledger entry for kept (zero savings)
    $ledger = SavingsLedger::where('source_type', 'recommendation')
        ->where('source_id', $rec->id)
        ->first();
    expect($ledger)->toBeNull();
});

it('validates recommendation response fields', function () {
    ['user' => $user] = createUserWithBank();

    $rec = SavingsRecommendation::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    // Missing response_type
    $this->postJson("/api/v1/savings/{$rec->id}/respond", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['response_type']);

    // Invalid response_type
    $this->postJson("/api/v1/savings/{$rec->id}/respond", [
        'response_type' => 'invalid',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['response_type']);

    // Missing new_amount when reduced
    $this->postJson("/api/v1/savings/{$rec->id}/respond", [
        'response_type' => 'reduced',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['new_amount']);
});

it('responds to subscription as cancelled and records savings', function () {
    ['user' => $user] = createUserWithBank();

    $sub = Subscription::factory()->create([
        'user_id' => $user->id,
        'amount' => 15.99,
        'annual_cost' => 191.88,
    ]);

    $response = $this->postJson("/api/v1/subscriptions/{$sub->id}/respond", [
        'response_type' => 'cancelled',
    ]);

    $response->assertOk()
        ->assertJsonPath('subscription.status', 'cancelled')
        ->assertJsonPath('subscription.response_type', 'cancelled');

    $sub->refresh();
    expect($sub->status->value)->toBe('cancelled');
    expect((float) $sub->previous_amount)->toBe(15.99);
    expect($sub->responded_at)->not->toBeNull();

    // Verify ledger entry
    $ledger = SavingsLedger::where('source_type', 'subscription')
        ->where('source_id', $sub->id)
        ->first();
    expect($ledger)->not->toBeNull();
    expect((float) $ledger->monthly_savings)->toBe(15.99);
});

it('responds to subscription as reduced and updates amount', function () {
    ['user' => $user] = createUserWithBank();

    $sub = Subscription::factory()->create([
        'user_id' => $user->id,
        'amount' => 22.99,
        'annual_cost' => 275.88,
    ]);

    $response = $this->postJson("/api/v1/subscriptions/{$sub->id}/respond", [
        'response_type' => 'reduced',
        'new_amount' => 15.49,
    ]);

    $response->assertOk()
        ->assertJsonPath('subscription.response_type', 'reduced');

    $sub->refresh();
    expect((float) $sub->amount)->toBe(15.49);
    expect((float) $sub->previous_amount)->toBe(22.99);
    expect((float) $sub->annual_cost)->toBe(185.88);

    // Verify ledger entry with partial savings
    $ledger = SavingsLedger::where('source_type', 'subscription')
        ->where('source_id', $sub->id)
        ->first();
    expect($ledger)->not->toBeNull();
    expect((float) $ledger->monthly_savings)->toBe(7.50);
});

it('returns projected savings totals', function () {
    ['user' => $user] = createUserWithBank();

    SavingsRecommendation::factory()->create([
        'user_id' => $user->id,
        'status' => 'applied',
        'response_type' => 'cancelled',
        'actual_monthly_savings' => 40.00,
    ]);

    Subscription::factory()->create([
        'user_id' => $user->id,
        'response_type' => 'cancelled',
        'previous_amount' => 15.00,
        'status' => 'cancelled',
    ]);

    $response = $this->getJson('/api/v1/savings/projected');

    $response->assertOk();

    expect((float) $response->json('projected_monthly_savings'))->toBe(55.00);
    expect((float) $response->json('projected_annual_savings'))->toBe(660.00);
});

it('returns savings tracking history', function () {
    ['user' => $user] = createUserWithBank();

    SavingsLedger::create([
        'user_id' => $user->id,
        'source_type' => 'recommendation',
        'source_id' => 1,
        'action_taken' => 'cancelled',
        'monthly_savings' => 25.00,
        'month' => now()->format('Y-m'),
    ]);

    SavingsLedger::create([
        'user_id' => $user->id,
        'source_type' => 'subscription',
        'source_id' => 1,
        'action_taken' => 'cancelled',
        'monthly_savings' => 10.00,
        'month' => now()->format('Y-m'),
    ]);

    $response = $this->getJson('/api/v1/savings/tracking');

    $response->assertOk();

    $data = $response->json();
    expect($data)->toBeArray();
    expect($data)->not->toBeEmpty();

    // Find current month's entry
    $currentMonth = now()->format('Y-m');
    $monthEntry = collect($data)->firstWhere('month', $currentMonth);

    expect($monthEntry)->not->toBeNull();
    expect((float) $monthEntry['total_savings'])->toBe(35.00);
    expect((int) $monthEntry['actions_count'])->toBe(2);
    expect((float) $monthEntry['subscription_savings'])->toBe(10.00);
    expect((float) $monthEntry['recommendation_savings'])->toBe(25.00);
});

it('prevents responding to another users recommendation', function () {
    createUserWithBank();

    $otherUser = \App\Models\User::factory()->create();
    $rec = SavingsRecommendation::factory()->create([
        'user_id' => $otherUser->id,
        'status' => 'active',
    ]);

    $response = $this->postJson("/api/v1/savings/{$rec->id}/respond", [
        'response_type' => 'cancelled',
    ]);

    $response->assertForbidden();
});
