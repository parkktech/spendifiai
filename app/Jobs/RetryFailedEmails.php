<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParsedEmail;
use App\Services\AI\EmailParserService;
use App\Services\Email\GmailService;
use App\Services\Email\ImapEmailService;
use App\Services\Email\MicrosoftOutlookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryFailedEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function handle(
        GmailService $gmailService,
        ImapEmailService $imapService,
        MicrosoftOutlookService $microsoftService,
        EmailParserService $parser,
    ): void {
        $failedEmails = ParsedEmail::where('parse_status', 'failed')
            ->where('retry_count', '<', 3)
            ->where('created_at', '>', now()->subDays(7))
            ->with('emailConnection')
            ->get();

        if ($failedEmails->isEmpty()) {
            return;
        }

        Log::info('Retrying failed email parses', ['count' => $failedEmails->count()]);

        $retried = 0;
        $succeeded = 0;

        foreach ($failedEmails as $parsedEmail) {
            $connection = $parsedEmail->emailConnection;

            if (! $connection || $connection->status !== 'active') {
                continue;
            }

            try {
                // Re-fetch email content from provider
                $emailData = $this->refetchEmail($connection, $parsedEmail, $gmailService, $imapService, $microsoftService);

                if (! $emailData) {
                    $parsedEmail->update([
                        'retry_count' => $parsedEmail->retry_count + 1,
                        'last_retry_at' => now(),
                        'parse_error' => 'Could not re-fetch email content',
                    ]);

                    continue;
                }

                // Re-send to Claude for parsing
                $parsed = $parser->parseOrderEmail($emailData);

                $parsedEmail->update([
                    'retry_count' => $parsedEmail->retry_count + 1,
                    'last_retry_at' => now(),
                ]);

                if (isset($parsed['error'])) {
                    $parsedEmail->update([
                        'parse_error' => $parsed['error'],
                        'raw_parsed_data' => $parsed,
                    ]);

                    continue;
                }

                if (! ($parsed['is_purchase'] ?? false)) {
                    $parsedEmail->update([
                        'parse_status' => 'skipped',
                        'is_purchase' => false,
                        'raw_parsed_data' => $parsed,
                    ]);

                    continue;
                }

                // Create order with items
                DB::transaction(function () use ($parsedEmail, $parsed, $connection) {
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
                });

                $succeeded++;

                // Dispatch reconciliation for this user
                ReconcileOrders::dispatch($connection->user);

                usleep(500000); // 0.5s rate limit

            } catch (\Exception $e) {
                Log::error('Retry failed for parsed email', [
                    'parsed_email_id' => $parsedEmail->id,
                    'error' => $e->getMessage(),
                ]);

                $parsedEmail->update([
                    'retry_count' => $parsedEmail->retry_count + 1,
                    'last_retry_at' => now(),
                ]);
            }

            $retried++;
        }

        Log::info('Retry failed emails completed', [
            'retried' => $retried,
            'succeeded' => $succeeded,
        ]);
    }

    protected function refetchEmail($connection, $parsedEmail, $gmailService, $imapService, $microsoftService): ?array
    {
        $messageId = $parsedEmail->email_message_id;

        return match ($connection->connection_type) {
            'imap' => $this->refetchViaImap($imapService, $connection, $messageId),
            'oauth' => $connection->provider === 'outlook'
                ? $this->refetchViaMicrosoft($microsoftService, $connection, $messageId)
                : $this->refetchViaGmail($gmailService, $messageId),
            default => $this->refetchViaGmail($gmailService, $messageId),
        };
    }

    protected function refetchViaGmail(GmailService $service, string $messageId): ?array
    {
        try {
            return $service->getEmailContent($messageId);
        } catch (\Exception $e) {
            Log::warning("Gmail refetch failed for {$messageId}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function refetchViaMicrosoft(MicrosoftOutlookService $service, $connection, string $messageId): ?array
    {
        try {
            return $service->getEmailContent($connection, $messageId);
        } catch (\Exception $e) {
            Log::warning("Microsoft refetch failed for {$messageId}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function refetchViaImap(ImapEmailService $service, $connection, string $messageId): ?array
    {
        // IMAP stores full content at fetch time — raw_parsed_data should have it
        // If not, IMAP doesn't support fetching by message-id easily, so return null
        return null;
    }

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
