<?php

namespace App\Services\Email;

use App\Models\EmailConnection;
use App\Models\ParsedEmail;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransactionGuidedSearchService
{
    /**
     * Search for emails matching unreconciled bank transactions.
     *
     * Uses merchant names and amounts from bank transactions to find
     * receipts that keyword-based subject search would miss.
     */
    public function search(EmailConnection $connection, ?Carbon $since = null): array
    {
        $config = config('email-search.transaction_guided');
        $since = $since ?? now()->subDays($config['lookback_days']);

        $queries = $this->buildMerchantQueries($connection->user_id, $since, $config);

        if (empty($queries)) {
            return [];
        }

        Log::info('Transaction-guided search', [
            'connection_id' => $connection->id,
            'merchant_queries' => count($queries),
        ]);

        return match ($connection->connection_type) {
            'imap' => $this->searchImap($connection, $queries, $since),
            'oauth' => $connection->provider === 'outlook'
                ? $this->searchOutlook($connection, $queries, $since)
                : $this->searchGmail($connection, $queries, $since),
            default => $this->searchGmail($connection, $queries, $since),
        };
    }

    /**
     * Build search queries from unreconciled bank transactions.
     */
    protected function buildMerchantQueries(int $userId, Carbon $since, array $config): array
    {
        $transactions = Transaction::where('user_id', $userId)
            ->where('is_reconciled', false)
            ->where('amount', '>', $config['min_amount'])
            ->where('transaction_date', '>=', $since)
            ->spending()
            ->selectRaw('merchant_normalized, merchant_name, SUM(amount) as total_spend, MAX(amount) as max_amount')
            ->groupBy('merchant_normalized', 'merchant_name')
            ->orderByDesc('total_spend')
            ->limit($config['max_merchant_queries'])
            ->get();

        $queries = [];
        $seenMerchants = [];

        foreach ($transactions as $txn) {
            $merchant = $txn->merchant_normalized ?: $txn->merchant_name;

            if (! $merchant) {
                continue;
            }

            // Clean merchant name: strip trailing numbers, special chars, common bank suffixes
            $cleaned = $this->cleanMerchantName($merchant);

            if (! $cleaned || strlen($cleaned) < 3 || isset($seenMerchants[$cleaned])) {
                continue;
            }

            $seenMerchants[$cleaned] = true;
            $queries[] = ['type' => 'merchant', 'value' => $cleaned];

            // For high-value transactions, also search by dollar amount
            if ((float) $txn->max_amount >= $config['high_value_threshold']) {
                $queries[] = ['type' => 'amount', 'value' => number_format((float) $txn->max_amount, 2, '.', '')];
            }
        }

        return $queries;
    }

    /**
     * Clean a merchant name for email search.
     */
    protected function cleanMerchantName(string $name): string
    {
        // Remove common bank statement prefixes/suffixes
        $name = preg_replace('/\b(POS|DEBIT|CREDIT|PURCHASE|CHECKCARD|SQ \*|TST \*|PP \*|PAYPAL \*)\b/i', '', $name);

        // Remove trailing reference numbers
        $name = preg_replace('/\s*#?\d{4,}$/', '', $name);

        // Remove special characters but keep spaces and basic punctuation
        $name = preg_replace('/[^a-zA-Z0-9\s\-\'&.]/', '', $name);

        // Collapse whitespace
        $name = preg_replace('/\s+/', ' ', trim($name));

        return $name;
    }

    /**
     * Search Gmail for transaction-guided queries.
     */
    protected function searchGmail(EmailConnection $connection, array $queries, Carbon $since): array
    {
        $gmailService = app(GmailService::class);
        $gmailService->authenticateConnection($connection);

        $existingIds = ParsedEmail::where('email_connection_id', $connection->id)
            ->pluck('email_message_id')
            ->flip()
            ->toArray();

        $messageIds = [];
        $afterDate = $since->format('Y/m/d');

        foreach ($queries as $query) {
            try {
                $searchQuery = match ($query['type']) {
                    'merchant' => '"'.$query['value'].'" -label:trash after:'.$afterDate,
                    'amount' => '"$'.$query['value'].'" -label:trash after:'.$afterDate,
                    default => null,
                };

                if (! $searchQuery) {
                    continue;
                }

                $results = $gmailService->getGmailInstance()->users_messages->listUsersMessages('me', [
                    'q' => $searchQuery,
                    'maxResults' => 20,
                ]);

                foreach ($results->getMessages() ?? [] as $message) {
                    $gmailId = $message->getId();

                    if (! isset($existingIds[$gmailId]) && ! in_array($gmailId, $messageIds)) {
                        $messageIds[] = $gmailId;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Transaction-guided Gmail search failed for: {$query['value']}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fetch full content for found message IDs
        $emails = [];
        foreach ($messageIds as $messageId) {
            try {
                $content = $gmailService->getEmailContent($messageId);
                $emails[] = [
                    'message_id' => $messageId,
                    'thread_id' => $content['thread_id'] ?? null,
                    'subject' => $content['subject'] ?? '',
                    'from' => $content['from'] ?? '',
                    'date' => $content['date'] ?? '',
                    'body' => $content['body'] ?? '',
                    'snippet' => $content['snippet'] ?? '',
                    'search_source' => 'transaction_guided',
                ];
            } catch (\Exception $e) {
                Log::warning("Failed to fetch Gmail content for transaction-guided result {$messageId}");
            }
        }

        return $emails;
    }

    /**
     * Search IMAP for transaction-guided queries.
     */
    protected function searchImap(EmailConnection $connection, array $queries, Carbon $since): array
    {
        $imapService = app(ImapEmailService::class);

        $cm = new \Webklex\PHPIMAP\ClientManager;
        $client = $cm->make([
            'host' => $connection->imap_host,
            'port' => $connection->imap_port,
            'encryption' => $connection->imap_encryption,
            'validate_cert' => true,
            'username' => $connection->email_address,
            'password' => $connection->access_token,
            'protocol' => 'imap',
        ]);

        $client->connect();
        $inbox = $client->getFolder('INBOX');

        $existingIds = ParsedEmail::where('email_connection_id', $connection->id)
            ->pluck('email_message_id')
            ->flip()
            ->toArray();

        $messageIds = [];

        foreach ($queries as $query) {
            if ($query['type'] !== 'merchant') {
                continue; // IMAP text search works best for merchant names
            }

            try {
                $messages = $inbox->query()
                    ->text($query['value'])
                    ->since($since->toDate())
                    ->get();

                foreach ($messages as $message) {
                    $messageId = $message->getMessageId()?->toString() ?? $message->getUid();

                    if (isset($existingIds[$messageId])) {
                        continue;
                    }

                    $messageIds[] = [
                        'message_id' => $messageId,
                        'subject' => $message->getSubject()?->toString() ?? '',
                        'from' => $message->getFrom()?->toString() ?? '',
                        'date' => $message->getDate()?->toString() ?? '',
                        'body' => $this->extractImapBody($message),
                        'snippet' => mb_substr(strip_tags($message->getTextBody() ?? ''), 0, 200),
                        'search_source' => 'transaction_guided',
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("Transaction-guided IMAP search failed for: {$query['value']}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $client->disconnect();
        } catch (\Exception) {
        }

        return collect($messageIds)->unique('message_id')->values()->toArray();
    }

    /**
     * Search Microsoft Outlook for transaction-guided queries.
     */
    protected function searchOutlook(EmailConnection $connection, array $queries, Carbon $since): array
    {
        $microsoftService = app(MicrosoftOutlookService::class);
        $accessToken = $microsoftService->authenticateConnection($connection);

        $existingIds = ParsedEmail::where('email_connection_id', $connection->id)
            ->pluck('email_message_id')
            ->flip()
            ->toArray();

        $messageIds = [];
        $graphUrl = 'https://graph.microsoft.com/v1.0';
        $sinceStr = $since->toIso8601String();

        foreach ($queries as $query) {
            try {
                $searchValue = match ($query['type']) {
                    'merchant' => '"'.$query['value'].'"',
                    'amount' => '"$'.$query['value'].'"',
                    default => null,
                };

                if (! $searchValue) {
                    continue;
                }

                $response = Http::withToken($accessToken)
                    ->get($graphUrl.'/me/messages', [
                        '$search' => $searchValue,
                        '$filter' => "receivedDateTime ge {$sinceStr}",
                        '$top' => 20,
                        '$select' => 'id,subject,from,receivedDateTime,bodyPreview',
                    ]);

                if (! $response->successful()) {
                    $response = Http::withToken($accessToken)
                        ->get($graphUrl.'/me/messages', [
                            '$search' => $searchValue,
                            '$top' => 20,
                            '$select' => 'id,subject,from,receivedDateTime,bodyPreview',
                        ]);
                }

                if ($response->successful()) {
                    foreach ($response->json()['value'] ?? [] as $message) {
                        $msgId = $message['id'];

                        if (! isset($existingIds[$msgId]) && ! in_array($msgId, $messageIds)) {
                            $messageIds[] = $msgId;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Transaction-guided Outlook search failed for: {$query['value']}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fetch full content
        $emails = [];
        foreach ($messageIds as $messageId) {
            try {
                $content = $microsoftService->getEmailContent($connection, $messageId);
                $emails[] = [
                    'message_id' => $messageId,
                    'thread_id' => null,
                    'subject' => $content['subject'] ?? '',
                    'from' => $content['from'] ?? '',
                    'date' => $content['date'] ?? '',
                    'body' => $content['body'] ?? '',
                    'snippet' => $content['snippet'] ?? '',
                    'search_source' => 'transaction_guided',
                ];
            } catch (\Exception $e) {
                Log::warning("Failed to fetch Outlook content for transaction-guided result {$messageId}");
            }
        }

        return $emails;
    }

    /**
     * Extract body from IMAP message.
     */
    protected function extractImapBody($message): string
    {
        $html = $message->getHTMLBody();
        $text = $message->getTextBody();

        if ($html) {
            return app(ImapEmailService::class)->cleanHtml($html);
        }

        return $text ?? '';
    }
}
