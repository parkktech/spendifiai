<?php

namespace App\Services\Email;

use App\Models\EmailConnection;
use App\Models\ParsedEmail;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Illuminate\Support\Facades\Log;

class GmailService
{
    protected GoogleClient $client;

    protected Gmail $gmail;

    /**
     * Build search queries from shared config.
     */
    protected function getSearchQueries(): array
    {
        $queries = [];

        // Subject-based queries
        foreach (config('email-search.subject_patterns') as $pattern) {
            $queries[] = 'subject:('.$pattern.') -label:trash';
        }

        // Sender-prefix queries — catches niche retailers using standard sender addresses
        foreach (config('email-search.sender_prefixes') as $prefix) {
            $queries[] = 'from:'.$prefix.' (order OR receipt OR purchase OR invoice) -label:trash';
        }

        return $queries;
    }

    public function __construct()
    {
        $this->client = new GoogleClient;
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect_uri'));
        $this->client->addScope(Gmail::GMAIL_READONLY);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
    }

    /**
     * Generate the OAuth URL for user to connect their Gmail.
     */
    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange auth code for tokens and store the connection.
     *
     * NOTE: No manual encrypt() — the EmailConnection model has 'encrypted' casts
     * on access_token and refresh_token. Manual encrypt() would double-encrypt.
     */
    public function handleCallback(int $userId, string $authCode): EmailConnection
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($authCode);

        $this->client->setAccessToken($token);
        $gmail = new Gmail($this->client);
        $profile = $gmail->users->getProfile('me');

