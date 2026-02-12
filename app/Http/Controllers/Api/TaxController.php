<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportTaxRequest;
use App\Http\Requests\SendToAccountantRequest;
use App\Models\OrderItem;
use App\Models\Transaction;
use App\Models\UserFinancialProfile;
use App\Services\TaxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaxController extends Controller
{
    public function __construct(
        private readonly TaxExportService $exporter,
    ) {}

    /**
     * Tax summary: deductible categories by transaction and order item.
     */
    public function summary(Request $request): JsonResponse
    {
        $year = $request->input('year', now()->year);
        $userId = auth()->id();

        // Deductible transactions by category (toBase avoids model category accessor)
        $categories = Transaction::where('user_id', $userId)
            ->where('tax_deductible', true)
            ->whereYear('transaction_date', $year)
            ->toBase()
            ->select(
                DB::raw('COALESCE(tax_category, user_category, ai_category) as category'),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as item_count')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        // Deductible order items (from email parsing)
        $orderItems = OrderItem::where('user_id', $userId)
            ->where('tax_deductible', true)
            ->whereHas('order', fn ($q) => $q->whereYear('order_date', $year))
            ->select(
                DB::raw('COALESCE(tax_category, ai_category) as category'),
                DB::raw('SUM(total_price) as total'),
                DB::raw('COUNT(*) as item_count')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $totalDeductible = $categories->sum('total') + $orderItems->sum('total');
        $profile = UserFinancialProfile::where('user_id', $userId)->first();
        $estRate = ($profile?->estimated_tax_bracket ?? 22) / 100;

        return response()->json([
            'year' => $year,
            'total_deductible' => round($totalDeductible, 2),
            'estimated_tax_savings' => round($totalDeductible * $estRate, 2),
            'effective_rate_used' => $estRate,
            'transaction_categories' => $categories,
            'order_item_categories' => $orderItems,
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
                'LedgerIQ_Tax_'.$validated['year'].'.xlsx',
                'LedgerIQ_Tax_Summary_'.$validated['year'].'.pdf',
                'LedgerIQ_Transactions_'.$validated['year'].'.csv',
            ],
        ]);
    }

    /**
     * Download a specific tax export file.
     */
    public function download(Request $request, int $year, string $type): BinaryFileResponse
    {
        $allowedTypes = ['xlsx', 'pdf', 'csv'];
        if (! in_array($type, $allowedTypes)) {
            abort(404, 'Invalid file type');
        }

        // Find the most recent export for this year
        $dir = storage_path('app/tax-exports/'.auth()->id());
        $pattern = "{$dir}/LedgerIQ_Tax_{$year}_*.{$type}";
        $files = glob($pattern);

        if (empty($files)) {
            abort(404, 'Tax export not found. Generate it first.');
        }

        // Get the most recent one
        $latestFile = end($files);

        $mimeTypes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
        ];

        return response()->download(
            $latestFile,
            "LedgerIQ_Tax_{$year}.{$type}",
            ['Content-Type' => $mimeTypes[$type]]
        );
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
