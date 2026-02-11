<?php

use App\Models\AIQuestion;
use App\Models\Transaction;
use Illuminate\Support\Facades\Event;

it('can list pending questions', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
    ]);

    AIQuestion::factory()->count(3)->create([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
    ]);

    $response = $this->getJson('/api/v1/questions');

    $response->assertOk();
    expect($response->json())->toHaveCount(3);
});

it('can answer a question', function () {
    Event::fake();

    ['user' => $user, 'account' => $account] = createUserWithBank();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
    ]);

    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
        'question_type' => 'business_personal',
    ]);

    $response = $this->postJson("/api/v1/questions/{$question->id}/answer", [
        'answer' => 'Business',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Answer recorded');

    $question->refresh();
    expect($question->status->value)->toBe('answered');
    expect($question->user_answer)->toBe('Business');
});

it('can bulk answer questions', function () {
    Event::fake();

    ['user' => $user, 'account' => $account] = createUserWithBank();

    $questions = [];
    for ($i = 0; $i < 3; $i++) {
        $tx = Transaction::factory()->create([
            'user_id' => $user->id,
            'bank_account_id' => $account->id,
        ]);

        $questions[] = AIQuestion::factory()->create([
            'user_id' => $user->id,
            'transaction_id' => $tx->id,
        ]);
    }

    $answers = collect($questions)->map(fn ($q) => [
        'question_id' => $q->id,
        'answer' => 'Business',
    ])->toArray();

    $response = $this->postJson('/api/v1/questions/bulk-answer', [
        'answers' => $answers,
    ]);

    $response->assertOk()
        ->assertJsonPath('processed', 3);

    foreach ($questions as $q) {
        expect($q->fresh()->status->value)->toBe('answered');
    }
});

it('skipping a question sets status to skipped', function () {
    Event::fake();

    ['user' => $user, 'account' => $account] = createUserWithBank();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
    ]);

    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
    ]);

    $response = $this->postJson("/api/v1/questions/{$question->id}/answer", [
        'answer' => 'Skip',
    ]);

    $response->assertOk();
    expect($question->fresh()->status->value)->toBe('skipped');
});
