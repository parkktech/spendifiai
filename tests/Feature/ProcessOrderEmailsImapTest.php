<?php

use App\Jobs\ProcessOrderEmails;
use App\Models\EmailConnection;
use App\Models\Order;
use App\Models\ParsedEmail;
use App\Models\Transaction;
use App\Services\AI\EmailParserService;
use App\Services\Email\GmailService;
use App\Services\Email\ImapEmailService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// ── IMAP Connection Routing ─────────────────────────────────────────

it('routes imap connections to ImapEmailService', function () {
    $user = createAuthenticatedUser();

    $connection = EmailConnection::factory()->imap()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'sync_status' => 'pending',
    ]);

    $imapMock = Mockery::mock(ImapEmailService::class);
    $imapMock->shouldReceive('fetchOrderEmails')
        ->once()
        ->andReturn([]);

    $gmailMock = Mockery::mock(GmailService::class);
    $gmailMock->shouldNotReceive('fetchOrderEmails');

    $parserMock = Mockery::mock(EmailParserService::class);

    $job = new ProcessOrderEmails($connection);
    $job->handle($gmailMock, $imapMock, $parserMock);

    $connection->refresh();
    expect($connection->sync_status)->toBe('completed');
    expect($connection->last_synced_at)->not->toBeNull();
});

it('routes oauth connections to GmailService', function () {
    $user = createAuthenticatedUser();

    $connection = EmailConnection::factory()->create([
        'user_id' => $user->id,
        'connection_type' => 'oauth',
        'status' => 'active',
        'sync_status' => 'pending',
    ]);

    $imapMock = Mockery::mock(ImapEmailService::class);
    $imapMock->shouldNotReceive('fetchOrderEmails');

    $gmailMock = Mockery::mock(GmailService::class);
    $gmailMock->shouldReceive('fetchOrderEmails')
        ->once()
        ->andReturn([]);

    $parserMock = Mockery::mock(EmailParserService::class);

    $job = new ProcessOrderEmails($connection);
    $job->handle($gmailMock, $imapMock, $parserMock);

    $connection->refresh();
    expect($connection->sync_status)->toBe('completed');
});

// ── IMAP Email Processing ───────────────────────────────────────────

it('processes imap emails and creates orders', function () {
    $user = createAuthenticatedUser();

    $connection = EmailConnection::factory()->imap()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'sync_status' => 'pending',
    ]);

    $fakeEmails = [
        [
            'message_id' => 'test-msg-001',
            'thread_id' => null,
            'subject' => 'Your Amazon Order Confirmation',
            'from' => 'ship-confirm@amazon.com',
            'date' => '2025-12-15',
            'body' => 'Order #123-456 - USB-C Cable $12.99',
            'snippet' => 'Your order has shipped',
        ],
    ];

    $imapMock = Mockery::mock(ImapEmailService::class);
    $imapMock->shouldReceive('fetchOrderEmails')
        ->once()
        ->andReturn($fakeEmails);

    $gmailMock = Mockery::mock(GmailService::class);

    $parserMock = Mockery::mock(EmailParserService::class);
    $parserMock->shouldReceive('parseOrderEmail')
        ->once()
        ->andReturn([
            'is_purchase' => true,
            'is_refund' => false,
            'is_subscription' => false,
            'merchant' => 'Amazon',
            'merchant_normalized' => 'Amazon',
            'order_number' => '123-456',
            'order_date' => '2025-12-15',
            'total' => 12.99,
            'currency' => 'USD',
            'items' => [
                [
                    'product_name' => 'USB-C Cable',
                    'product_description' => '6ft braided',
                    'quantity' => 1,
                    'unit_price' => 12.99,
                    'total_price' => 12.99,
                    'suggested_category' => 'Computer & Electronics',
                    'tax_deductible_likelihood' => 0.3,
                    'business_use_indicator' => 'likely personal',
                    'product_type' => 'physical',
                ],
            ],
        ]);

    $job = new ProcessOrderEmails($connection);
    $job->handle($gmailMock, $imapMock, $parserMock);

    // Verify parsed email was created
    expect(ParsedEmail::where('user_id', $user->id)->count())->toBe(1);
    $parsedEmail = ParsedEmail::where('user_id', $user->id)->first();
    expect($parsedEmail->parse_status)->toBe('parsed');
    expect($parsedEmail->is_purchase)->toBeTrue();

    // Verify order was created
    $order = Order::where('user_id', $user->id)->first();
    expect($order)->not->toBeNull();
    expect($order->merchant)->toBe('Amazon');
    expect((float) $order->total)->toBe(12.99);
    expect($order->items)->toHaveCount(1);
    expect($order->items->first()->product_name)->toBe('USB-C Cable');
});

it('skips non-purchase emails', function () {
    $user = createAuthenticatedUser();

    $connection = EmailConnection::factory()->imap()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'sync_status' => 'pending',
    ]);

    $imapMock = Mockery::mock(ImapEmailService::class);
    $imapMock->shouldReceive('fetchOrderEmails')
        ->once()
        ->andReturn([[
            'message_id' => 'newsletter-001',
            'thread_id' => null,
            'subject' => 'Weekly Deals Newsletter',
            'from' => 'marketing@store.com',
            'date' => '2025-12-15',
            'body' => 'Check out our deals!',
            'snippet' => 'Weekly deals',
        ]]);

    $gmailMock = Mockery::mock(GmailService::class);

    $parserMock = Mockery::mock(EmailParserService::class);
    $parserMock->shouldReceive('parseOrderEmail')
        ->once()
        ->andReturn(['is_purchase' => false]);

    $job = new ProcessOrderEmails($connection);
    $job->handle($gmailMock, $imapMock, $parserMock);

    expect(Order::where('user_id', $user->id)->count())->toBe(0);
    expect(ParsedEmail::where('user_id', $user->id)->first()->parse_status)->toBe('skipped');
});

// ── Reconciliation Indicator ────────────────────────────────────────

it('includes is_reconciled in transaction api response', function () {
    ['user' => $user, 'account' => $account] = createUserWithBank();

    $tx = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'is_reconciled' => true,
        'matched_order_id' => 1,
    ]);

    $txUnmatched = Transaction::factory()->create([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'is_reconciled' => false,
        'matched_order_id' => null,
    ]);

    $response = $this->getJson('/api/v1/transactions');

    $response->assertOk();
    $data = $response->json('data');

    $matched = collect($data)->firstWhere('id', $tx->id);
    $unmatched = collect($data)->firstWhere('id', $txUnmatched->id);

    expect($matched['is_reconciled'])->toBeTrue();
    expect($matched['matched_order_id'])->toBe(1);
    expect($unmatched['is_reconciled'])->toBeFalse();
    expect($unmatched['matched_order_id'])->toBeNull();
});

// ── Sync Status Updates ─────────────────────────────────────────────

it('sets sync_status to failed on error', function () {
    $user = createAuthenticatedUser();

    $connection = EmailConnection::factory()->imap()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'sync_status' => 'pending',
    ]);

    $imapMock = Mockery::mock(ImapEmailService::class);
    $imapMock->shouldReceive('fetchOrderEmails')
        ->once()
        ->andThrow(new \RuntimeException('IMAP connection refused'));

    $gmailMock = Mockery::mock(GmailService::class);
    $parserMock = Mockery::mock(EmailParserService::class);

    $job = new ProcessOrderEmails($connection);

    try {
        $job->handle($gmailMock, $imapMock, $parserMock);
    } catch (\RuntimeException) {
        // Expected
    }

    $connection->refresh();
    expect($connection->sync_status)->toBe('failed');
});
