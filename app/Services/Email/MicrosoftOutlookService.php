<?php

namespace App\Services\Email;

use App\Models\EmailConnection;
use App\Models\ParsedEmail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftOutlookService
{
    /**
     * Microsoft Identity Platform endpoints (consumers = personal accounts).
     */
    protected string $authorizeUrl = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize';

    protected string $tokenUrl = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';

    protected string $graphUrl = 'https://graph.microsoft.com/v1.0';

    /**
     * Search queries to find order/receipt emails via Microsoft Graph.
     */
    protected array $searchQueries = [
        'subject:order confirmation',
        'subject:order receipt',
        'subject:your order',
        'subject:purchase confirmation',
        'subject:payment receipt',
        'subject:order shipped',
        'subject:subscription renewal',
        'subject:invoice',
        'subject:your receipt',
        'subject:thank you for your order',
        'subject:shipping confirmation',
        'subject:delivery confirmation',
        'subject:payment confirmation',
        'subject:purchase receipt',
        'subject:thank you for your purchase',
    ];

    /**
     * Generate the OAuth2 authorization URL.
     *
     * Encrypts user_id into the state parameter so the public callback
     * can identify the user without requiring auth:sanctum.
     */
    public function getAuthUrl(int $userId): string
    {
        $state = encrypt([
            'user_id' => $userId,
            'provider' => 'outlook',
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        $params = http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->getRedirectUri(),
            'response_mode' => 'query',
            'scope' => 'Mail.Read Mail.ReadBasic offline_access User.Read',
            'state' => $state,
            'prompt' => 'consent',
        ]);

        return $this->authorizeUrl.'?'.$params;
    }

    /**
     * Build the full redirect URI from config.
     */
    protected function getRedirectUri(): string
    {
        return url(config('services.microsoft.redirect_uri'));
    }

    /**
     * Exchange authorization code for tokens and store the connection.
     *
     * NOTE: No manual encrypt() — the EmailConnection model has 'encrypted' casts
     * on access_token and refresh_token. Model handles it automatically.
     */
    public function handleCallback(int $userId, string $authCode): EmailConnection
    {
        $response = Http::asForm()->post($this->tokenUrl, [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'code' => $authCode,
            'redirect_uri' => $this->getRedirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            Log::error('Microsoft OAuth token exchange failed', ['body' => $response->body()]);
            throw new \RuntimeException('Failed to authenticate with Microsoft. Please try again.');
        }

        $token = $response->json();

        // Get user's email address from Graph API
        $profileResponse = Http::withToken($token['access_token'])
            ->get($this->graphUrl.'/me', [
                '$select' => 'mail,userPrincipalName',
            ]);

        if (! $profileResponse->successful()) {
            throw new \RuntimeException('Could not retrieve Microsoft account info.');
        }

        $profile = $profileResponse->json();
        $email = $profile['mail'] ?? $profile['userPrincipalName'];

        return EmailConnection::updateOrCreate(
            ['user_id' => $userId, 'email_address' => $email],
            [
                'provider' => 'outlook',
                'connection_type' => 'oauth',
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? '',
                'token_expires_at' => Carbon::now()->addSeconds($token['expires_in'] ?? 3600),
                'status' => 'active',
            ]
        );
    }

    /**
     * Authenticate using stored tokens, refreshing if expired.
     *
     * NOTE: No manual decrypt() — the model's 'encrypted' cast handles it.
     */
    public function authenticateConnection(EmailConnection $connection): string
    {
        if ($connection->token_expires_at && Carbon::parse($connection->token_expires_at)->isPast()) {
            return $this->refreshAccessToken($connection);
        }

        return $connection->access_token;
    }

    /**
     * Refresh an expired access token.
     */
    protected function refreshAccessToken(EmailConnection $connection): string
    {
        $response = Http::asForm()->post($this->tokenUrl, [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::error('Microsoft token refresh failed', ['body' => $response->body()]);
            $connection->update(['status' => 'reauth_required']);

            throw new \RuntimeException('Microsoft authentication expired. Please reconnect your email.');
        }

        $token = $response->json();

        $connection->update([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => Carbon::now()->addSeconds($token['expires_in'] ?? 3600),
        ]);

        return $token['access_token'];
    }

    /**
     * Fetch order-related emails via Microsoft Graph API.
     */
    public function fetchOrderEmails(EmailConnection $connection, ?Carbon $since = null): array
    {
        $accessToken = $this->authenticateConnection($connection);

        // Match Plaid's lookback: Jan 1 of previous year
        $since = $since ?? ($connection->last_synced_at ? Carbon::parse($connection->last_synced_at) : Carbon::now()->subYear()->startOfYear());
        $sinceStr = $since->toIso8601String();
        $messageIds = [];

        foreach ($this->searchQueries as $query) {
            try {
                $response = Http::withToken($accessToken)
                    ->get($this->graphUrl.'/me/messages', [
                        '$search' => '"'.$query.'"',
                        '$filter' => "receivedDateTime ge {$sinceStr}",
                        '$top' => 100,
                        '$select' => 'id,subject,from,receivedDateTime,bodyPreview',
                        '$orderby' => 'receivedDateTime desc',
                    ]);

                // If $filter + $search combined fails, retry with $search only
                if (! $response->successful()) {
                    $response = Http::withToken($accessToken)
                        ->get($this->graphUrl.'/me/messages', [
                            '$search' => '"'.$query.'"',
                            '$top' => 100,
                            '$select' => 'id,subject,from,receivedDateTime,bodyPreview',
                        ]);
                }

                if ($response->successful()) {
                    $messages = $response->json()['value'] ?? [];

                    foreach ($messages as $message) {
                        $msgId = $message['id'];

                        // Filter by date if $filter wasn't applied
                        if (isset($message['receivedDateTime'])) {
                            $msgDate = Carbon::parse($message['receivedDateTime']);
                            if ($msgDate->lt($since)) {
                                continue;
                            }
                        }

                        // Skip already-processed emails
                        if (ParsedEmail::where('email_message_id', $msgId)->exists()) {
                            continue;
                        }

                        $messageIds[] = $msgId;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Microsoft Graph search failed for query: {$query}", [
                    'error' => $e->getMessage(),
                    'connection_id' => $connection->id,
                ]);
            }
        }

        return array_unique($messageIds);
    }

    /**
     * Get the full email content for a specific message.
     */
    public function getEmailContent(EmailConnection $connection, string $messageId): array
    {
        $accessToken = $this->authenticateConnection($connection);

        $response = Http::withToken($accessToken)
            ->get($this->graphUrl."/me/messages/{$messageId}", [
                '$select' => 'id,subject,from,receivedDateTime,body,bodyPreview',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Failed to fetch email {$messageId}");
        }

        $message = $response->json();

        $body = '';
        if (isset($message['body'])) {
            if ($message['body']['contentType'] === 'html') {
                $body = $this->cleanHtml($message['body']['content']);
            } else {
                $body = $message['body']['content'] ?? '';
            }
        }

        $from = '';
        if (isset($message['from']['emailAddress'])) {
            $from = $message['from']['emailAddress']['name']
                ? $message['from']['emailAddress']['name'].' <'.$message['from']['emailAddress']['address'].'>'
                : $message['from']['emailAddress']['address'];
        }

        return [
            'message_id' => $messageId,
            'subject' => $message['subject'] ?? '',
            'from' => $from,
            'date' => $message['receivedDateTime'] ?? '',
            'body' => $body,
            'snippet' => $message['bodyPreview'] ?? '',
        ];
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

        if (strlen($text) > 8000) {
            $text = substr($text, 0, 8000)."\n...[truncated]";
        }

        return $text;
    }
}
