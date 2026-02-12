<?php

use App\Jobs\ProcessOrderEmails;
use App\Models\EmailConnection;
use App\Services\Email\ImapEmailService;
use Illuminate\Support\Facades\Queue;

// ── List Connections ──────────────────────────────────────────────────

it('can list email connections', function () {
    $user = createAuthenticatedUser();

    EmailConnection::factory()->count(2)->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    // Another user's connection should not appear
    EmailConnection::factory()->create();

    $response = $this->getJson('/api/v1/email/connections');

    $response->assertOk()
        ->assertJsonCount(2, 'connections')
        ->assertJsonStructure(['connections' => [['id', 'provider', 'connection_type', 'email_address', 'status', 'last_synced_at', 'sync_status']]]);
});

it('returns empty connections for new user', function () {
    createAuthenticatedUser();

    $response = $this->getJson('/api/v1/email/connections');

    $response->assertOk()
        ->assertJsonCount(0, 'connections');
});

it('requires auth to list connections', function () {
    $this->getJson('/api/v1/email/connections')
        ->assertUnauthorized();
});

// ── Setup Instructions ───────────────────────────────────────────────

it('returns setup instructions for gmail', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/email/setup-instructions', [
        'email' => 'test@gmail.com',
    ]);

    $response->assertOk()
        ->assertJsonPath('provider', 'gmail')
        ->assertJsonPath('settings.host', 'imap.gmail.com')
        ->assertJsonPath('settings.port', 993)
        ->assertJsonStructure(['provider', 'settings', 'instructions' => ['title', 'steps', 'note']]);
});

it('returns setup instructions for outlook', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/email/setup-instructions', [
        'email' => 'test@outlook.com',
    ]);

    $response->assertOk()
        ->assertJsonPath('provider', 'outlook')
        ->assertJsonPath('settings.host', 'outlook.office365.com');
});

it('returns setup instructions for yahoo', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/email/setup-instructions', [
        'email' => 'test@yahoo.com',
    ]);

    $response->assertOk()
        ->assertJsonPath('provider', 'yahoo')
        ->assertJsonPath('settings.host', 'imap.mail.yahoo.com');
});

it('returns null settings for unknown domain', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/email/setup-instructions', [
        'email' => 'test@mycustomdomain.org',
    ]);

    $response->assertOk()
        ->assertJsonPath('provider', 'other')
        ->assertJsonPath('settings', null)
        ->assertJsonPath('instructions.title', 'Email Setup');
});

it('validates email for setup instructions', function () {
    createAuthenticatedUser();

    $this->postJson('/api/v1/email/setup-instructions', [
        'email' => 'not-an-email',
    ])->assertUnprocessable();

    $this->postJson('/api/v1/email/setup-instructions', [])
        ->assertUnprocessable();
});

// ── Test Connection ──────────────────────────────────────────────────

