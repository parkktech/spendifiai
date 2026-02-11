<?php

use App\Models\SavingsRecommendation;
use App\Models\SavingsPlanAction;
use App\Models\SavingsTarget;
use Illuminate\Support\Facades\Http;

it('can list savings recommendations', function () {
    ['user' => $user] = createUserWithBank();

    SavingsRecommendation::factory()->count(3)->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/v1/savings');

    $response->assertOk()
        ->assertJsonStructure(['recommendations', 'total_monthly', 'total_annual']);

    expect($response->json('recommendations'))->toHaveCount(3);
});

it('can set savings target', function () {
    ['user' => $user] = createUserWithBank();

    // Fake the Anthropic API for plan generation -- must return a flat array of actions
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([
                [
                    'title' => 'Reduce dining out',
                    'description' => 'Cut restaurant spending by 50%',
                    'how_to' => 'Cook at home more',
                    'monthly_savings' => 200,
                    'current_spending' => 400,
                    'recommended_spending' => 200,
                    'category' => 'Dining',
                    'difficulty' => 'medium',
                    'impact' => 'high',
                    'priority' => 1,
                    'is_essential_cut' => false,
                    'related_merchants' => ['Chipotle', 'DoorDash'],
                ],
            ])]],
        ]),
    ]);

    $response = $this->postJson('/api/v1/savings/target', [
        'monthly_target' => 500,
        'motivation' => 'Emergency fund',
    ]);

    $response->assertOk();

    expect(SavingsTarget::where('user_id', $user->id)->count())->toBe(1);
});

it('can respond to plan action', function () {
    ['user' => $user] = createUserWithBank();

    $target = SavingsTarget::factory()->create([
        'user_id' => $user->id,
    ]);

    $action = SavingsPlanAction::factory()->create([
        'user_id' => $user->id,
        'savings_target_id' => $target->id,
        'status' => 'suggested',
    ]);

    $response = $this->postJson("/api/v1/savings/plan/{$action->id}/respond", [
        'response' => 'accept',
    ]);

    $response->assertOk();

    $action->refresh();
    expect($action->status)->toBe('accepted');
    expect($action->accepted_at)->not->toBeNull();
});
