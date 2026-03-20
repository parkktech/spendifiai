<?php

namespace App\Jobs;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\PlaidStatement;
use App\Models\Transaction;
use App\Services\BankStatementParserService;
use App\Services\PlaidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadPlaidStatements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [60, 300, 900];

    public function __construct(
        protected BankConnection $bankConnection,
    ) {}

    public function handle(PlaidService $plaidService, BankStatementParserService $parser): void
    {
        $connection = $this->bankConnection;
        $userId = $connection->user_id;

        Log::info('DownloadPlaidStatements started', [
            'connection_id' => $connection->id,
            'user_id' => $userId,
            'institution' => $connection->institution_name,
        ]);

        try {
            // List available statements
            $response = $plaidService->listStatements($connection);
            $accounts = $response['accounts'] ?? [];

            $totalImported = 0;
            $statementsProcessed = 0;

            foreach ($accounts as $account) {
                $plaidAccountId = $account['account_id'] ?? null;
                $statements = $account['statements'] ?? [];

                // Only process depository accounts
                $bankAccount = $plaidAccountId
                    ? BankAccount::where('plaid_account_id', $plaidAccountId)->first()
                    : null;

                foreach ($statements as $stmt) {
                    $statementId = $stmt['statement_id'] ?? null;
                    if (! $statementId) {
                        continue;
                    }

                    // Skip if already processed
                    $existing = PlaidStatement::where('plaid_statement_id', $statementId)->first();
                    if ($existing && in_array($existing->status, ['complete', 'downloading', 'parsing'])) {
                        continue;
                    }

                    // Create or update record
                    $record = PlaidStatement::updateOrCreate(
                        ['plaid_statement_id' => $statementId],
                        [
                            'user_id' => $userId,
                            'bank_connection_id' => $connection->id,
                            'bank_account_id' => $bankAccount?->id,
                            'plaid_account_id' => $plaidAccountId,
                            'month' => (int) ($stmt['month'] ?? now()->month),
                            'year' => (int) ($stmt['year'] ?? now()->year),
                            'status' => 'downloading',
                        ]
                    );

                    try {
                        // Download the PDF
                        $download = $plaidService->downloadStatement($connection, $statementId);

                        // Save to storage
                        $dir = "plaid-statements/{$userId}";
                        $fileName = "{$statementId}.pdf";
                        Storage::disk('local')->put("{$dir}/{$fileName}", $download['content']);
                        $filePath = Storage::disk('local')->path("{$dir}/{$fileName}");

                        $record->update([
                            'file_path' => "{$dir}/{$fileName}",
                            'content_hash' => $download['content_hash'],
                            'status' => 'parsing',
                        ]);

                        // Parse the PDF
                        $bankName = $connection->institution_name ?? 'Unknown';
                        $result = $parser->parsePdf($filePath, $bankName);
                        $transactions = $result['transactions'] ?? [];

                        if (empty($transactions)) {
                            $record->update([
                                'status' => 'complete',
                                'total_extracted' => 0,
                                'processing_notes' => ['No transactions extracted from statement'],
                            ]);
                            $statementsProcessed++;

                            continue;
                        }

                        // Detect duplicates
                        $dupeResult = $parser->detectDuplicates($transactions, $userId, $bankAccount?->id);
                        $transactions = $dupeResult['transactions'];
                        $duplicatesFound = $dupeResult['duplicates_found'];

                        // Import non-duplicate transactions
                        $nonDuplicates = array_filter($transactions, fn ($tx) => empty($tx['is_duplicate']));
                        $imported = 0;
                        $accountPurpose = $bankAccount?->purpose?->value ?? 'personal';

                        if (! empty($nonDuplicates)) {
                            DB::transaction(function () use ($nonDuplicates, $userId, $bankAccount, $accountPurpose, &$imported) {
                                foreach ($nonDuplicates as $tx) {
                                    try {
                                        $isIncome = (bool) ($tx['is_income'] ?? false);
                                        $amount = abs((float) $tx['amount']);
                                        $storedAmount = $isIncome ? -$amount : $amount;

                                        Transaction::create([
                                            'user_id' => $userId,
                                            'bank_account_id' => $bankAccount?->id,
                                            'merchant_name' => $tx['merchant_name'] ?? $tx['description'] ?? 'Unknown',
                                            'merchant_normalized' => $tx['merchant_name'] ?? $tx['description'] ?? 'Unknown',
                                            'description' => $tx['description'] ?? '',
                                            'amount' => $storedAmount,
                                            'transaction_date' => $tx['date'],
                                            'account_purpose' => $accountPurpose,
                                            'review_status' => 'pending_ai',
                                            'expense_type' => 'personal',
                                        ]);
                                        $imported++;
                                    } catch (\Throwable $e) {
                                        Log::warning('Failed to import Plaid statement transaction', [
                                            'error' => $e->getMessage(),
                                        ]);
                                    }
                                }
                            });
                        }

                        // Compute date range
                        $dates = array_filter(array_column($transactions, 'date'));

                        $record->update([
                            'status' => 'complete',
                            'total_extracted' => count($transactions),
                            'duplicates_found' => $duplicatesFound,
                            'transactions_imported' => $imported,
                            'date_range_from' => ! empty($dates) ? min($dates) : null,
                            'date_range_to' => ! empty($dates) ? max($dates) : null,
                            'processing_notes' => $dupeResult['notes'] ?? [],
                        ]);

                        $totalImported += $imported;
                        $statementsProcessed++;

                    } catch (\Throwable $e) {
                        Log::warning('Failed to process Plaid statement', [
                            'statement_id' => $statementId,
                            'error' => $e->getMessage(),
                        ]);

                        $record->update([
                            'status' => 'error',
                            'error_message' => $e->getMessage(),
                        ]);
                    }

                    // Rate limit between statements
                    usleep(500000);
                }
            }

            // Update connection status
            $connection->update([
                'statements_refresh_status' => 'ready',
                'statements_last_refreshed_at' => now(),
            ]);

            // Dispatch categorization if any transactions were imported
            if ($totalImported > 0) {
                CategorizePendingTransactions::dispatch($userId);
            }

            Log::info('DownloadPlaidStatements complete', [
                'connection_id' => $connection->id,
                'statements_processed' => $statementsProcessed,
                'total_imported' => $totalImported,
            ]);

        } catch (\Throwable $e) {
            Log::error('DownloadPlaidStatements failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            $connection->update([
                'statements_refresh_status' => 'failed',
            ]);

            throw $e;
        }
    }
}
