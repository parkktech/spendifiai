<?php

namespace App\Services\Email;

use App\Models\EmailConnection;
use App\Models\ParsedEmail;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class ImapEmailService
{
    /**
     * Provider presets: auto-detect IMAP settings from email domain.
     */
    protected array $providerPresets = [
        'gmail.com' => ['host' => 'imap.gmail.com',        'port' => 993, 'encryption' => 'ssl'],
        'googlemail.com' => ['host' => 'imap.gmail.com',        'port' => 993, 'encryption' => 'ssl'],
        'outlook.com' => ['host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl'],
        'hotmail.com' => ['host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl'],
        'live.com' => ['host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl'],
        'msn.com' => ['host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl'],
        'yahoo.com' => ['host' => 'imap.mail.yahoo.com',  'port' => 993, 'encryption' => 'ssl'],
        'aol.com' => ['host' => 'imap.aol.com',         'port' => 993, 'encryption' => 'ssl'],
        'icloud.com' => ['host' => 'imap.mail.me.com',     'port' => 993, 'encryption' => 'ssl'],
        'me.com' => ['host' => 'imap.mail.me.com',     'port' => 993, 'encryption' => 'ssl'],
        'mac.com' => ['host' => 'imap.mail.me.com',     'port' => 993, 'encryption' => 'ssl'],
        'fastmail.com' => ['host' => 'imap.fastmail.com',    'port' => 993, 'encryption' => 'ssl'],
        'protonmail.com' => ['host' => '127.0.0.1',            'port' => 1143, 'encryption' => 'tls', 'note' => 'Requires ProtonMail Bridge'],
        'zoho.com' => ['host' => 'imap.zoho.com',        'port' => 993, 'encryption' => 'ssl'],
    ];

    /**
     * Search queries to find order/receipt emails.
     */
    protected array $searchSubjects = [
        'order confirmation',
        'order receipt',
        'your order',
        'purchase confirmation',
        'payment receipt',
        'order shipped',
        'subscription renewal',
        'invoice',
        'your receipt',
    ];

    /**
     * Detect IMAP settings from an email address.
     */
    public function detectSettings(string $email): ?array
    {
        $domain = strtolower(substr($email, strpos($email, '@') + 1));

        return $this->providerPresets[$domain] ?? null;
    }

    /**
     * Get the provider name from email domain.
     */
    public function detectProvider(string $email): string
    {
        $domain = strtolower(substr($email, strpos($email, '@') + 1));

        return match (true) {
            in_array($domain, ['gmail.com', 'googlemail.com']) => 'gmail',
            in_array($domain, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com']) => 'outlook',
            $domain === 'yahoo.com' => 'yahoo',
            $domain === 'aol.com' => 'aol',
            in_array($domain, ['icloud.com', 'me.com', 'mac.com']) => 'icloud',
            $domain === 'fastmail.com' => 'fastmail',
            $domain === 'protonmail.com' => 'protonmail',
            $domain === 'zoho.com' => 'zoho',
            default => 'other',
        };
    }

    /**
     * Get setup instructions for a provider.
     */
    public function getSetupInstructions(string $provider): array
    {
        return match ($provider) {
            'gmail' => [
                'title' => 'Gmail Setup',
                'steps' => [
                    'Go to myaccount.google.com/security',
                    'Enable 2-Step Verification if not already enabled',
                    'Go to myaccount.google.com/apppasswords',
                    'Select "Mail" and your device, then click "Generate"',
                    'Use the generated 16-character password below (not your Gmail password)',
                ],
                'note' => 'Gmail requires an App Password for IMAP access. Your regular password will not work.',
            ],
            'outlook' => [
                'title' => 'Outlook / Hotmail Setup',
                'steps' => [
                    'Use your regular Outlook email and password',
                    'If you have 2FA enabled, create an App Password at account.live.com/proofs/AppPassword',
                ],
                'note' => null,
            ],
            'yahoo' => [
                'title' => 'Yahoo Mail Setup',
                'steps' => [
                    'Go to login.yahoo.com/account/security',
                    'Enable 2-Step Verification',
                    'Generate an App Password for "Other App"',
                    'Use the generated password below',
                ],
                'note' => 'Yahoo requires an App Password for IMAP access.',
            ],
            'icloud' => [
                'title' => 'iCloud Mail Setup',
                'steps' => [
                    'Go to appleid.apple.com and sign in',
                    'Go to Sign-In and Security > App-Specific Passwords',
                    'Click "Generate an app-specific password"',
                    'Use the generated password below',
                ],
                'note' => 'iCloud requires an App-Specific Password.',
            ],
            default => [
                'title' => 'Email Setup',
                'steps' => [
                    'Enter your email address and password',
                    'If your provider requires an App Password, generate one in your email security settings',
                    'You may need to provide custom IMAP server settings below',
                ],
                'note' => 'Check your email provider\'s help docs for IMAP settings if auto-detection fails.',
            ],
        };
    }

    /**
     * Test IMAP connection with provided credentials.
     */
    public function testConnection(
        string $email,
        string $password,
        ?string $host = null,
        ?int $port = null,
        ?string $encryption = null,
    ): array {
        $settings = $this->resolveSettings($email, $host, $port, $encryption);

        if (! $settings) {
            return [
                'success' => false,
                'error' => 'Could not determine IMAP settings for this email. Please provide server details manually.',
            ];
        }

        try {
            $cm = new ClientManager;
            $client = $cm->make([
                'host' => $settings['host'],
                'port' => $settings['port'],
                'encryption' => $settings['encryption'],
                'validate_cert' => true,
                'username' => $email,
                'password' => $password,
                'protocol' => 'imap',
            ]);

            $client->connect();
            $folders = $client->getFolders();
            $client->disconnect();

            return [
                'success' => true,
                'folders' => collect($folders)->map(fn ($f) => $f->name)->take(10)->toArray(),
                'settings' => $settings,
            ];
        } catch (ConnectionFailedException $e) {
            return [
                'success' => false,
                'error' => 'Connection failed. Check your credentials and server settings. '.$e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Connect and store IMAP email connection.
     */
    public function connect(
        int $userId,
        string $email,
        string $password,
        ?string $host = null,
        ?int $port = null,
        ?string $encryption = null,
    ): EmailConnection {
        $settings = $this->resolveSettings($email, $host, $port, $encryption);

        if (! $settings) {
            throw new \RuntimeException('Could not determine IMAP settings for this email.');
        }

        // Verify connection first
        $test = $this->testConnection($email, $password, $settings['host'], $settings['port'], $settings['encryption']);
        if (! $test['success']) {
            throw new \RuntimeException($test['error']);
        }

        $provider = $this->detectProvider($email);

        return EmailConnection::updateOrCreate(
            ['user_id' => $userId, 'email_address' => $email],
            [
                'provider' => $provider,
                'connection_type' => 'imap',
                'access_token' => $password, // Encrypted by model cast
                'refresh_token' => '',
                'imap_host' => $settings['host'],
                'imap_port' => $settings['port'],
                'imap_encryption' => $settings['encryption'],
                'status' => 'active',
            ]
        );
    }

    /**
     * Fetch order-related emails via IMAP since a given date.
     */
    public function fetchOrderEmails(EmailConnection $connection, ?\Carbon\Carbon $since = null): array
    {
        $since = $since ?? $connection->last_synced_at ?? now()->subYear();

        $cm = new ClientManager;
        $client = $cm->make([
            'host' => $connection->imap_host,
            'port' => $connection->imap_port,
            'encryption' => $connection->imap_encryption,
            'validate_cert' => true,
            'username' => $connection->email_address,
            'password' => $connection->access_token, // Model cast auto-decrypts
            'protocol' => 'imap',
        ]);

        $client->connect();
        $inbox = $client->getFolder('INBOX');

        $messageIds = [];

        foreach ($this->searchSubjects as $subject) {
            try {
                $messages = $inbox->query()
                    ->subject($subject)
                    ->since($since->toDate())
                    ->limit(100)
                    ->get();

                foreach ($messages as $message) {
                    $messageId = $message->getMessageId()?->toString() ?? $message->getUid();

                    // Skip already-processed emails
                    if (ParsedEmail::where('email_message_id', $messageId)->exists()) {
                        continue;
                    }

                    $messageIds[] = [
                        'message_id' => $messageId,
                        'subject' => $message->getSubject()?->toString() ?? '',
                        'from' => $message->getFrom()?->toString() ?? '',
                        'date' => $message->getDate()?->toString() ?? '',
                        'body' => $this->extractBody($message),
                        'snippet' => mb_substr(strip_tags($message->getTextBody() ?? ''), 0, 200),
                    ];
                }
            } catch (\Exception $e) {
                Log::error("IMAP search failed for subject: {$subject}", [
                    'error' => $e->getMessage(),
                    'connection_id' => $connection->id,
                ]);
            }
        }

        $client->disconnect();

        // Deduplicate by message_id
        $unique = collect($messageIds)->unique('message_id')->values()->toArray();

        return $unique;
    }

    /**
     * Extract and clean the email body.
     */
    protected function extractBody($message): string
    {
        $html = $message->getHTMLBody();
        $text = $message->getTextBody();

        if ($html) {
            return $this->cleanHtml($html);
        }

        return $text ?? '';
    }

    /**
     * Clean HTML while preserving structure for Claude parsing.
     */
    protected function cleanHtml(string $html): string
    {
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<\/td>/i', ' | ', $html);
        $html = preg_replace('/<\/tr>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);

        $text = strip_tags($html);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        if (strlen($text) > 4000) {
            $text = substr($text, 0, 4000)."\n...[truncated]";
        }

        return $text;
    }

    /**
     * Resolve IMAP settings from explicit values or auto-detection.
     */
    protected function resolveSettings(
        string $email,
        ?string $host,
        ?int $port,
        ?string $encryption,
    ): ?array {
        if ($host && $port) {
            return [
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption ?? 'ssl',
            ];
        }

        return $this->detectSettings($email);
    }
}