        return EmailConnection::updateOrCreate(
            ['user_id' => $userId, 'email_address' => $profile->getEmailAddress()],
            [
                'provider' => 'gmail',
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? '',
                'token_expires_at' => Carbon::now()->addSeconds($token['expires_in']),
                'status' => 'active',
            ]
        );
    }

    /**
     * Authenticate using a stored connection's tokens.
     *
     * NOTE: No manual decrypt() — the EmailConnection model's 'encrypted' cast
     * already handles decryption when accessing these properties.
     */
    public function authenticateConnection(EmailConnection $connection): void
    {
        $this->client->setAccessToken($connection->access_token);

        // Refresh if expired
        if ($this->client->isAccessTokenExpired()) {
            $refreshToken = $connection->refresh_token;
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);

            $connection->update([
                'access_token' => $newToken['access_token'],
                'token_expires_at' => Carbon::now()->addSeconds($newToken['expires_in']),
            ]);
        }

        $this->gmail = new Gmail($this->client);
    }

    /**
     * Get the authenticated Gmail instance for direct API calls.
     */
    public function getGmailInstance(): Gmail
    {
        return $this->gmail;
    }

    /**
     * Fetch all order-related emails since the last sync (or a given date).
     * Returns array of Gmail message IDs that haven't been processed yet.
     */
    public function fetchOrderEmails(
        EmailConnection $connection,
        ?Carbon $since = null
    ): array {
        $this->authenticateConnection($connection);

        // Match Plaid's lookback: Jan 1 of previous year (not just 1 year ago)
        // so email orders align with the full transaction history
        $since = $since ?? $connection->last_synced_at ?? Carbon::now()->subYear()->startOfYear();
        $messageIds = [];

        // Batch-load all previously processed message IDs for O(1) lookups
        $existingIds = ParsedEmail::where('email_connection_id', $connection->id)
            ->pluck('email_message_id')
            ->flip()
            ->toArray();

        foreach ($this->getSearchQueries() as $query) {
            $fullQuery = $query.' after:'.$since->format('Y/m/d');

            try {
                $results = $this->gmail->users_messages->listUsersMessages('me', [
                    'q' => $fullQuery,
                    'maxResults' => 100,
                ]);

                $pageToken = null;
                do {
                    if ($pageToken) {
                        $results = $this->gmail->users_messages->listUsersMessages('me', [
                            'q' => $fullQuery,
                            'maxResults' => 100,
                            'pageToken' => $pageToken,
                        ]);
                    }

                    foreach ($results->getMessages() ?? [] as $message) {
                        $gmailId = $message->getId();

                        if (! isset($existingIds[$gmailId])) {
                            $messageIds[] = $gmailId;
                        }
                    }

                    $pageToken = $results->getNextPageToken();
                } while ($pageToken);

            } catch (\Exception $e) {
                Log::error("Gmail search failed for query: {$query}", [
                    'error' => $e->getMessage(),
                    'connection_id' => $connection->id,
                ]);
            }
        }

        // Deduplicate (same email might match multiple search queries)
        return array_unique($messageIds);
    }

    /**
     * Get the full email content for a specific message.
     * Extracts subject, from, date, and body (preferring text/html, falling back to text/plain).
     */
    public function getEmailContent(string $messageId): array
    {
        $message = $this->gmail->users_messages->get('me', $messageId, ['format' => 'full']);
        $payload = $message->getPayload();
        $headers = $payload->getHeaders();

        $subject = '';
        $from = '';
        $date = '';

        foreach ($headers as $header) {
            match ($header->getName()) {
                'Subject' => $subject = $header->getValue(),
                'From' => $from = $header->getValue(),
                'Date' => $date = $header->getValue(),
                default => null,
            };
        }

        // Extract body — handle multipart and simple messages
        $body = $this->extractBody($payload);

        return [
            'message_id' => $messageId,
            'thread_id' => $message->getThreadId(),
            'subject' => $subject,
            'from' => $from,
            'date' => $date,
            'body' => $body,
            'snippet' => $message->getSnippet(),
        ];
    }

    /**
     * Recursively extract the email body from MIME parts.
     * Prefers HTML (better for parsing structured order tables), falls back to plain text.
     */
    protected function extractBody($payload): string
    {
        $htmlBody = '';
        $textBody = '';

        // Simple message (no parts)
        if ($payload->getBody()->getData()) {
            return $this->decodeBody($payload->getBody()->getData());
        }

        // Multipart message — recurse through parts
        foreach ($payload->getParts() ?? [] as $part) {
            $mimeType = $part->getMimeType();

            if ($mimeType === 'text/html' && $part->getBody()->getData()) {
                $htmlBody = $this->decodeBody($part->getBody()->getData());
            } elseif ($mimeType === 'text/plain' && $part->getBody()->getData()) {
                $textBody = $this->decodeBody($part->getBody()->getData());
            } elseif (str_starts_with($mimeType, 'multipart/')) {
                // Recurse into nested multipart
                foreach ($part->getParts() ?? [] as $subPart) {
                    if ($subPart->getMimeType() === 'text/html' && $subPart->getBody()->getData()) {
                        $htmlBody = $this->decodeBody($subPart->getBody()->getData());
                    } elseif ($subPart->getMimeType() === 'text/plain' && $subPart->getBody()->getData()) {
                        $textBody = $this->decodeBody($subPart->getBody()->getData());
                    }
                }
            }
        }

        // Prefer HTML — it preserves table structure from order emails
        // But strip tags to reduce token usage when sending to Claude
        if ($htmlBody) {
            return $this->cleanHtml($htmlBody);
        }

        return $textBody;
    }

    protected function decodeBody(string $data): string
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    /**
     * Clean HTML while preserving structural information that helps Claude
     * understand product tables, line items, prices, etc.
     */
    protected function cleanHtml(string $html): string
    {
        // Remove style and script tags entirely
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);

        // Convert table cells and rows to readable format
        $html = preg_replace('/<\/td>/i', ' | ', $html);
        $html = preg_replace('/<\/tr>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);

        // Strip remaining HTML tags
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Truncate to ~8000 chars — larger limit catches order items in emails
        // that have verbose marketing/header content before the actual line items.
        // Claude Sonnet handles this token count easily within the 2000 output limit.
        if (strlen($text) > 8000) {
            $text = substr($text, 0, 8000)."\n...[truncated]";
        }

        return $text;
    }
}