it('can test imap connection successfully', function () {
    createAuthenticatedUser();

    $mock = $this->mock(ImapEmailService::class);
    $mock->shouldReceive('testConnection')
        ->with('user@gmail.com', 'app-password-123', null, null, null)
        ->once()
        ->andReturn([
            'success' => true,
            'folders' => ['INBOX', 'Sent', 'Trash'],
            'settings' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl'],
        ]);

    $response = $this->postJson('/api/v1/email/test', [
        'email' => 'user@gmail.com',
        'password' => 'app-password-123',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('folders', ['INBOX', 'Sent', 'Trash']);
});

it('returns failure for bad imap credentials', function () {
    createAuthenticatedUser();

    $mock = $this->mock(ImapEmailService::class);
    $mock->shouldReceive('testConnection')
        ->once()
        ->andReturn([
            'success' => false,
            'error' => 'Connection failed. Check your credentials and server settings.',
        ]);

    $response = $this->postJson('/api/v1/email/test', [
        'email' => 'user@gmail.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'Connection failed. Check your credentials and server settings.');
});

it('can test with custom imap settings', function () {
    createAuthenticatedUser();

    $mock = $this->mock(ImapEmailService::class);
    $mock->shouldReceive('testConnection')
        ->with('user@example.com', 'secret', 'mail.example.com', 993, 'ssl')
        ->once()
        ->andReturn(['success' => true, 'folders' => ['INBOX'], 'settings' => []]);

    $response = $this->postJson('/api/v1/email/test', [
        'email' => 'user@example.com',
        'password' => 'secret',
        'imap_host' => 'mail.example.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);
});

it('validates test connection input', function () {
    createAuthenticatedUser();

    $this->postJson('/api/v1/email/test', [])
        ->assertUnprocessable();

    $this->postJson('/api/v1/email/test', ['email' => 'user@gmail.com'])
        ->assertUnprocessable();

    $this->postJson('/api/v1/email/test', ['password' => 'secret'])
        ->assertUnprocessable();

    $this->postJson('/api/v1/email/test', [
        'email' => 'user@gmail.com',
        'password' => 'secret',
        'imap_encryption' => 'invalid',
    ])->assertUnprocessable();
});

// ── Connect via IMAP ─────────────────────────────────────────────────

it('can connect email via imap', function () {
    $user = createAuthenticatedUser();

    $mock = $this->mock(ImapEmailService::class);
    $mock->shouldReceive('connect')
        ->with($user->id, 'user@gmail.com', 'app-password', null, null, null)
        ->once()
        ->andReturn(EmailConnection::factory()->imap()->make([
            'user_id' => $user->id,
            'email_address' => 'user@gmail.com',
            'provider' => 'gmail',
        ]));

    $response = $this->postJson('/api/v1/email/connect-imap', [
        'email' => 'user@gmail.com',
        'password' => 'app-password',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Email connected successfully via IMAP')
        ->assertJsonPath('email', 'user@gmail.com')
        ->assertJsonPath('provider', 'gmail');
});

it('can connect with custom imap host and port', function () {
    $user = createAuthenticatedUser();

    $mock = $this->mock(ImapEmailService::class);
    $mock->shouldReceive('connect')
        ->with($user->id, 'user@example.com', 'pass', 'mail.example.com', 587, 'tls')
        ->once()
        ->andReturn(EmailConnection::factory()->imap('other')->make([
            'user_id' => $user->id,
            'email_address' => 'user@example.com',
            'provider' => 'other',
        ]));

    $response = $this->postJson('/api/v1/email/connect-imap', [
        'email' => 'user@example.com',
        'password' => 'pass',
        'imap_host' => 'mail.example.com',
        'imap_port' => 587,
        'imap_encryption' => 'tls',
    ]);

    $response->assertOk()
        ->assertJsonPath('provider', 'other');
});

it('returns 422 when imap connection fails', function () {
    $user = createAuthenticatedUser();

    $mock = $this->mock(ImapEmailService::class);
    $mock->shouldReceive('connect')
        ->once()
        ->andThrow(new \RuntimeException('Connection failed. Check your credentials.'));

    $response = $this->postJson('/api/v1/email/connect-imap', [
        'email' => 'user@gmail.com',
        'password' => 'bad-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error', 'Connection failed. Check your credentials.');
});

it('validates connect-imap input', function () {
    createAuthenticatedUser();

    $this->postJson('/api/v1/email/connect-imap', [])
        ->assertUnprocessable();

    $this->postJson('/api/v1/email/connect-imap', ['email' => 'not-email', 'password' => 'x'])
        ->assertUnprocessable();

    $this->postJson('/api/v1/email/connect-imap', [
        'email' => 'user@gmail.com',
        'password' => 'x',
        'imap_encryption' => 'starttls', // invalid — must be ssl or tls
    ])->assertUnprocessable();
});

// ── Sync ─────────────────────────────────────────────────────────────

it('can trigger email sync', function () {
    Queue::fake([ProcessOrderEmails::class]);

    $user = createAuthenticatedUser();
    $connection = EmailConnection::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'sync_status' => 'pending',
    ]);

    $response = $this->postJson('/api/v1/email/sync', [
        'connection_id' => $connection->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Email sync started. Orders will be processed in the background.');

    Queue::assertPushed(ProcessOrderEmails::class);
});

it('cannot sync an already syncing connection', function () {
    $user = createAuthenticatedUser();
    EmailConnection::factory()->syncing()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->postJson('/api/v1/email/sync');

    $response->assertNotFound();
});

it('cannot sync another users connection', function () {
    Queue::fake();

    $user = createAuthenticatedUser();
    $otherConnection = EmailConnection::factory()->create([
        'sync_status' => 'pending',
    ]);

    $response = $this->postJson('/api/v1/email/sync', [
        'connection_id' => $otherConnection->id,
    ]);

    $response->assertNotFound();

    Queue::assertNothingPushed();
});

// ── Disconnect ───────────────────────────────────────────────────────

it('can disconnect email connection', function () {
    $user = createAuthenticatedUser();
    $connection = EmailConnection::factory()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->deleteJson("/api/v1/email/{$connection->id}");

    $response->assertOk()
        ->assertJsonPath('message', 'Email connection removed');

    expect(EmailConnection::find($connection->id))->toBeNull();
});

it('cannot disconnect another users email connection', function () {
    createAuthenticatedUser();
    $otherConnection = EmailConnection::factory()->create();

    $response = $this->deleteJson("/api/v1/email/{$otherConnection->id}");

    $response->assertNotFound();

    // Should still exist
    expect(EmailConnection::find($otherConnection->id))->not->toBeNull();
});

it('returns 404 for non-existent connection disconnect', function () {
    createAuthenticatedUser();

    $this->deleteJson('/api/v1/email/99999')
        ->assertNotFound();
});

// ── OAuth Connect (Gmail) ────────────────────────────────────────────

it('rejects non-gmail oauth connect', function () {
    createAuthenticatedUser();

    $response = $this->postJson('/api/v1/email/connect/outlook');

    $response->assertBadRequest()
        ->assertJsonPath('error', 'For non-Gmail providers, use the IMAP connection endpoint.');
});

// ── Auth required on all endpoints ───────────────────────────────────

it('requires auth on all email endpoints', function (string $method, string $url) {
    $response = $this->{$method}($url);
    $response->assertUnauthorized();
})->with([
    'list connections' => ['getJson', '/api/v1/email/connections'],
    'connect imap' => ['postJson', '/api/v1/email/connect-imap'],
    'test connection' => ['postJson', '/api/v1/email/test'],
    'setup instructions' => ['postJson', '/api/v1/email/setup-instructions'],
    'sync' => ['postJson', '/api/v1/email/sync'],
    'disconnect' => ['deleteJson', '/api/v1/email/99999'],
]);
