<?php

namespace App\Services\Email;

use App\Models\EmailConnection;
use App\Models\MerchantAlias;
use App\Models\ParsedEmail;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
    public function search(EmailConnection $connection, ?Carbon $since = null, ?int $transactionId = null): array
    {
        $config = config('email-search.transaction_guided');
        $since = $since ?? now()->subDays($config['lookback_days']);

        $queries = $this->buildMerchantQueries($connection->user_id, $since, $config, $transactionId);

        if (empty($queries)) {
            return [];
        }

        Log::info('Transaction-guided search', [
            'connection_id' => $connection->id,
            'connection_type' => $connection->connection_type,
            'provider' => $connection->provider,
            'queries' => $queries,
        ]);

        // For single-transaction search (user clicked "Search my email"),
        // skip the existingIds filter so previously-skipped emails get re-parsed.
        $skipExisting = $transactionId !== null;

        return match ($connection->connection_type) {
            'imap' => $this->searchImap($connection, $queries, $since, $skipExisting),
            'oauth' => $connection->provider === 'outlook'
                ? $this->searchOutlook($connection, $queries, $since, $skipExisting, $transactionId)
                : $this->searchGmail($connection, $queries, $since, $skipExisting),
            default => $this->searchGmail($connection, $queries, $since),
        };
    }

    /**
     * Build search queries from unreconciled bank transactions.
     */
    protected function buildMerchantQueries(int $userId, Carbon $since, array $config, ?int $transactionId = null): array
    {
        $query = Transaction::where('user_id', $userId)
            ->where('is_reconciled', false)
            ->spending();

        if ($transactionId) {
            // Single transaction search — skip amount/date filters and grouping
            $transaction = $query->where('id', $transactionId)->first();

            if (! $transaction) {
                return [];
            }

            $merchant = $transaction->merchant_normalized ?: $transaction->merchant_name;
            $cleaned = $merchant ? $this->cleanMerchantName($merchant) : null;

            if (! $cleaned || strlen($cleaned) < 3) {
                return [];
            }

            $queries = [['type' => 'merchant', 'value' => $cleaned]];

            // Also search by the real merchant name if bank name is cryptic
            $realName = $this->resolveRealMerchantName($merchant);
            if ($realName && strtolower($realName) !== strtolower($cleaned)) {
                $queries[] = ['type' => 'merchant', 'value' => $realName];
            }

            // For single-transaction search, always include amount — it's the most
            // reliable signal for finding the exact receipt email.
            $queries[] = ['type' => 'amount', 'value' => number_format(abs((float) $transaction->amount), 2, '.', '')];

            return $queries;
        }

        $transactions = $query
            ->where('amount', '>', $config['min_amount'])
            ->where('transaction_date', '>=', $since)
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
     * Resolve cryptic bank merchant names to real names using aliases.
     * "AMZN MKTP US" → "Amazon", "WMT GROCERY" → "Walmart", etc.
     */
    protected function resolveRealMerchantName(string $name): ?string
    {
        $upper = strtoupper(trim($name));

        // Check DB-backed aliases first
        $dbAliases = Cache::remember('merchant_aliases', 3600, function () {
            return MerchantAlias::pluck('normalized_name', 'bank_name')->toArray();
        });

        foreach ($dbAliases as $pattern => $normalized) {
            if (str_starts_with($upper, strtoupper($pattern))) {
                return $normalized;
            }
        }

        // Hardcoded common bank statement → real name mappings
        $aliases = [
            'AMZN MKTP' => 'Amazon', 'AMAZON.COM' => 'Amazon', 'AMZN.COM' => 'Amazon',
            'AMZN DIGITAL' => 'Amazon', 'AMZN' => 'Amazon',
            'WMT GROCERY' => 'Walmart', 'WAL-MART' => 'Walmart', 'WALMART.COM' => 'Walmart',
            'WM SUPERCENTER' => 'Walmart',
            'COSTCO WHSE' => 'Costco', 'COSTCO.COM' => 'Costco',
            'APPLE.COM/BILL' => 'Apple', 'APL*APPLE' => 'Apple',
            'BESTBUYCOM' => 'Best Buy', 'BBY' => 'Best Buy',
            'HOMEDEPOT.COM' => 'Home Depot', 'THE HOME DEPOT' => 'Home Depot',
            'DD DOORDASH' => 'DoorDash',
            'UBER *EATS' => 'Uber Eats',
        ];

        foreach ($aliases as $pattern => $normalized) {
            if (str_starts_with($upper, strtoupper($pattern))) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Search Gmail for transaction-guided queries.
     */
    protected function searchGmail(EmailConnection $connection, array $queries, Carbon $since, bool $skipExisting = false): array
    {
        $gmailService = app(GmailService::class);
        $gmailService->authenticateConnection($connection);

        $existingIds = $skipExisting ? [] : ParsedEmail::where('email_connection_id', $connection->id)
            ->pluck('email_message_id')
            ->flip()
            ->toArray();

        $messageIds = [];
        $afterDate = $since->format('Y/m/d');

        foreach ($queries as $query) {
            // For amounts, try both with and without $ sign
            $searchQueries = match ($query['type']) {
                'merchant' => ['"'.$query['value'].'" -label:trash after:'.$afterDate],
                'amount' => [
                    '"$'.$query['value'].'" -label:trash after:'.$afterDate,
                    '"'.$query['value'].'" -label:trash after:'.$afterDate,
                ],
                default => [],
            };

            foreach ($searchQueries as $searchQuery) {
                try {
                    $results = $gmailService->getGmailInstance()->users_messages->listUsersMessages('me', [
                        'q' => $searchQuery,
                        'maxResults' => 20,
                    ]);

                    $found = false;
                    foreach ($results->getMessages() ?? [] as $message) {
                        $gmailId = $message->getId();

                        if (! isset($existingIds[$gmailId]) && ! in_array($gmailId, $messageIds)) {
                            $messageIds[] = $gmailId;
                            $found = true;
                        }
                    }

                    // If first format found results, skip the alternate format
                    if ($found) {
                        break;
                    }
                } catch (\Exception $e) {
                    Log::warning("Transaction-guided Gmail search failed for: {$searchQuery}", [
                        'error' => $e->getMessage(),
                    ]);
                }
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
    protected function searchImap(EmailConnection $connection, array $queries, Carbon $since, bool $skipExisting = false): array
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

        $existingIds = $skipExisting ? [] : ParsedEmail::where('email_connection_id', $connection->id)
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
    protected function searchOutlook(EmailConnection $connection, array $queries, Carbon $since, bool $skipExisting = false, ?int $transactionId = null): array
    {
        $microsoftService = app(MicrosoftOutlookService::class);
        $accessToken = $microsoftService->authenticateConnection($connection);

        $existingIds = $skipExisting ? [] : ParsedEmail::where('email_connection_id', $connection->id)
            ->pluck('email_message_id')
            ->flip()
            ->toArray();

        $messageIds = [];
        $graphUrl = 'https://graph.microsoft.com/v1.0';

        foreach ($queries as $query) {
            // For amounts, try both with and without $ sign since Outlook
            // may treat $ as a special character in search.
            $searchValues = match ($query['type']) {
                'merchant' => ['"'.$query['value'].'"'],
                'amount' => ['"$'.$query['value'].'"', '"'.$query['value'].'"'],
                default => [],
            };

            foreach ($searchValues as $searchValue) {
                try {
                    // Microsoft Graph does not support $search + $filter together on messages.
                    // Use $search alone and filter by date client-side.
                    // Use higher limit for merchant searches — Graph's search index
                    // doesn't reliably index amounts inside HTML email bodies.
                    $limit = $query['type'] === 'merchant' ? 50 : 20;
                    $response = Http::withToken($accessToken)
                        ->get($graphUrl.'/me/messages', [
                            '$search' => $searchValue,
                            '$top' => $limit,
                            '$select' => 'id,subject,from,receivedDateTime,bodyPreview',
                        ]);

                    Log::info('Outlook search request', [
                        'search' => $searchValue,
                        'status' => $response->status(),
                        'result_count' => count($response->json()['value'] ?? []),
                    ]);

                    if ($response->successful()) {
                        $found = false;
                        foreach ($response->json()['value'] ?? [] as $message) {
                            $msgId = $message['id'];

                            // Client-side date filter: skip emails older than $since
                            if (isset($message['receivedDateTime'])) {
                                $receivedAt = Carbon::parse($message['receivedDateTime']);
                                if ($receivedAt->lt($since)) {
                                    Log::debug('Outlook: skipped (too old)', [
                                        'subject' => $message['subject'] ?? '',
                                        'date' => $message['receivedDateTime'],
                                        'since' => $since->toIso8601String(),
                                    ]);

                                    continue;
                                }
                            }

                            if (isset($existingIds[$msgId])) {
                                Log::debug('Outlook: skipped (already processed)', [
                                    'subject' => $message['subject'] ?? '',
                                    'message_id' => $msgId,
                                ]);

                                continue;
                            }

                            if (! in_array($msgId, $messageIds)) {
                                $messageIds[] = $msgId;
                                $found = true;
                            }
                        }

                        // If first format found results, skip the alternate format
                        if ($found) {
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Transaction-guided Outlook search failed for: {$searchValue}", [
                        'error' => $e->getMessage(),
                    ]);
                }
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

        // Phase 2: Fallback for single-transaction searches.
        // When $search didn't find an email with the target amount, try:
        // 2a) Search by known receipt sender addresses (fast, targeted)
        // 2b) Scan all emails in date range for the exact amount (thorough)
        if ($transactionId) {
            $targetAmount = null;
            foreach ($queries as $q) {
                if ($q['type'] === 'amount') {
                    $targetAmount = $q['value'];
                    break;
                }
            }

            if ($targetAmount) {
                $amountFound = false;
                foreach ($emails as $email) {
                    $searchText = ($email['body'] ?? '').' '.($email['snippet'] ?? '');
                    if (str_contains($searchText, '$'.$targetAmount) || str_contains($searchText, $targetAmount)) {
                        $amountFound = true;
                        break;
                    }
                }

                if (! $amountFound) {
                    $transaction = Transaction::find($transactionId);
                    $until = $transaction
                        ? Carbon::parse($transaction->transaction_date)->addDays(2)
                        : $since->copy()->addDays(9);

                    // Phase 2a: Search by known receipt sender addresses.
                    // Even if the bank charge doesn't match the email total exactly
                    // (tax adjustments, split charges), we can still find receipt emails
                    // from the merchant and let reconciliation do fuzzy matching.
                    $merchantNames = [];
                    foreach ($queries as $q) {
                        if ($q['type'] === 'merchant') {
                            $merchantNames[] = $q['value'];
                        }
                    }

                    $senderEmails = $this->searchOutlookByReceiptSenders(
                        $accessToken, $since, $until, $merchantNames, $messageIds
                    );

                    if (! empty($senderEmails)) {
                        Log::info('Outlook: found emails via receipt sender search', [
                            'amount' => $targetAmount,
                            'found' => count($senderEmails),
                        ]);
                        $emails = array_merge($emails, $senderEmails);
                    } else {
                        // Phase 2b: Brute-force date-range scan for the exact amount.
                        Log::info('Outlook: no receipt sender results, falling back to date-range scan', [
                            'amount' => $targetAmount,
                            'search_results' => count($emails),
                        ]);

                        $fallbackEmails = $this->searchOutlookByDateRange(
                            $accessToken, $connection, $since, $until, $targetAmount, $messageIds
                        );

                        $emails = array_merge($emails, $fallbackEmails);
                    }
                }
            }
        }

        return $emails;
    }

    /**
     * Fallback: fetch emails by date range and scan bodies for the target amount.
     * Bypasses Microsoft Graph's $search index which doesn't reliably index
     * amounts inside HTML email tables (e.g., Amazon order confirmations).
     */
    protected function searchOutlookByDateRange(
        string $accessToken,
        EmailConnection $connection,
        Carbon $since,
        Carbon $until,
        string $targetAmount,
        array $alreadyFoundIds
    ): array {
        $graphUrl = 'https://graph.microsoft.com/v1.0';
        $microsoftService = app(MicrosoftOutlookService::class);

        $emails = [];
        $scannedCount = 0;
        $nextLink = null;
        $pagesScanned = 0;
        $maxPages = 10; // Up to 500 emails

        $sinceStr = $since->utc()->format('Y-m-d\TH:i:s\Z');
        $untilStr = $until->utc()->format('Y-m-d\TH:i:s\Z');

        do {
            try {
                $response = $nextLink
                    ? Http::withToken($accessToken)->get($nextLink)
                    : Http::withToken($accessToken)->get($graphUrl.'/me/messages', [
                        '$filter' => "receivedDateTime ge {$sinceStr} and receivedDateTime le {$untilStr}",
                        '$orderby' => 'receivedDateTime desc',
                        '$top' => 50,
                        '$select' => 'id,subject,from,receivedDateTime,body,bodyPreview',
                    ]);

                if (! $response->successful()) {
                    Log::warning('Outlook date-range scan: request failed', [
                        'status' => $response->status(),
                    ]);
                    break;
                }

                $data = $response->json();
                $messages = $data['value'] ?? [];
                $scannedCount += count($messages);

                foreach ($messages as $message) {
                    $msgId = $message['id'];
                    if (in_array($msgId, $alreadyFoundIds)) {
                        continue;
                    }

                    // Use raw strip_tags for amount scanning (no 8000-char truncation)
                    // Amazon HTML emails can be very long, with totals appearing late in the body.
                    $rawText = '';
                    if (isset($message['body'])) {
                        $rawText = strip_tags($message['body']['content'] ?? '');
                    }
                    // Decode HTML entities (&#36; → $) so amount matching works
                    $searchText = html_entity_decode($rawText.' '.($message['bodyPreview'] ?? ''));

                    if (str_contains($searchText, '$'.$targetAmount) || str_contains($searchText, $targetAmount)) {
                        // Match found — now clean body properly for Claude parsing
                        $cleanedBody = '';
                        if (isset($message['body'])) {
                            if ($message['body']['contentType'] === 'html') {
                                $cleanedBody = $microsoftService->cleanHtml($message['body']['content']);
                            } else {
                                $cleanedBody = $message['body']['content'] ?? '';
                            }
                        }

                        $from = '';
                        if (isset($message['from']['emailAddress'])) {
                            $from = $message['from']['emailAddress']['name']
                                ? $message['from']['emailAddress']['name'].' <'.$message['from']['emailAddress']['address'].'>'
                                : $message['from']['emailAddress']['address'];
                        }

                        $emails[] = [
                            'message_id' => $msgId,
                            'thread_id' => null,
                            'subject' => $message['subject'] ?? '',
                            'from' => $from,
                            'date' => $message['receivedDateTime'] ?? '',
                            'body' => $cleanedBody,
                            'snippet' => $message['bodyPreview'] ?? '',
                            'search_source' => 'transaction_guided_daterange',
                        ];

                        Log::info('Outlook date-range scan: found amount match', [
                            'subject' => $message['subject'] ?? '',
                            'from' => $from,
                            'amount' => $targetAmount,
                            'date' => $message['receivedDateTime'] ?? '',
                        ]);
                    }
                }

                $nextLink = $data['@odata.nextLink'] ?? null;
                $pagesScanned++;

            } catch (\Exception $e) {
                Log::warning('Outlook date-range scan failed', ['error' => $e->getMessage()]);
                break;
            }
        } while ($nextLink && $pagesScanned < $maxPages);

        Log::info('Outlook date-range scan completed', [
            'pages_scanned' => $pagesScanned,
            'emails_scanned' => $scannedCount,
            'matches_found' => count($emails),
            'amount' => $targetAmount,
            'exhausted_pages' => $nextLink !== null,
        ]);

        return $emails;
    }

    /**
     * Search Outlook for emails from known receipt sender addresses.
     * Returns ALL matching emails from the date range — doesn't filter by amount.
     * Lets Claude parse them and reconciliation handle fuzzy matching.
     */
    protected function searchOutlookByReceiptSenders(
        string $accessToken,
        Carbon $since,
        Carbon $until,
        array $merchantNames,
        array $alreadyFoundIds
    ): array {
        $senders = [];
        foreach ($merchantNames as $name) {
            $senders = array_merge($senders, $this->getReceiptSenders($name));
        }
        $senders = array_unique($senders);

        if (empty($senders)) {
            return [];
        }

        $graphUrl = 'https://graph.microsoft.com/v1.0';
        $microsoftService = app(MicrosoftOutlookService::class);
        $sinceStr = $since->utc()->format('Y-m-d\TH:i:s\Z');
        $untilStr = $until->utc()->format('Y-m-d\TH:i:s\Z');

        $emails = [];

        foreach ($senders as $sender) {
            try {
                // $filter on from/emailAddress/address + date range works together
                // (the limitation is only $filter + $search combined)
                $filter = "from/emailAddress/address eq '{$sender}'"
                    ." and receivedDateTime ge {$sinceStr}"
                    ." and receivedDateTime le {$untilStr}";

                $response = Http::withToken($accessToken)
                    ->get($graphUrl.'/me/messages', [
                        '$filter' => $filter,
                        '$orderby' => 'receivedDateTime desc',
                        '$top' => 15,
                        '$select' => 'id,subject,from,receivedDateTime,body,bodyPreview',
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                foreach ($response->json()['value'] ?? [] as $message) {
                    $msgId = $message['id'];
                    if (in_array($msgId, $alreadyFoundIds)) {
                        continue;
                    }

                    // Only include order/receipt emails (skip marketing, reviews, etc.)
                    $subject = strtolower($message['subject'] ?? '');
                    if (! $this->isReceiptSubject($subject)) {
                        continue;
                    }

                    $cleanedBody = '';
                    if (isset($message['body'])) {
                        $cleanedBody = $message['body']['contentType'] === 'html'
                            ? $microsoftService->cleanHtml($message['body']['content'])
                            : ($message['body']['content'] ?? '');
                    }

                    $from = '';
                    if (isset($message['from']['emailAddress'])) {
                        $from = $message['from']['emailAddress']['name']
                            ? $message['from']['emailAddress']['name'].' <'.$message['from']['emailAddress']['address'].'>'
                            : $message['from']['emailAddress']['address'];
                    }

                    $emails[] = [
                        'message_id' => $msgId,
                        'thread_id' => null,
                        'subject' => $message['subject'] ?? '',
                        'from' => $from,
                        'date' => $message['receivedDateTime'] ?? '',
                        'body' => $cleanedBody,
                        'snippet' => $message['bodyPreview'] ?? '',
                        'search_source' => 'transaction_guided_sender',
                    ];

                    // Don't mark as already found — let the caller decide
                    $alreadyFoundIds[] = $msgId;
                }
            } catch (\Exception $e) {
                Log::warning("Receipt sender search failed for: {$sender}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Outlook receipt sender search completed', [
            'senders_checked' => count($senders),
            'matches_found' => count($emails),
        ]);

        return $emails;
    }

    /**
     * Get known receipt sender email addresses for a merchant.
     */
    protected function getReceiptSenders(string $merchantName): array
    {
        $upper = strtoupper(trim($merchantName));

        $senderMap = [
            'AMAZON' => ['auto-confirm@amazon.com', 'shipment-tracking@amazon.com', 'digital-no-reply@amazon.com'],
            'WALMART' => ['help@walmart.com'],
            'COSTCO' => ['costco@online.costco.com'],
            'APPLE' => ['no_reply@email.apple.com'],
            'BEST BUY' => ['BestBuyInfo@emailinfo.bestbuy.com'],
            'HOME DEPOT' => ['order_confirmation@homedepot.com'],
            'TARGET' => ['target@e.target.com'],
            'DOORDASH' => ['no-reply@doordash.com'],
            'UBER' => ['uber.us@uber.com'],
            'GOOGLE' => ['googleplay-noreply@google.com'],
            'NETFLIX' => ['info@account.netflix.com'],
            'SPOTIFY' => ['no-reply@spotify.com'],
        ];

        foreach ($senderMap as $pattern => $senders) {
            if (str_contains($upper, $pattern)) {
                return $senders;
            }
        }

        return [];
    }

    /**
     * Check if an email subject looks like an order/receipt.
     */
    protected function isReceiptSubject(string $subject): bool
    {
        $receiptPatterns = [
            'order', 'ordered', 'shipped', 'delivered', 'confirmed',
            'receipt', 'invoice', 'purchase', 'payment', 'subscription',
            'your .* has been', 'thanks for your',
        ];

        foreach ($receiptPatterns as $pattern) {
            if (str_contains($pattern, '.*')) {
                if (preg_match("/{$pattern}/i", $subject)) {
                    return true;
                }
            } elseif (str_contains($subject, $pattern)) {
                return true;
            }
        }

        return false;
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
