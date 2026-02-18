<?php

namespace App\Jobs;

use App\Models\EmailConnection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParsedEmail;
use App\Services\AI\EmailParserService;
use App\Services\Email\GmailService;
use App\Services\Email\ImapEmailService;
use App\Services\Email\MicrosoftOutlookService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOrderEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        protected EmailConnection $emailConnection,
        protected ?string $sinceDate = null
    ) {}

    public function handle(GmailService $gmailService, ImapEmailService $imapService, MicrosoftOutlookService $microsoftService, EmailParserService $parser): void
    {
        $connection = $this->emailConnection;
        $connection->update(['sync_status' => 'syncing']);

        try {
            $since = $this->sinceDate
                ? Carbon::parse($this->sinceDate)
                : null;

            // Step 1: Fetch emails — route to correct service based on connection type
            $emails = match ($connection->connection_type) {
                'imap' => $this->fetchViaImap($imapService, $connection, $since),
                'oauth' => $connection->provider === 'outlook'
                    ? $this->fetchViaMicrosoft($microsoftService, $connection, $since)
                    : $this->fetchViaGmail($gmailService, $connection, $since),
                default => $this->fetchViaGmail($gmailService, $connection, $since),
            };

            $count = count($emails);
            Log::info("Found {$count} new potential order emails", [
                'count' => $count,
                'connection_id' => $connection->id,
            ]);

            $processed = 0;
            $orders_created = 0;

            foreach ($emails as $emailData) {
                try {
                    // Step 2: Create parsed email record
                    $parsedEmail = ParsedEmail::create([
                        'user_id' => $connection->user_id,
                        'email_connection_id' => $connection->id,
                        'email_message_id' => $emailData['message_id'],
                        'email_thread_id' => $emailData['thread_id'] ?? null,
                        'email_date' => Carbon::parse($emailData['date']),
                        'parse_status' => 'pending',
                    ]);

                    // Step 3: Send to Claude for parsing
                    $parsed = $parser->parseOrderEmail($emailData);

                    if (isset($parsed['error'])) {
                        $parsedEmail->update([
                            'parse_status' => 'failed',
                            'parse_error' => $parsed['error'],
                            'raw_parsed_data' => $parsed,
                        ]);

                        continue;
                    }

                    // Step 4: Store results
                    if (! ($parsed['is_purchase'] ?? false)) {
                        $parsedEmail->update([
                            'parse_status' => 'skipped',
                            'is_purchase' => false,
                            'raw_parsed_data' => $parsed,
                        ]);

                        continue;
                    }

                    // Step 5: Create order with individual items in a transaction
                    DB::transaction(function () use ($parsedEmail, $parsed, $connection, &$orders_created) {
                        $order = Order::create([
                            'user_id' => $connection->user_id,
                            'parsed_email_id' => $parsedEmail->id,
                            'merchant' => $parsed['merchant'] ?? 'Unknown',
                            'merchant_normalized' => $parsed['merchant_normalized'] ?? $parsed['merchant'] ?? 'Unknown',
                            'order_number' => $parsed['order_number'] ?? null,
                            'order_date' => $parsed['order_date'] ?? $parsedEmail->email_date->toDateString(),
                            'subtotal' => $parsed['subtotal'] ?? null,
                            'tax' => $parsed['tax'] ?? null,
                            'shipping' => $parsed['shipping'] ?? null,
                            'total' => $parsed['total'] ?? 0,
                            'currency' => $parsed['currency'] ?? 'USD',
                        ]);

                        foreach ($parsed['items'] ?? [] as $item) {
                            OrderItem::create([
                                'order_id' => $order->id,
                                'user_id' => $connection->user_id,
                                'product_name' => $item['product_name'],
                                'product_description' => $item['product_description'] ?? null,
                                'quantity' => $item['quantity'] ?? 1,
                                'unit_price' => $item['unit_price'] ?? $item['total_price'] ?? 0,
                                'total_price' => $item['total_price'] ?? $item['unit_price'] ?? 0,
                                'ai_category' => $item['suggested_category'] ?? null,
                                'tax_deductible' => ($item['tax_deductible_likelihood'] ?? 0) >= 0.6,
                                'tax_deductible_confidence' => $item['tax_deductible_likelihood'] ?? null,
                                'expense_type' => $this->inferExpenseType($item),
                                'ai_metadata' => [
                                    'business_use_indicator' => $item['business_use_indicator'] ?? null,
                                    'product_type' => $item['product_type'] ?? null,
                                ],
                            ]);
                        }

                        $parsedEmail->update([
                            'is_purchase' => true,
                            'is_refund' => $parsed['is_refund'] ?? false,
                            'is_subscription' => $parsed['is_subscription'] ?? false,
                            'parse_status' => 'parsed',
                            'raw_parsed_data' => $parsed,
                        ]);

                        $orders_created++;
                    });

                    $processed++;

                    // Rate limiting — don't hammer email provider or Claude API
                    usleep(500000); // 0.5 second between emails

                } catch (\Exception $e) {
                    Log::error("Failed to process email {$emailData['message_id']}", [
                        'error' => $e->getMessage(),
                        'connection_id' => $connection->id,
                    ]);
                }
            }

            // After all emails processed, reconcile orders with bank transactions
            if ($orders_created > 0) {
                $reconciler = app(\App\Services\ReconciliationService::class);
                $reconcileResult = $reconciler->reconcile($connection->user);
                Log::info('Reconciliation complete', $reconcileResult);
            }

            $connection->update([
                'sync_status' => 'completed',
                'last_synced_at' => now(),
            ]);

            Log::info('Email sync completed', [
                'connection_id' => $connection->id,
                'processed' => $processed,
                'orders_created' => $orders_created,
            ]);

        } catch (\Exception $e) {
            $connection->update(['sync_status' => 'failed']);
            Log::error('Email sync job failed', [
                'error' => $e->getMessage(),
                'connection_id' => $connection->id,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch emails via IMAP — returns full email data directly.
     */
    protected function fetchViaImap(ImapEmailService $imapService, EmailConnection $connection, ?Carbon $since): array
    {
        return $imapService->fetchOrderEmails($connection, $since);
    }

    /**
     * Fetch emails via Gmail OAuth API — returns message IDs, then fetches full content.
     */
    protected function fetchViaGmail(GmailService $gmailService, EmailConnection $connection, ?Carbon $since): array
    {
        $messageIds = $gmailService->fetchOrderEmails($connection, $since);

        $emails = [];
        foreach ($messageIds as $messageId) {
            try {
                $emailContent = $gmailService->getEmailContent($messageId);
                $emails[] = [
                    'message_id' => $messageId,
                    'thread_id' => $emailContent['thread_id'] ?? null,
                    'subject' => $emailContent['subject'] ?? '',
                    'from' => $emailContent['from'] ?? '',
                    'date' => $emailContent['date'] ?? '',
                    'body' => $emailContent['body'] ?? '',
                    'snippet' => $emailContent['snippet'] ?? '',
                ];
            } catch (\Exception $e) {
                Log::error("Failed to fetch Gmail content for {$messageId}", [
                    'error' => $e->getMessage(),
                    'connection_id' => $connection->id,
                ]);
            }
        }

        return $emails;
    }

    /**
     * Fetch emails via Microsoft Graph OAuth API — returns message IDs, then fetches full content.
     */
    protected function fetchViaMicrosoft(MicrosoftOutlookService $microsoftService, EmailConnection $connection, ?Carbon $since): array
    {
        $messageIds = $microsoftService->fetchOrderEmails($connection, $since);

        $emails = [];
        foreach ($messageIds as $messageId) {
            try {
                $emailContent = $microsoftService->getEmailContent($connection, $messageId);
                $emails[] = [
                    'message_id' => $messageId,
                    'thread_id' => null,
                    'subject' => $emailContent['subject'] ?? '',
                    'from' => $emailContent['from'] ?? '',
                    'date' => $emailContent['date'] ?? '',
                    'body' => $emailContent['body'] ?? '',
                    'snippet' => $emailContent['snippet'] ?? '',
                ];
            } catch (\Exception $e) {
                Log::error("Failed to fetch Microsoft email content for {$messageId}", [
                    'error' => $e->getMessage(),
                    'connection_id' => $connection->id,
                ]);
            }
        }

        return $emails;
    }

    /**
     * Infer expense type from Claude's categorization hints.
     */
    protected function inferExpenseType(array $item): string
    {
        $likelihood = $item['tax_deductible_likelihood'] ?? 0;
        $indicator = $item['business_use_indicator'] ?? '';

        if ($likelihood >= 0.7 || str_contains(strtolower($indicator), 'business')) {
            return 'business';
        }

        if ($likelihood >= 0.3 && $likelihood < 0.7) {
            return 'mixed';
        }

        return 'personal';
    }
}
