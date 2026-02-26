<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatementImportRequest;
use App\Http\Requests\StatementUploadRequest;
use App\Jobs\CategorizePendingTransactions;
use App\Jobs\ParseBankStatement;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\StatementUpload;
use App\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StatementUploadController extends Controller
{
    public function upload(StatementUploadRequest $request): JsonResponse
    {
        $user = $request->user();

        // Guard: max 24 concurrent processing uploads per user
        $activeUploads = StatementUpload::where('user_id', $user->id)
            ->whereIn('status', ['queued', 'parsing', 'extracting', 'analyzing'])
            ->count();

        if ($activeUploads >= 24) {
            return response()->json([
                'message' => 'Too many statements being processed. Please wait for current uploads to finish.',
            ], 429);
        }

        $file = $request->file('file');
        $bankAccountId = $request->validated('bank_account_id');

        // Resolve the target bank account
        if ($bankAccountId) {
            $bankAccount = BankAccount::where('id', $bankAccountId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $bankName = $bankAccount->bankConnection?->institution_name ?? 'Unknown';
            $accountType = match ($bankAccount->subtype) {
                'checking' => 'checking',
                'savings' => 'savings',
                'credit card' => 'credit',
                'brokerage' => 'investment',
                default => $bankAccount->type === 'credit' ? 'credit' : 'checking',
            };
        } else {
            $bankName = $request->validated('bank_name');
            $accountType = $request->validated('account_type');
            $nickname = $request->validated('nickname');
            $bankAccount = $this->findOrCreateManualAccount($user, $bankName, $accountType, $nickname);
        }

        // Store the file
        $fileName = Str::uuid().'.'.$file->getClientOriginalExtension();
        $filePath = $file->storeAs("statements/{$user->id}", $fileName, 'local');

        // Create upload record
        $upload = StatementUpload::create([
            'user_id' => $user->id,
            'bank_account_id' => $bankAccount->id,
            'file_name' => $fileName,
            'original_file_name' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'file_type' => strtolower($file->getClientOriginalExtension()),
            'bank_name' => $bankName,
            'account_type' => $accountType,
            'status' => 'queued',
        ]);

        ParseBankStatement::dispatch($upload);

        return response()->json([
            'upload_id' => $upload->id,
            'status' => 'queued',
            'message' => 'File uploaded. Processing has started.',
        ]);
    }

    public function status(Request $request, StatementUpload $upload): JsonResponse
    {
        if ($upload->user_id !== $request->user()->id) {
            abort(403);
        }

        $response = [
            'upload_id' => $upload->id,
            'status' => $upload->status,
            'file_name' => $upload->original_file_name,
            'error_message' => $upload->error_message,
            'processing_notes' => $upload->processing_notes ?? [],
        ];

        if ($upload->status === 'complete') {
            $transactions = Cache::get("statement_transactions:{$upload->id}", []);

            $response['total_extracted'] = $upload->total_extracted;
            $response['duplicates_found'] = $upload->duplicates_found;
            $response['transactions'] = $transactions;
            $response['date_range'] = [
                'from' => $upload->date_range_from?->format('Y-m-d'),
                'to' => $upload->date_range_to?->format('Y-m-d'),
            ];
        }

        return response()->json($response);
    }

    public function batchStatus(Request $request): JsonResponse
    {
        $request->validate([
            'upload_ids' => 'required|array|min:1|max:24',
            'upload_ids.*' => 'integer',
        ]);

        $userId = $request->user()->id;
        $uploads = StatementUpload::where('user_id', $userId)
            ->whereIn('id', $request->input('upload_ids'))
            ->get()
            ->keyBy('id');

        $results = [];
        $totalExtracted = 0;
        $totalDuplicates = 0;

        foreach ($request->input('upload_ids') as $id) {
            $upload = $uploads->get($id);
            if (! $upload) {
                $results[] = [
                    'upload_id' => $id,
                    'status' => 'error',
                    'file_name' => null,
                    'error_message' => 'Upload not found',
                ];

                continue;
            }

            $item = [
                'upload_id' => $upload->id,
                'status' => $upload->status,
                'file_name' => $upload->original_file_name,
                'error_message' => $upload->error_message,
            ];

            if ($upload->status === 'complete') {
                $item['total_extracted'] = $upload->total_extracted;
                $item['duplicates_found'] = $upload->duplicates_found;
                $item['date_range'] = [
                    'from' => $upload->date_range_from?->format('Y-m-d'),
                    'to' => $upload->date_range_to?->format('Y-m-d'),
                ];
                $totalExtracted += $upload->total_extracted;
                $totalDuplicates += $upload->duplicates_found;
            }

            $results[] = $item;
        }

        $completedCount = collect($results)->where('status', 'complete')->count();
        $errorCount = collect($results)->where('status', 'error')->count();
        $totalCount = count($results);

        return response()->json([
            'uploads' => $results,
            'summary' => [
                'total' => $totalCount,
                'completed' => $completedCount,
                'failed' => $errorCount,
                'processing' => $totalCount - $completedCount - $errorCount,
                'all_done' => ($completedCount + $errorCount) === $totalCount,
                'total_extracted' => $totalExtracted,
                'total_duplicates' => $totalDuplicates,
            ],
        ]);
    }

    public function batchTransactions(Request $request): JsonResponse
    {
        $request->validate([
            'upload_ids' => 'required|array|min:1|max:24',
            'upload_ids.*' => 'integer',
        ]);

        $userId = $request->user()->id;
        $uploads = StatementUpload::where('user_id', $userId)
            ->whereIn('id', $request->input('upload_ids'))
            ->where('status', 'complete')
            ->get();

        $allTransactions = [];
        $processingNotes = [];
        $dateFrom = null;
        $dateTo = null;

        foreach ($uploads as $upload) {
            $cached = Cache::get("statement_transactions:{$upload->id}", []);

            foreach ($cached as &$tx) {
                $tx['source_upload_id'] = $upload->id;
                $tx['source_file_name'] = $upload->original_file_name;
            }

            $allTransactions = array_merge($allTransactions, $cached);

            if ($upload->processing_notes) {
                foreach ($upload->processing_notes as $note) {
                    $processingNotes[] = "[{$upload->original_file_name}] {$note}";
                }
            }

            $from = $upload->date_range_from;
            $to = $upload->date_range_to;
            if ($from && (! $dateFrom || $from < $dateFrom)) {
                $dateFrom = $from;
            }
            if ($to && (! $dateTo || $to > $dateTo)) {
                $dateTo = $to;
            }
        }

        // Cross-file duplicate detection
        $crossFileResult = $this->detectCrossFileDuplicates($allTransactions);
        $allTransactions = $crossFileResult['transactions'];
        if ($crossFileResult['cross_file_duplicates'] > 0) {
            $processingNotes[] = "Found {$crossFileResult['cross_file_duplicates']} duplicate(s) across overlapping statement periods.";
        }

        // Sort by date ascending
        usort($allTransactions, fn ($a, $b) => strcmp($a['date'], $b['date']));

        // Re-index row_index globally
        foreach ($allTransactions as $i => &$tx) {
            $tx['row_index'] = $i;
        }

        $dbDuplicates = collect($allTransactions)->where('is_duplicate', true)->where('duplicate_reason', '!=', 'cross_file')->count();
        $crossFileDupes = $crossFileResult['cross_file_duplicates'];

        return response()->json([
            'transactions' => $allTransactions,
            'total_extracted' => count($allTransactions),
            'duplicates_found' => $dbDuplicates + $crossFileDupes,
            'db_duplicates' => $dbDuplicates,
            'cross_file_duplicates' => $crossFileDupes,
            'date_range' => [
                'from' => $dateFrom?->format('Y-m-d'),
                'to' => $dateTo?->format('Y-m-d'),
            ],
            'processing_notes' => $processingNotes,
            'files_included' => $uploads->count(),
        ]);
    }

    public function import(StatementImportRequest $request): JsonResponse
    {
        $user = $request->user();

        // Support both single upload_id and batch upload_ids
        $uploadIds = $request->validated('upload_ids') ?? [$request->validated('upload_id')];

        $uploads = StatementUpload::where('user_id', $user->id)
            ->whereIn('id', $uploadIds)
            ->get();

        if ($uploads->isEmpty()) {
            abort(403);
        }

        $bankAccountId = $uploads->first()->bank_account_id;
        $accountPurpose = $uploads->first()->bankAccount?->purpose?->value ?? 'personal';

        $incomingTransactions = $request->validated('transactions');
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        DB::transaction(function () use ($incomingTransactions, $bankAccountId, $accountPurpose, $user, $uploads, &$imported, &$errors) {
            foreach ($incomingTransactions as $tx) {
                try {
                    $isIncome = (bool) $tx['is_income'];
                    $amount = abs((float) $tx['amount']);
                    $storedAmount = $isIncome ? -$amount : $amount;

                    Transaction::create([
                        'user_id' => $user->id,
                        'bank_account_id' => $bankAccountId,
                        'merchant_name' => $tx['merchant_name'],
                        'merchant_normalized' => $tx['merchant_name'],
                        'description' => $tx['description'],
                        'amount' => $storedAmount,
                        'transaction_date' => $tx['date'],
                        'account_purpose' => $accountPurpose,
                        'review_status' => 'pending_ai',
                        'expense_type' => 'personal',
                    ]);

                    $imported++;
                } catch (\Throwable $e) {
                    $errors++;
                }
            }

            foreach ($uploads as $upload) {
                $upload->update(['transactions_imported' => $imported]);
            }
        });

        if ($imported > 0) {
            CategorizePendingTransactions::dispatch($user->id);
        }

        // Clean up all cached transactions
        foreach ($uploadIds as $id) {
            Cache::forget("statement_transactions:{$id}");
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

    private function detectCrossFileDuplicates(array $transactions): array
    {
        $crossFileDupes = 0;
        $seen = [];

        foreach ($transactions as &$tx) {
            if ($tx['is_duplicate'] ?? false) {
                continue;
            }

            $fingerprint = $tx['date'].'|'.
                number_format(abs((float) $tx['amount']), 2, '.', '').'|'.
                strtolower(trim($tx['merchant_name'] ?? ''));

            if (isset($seen[$fingerprint])) {
                if (($seen[$fingerprint]['source_upload_id'] ?? null) !== ($tx['source_upload_id'] ?? null)) {
                    $tx['is_duplicate'] = true;
                    $tx['duplicate_reason'] = 'cross_file';
                    $crossFileDupes++;
                }
            } else {
                $seen[$fingerprint] = $tx;
            }
        }

        return [
            'transactions' => $transactions,
            'cross_file_duplicates' => $crossFileDupes,
        ];
    }

    /**
     * Detect gaps in transaction coverage.
     *
     * Analyzes the user's full transaction timeline to find months
     * with missing or suspiciously low transaction counts relative
     * to their overall history. Works across all data sources
     * (Plaid, statement uploads, etc.).
     */
    public function gaps(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get the overall transaction date span
        $dateSpan = Transaction::where('user_id', $user->id)
            ->select(
                DB::raw('MIN(transaction_date) as earliest'),
                DB::raw('MAX(transaction_date) as latest')
            )
            ->first();

        if (! $dateSpan->earliest || ! $dateSpan->latest) {
            return response()->json(['gaps' => [], 'coverage' => []]);
        }

        $earliest = Carbon::parse($dateSpan->earliest);
        $latest = Carbon::parse($dateSpan->latest);
        $startMonth = $earliest->copy()->startOfMonth();
        $endMonth = $latest->copy()->startOfMonth();

        // Need at least 2 months of data to detect gaps
        if ($startMonth->equalTo($endMonth)) {
            return response()->json(['gaps' => [], 'coverage' => []]);
        }

        // Get transaction counts per month (across all accounts)
        $monthlyCounts = Transaction::where('user_id', $user->id)
            ->select(
                DB::raw("to_char(transaction_date, 'YYYY-MM') as month"),
                DB::raw('COUNT(*) as count'),
                DB::raw('MIN(transaction_date) as first_date'),
                DB::raw('MAX(transaction_date) as last_date')
            )
            ->groupBy(DB::raw("to_char(transaction_date, 'YYYY-MM')"))
            ->get()
            ->keyBy('month');

        // Get dismissed gaps
        $dismissed = DB::table('dismissed_statement_gaps')
            ->where('user_id', $user->id)
            ->pluck('gap_key')
            ->flip();

        // Get statement upload coverage for context
        $uploads = StatementUpload::where('user_id', $user->id)
            ->where('status', 'complete')
            ->whereNotNull('date_range_from')
            ->whereNotNull('date_range_to')
            ->orderBy('date_range_from')
            ->get();

        $statementMonths = [];
        foreach ($uploads as $upload) {
            $from = Carbon::parse($upload->date_range_from)->startOfMonth();
            $to = Carbon::parse($upload->date_range_to)->startOfMonth();
            $period = CarbonPeriod::create($from, '1 month', $to);
            foreach ($period as $m) {
                $statementMonths[$m->format('Y-m')] = true;
            }
        }

        // Calculate average transaction count for months that have data
        $period = CarbonPeriod::create($startMonth, '1 month', $endMonth);
        $totalWithData = 0;
        $monthsWithData = 0;

        foreach ($period as $m) {
            $key = $m->format('Y-m');
            $count = (int) ($monthlyCounts[$key]->count ?? 0);
            if ($count > 0) {
                $totalWithData += $count;
                $monthsWithData++;
            }
        }

        $avgCount = $monthsWithData > 0 ? $totalWithData / $monthsWithData : 0;

        // Threshold: flag months with less than 25% of average (min 5)
        $lowThreshold = max(5, (int) ($avgCount * 0.25));

        $gaps = [];
        $coverageMonths = [];

        foreach ($period as $m) {
            $key = $m->format('Y-m');
            $count = (int) ($monthlyCounts[$key]->count ?? 0);
            $hasStatement = isset($statementMonths[$key]);
            $gapKey = "all:{$key}";
            $isDismissed = isset($dismissed[$gapKey]);

            $firstDate = $monthlyCounts[$key]->first_date ?? null;
            $lastDate = $monthlyCounts[$key]->last_date ?? null;

            $coverageMonths[] = [
                'month' => $key,
                'transaction_count' => $count,
                'has_statement' => $hasStatement,
                'first_date' => $firstDate,
                'last_date' => $lastDate,
            ];

            // Detect gaps
            $isGap = false;
            $reason = '';
            $severity = 'warning'; // warning or critical

            if ($count === 0) {
                $isGap = true;
                $severity = 'critical';
                $reason = 'No transactions found for this month';
            } elseif ($count < $lowThreshold && $avgCount > 15) {
                // Partial month: check if transactions only cover part of the month
                $isGap = true;
                $severity = 'warning';
                $reason = "Only {$count} transactions found (your monthly average is ".round($avgCount).')';
            }

            if ($isGap && ! $isDismissed) {
                $gaps[] = [
                    'gap_key' => $gapKey,
                    'month' => $key,
                    'month_label' => Carbon::parse($key.'-01')->format('F Y'),
                    'transaction_count' => $count,
                    'average_count' => round($avgCount),
                    'severity' => $severity,
                    'reason' => $reason,
                    'has_statement' => $hasStatement,
                ];
            }
        }

        // Sort gaps by month descending (most recent first)
        usort($gaps, fn ($a, $b) => strcmp($b['month'], $a['month']));

        return response()->json([
            'gaps' => $gaps,
            'coverage' => [
                'date_range' => [
                    'from' => $earliest->format('Y-m-d'),
                    'to' => $latest->format('Y-m-d'),
                ],
                'total_months' => iterator_count(CarbonPeriod::create($startMonth, '1 month', $endMonth)),
                'average_monthly_transactions' => round($avgCount),
                'months' => $coverageMonths,
            ],
        ]);
    }

    /**
     * Dismiss a statement gap alert.
     */
    public function dismissGap(Request $request): JsonResponse
    {
        $request->validate([
            'gap_key' => 'required|string|max:50',
        ]);

        DB::table('dismissed_statement_gaps')->updateOrInsert(
            [
                'user_id' => $request->user()->id,
                'gap_key' => $request->input('gap_key'),
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json(['message' => 'Gap dismissed']);
    }

    private function findOrCreateManualAccount(mixed $user, string $bankName, string $accountType, ?string $nickname): BankAccount
    {
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

        [$type, $subtype] = match ($accountType) {
            'checking' => ['depository', 'checking'],
            'savings' => ['depository', 'savings'],
            'credit' => ['credit', 'credit card'],
            'investment' => ['investment', 'brokerage'],
            default => ['depository', 'checking'],
        };

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
