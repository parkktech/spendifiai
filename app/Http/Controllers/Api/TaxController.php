<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportTaxRequest;
use App\Http\Requests\SendToAccountantRequest;
use App\Models\OrderItem;
use App\Models\TaxDeduction;
use App\Models\Transaction;
use App\Models\UserFinancialProfile;
use App\Services\TaxCategoryNormalizerService;
use App\Services\TaxDeductionFinderService;
use App\Services\TaxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaxController extends Controller
{
    public function __construct(
        private readonly TaxExportService $exporter,
        private readonly TaxCategoryNormalizerService $normalizer,
        private readonly TaxDeductionFinderService $deductionFinder,
    ) {}

    /**
     * Tax summary: deductible categories by transaction and order item.
     */
    public function summary(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $user = auth()->user();
        $userIds = $user->householdUserIds();

        // Deductible transactions by category (toBase avoids model category accessor)
        $categories = Transaction::whereIn('user_id', $userIds)
            ->where('tax_deductible', true)
            ->whereYear('transaction_date', $year)
            ->toBase()
            ->select(
                DB::raw("COALESCE(tax_category, user_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as item_count')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => (object) [
                'category' => $row->category,
                'total' => (float) $row->total,
                'item_count' => (int) $row->item_count,
            ]);

        // Deductible order items (from email parsing)
        $orderItems = OrderItem::whereIn('user_id', $userIds)
            ->where('tax_deductible', true)
            ->whereHas('order', fn ($q) => $q->whereYear('order_date', $year))
            ->select(
                DB::raw("COALESCE(tax_category, ai_category, 'Uncategorized') as category"),
                DB::raw('SUM(total_price) as total'),
                DB::raw('COUNT(*) as item_count')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => (object) [
                'category' => $row->category,
                'total' => (float) $row->total,
                'item_count' => (int) $row->item_count,
            ]);

        // Individual deductible transactions for drill-down
        $transactionDetails = Transaction::whereIn('user_id', $userIds)
            ->where('tax_deductible', true)
            ->whereYear('transaction_date', $year)
            ->orderBy('transaction_date', 'desc')
            ->get()
            ->map(fn (Transaction $tx) => [
                'date' => $tx->transaction_date->format('Y-m-d'),
                'merchant' => $tx->merchant_normalized ?? $tx->merchant_name,
                'description' => $tx->description,
                'amount' => (float) $tx->amount,
                'category' => $tx->tax_category ?? $tx->user_category ?? $tx->ai_category ?? 'Uncategorized',
                'source' => 'bank',
                'donation_note' => $tx->donation_note,
            ]);

        // Individual deductible order items for drill-down
        $orderItemDetails = OrderItem::whereIn('user_id', $userIds)
            ->where('tax_deductible', true)
            ->whereHas('order', fn ($q) => $q->whereYear('order_date', $year))
            ->with('order:id,merchant,order_number,order_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrderItem $item) => [
                'date' => $item->order->order_date->format('Y-m-d'),
                'merchant' => $item->order->merchant,
                'description' => $item->product_name,
                'amount' => (float) $item->total_price,
                'category' => $item->tax_category ?? $item->ai_category ?? 'Uncategorized',
                'source' => 'email',
                'order_number' => $item->order->order_number,
            ]);

        $totalDeductible = $categories->sum('total') + $orderItems->sum('total');
        $profile = UserFinancialProfile::where('user_id', $user->id)->first();
        $estRate = ($profile?->estimated_tax_bracket ?? 22) / 100;

        // Build dynamic schedule map from actual category data
        $allCats = $categories->merge($orderItems);
        $catsForNormalizer = $allCats->map(fn ($c) => [
            'category' => $c->category,
            'tax_category' => $c->category,
            'total' => $c->total,
            'item_count' => $c->item_count,
        ])->values()->toArray();

        $scheduleCMap = $this->normalizer->buildScheduleCMap($catsForNormalizer);
        $normalizedLines = $this->normalizer->mapCategoriesToLines($catsForNormalizer);

        $scheduleCTotal = collect($normalizedLines)->where('schedule', 'C')->sum('total');
        $scheduleATotal = collect($normalizedLines)->where('schedule', 'A')->sum('total');

        return response()->json([
            'year' => $year,
            'total_deductible' => round($totalDeductible, 2),
            'estimated_tax_savings' => round($totalDeductible * $estRate, 2),
            'effective_rate_used' => $estRate,
            'transaction_categories' => $categories,
            'order_item_categories' => $orderItems,
            'transaction_details' => $transactionDetails,
            'order_item_details' => $orderItemDetails,
            'schedule_c_map' => $scheduleCMap,
            'normalized_lines' => $normalizedLines,
            'schedule_c_total' => round($scheduleCTotal, 2),
            'schedule_a_total' => round($scheduleATotal, 2),
        ]);
    }

    /**
     * Generate the full tax export package (Excel + PDF + CSV).
     */
    public function export(ExportTaxRequest $request): JsonResponse
    {
        $result = $this->exporter->generate(auth()->user(), $request->validated('year'));

        // Generate download links
        $downloadLinks = [];
        foreach ($result['files'] as $type => $path) {
            $filename = basename($path);
            $downloadLinks[$type] = [
                'filename' => $filename,
                'url' => route('tax.download', [
                    'year' => $request->validated('year'),
                    'type' => $type,
                ]),
                'size' => file_exists($path) ? $this->formatFileSize(filesize($path)) : null,
            ];
        }

        return response()->json([
            'message' => 'Tax package generated successfully',
            'year' => $request->validated('year'),
            'summary' => $result['summary'],
            'downloads' => $downloadLinks,
        ]);
    }

    /**
     * Send the tax package directly to an accountant's email.
     */
    public function sendToAccountant(SendToAccountantRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->exporter->generate(
            auth()->user(),
            $validated['year'],
            $validated['accountant_email']
        );

        return response()->json([
            'message' => "Tax package for {$validated['year']} sent to {$validated['accountant_email']}",
            'emailed_to' => $validated['accountant_email'],
            'cc' => auth()->user()->email,
            'summary' => $result['summary'],
            'files_sent' => [
                'SpendifiAI_Tax_'.$validated['year'].'.xlsx',
                'SpendifiAI_Tax_Summary_'.$validated['year'].'.pdf',
                'SpendifiAI_Transactions_'.$validated['year'].'.csv',
            ],
        ]);
    }

    /**
     * Download a specific tax export file.
     */
    public function download(Request $request, int $year, string $type): BinaryFileResponse
    {
        $typeConfig = [
            'xlsx' => ['ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'suffix' => ''],
            'pdf' => ['ext' => 'pdf', 'mime' => 'application/pdf', 'suffix' => '_Summary'],
            'csv' => ['ext' => 'csv', 'mime' => 'text/csv', 'suffix' => '_Transactions'],
            'txf' => ['ext' => 'txf', 'mime' => 'application/octet-stream', 'suffix' => ''],
            'qbo_csv' => ['ext' => 'csv', 'mime' => 'text/csv', 'suffix' => '_QuickBooks'],
            'ofx' => ['ext' => 'ofx', 'mime' => 'application/x-ofx', 'suffix' => ''],
        ];

        if (! isset($typeConfig[$type])) {
            abort(404, 'Invalid file type');
        }

        $config = $typeConfig[$type];
        $dir = storage_path('app/private/tax-exports/'.auth()->id());
        $pattern = "{$dir}/SpendifiAI_Tax_{$year}_*{$config['suffix']}.{$config['ext']}";
        $files = glob($pattern);

        if (empty($files)) {
            abort(404, 'Tax export not found. Generate it first.');
        }

        $latestFile = end($files);
        $downloadName = "SpendifiAI_Tax_{$year}{$config['suffix']}.{$config['ext']}";

        return response()->download(
            $latestFile,
            $downloadName,
            ['Content-Type' => $config['mime']]
        );
    }

    /**
     * List all eligible/discovered deductions for a tax year.
     */
    public function deductions(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $result = $this->deductionFinder->findDeductions(auth()->user(), $year);

        return response()->json([
            'year' => $year,
            ...$result,
        ]);
    }

    /**
     * Trigger a transaction scan to find deductions automatically.
     */
    public function scanDeductions(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $result = $this->deductionFinder->scanTransactions(auth()->user(), $year);
        $profileResult = $this->deductionFinder->matchProfile(auth()->user(), $year);

        return response()->json([
            'message' => 'Deduction scan complete',
            'scan' => $result,
            'profile' => $profileResult,
        ]);
    }

    /**
     * Answer an eligibility question for a deduction.
     */
    public function answerDeductionQuestion(Request $request, TaxDeduction $deduction): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer',
            'answer' => 'required|array',
        ]);

        $record = $this->deductionFinder->answerQuestion(
            auth()->user(),
            $deduction,
            $validated['year'],
            $validated['answer']
        );

        return response()->json([
            'status' => $record->status,
            'estimated_amount' => $record->estimated_amount ? (float) $record->estimated_amount : null,
        ]);
    }

    /**
     * Claim a deduction with an actual amount.
     */
    public function claimDeduction(Request $request, TaxDeduction $deduction): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $record = $this->deductionFinder->claimDeduction(
            auth()->user(),
            $deduction,
            $validated['year'],
            (float) $validated['amount'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'Deduction claimed',
            'actual_amount' => (float) $record->actual_amount,
        ]);
    }

    /**
     * Get the next batch of questionnaire questions.
     */
    public function questionnaireGet(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $result = $this->deductionFinder->getQuestionnaire(auth()->user(), $year);

        return response()->json($result);
    }

    /**
     * Submit multiple questionnaire answers at once.
     */
    public function questionnaireSubmit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer',
            'answers' => 'required|array',
            'answers.*.deduction_id' => 'required|integer|exists:tax_deductions,id',
            'answers.*.answer' => 'required|array',
        ]);

        $user = auth()->user();
        $year = $validated['year'];
        $results = [];

        foreach ($validated['answers'] as $item) {
            $deduction = TaxDeduction::find($item['deduction_id']);
            if (! $deduction) {
                continue;
            }

            $record = $this->deductionFinder->answerQuestion($user, $deduction, $year, $item['answer']);
            $results[] = [
                'deduction_id' => $deduction->id,
                'status' => $record->status,
                'estimated_amount' => $record->estimated_amount ? (float) $record->estimated_amount : null,
            ];
        }

        return response()->json([
            'message' => count($results).' answers processed',
            'results' => $results,
        ]);
    }

    /**
     * Format file size for human-readable output.
     */
    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
