<?php

namespace App\Jobs;

use App\Models\AIQuestion;
use App\Models\EmailConnection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParsedEmail;
use App\Models\Transaction;
use App\Services\AI\EmailParserService;
use App\Services\Email\TransactionGuidedSearchService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SearchTransactionEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    public function __construct(
        protected Transaction $transaction
    ) {}

    public function handle(TransactionGuidedSearchService $guidedSearch, EmailParserService $parser): void
    {
        // If transaction is already reconciled to an order, resolve the question
        $this->transaction->refresh();
        if ($this->transaction->is_reconciled || $this->transaction->matched_order_id) {
            $question = AIQuestion::where('transaction_id', $this->transaction->id)
                ->where('status', 'pending')
                ->first();

            if ($question) {
                $question->update(['email_search_status' => 'found']);
            }

            Log::info('SearchTransactionEmails: transaction already reconciled', [
                'transaction_id' => $this->transaction->id,
            ]);

            return;
        }

        $userId = $this->transaction->user_id;
        $connections = EmailConnection::where('user_id', $userId)
            ->where('status', 'active')
            ->get();

        if ($connections->isEmpty()) {
            Log::info('SearchTransactionEmails: no email connections', [
                'transaction_id' => $this->transaction->id,
            ]);

            return;
        }

        $since = Carbon::parse($this->transaction->transaction_date)->subDays(7);
        $ordersCreated = 0;

        foreach ($connections as $connection) {
            try {
                $emails = $guidedSearch->search($connection, $since, $this->transaction->id);

                // Prioritize emails whose body contains the target amount.
                // Microsoft Graph search doesn't reliably index amounts inside HTML tables,
                // so we do a local body scan to find the right email.
                $targetAmount = number_format(abs((float) $this->transaction->amount), 2, '.', '');
                $emails = $this->prioritizeByAmount($emails, $targetAmount);

                Log::info('SearchTransactionEmails: found emails', [
                    'transaction_id' => $this->transaction->id,
                    'connection_id' => $connection->id,
                    'count' => count($emails),
                ]);

                foreach ($emails as $emailData) {
                    try {
                        // Use updateOrCreate to handle re-parsing previously skipped emails
                        $parsedEmail = ParsedEmail::updateOrCreate(
                            [
                                'email_connection_id' => $connection->id,
                                'email_message_id' => $emailData['message_id'],
                            ],
                            [
                                'user_id' => $userId,
                                'email_thread_id' => $emailData['thread_id'] ?? null,
                                'email_date' => Carbon::parse($emailData['date']),
                                'parse_status' => 'pending',
                                'search_source' => 'question_search',
                            ]
                        );

                        // Skip if an order already exists for this parsed email
                        if (Order::where('parsed_email_id', $parsedEmail->id)->exists()) {
                            $parsedEmail->update(['parse_status' => 'parsed']);

                            continue;
                        }

                        $parsed = $parser->parseOrderEmail($emailData);

                        if (isset($parsed['error'])) {
                            $parsedEmail->update([
                                'parse_status' => 'failed',
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

                        DB::transaction(function () use ($parsedEmail, $parsed, $userId, &$ordersCreated) {
                            $order = Order::create([
                                'user_id' => $userId,
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
                                    'user_id' => $userId,
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

                            $ordersCreated++;
                        });

                        usleep(500000); // 0.5s rate limit
                    } catch (\Exception $e) {
                        Log::error("SearchTransactionEmails: failed to process email {$emailData['message_id']}", [
                            'error' => $e->getMessage(),
                            'transaction_id' => $this->transaction->id,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('SearchTransactionEmails: connection search failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($ordersCreated > 0) {
            ReconcileOrders::dispatch($this->transaction->user);
        }

        // Update the AIQuestion status so the UI reflects what happened
        $question = AIQuestion::where('transaction_id', $this->transaction->id)
            ->where('status', 'pending')
            ->first();

        if ($question) {
            $question->update([
                'email_search_status' => $ordersCreated > 0 ? 'found' : 'no_results',
            ]);
        }

        Log::info('SearchTransactionEmails: completed', [
            'transaction_id' => $this->transaction->id,
            'orders_created' => $ordersCreated,
        ]);
    }

    /**
     * Sort emails so those containing the target amount in their body come first.
     * This ensures we parse the most relevant email first, saving API calls.
     */
    protected function prioritizeByAmount(array $emails, string $targetAmount): array
    {
        $withAmount = [];
        $without = [];

        foreach ($emails as $email) {
            $body = $email['body'] ?? '';
            $snippet = $email['snippet'] ?? '';
            $searchText = $body.' '.$snippet;

            if (str_contains($searchText, '$'.$targetAmount) || str_contains($searchText, $targetAmount)) {
                $withAmount[] = $email;
            } else {
                $without[] = $email;
            }
        }

        if (! empty($withAmount)) {
            Log::info('SearchTransactionEmails: found amount in email body', [
                'transaction_id' => $this->transaction->id,
                'amount' => $targetAmount,
                'matching_emails' => count($withAmount),
            ]);
        }

        return array_merge($withAmount, $without);
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
