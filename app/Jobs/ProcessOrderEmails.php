<?php

namespace App\Jobs;

use App\Models\EmailConnection;
use App\Models\ParsedEmail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Email\GmailService;
use App\Services\AI\EmailParserService;
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
        protected EmailConnection $connection,
        protected ?string $sinceDate = null
    ) {}

    public function handle(GmailService $gmailService, EmailParserService $parser): void
    {
        $connection = $this->connection;
        $connection->update(['sync_status' => 'syncing']);

        try {
            $since = $this->sinceDate
                ? Carbon::parse($this->sinceDate)
                : null;

            // Step 1: Fetch new email IDs from Gmail
            $messageIds = $gmailService->fetchOrderEmails($connection, $since);

            $count = count($messageIds);
            Log::info("Found {$count} new potential order emails", [
                'count' => $count,
                'connection_id' => $connection->id,
            ]);

            $processed = 0;
            $orders_created = 0;

            foreach ($messageIds as $messageId) {
                try {
                    // Step 2: Get full email content
                    $emailContent = $gmailService->getEmailContent($messageId);

                    // Step 3: Create parsed email record (uses correct column names)
                    $parsedEmail = ParsedEmail::create([
                        'user_id' => $connection->user_id,
                        'email_connection_id' => $connection->id,
                        'email_message_id' => $messageId,
                        'email_thread_id' => $emailContent['thread_id'],
                        'email_date' => Carbon::parse($emailContent['date']),
                        'parse_status' => 'pending',
                    ]);

                    // Step 4: Send to Claude for parsing
                    $parsed = $parser->parseOrderEmail($emailContent);

                    if (isset($parsed['error'])) {
                        $parsedEmail->update([
                            'parse_status' => 'failed',
                            'parse_error' => $parsed['error'],
                            'raw_parsed_data' => $parsed,
                        ]);
                        continue;
                    }

                    // Step 5: Store results
                    if (!($parsed['is_purchase'] ?? false)) {
                        $parsedEmail->update([
                            'parse_status' => 'skipped',
                            'is_purchase' => false,
                            'raw_parsed_data' => $parsed,
                        ]);
                        continue;
                    }

                    // Step 6: Create order with individual items in a transaction
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

                        // THE KEY PART — individual products
                        foreach ($parsed['items'] ?? [] as $item) {
                            OrderItem::create([
                                'order_id' => $order->id,
                                'user_id' => $connection->user_id,
                                'product_name' => $item['product_name'],
                                'product_description' => $item['product_description'] ?? null,
                                'quantity' => $item['quantity'] ?? 1,
                                'unit_price' => $item['unit_price'] ?? $item['total_price'],
                                'total_price' => $item['total_price'],
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

                    // Rate limiting — don't hammer Gmail or Claude API
                    usleep(500000); // 0.5 second between emails

                } catch (\Exception $e) {
                    Log::error("Failed to process email {$messageId}", [
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

            Log::info("Email sync completed", [
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
