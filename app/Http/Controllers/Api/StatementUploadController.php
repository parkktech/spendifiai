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

        return response()->json(['uploads' => $uploads]);
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

        // Step 1: Identify accounts with statement uploads (gap detection applies to uploaded data)
        $accountIdsWithUploads = StatementUpload::where('user_id', $user->id)
            ->where('status', 'complete')
            ->whereNotNull('bank_account_id')
            ->distinct()
            ->pluck('bank_account_id');

        $accounts = BankAccount::where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('id', $accountIdsWithUploads)
            ->with('bankConnection:id,institution_name')
            ->get();

        $emptyResponse = ['gaps' => [], 'overlaps' => [], 'coverage' => ['accounts' => []]];

        if ($accounts->isEmpty()) {
            return response()->json($emptyResponse);
        }

        $accountIds = $accounts->pluck('id')->toArray();

        // Step 2: Bulk-fetch data (2 queries — no N+1)
        $txCounts = Transaction::where('user_id', $user->id)
            ->whereIn('bank_account_id', $accountIds)
            ->select(
                'bank_account_id',
                DB::raw("to_char(transaction_date, 'YYYY-MM') as month"),
                DB::raw('COUNT(*) as count'),
                DB::raw('MIN(transaction_date) as first_date'),
                DB::raw('MAX(transaction_date) as last_date')
            )
            ->groupBy('bank_account_id', DB::raw("to_char(transaction_date, 'YYYY-MM')"))
            ->get();

        $txByAccount = [];
        foreach ($txCounts as $row) {
            $txByAccount[$row->bank_account_id][$row->month] = $row;
        }

        $uploadsByAccount = StatementUpload::where('user_id', $user->id)
            ->whereIn('bank_account_id', $accountIds)
            ->where('status', 'complete')
            ->whereNotNull('date_range_from')
            ->whereNotNull('date_range_to')
            ->orderBy('date_range_from')
            ->get()
            ->groupBy('bank_account_id');

        $dismissed = DB::table('dismissed_statement_gaps')
            ->where('user_id', $user->id)
            ->pluck('gap_key')
            ->flip();

        $allGaps = [];
        $allOverlaps = [];
        $coverageAccounts = [];

        // Step 3: Per-account processing (all in-memory)
        foreach ($accounts as $account) {
            $accountId = $account->id;
            $accountTx = $txByAccount[$accountId] ?? [];
            $accountUploads = $uploadsByAccount->get($accountId, collect());
            $accountName = ($account->nickname ?? $account->name).($account->mask ? " ****{$account->mask}" : '');
            $institutionName = $account->bankConnection?->institution_name;

            // Date span bounded by statement uploads — only detect gaps within uploaded range
            $uploadDates = collect();
            foreach ($accountUploads as $u) {
                $uploadDates->push(Carbon::parse($u->date_range_from));
                $uploadDates->push(Carbon::parse($u->date_range_to));
            }

            if ($uploadDates->isEmpty()) {
                continue;
            }

            $earliest = $uploadDates->min();
            $latest = $uploadDates->max();
            $startMonth = $earliest->copy()->startOfMonth();
            $endMonth = $latest->copy()->startOfMonth();

            // Need at least 2 months per account
            if ($startMonth->equalTo($endMonth)) {
                continue;
            }

            // Build raw statement intervals for this account
            $hasUploads = $accountUploads->isNotEmpty();
            $rawIntervals = [];
            foreach ($accountUploads as $u) {
                $rawIntervals[] = [
                    'from' => Carbon::parse($u->date_range_from),
                    'to' => Carbon::parse($u->date_range_to),
                    'upload_id' => $u->id,
                    'file_name' => $u->original_file_name,
                ];
            }

            // Detect overlaps between raw intervals (before merging)
            for ($i = 0; $i < count($rawIntervals); $i++) {
                for ($j = $i + 1; $j < count($rawIntervals); $j++) {
                    $a = $rawIntervals[$i];
                    $b = $rawIntervals[$j];
                    if ($a['from']->lt($b['to']) && $b['from']->lt($a['to'])) {
                        $overlapFrom = $a['from']->max($b['from']);
                        $overlapTo = $a['to']->min($b['to']);
                        $allOverlaps[] = [
                            'account_id' => $accountId,
                            'account_name' => $accountName,
                            'overlap_range' => [
                                'from' => $overlapFrom->format('Y-m-d'),
                                'to' => $overlapTo->format('Y-m-d'),
                            ],
                            'statements' => [
                                ['id' => $a['upload_id'], 'file_name' => $a['file_name']],
                                ['id' => $b['upload_id'], 'file_name' => $b['file_name']],
                            ],
                            'severity' => 'info',
                        ];
                    }
                }
            }

            // Merge intervals for coverage calculation
            $mergedIntervals = $this->mergeIntervals($rawIntervals);

            // Per-account average transaction count
            $totalTx = array_sum(array_map(fn ($m) => (int) $m->count, $accountTx));
            $monthsWithData = count(array_filter($accountTx, fn ($m) => (int) $m->count > 0));
            $avgCount = $monthsWithData > 0 ? $totalTx / $monthsWithData : 0;
            $lowThreshold = max(5, (int) ($avgCount * 0.25));

            // Iterate each month in the account's span
            $period = CarbonPeriod::create($startMonth, '1 month', $endMonth);
            $coverageMonths = [];
            $accountGapCount = 0;

            foreach ($period as $m) {
                $monthKey = $m->format('Y-m');
                $monthStart = $m->copy()->startOfMonth();
                $monthEnd = $m->copy()->endOfMonth();
                $count = (int) ($accountTx[$monthKey]->count ?? 0);
                $firstDate = $accountTx[$monthKey]->first_date ?? null;
                $lastDate = $accountTx[$monthKey]->last_date ?? null;

                // Compute coverage ranges within this month
                $monthCoverage = $this->getMonthCoverage($monthStart, $monthEnd, $mergedIntervals);
                $uncovered = $this->findUncoveredRanges($monthStart, $monthEnd, $monthCoverage);
                $hasStatement = ! empty($monthCoverage);

                $coverageMonths[] = [
                    'month' => $monthKey,
                    'transaction_count' => $count,
                    'has_statement' => $hasStatement,
                    'coverage_ranges' => array_map(fn ($r) => [
                        'from' => $r['from']->format('Y-m-d'),
                        'to' => $r['to']->format('Y-m-d'),
                    ], $monthCoverage),
                    'first_date' => $firstDate,
                    'last_date' => $lastDate,
                ];

                // Check dismissed status (new key + legacy "all:" key)
                $gapKey = "{$accountId}:{$monthKey}";
                $legacyKey = "all:{$monthKey}";
                $isDismissed = isset($dismissed[$gapKey]) || isset($dismissed[$legacyKey]);

                if ($isDismissed) {
                    continue;
                }

                // Is this month inside the upload coverage window (not an edge)?
                $isInsideCoverage = $monthStart->gte($earliest->copy()->startOfMonth())
                    && $monthEnd->lte($latest->copy()->endOfMonth());

                // Gap detection logic
                if ($count === 0 && $isInsideCoverage) {
                    // Critical: no transactions for a month within upload range
                    $allGaps[] = [
                        'gap_key' => $gapKey,
                        'account_id' => $accountId,
                        'account_name' => $accountName,
                        'month' => $monthKey,
                        'month_label' => Carbon::parse("{$monthKey}-01")->format('F Y'),
                        'date_range' => null,
                        'transaction_count' => 0,
                        'average_count' => round($avgCount),
                        'severity' => 'critical',
                        'reason' => 'No transactions found for this month',
                        'has_statement' => $hasStatement,
                        'gap_type' => 'full_month',
                    ];
                    $accountGapCount++;
                } elseif ($hasUploads && ! empty($uncovered)) {
                    // Partial month: interior gaps between uploaded statements
                    foreach ($uncovered as $gap) {
                        // Only flag gaps that fall within the upload range (not edges)
                        if ($gap['from']->lt($earliest) || $gap['to']->gt($latest)) {
                            continue;
                        }
                        $gapDays = $gap['from']->diffInDays($gap['to']) + 1;
                        if ($gapDays > 7) {
                            $rangeKey = "{$accountId}:{$gap['from']->format('Y-m-d')}:{$gap['to']->format('Y-m-d')}";
                            if (! isset($dismissed[$rangeKey])) {
                                $allGaps[] = [
                                    'gap_key' => $rangeKey,
                                    'account_id' => $accountId,
                                    'account_name' => $accountName,
                                    'month' => $monthKey,
                                    'month_label' => Carbon::parse("{$monthKey}-01")->format('F Y'),
                                    'date_range' => [
                                        'from' => $gap['from']->format('Y-m-d'),
                                        'to' => $gap['to']->format('Y-m-d'),
                                    ],
                                    'transaction_count' => $count,
                                    'average_count' => round($avgCount),
                                    'severity' => 'warning',
                                    'reason' => 'Missing coverage for '.$gap['from']->format('M j').' – '.$gap['to']->format('M j'),
                                    'has_statement' => $hasStatement,
                                    'gap_type' => 'partial_month',
                                ];
                                $accountGapCount++;
                            }
                        }
                    }
                } elseif ($count < $lowThreshold && $avgCount > 15) {
                    // Low activity warning
                    $allGaps[] = [
                        'gap_key' => $gapKey,
                        'account_id' => $accountId,
                        'account_name' => $accountName,
                        'month' => $monthKey,
                        'month_label' => Carbon::parse("{$monthKey}-01")->format('F Y'),
                        'date_range' => null,
                        'transaction_count' => $count,
                        'average_count' => round($avgCount),
                        'severity' => 'warning',
                        'reason' => "Only {$count} transactions found (account average is ".round($avgCount).')',
                        'has_statement' => $hasStatement,
                        'gap_type' => 'low_activity',
                    ];
                    $accountGapCount++;
                }
            }

            $coverageAccounts[] = [
                'account_id' => $accountId,
                'account_name' => $accountName,
                'institution_name' => $institutionName,
                'date_range' => [
                    'from' => $earliest->format('Y-m-d'),
                    'to' => $latest->format('Y-m-d'),
                ],
                'total_months' => iterator_count(CarbonPeriod::create($startMonth, '1 month', $endMonth)),
                'average_monthly_transactions' => round($avgCount),
                'gap_count' => $accountGapCount,
                'months' => $coverageMonths,
            ];
        }

        // Sort gaps: critical first, then by most recent month
        usort($allGaps, function ($a, $b) {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] === 'critical' ? -1 : 1;
            }

            return strcmp($b['month'], $a['month']);
        });

        return response()->json([
            'gaps' => $allGaps,
            'overlaps' => $allOverlaps,
            'coverage' => [
                'accounts' => $coverageAccounts,
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

    /**
     * Merge overlapping/adjacent date intervals into non-overlapping ranges.
     */
    private function mergeIntervals(array $intervals): array
    {
        if (empty($intervals)) {
            return [];
        }

        usort($intervals, fn ($a, $b) => $a['from']->lt($b['from']) ? -1 : ($a['from']->gt($b['from']) ? 1 : 0));

        $merged = [['from' => $intervals[0]['from']->copy(), 'to' => $intervals[0]['to']->copy()]];

        for ($i = 1; $i < count($intervals); $i++) {
            $last = &$merged[count($merged) - 1];
            if ($intervals[$i]['from']->lte($last['to']->copy()->addDay())) {
                if ($intervals[$i]['to']->gt($last['to'])) {
                    $last['to'] = $intervals[$i]['to']->copy();
                }
            } else {
                $merged[] = ['from' => $intervals[$i]['from']->copy(), 'to' => $intervals[$i]['to']->copy()];
            }
        }

        return $merged;
    }

    /**
     * Get the parts of a month that are covered by the given merged intervals.
     */
    private function getMonthCoverage(Carbon $monthStart, Carbon $monthEnd, array $mergedIntervals): array
    {
        $coverage = [];
        foreach ($mergedIntervals as $interval) {
            if ($interval['from']->gt($monthEnd) || $interval['to']->lt($monthStart)) {
                continue;
            }
            $coverage[] = [
                'from' => $interval['from']->max($monthStart)->copy(),
                'to' => $interval['to']->min($monthEnd)->copy(),
            ];
        }

        return $coverage;
    }

    /**
     * Find date ranges within a month NOT covered by any interval.
     */
    private function findUncoveredRanges(Carbon $monthStart, Carbon $monthEnd, array $coverageRanges): array
    {
        if (empty($coverageRanges)) {
            return [['from' => $monthStart->copy(), 'to' => $monthEnd->copy()]];
        }

        usort($coverageRanges, fn ($a, $b) => $a['from']->lt($b['from']) ? -1 : ($a['from']->gt($b['from']) ? 1 : 0));

        $uncovered = [];
        $cursor = $monthStart->copy();

        foreach ($coverageRanges as $range) {
            if ($range['from']->gt($cursor)) {
                $uncovered[] = [
                    'from' => $cursor->copy(),
                    'to' => $range['from']->copy()->subDay(),
                ];
            }
            if ($range['to']->gte($cursor)) {
                $cursor = $range['to']->copy()->addDay();
            }
        }

        if ($cursor->lte($monthEnd)) {
            $uncovered[] = [
                'from' => $cursor->copy(),
                'to' => $monthEnd->copy(),
            ];
        }

        return $uncovered;
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
