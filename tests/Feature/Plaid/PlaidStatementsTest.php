<?php

use App\Jobs\DownloadPlaidStatements;
use App\Models\PlaidStatement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.plaid.client_id' => 'test_client_id',
        'services.plaid.secret' => 'test_secret',
        'services.plaid.env' => 'sandbox',
    ]);

    Http::fake([
        'sandbox.plaid.com/link/token/create' => Http::response([
            'link_token' => 'link-sandbox-statements',
            'expiration' => '2026-12-31T00:00:00Z',
        ]),
        'sandbox.plaid.com/statements/list' => Http::response([
            'accounts' => [[
                'account_id' => 'acc_test_123',
                'statements' => [[
                    'statement_id' => 'stmt_test_1',
                    'month' => 1,
                    'year' => 2026,
                ]],
            ]],
        ]),
        'sandbox.plaid.com/statements/refresh' => Http::response([
            'request_id' => 'req_refresh_test',
        ]),
        'sandbox.plaid.com/statements/download' => Http::response(
            'fake-pdf-content',
            200,
            ['Content-Type' => 'application/pdf']
        ),
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => '[]']],
        ]),
    ]);
});

it('can create statements link token for a connection', function () {
    ['user' => $user, 'connection' => $connection] = createUserWithBank();

    $response = $this->postJson("/api/v1/plaid/{$connection->id}/statements/link-token");

    $response->assertOk()
        ->assertJsonPath('link_token', 'link-sandbox-statements');
});

it('can refresh statements for a connection', function () {
    Queue::fake([DownloadPlaidStatements::class]);

    ['user' => $user, 'connection' => $connection] = createUserWithBank();

    $response = $this->postJson("/api/v1/plaid/{$connection->id}/statements/refresh");

    $response->assertOk()
        ->assertJsonStructure(['message', 'status'])
        ->assertJsonPath('status', 'refreshing');

    $connection->refresh();
    expect($connection->statements_refresh_status)->toBe('refreshing');
});

it('can list statements for a connection', function () {
    ['user' => $user, 'connection' => $connection] = createUserWithBank();

    // Create some statement records
    PlaidStatement::factory()->count(2)->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
        'status' => 'complete',
    ]);

    $response = $this->getJson("/api/v1/plaid/{$connection->id}/statements");

    $response->assertOk()
        ->assertJsonCount(2, 'statements');
});

it('cannot access another users connection statements', function () {
    ['connection' => $otherConnection] = createUserWithBank();

    // Create a second user
    $user = createAuthenticatedUser();

    $response = $this->getJson("/api/v1/plaid/{$otherConnection->id}/statements");

    $response->assertForbidden();
});

it('webhook handler method exists for statements', function () {
    $controller = app(\App\Http\Controllers\Api\PlaidWebhookController::class);

    expect(method_exists($controller, 'handleStatementsWebhook'))->toBeTrue();
});

it('refresh prevents date range exceeding 2 years', function () {
    ['connection' => $connection] = createUserWithBank();

    $response = $this->postJson("/api/v1/plaid/{$connection->id}/statements/refresh", [
        'start_date' => '2020-01-01',
        'end_date' => '2026-03-01',
    ]);

    $response->assertStatus(422);
});
