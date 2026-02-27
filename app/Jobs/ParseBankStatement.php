<?php

namespace App\Jobs;

use App\Models\EmailConnection;
use App\Models\StatementUpload;
use App\Models\Transaction;
use App\Services\BankStatementParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ParseBankStatement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        protected StatementUpload $upload,
    ) {}

    public function handle(BankStatementParserService $parser): void
    {
        $upload = $this->upload;

        Log::info('ParseBankStatement job started', [
            'upload_id' => $upload->id,
            'file_type' => $upload->file_type,
            'bank_name' => $upload->bank_name,
        ]);

        try {
            // Step 1: Parse the file
            $upload->update(['status' => 'extracting']);

            $filePath = Storage::disk('local')->path($upload->file_path);

            $result = match ($upload->file_type) {
                'pdf' => $parser->parsePdf($filePath, $upload->bank_name),
                'csv', 'txt' => $parser->parseCsv($filePath, $upload->bank_name),
                default => throw new \RuntimeException("Unsupported file type: {$upload->file_type}"),
            };

            $transactions = $result['transactions'] ?? [];
            $notes = $result['processing_notes'] ?? [];

            if (empty($transactions)) {
                $upload->update([
                    'status' => 'error',
                    'error_message' => 'No transactions could be extracted from this file.',
                    'processing_notes' => $notes,
                ]);

                return;
            }

            // Step 2: Detect duplicates
            $upload->update(['status' => 'analyzing']);
            $dupeResult = $parser->detectDuplicates($transactions, $upload->user_id, $upload->bank_account_id);
            $transactions = $dupeResult['transactions'];
            $duplicatesFound = $dupeResult['duplicates_found'];
            $notes = array_merge($notes, $dupeResult['notes']);

            // Compute date range
            $dates = array_filter(array_column($transactions, 'date'));
            $dateFrom = ! empty($dates) ? min($dates) : null;
            $dateTo = ! empty($dates) ? max($dates) : null;

            // Store parsed transactions in cache (for status endpoint)
            Cache::put(
                "statement_transactions:{$upload->id}",
                $transactions,
                now()->addHours(2)
            );

            // Step 3: Auto-import non-duplicate transactions
            $nonDuplicates = array_filter($transactions, fn ($tx) => empty($tx['is_duplicate']));
            $imported = 0;
            $errors = 0;
            $accountPurpose = $upload->bankAccount?->purpose?->value ?? 'personal';

            if (! empty($nonDuplicates)) {
                DB::transaction(function () use ($nonDuplicates, $upload, $accountPurpose, &$imported, &$errors) {
                    foreach ($nonDuplicates as $tx) {
                        try {
                            $isIncome = (bool) ($tx['is_income'] ?? false);
                            $amount = abs((float) $tx['amount']);
                            $storedAmount = $isIncome ? -$amount : $amount;

                            Transaction::create([
                                'user_id' => $upload->user_id,
                                'bank_account_id' => $upload->bank_account_id,
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
                            $errors++;
                            Log::warning('Failed to import transaction during auto-import', [
                                'upload_id' => $upload->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
            }

            // Update upload record
            $upload->update([
                'status' => 'complete',
                'total_extracted' => count($transactions),
                'duplicates_found' => $duplicatesFound,
                'transactions_imported' => $imported,
                'date_range_from' => $dateFrom,
                'date_range_to' => $dateTo,
                'processing_notes' => $notes,
            ]);

            Log::info('ParseBankStatement job complete', [
                'upload_id' => $upload->id,
                'total_extracted' => count($transactions),
                'duplicates_found' => $duplicatesFound,
                'imported' => $imported,
                'errors' => $errors,
            ]);

            // Dispatch follow-up jobs
            if ($imported > 0) {
                CategorizePendingTransactions::dispatch($upload->user_id);

                // Auto-query emails for receipt matching
                if ($dateFrom) {
                    $emailConnections = EmailConnection::where('user_id', $upload->user_id)
                        ->where('status', 'active')
                        ->where('sync_status', '!=', 'syncing')
                        ->get();

                    foreach ($emailConnections as $emailConn) {
                        ProcessOrderEmails::dispatch($emailConn, $dateFrom)
                            ->delay(now()->addSeconds(10));
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('ParseBankStatement job failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);

            $upload->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
