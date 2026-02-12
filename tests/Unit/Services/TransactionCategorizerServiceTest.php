<?php

use App\Models\AIQuestion;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\TransactionCategorizerService;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createServiceTestData(): array
{
    $user = User::factory()->create();
    $connection = BankConnection::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);
    $account = BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
    ]);

    return compact('user', 'connection', 'account');
}

function fakeAnthropicResponse(int $txId, float $confidence, array $extra = []): void
{
    $result = array_merge([
        'id' => $txId,
        'category' => 'Food & Groceries',
        'confidence' => $confidence,
        'expense_type' => 'personal',
        'tax_deductible' => false,
        'tax_category' => null,
        'is_subscription' => false,
        'merchant_normalized' => 'Whole Foods',
        'reasoning' => 'Grocery store purchase',
        'uncertain_about' => null,
        'suggested_question' => null,
        'question_type' => null,
        'question_options' => null,
    ], $extra);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([$result])]],
        ]),
    ]);
}

it('auto-categorizes transactions with confidence >= 0.85', function () {
    ['user' => $user, 'account' => $account] = createServiceTestData();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'WHOLE FOODS',
    ]);

    fakeAnthropicResponse($tx->id, 0.92);

    $service = app(TransactionCategorizerService::class);
    $result = $service->categorizeBatch(collect([$tx]), $user->id);

    expect($result['auto_categorized'])->toBe(1);
    expect($result['needs_review'])->toBe(0);
    expect($tx->fresh()->review_status->value)->toBe('auto_categorized');
    expect(AIQuestion::where('transaction_id', $tx->id)->count())->toBe(0);
});

it('flags transactions for review with confidence 0.60-0.84', function () {
    ['user' => $user, 'account' => $account] = createServiceTestData();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'COSTCO',
    ]);

    fakeAnthropicResponse($tx->id, 0.72, [
        'suggested_question' => 'Is this a personal or business purchase?',
        'question_type' => 'business_personal',
        'question_options' => ['Personal', 'Business', 'Skip'],
    ]);

    $service = app(TransactionCategorizerService::class);
    $result = $service->categorizeBatch(collect([$tx]), $user->id);

    expect($result['needs_review'])->toBe(1);
    expect($tx->fresh()->review_status->value)->toBe('ai_uncertain');
    expect(AIQuestion::where('transaction_id', $tx->id)->count())->toBe(1);
});

it('generates multiple-choice question for confidence 0.40-0.59', function () {
    ['user' => $user, 'account' => $account] = createServiceTestData();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'AMAZON',
    ]);

    fakeAnthropicResponse($tx->id, 0.48, [
        'suggested_question' => 'What category best fits this Amazon purchase?',
        'question_type' => 'category',
        'question_options' => ['Office Supplies', 'Shopping (General)', 'Electronics', 'Skip'],
    ]);

    $service = app(TransactionCategorizerService::class);
    $result = $service->categorizeBatch(collect([$tx]), $user->id);

    $question = AIQuestion::where('transaction_id', $tx->id)->first();
    expect($question)->not->toBeNull();
    expect($question->options)->toBeArray();
    expect(count($question->options))->toBeGreaterThanOrEqual(2);
});

it('generates open-ended question for confidence < 0.40', function () {
    ['user' => $user, 'account' => $account] = createServiceTestData();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'merchant_name' => 'VENMO',
    ]);

    fakeAnthropicResponse($tx->id, 0.25, [
        'suggested_question' => 'What was this Venmo payment for?',
        'question_type' => 'category',
        'question_options' => ['Personal', 'Business', 'Skip'],
    ]);

    $service = app(TransactionCategorizerService::class);
    $result = $service->categorizeBatch(collect([$tx]), $user->id);

    $question = AIQuestion::where('transaction_id', $tx->id)->first();
    expect($question)->not->toBeNull();
    expect($question->question_type->value)->toBe('category');
});

it('handleUserAnswer updates transaction for business_personal question', function () {
    ['user' => $user, 'account' => $account] = createServiceTestData();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'ai_category' => 'Office Supplies',
        'expense_type' => 'personal',
        'tax_deductible' => false,
    ]);

    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
        'question_type' => 'business_personal',
    ]);

    $service = app(TransactionCategorizerService::class);
    $service->handleUserAnswer($question, 'Business');

    $tx->refresh();
    expect($tx->expense_type->value)->toBe('business');
    expect($tx->tax_deductible)->toBeTrue();
    expect($tx->review_status->value)->toBe('user_confirmed');
});

it('handleUserAnswer skips transaction when answer is Skip', function () {
    ['user' => $user, 'account' => $account] = createServiceTestData();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'review_status' => 'needs_review',
    ]);

    $question = AIQuestion::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
    ]);

    $service = app(TransactionCategorizerService::class);
    $service->handleUserAnswer($question, 'Skip');

    expect($question->fresh()->status->value)->toBe('skipped');
    // Transaction should NOT be updated
    expect($tx->fresh()->review_status->value)->toBe('needs_review');
});
