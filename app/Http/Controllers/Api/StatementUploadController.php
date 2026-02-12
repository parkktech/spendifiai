<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatementImportRequest;
use App\Http\Requests\StatementUploadRequest;
use App\Jobs\CategorizePendingTransactions;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\StatementUpload;
use App\Models\Transaction;
use App\Services\BankStatementParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StatementUploadController extends Controller
{
    public function __construct(
        private readonly BankStatementParserService $parser,
    ) {}

    public function upload(StatementUploadRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');
        $bankName = $request->validated('bank_name');
        $accountType = $request->validated('account_type');
        $nickname = $request->validated('nickname');

        // Store the file
        $fileName = Str::uuid().'.'.$file->getClientOriginalExtension();
        $filePath = $file->storeAs("statements/{$user->id}", $fileName, 'local');

        // Create upload record
        $upload = StatementUpload::create([
            'user_id' => $user->id,
            'file_name' => $fileName,
            'original_file_name' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'file_type' => strtolower($file->getClientOriginalExtension()),
            'bank_name' => $bankName,
            'account_type' => $accountType,
            'status' => 'parsing',
        ]);

        // Find or create a manual BankConnection + BankAccount for this bank
        $bankAccount = $this->findOrCreateManualAccount($user, $bankName, $accountType, $nickname);
        $upload->update(['bank_account_id' => $bankAccount->id]);

        try {
            // Parse the file
            $upload->update(['status' => 'extracting']);
            $result = $this->parser->parseFile($file, $bankName, $accountType);

            $transactions = $result['transactions'] ?? [];
            $notes = $result['processing_notes'] ?? [];

            if (empty($transactions)) {
                $upload->update([
                    'status' => 'error',
                    'error_message' => 'No transactions could be extracted from this file.',
                    'processing_notes' => $notes,
                ]);

                return response()->json([
                    'message' => 'No transactions found in the uploaded file.',
                    'processing_notes' => $notes,
                ], 422);
            }

            // Detect duplicates
            $upload->update(['status' => 'analyzing']);
            $dupeResult = $this->parser->detectDuplicates($transactions, $user->id, $bankAccount->id);
            $transactions = $dupeResult['transactions'];
            $duplicatesFound = $dupeResult['duplicates_found'];
            $notes = array_merge($notes, $dupeResult['notes']);

            // Compute date range
            $dates = array_filter(array_column($transactions, 'date'));
            $dateFrom = ! empty($dates) ? min($dates) : null;
            $dateTo = ! empty($dates) ? max($dates) : null;

            // Update upload record
            $upload->update([
                'status' => 'complete',
                'total_extracted' => count($transactions),
                'duplicates_found' => $duplicatesFound,
                'date_range_from' => $dateFrom,
                'date_range_to' => $dateTo,
                'processing_notes' => $notes,
            ]);

            return response()->json([
                'upload_id' => $upload->id,
                'file_name' => $upload->original_file_name,
                'total_extracted' => count($transactions),
                'duplicates_found' => $duplicatesFound,
                'transactions' => $transactions,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo,
                ],
                'processing_notes' => $notes,
            ]);
        } catch (\Throwable $e) {
            $upload->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to process statement: '.$e->getMessage(),
            ], 422);
        }
    }

    public function import(StatementImportRequest $request): JsonResponse
    {
        $user = $request->user();
        $upload = StatementUpload::where('id', $request->validated('upload_id'))
            ->where('user_id', $user->id)
            ->firstOrFail();

        $incomingTransactions = $request->validated('transactions');
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        DB::transaction(function () use ($incomingTransactions, $upload, $user, &$imported, &$skipped, &$errors) {
            foreach ($incomingTransactions as $tx) {
                try {
                    $isIncome = (bool) $tx['is_income'];
                    $amount = abs((float) $tx['amount']);

                    // Plaid convention: positive = spending, negative = income
                    $storedAmount = $isIncome ? -$amount : $amount;

                    Transaction::create([
                        'user_id' => $user->id,
                        'bank_account_id' => $upload->bank_account_id,
                        'merchant_name' => $tx['merchant_name'],
                        'merchant_normalized' => $tx['merchant_name'],
                        'description' => $tx['description'],
                        'amount' => $storedAmount,
                        'transaction_date' => $tx['date'],
                        'account_purpose' => $upload->bankAccount?->purpose?->value ?? 'personal',
                        'review_status' => 'pending_ai',
                        'expense_type' => 'personal',
                    ]);

                    $imported++;
                } catch (\Throwable $e) {
                    $errors++;
                }
            }

            $upload->update([
                'transactions_imported' => $imported,
            ]);
        });

        // Dispatch AI categorization for the newly imported transactions
        if ($imported > 0) {
            CategorizePendingTransactions::dispatch($user->id);
        }

        return response()->json([
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => "{$imported} transactions imported successfully.",
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $uploads = StatementUpload::where('user_id', $request->user()->id)
            ->where('status', 'complete')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StatementUpload $upload) => [
                'id' => $upload->id,
                'file_name' => $upload->original_file_name,
                'bank_name' => $upload->bank_name,
                'account_type' => $upload->account_type,
                'transactions_imported' => $upload->transactions_imported,
                'duplicates_skipped' => $upload->duplicates_found,
                'uploaded_at' => $upload->created_at->toISOString(),
                'date_range' => [
                    'from' => $upload->date_range_from?->format('Y-m-d'),
                    'to' => $upload->date_range_to?->format('Y-m-d'),
                ],
            ]);

        return response()->json($uploads);
    }

    private function findOrCreateManualAccount(mixed $user, string $bankName, string $accountType, ?string $nickname): BankAccount
    {
        // Find existing manual connection for this bank
        $connection = BankConnection::where('user_id', $user->id)
            ->where('institution_name', $bankName)
            ->whereNull('plaid_item_id')
            ->first();

        if (! $connection) {
            $connection = BankConnection::create([
                'user_id' => $user->id,
                'institution_name' => $bankName,
                'status' => 'active',
            ]);
        }

        // Map account_type to Plaid-compatible type/subtype
        [$type, $subtype] = match ($accountType) {
            'checking' => ['depository', 'checking'],
            'savings' => ['depository', 'savings'],
            'credit' => ['credit', 'credit card'],
            'investment' => ['investment', 'brokerage'],
            default => ['depository', 'checking'],
        };

        // Find existing account or create new one
        $account = BankAccount::where('user_id', $user->id)
            ->where('bank_connection_id', $connection->id)
            ->where('type', $type)
            ->where('subtype', $subtype)
            ->first();

        if (! $account) {
            $account = BankAccount::create([
                'user_id' => $user->id,
                'bank_connection_id' => $connection->id,
                'name' => $nickname ?: "{$bankName} {$accountType}",
                'type' => $type,
                'subtype' => $subtype,
                'nickname' => $nickname,
                'is_active' => true,
            ]);
        }

        return $account;
    }
}
