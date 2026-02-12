<?php

namespace App\Services;

use App\Mail\TaxPackageMail;
use App\Models\BankAccount;
use App\Models\OrderItem;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class TaxExportService
{
    /**
     * Generate the complete tax package and optionally email it.
     *
     * Returns paths to generated files.
     */
    public function generate(User $user, int $year, ?string $accountantEmail = null): array
    {
        $data = $this->gatherTaxData($user, $year);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "LedgerIQ_Tax_{$year}_{$timestamp}";
        $dir = "tax-exports/{$user->id}";

        Storage::disk('local')->makeDirectory($dir);

        // Generate Excel workbook with all detail
        $xlsxPath = "{$dir}/{$baseName}.xlsx";
        $this->generateExcel($data, Storage::disk('local')->path($xlsxPath));

        // Generate PDF summary (accountant cover sheet)
        $pdfPath = "{$dir}/{$baseName}_Summary.pdf";
        $this->generatePDF($data, Storage::disk('local')->path($pdfPath));

        // Generate CSV backup (universal fallback)
        $csvPath = "{$dir}/{$baseName}_Transactions.csv";
        $this->generateCSV($data, Storage::disk('local')->path($csvPath));

        $files = [
            'xlsx' => Storage::disk('local')->path($xlsxPath),
            'pdf' => Storage::disk('local')->path($pdfPath),
            'csv' => Storage::disk('local')->path($csvPath),
        ];

        // Email to accountant if requested
        if ($accountantEmail) {
            Mail::to($accountantEmail)
                ->cc($user->email)
                ->send(new TaxPackageMail($user, $year, $data['summary'], $files));
        }

        return [
            'files' => $files,
            'summary' => $data['summary'],
            'emailed_to' => $accountantEmail,
        ];
    }

    /**
     * Gather all tax-relevant data for the year.
     */
    protected function gatherTaxData(User $user, int $year): array
    {
        $userId = $user->id;
        $profile = UserFinancialProfile::where('user_id', $userId)->first();

        // ── Deductible Transactions by Tax Category ──
        // toBase() avoids the model's `category` accessor overriding COALESCE alias
        $deductionsByCategory = Transaction::where('user_id', $userId)
            ->where('tax_deductible', true)
            ->whereYear('transaction_date', $year)
            ->toBase()
            ->select(
                DB::raw("COALESCE(tax_category, user_category, ai_category, 'Uncategorized') as tax_category"),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as item_count'),
                DB::raw('MIN(transaction_date) as first_date'),
                DB::raw('MAX(transaction_date) as last_date'),
            )
            ->groupBy(DB::raw("COALESCE(tax_category, user_category, ai_category, 'Uncategorized')"))
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();

        // ── All Deductible Transaction Details ──
        $deductibleTransactions = Transaction::where('user_id', $userId)
            ->where('tax_deductible', true)
            ->whereYear('transaction_date', $year)
            ->with('bankAccount:id,name,mask,purpose,nickname,business_name')
            ->orderBy('transaction_date')
            ->get()
            ->map(fn ($tx) => [
                'date' => $tx->transaction_date->format('Y-m-d'),
                'merchant' => $tx->merchant_normalized ?? $tx->merchant_name,
                'description' => $tx->description,
                'amount' => $tx->amount,
                'category' => $tx->user_category ?? $tx->ai_category,
                'tax_category' => $tx->tax_category ?? $tx->user_category ?? $tx->ai_category,
                'expense_type' => $tx->expense_type,
                'account' => $tx->bankAccount?->nickname ?? $tx->bankAccount?->name,
                'account_mask' => $tx->bankAccount?->mask,
                'account_purpose' => $tx->account_purpose,
                'business_name' => $tx->bankAccount?->business_name,
                'confidence' => $tx->ai_confidence,
                'user_confirmed' => $tx->review_status === 'user_confirmed',
            ])
            ->toArray();

        // ── Deductible Order Items (from email parsing) ──
        $deductibleItems = OrderItem::where('user_id', $userId)
            ->where('tax_deductible', true)
            ->whereHas('order', fn ($q) => $q->whereYear('order_date', $year))
            ->with('order:id,merchant,order_number,order_date')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->order->order_date->format('Y-m-d'),
                'merchant' => $item->order->merchant,
                'order_number' => $item->order->order_number,
                'product' => $item->product_name,
                'quantity' => $item->quantity,
                'amount' => $item->total_price,
                'category' => $item->user_category ?? $item->ai_category,
                'tax_category' => $item->tax_category ?? $item->ai_category,
            ])
            ->toArray();

        // ── Business vs Personal Spending Summary ──
        $spendingByPurpose = Transaction::where('user_id', $userId)
            ->where('amount', '>', 0)
            ->whereYear('transaction_date', $year)
            ->select(
                'account_purpose',
                'expense_type',
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('account_purpose', 'expense_type')
            ->get()
            ->toArray();

        // ── Business Subscription Expenses ──
        $businessSubs = Subscription::where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) {
                $query->where('category', 'Software')
                    ->orWhere('is_essential', true);
            })
            ->get()
            ->map(fn ($s) => [
                'service' => $s->merchant_normalized ?? $s->merchant_name,
                'monthly_cost' => $s->amount,
                'annual_cost' => $s->annual_cost,
                'category' => $s->category,
                'frequency' => $s->frequency,
            ])
            ->toArray();

        // ── Schedule C Line Mapping ──
        $scheduleCLines = $this->mapToScheduleC($deductionsByCategory);

        // ── Summary Totals ──
        $totalDeductible = array_sum(array_column($deductionsByCategory, 'total'));
        $totalItemized = array_sum(array_column($deductibleItems, 'amount'));
        $grandTotal = $totalDeductible + $totalItemized;
        $estRate = ($profile?->estimated_tax_bracket ?? 22) / 100;

        // ── Account Summary ──
        $accounts = BankAccount::where('user_id', $userId)
            ->where('is_active', true)
            ->with('bankConnection:id,institution_name')
            ->get()
            ->map(fn ($a) => [
                'institution' => $a->bankConnection->institution_name,
                'account' => $a->nickname ?? $a->name,
                'type' => $a->subtype ?? $a->type,
                'mask' => $a->mask,
                'purpose' => $a->purpose,
                'business_name' => $a->business_name,
                'entity_type' => $a->tax_entity_type,
                'ein' => $a->ein,
            ])
            ->toArray();

        return [
            'year' => $year,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'profile' => [
                'employment_type' => $profile?->employment_type,
                'business_type' => $profile?->business_type,
                'filing_status' => $profile?->tax_filing_status,
                'tax_bracket' => $profile?->estimated_tax_bracket,
                'has_home_office' => $profile?->has_home_office ?? false,
            ],
            'summary' => [
                'total_deductible_transactions' => $totalDeductible,
                'total_deductible_items' => $totalItemized,
                'grand_total_deductible' => $grandTotal,
                'estimated_tax_savings' => round($grandTotal * $estRate, 2),
                'effective_rate_used' => $estRate,
                'total_categories' => count($deductionsByCategory),
                'total_line_items' => count($deductibleTransactions) + count($deductibleItems),
                'generated_at' => now()->toIso8601String(),
            ],
            'deductions_by_category' => $deductionsByCategory,
            'deductible_transactions' => $deductibleTransactions,
            'deductible_items' => $deductibleItems,
            'spending_by_purpose' => $spendingByPurpose,
            'business_subscriptions' => $businessSubs,
            'schedule_c_mapping' => $scheduleCLines,
            'accounts' => $accounts,
        ];
    }

    /**
     * Map deduction categories to IRS Schedule C lines.
     */
    protected function mapToScheduleC(array $categories): array
    {
        $lineMap = [
            'Marketing & Advertising' => ['line' => '8',   'label' => 'Advertising'],
            'Car Insurance' => ['line' => '9',   'label' => 'Car and truck expenses'],
            'Auto Maintenance' => ['line' => '9',   'label' => 'Car and truck expenses'],
            'Gas & Fuel' => ['line' => '9',   'label' => 'Car and truck expenses'],
            'Transportation' => ['line' => '9',   'label' => 'Car and truck expenses'],
            'Professional Services' => ['line' => '11',  'label' => 'Contract labor'],
            'Health Insurance' => ['line' => '15',  'label' => 'Insurance (health)'],
            'Home Insurance' => ['line' => '15',  'label' => 'Insurance (other)'],
            'Professional Development' => ['line' => '27a', 'label' => 'Other expenses'],
            'Office Supplies' => ['line' => '18',  'label' => 'Office expense'],
            'Software & SaaS' => ['line' => '18',  'label' => 'Office expense'],
            'Business Meals' => ['line' => '24b', 'label' => 'Meals (50% deductible)'],
            'Restaurant & Dining' => ['line' => '24b', 'label' => 'Meals (business)'],
            'Travel & Hotels' => ['line' => '24a', 'label' => 'Travel'],
            'Flights' => ['line' => '24a', 'label' => 'Travel'],
            'Phone & Internet' => ['line' => '25',  'label' => 'Utilities'],
            'Utilities' => ['line' => '25',  'label' => 'Utilities'],
            'Home Office' => ['line' => '30',  'label' => 'Business use of home'],
            'Shipping & Postage' => ['line' => '18',  'label' => 'Office expense'],
            'Charity & Donations' => ['line' => 'Sch A', 'label' => 'Charitable contributions (Schedule A)'],
            'Medical & Dental' => ['line' => 'Sch A', 'label' => 'Medical expenses (Schedule A)'],
            'Mortgage' => ['line' => 'Sch A', 'label' => 'Mortgage interest (Schedule A)'],
        ];

        $lines = [];
        foreach ($categories as $cat) {
            $name = $cat['tax_category'];
            $mapping = $lineMap[$name] ?? ['line' => '27a', 'label' => 'Other expenses'];

            $lineKey = "Line {$mapping['line']}";
            if (! isset($lines[$lineKey])) {
                $lines[$lineKey] = [
                    'line' => $mapping['line'],
                    'label' => $mapping['label'],
                    'total' => 0,
                    'categories' => [],
                ];
            }
            $lines[$lineKey]['total'] += $cat['total'];
            $lines[$lineKey]['categories'][] = [
                'name' => $name,
                'amount' => $cat['total'],
                'items' => $cat['item_count'],
            ];
        }

        return array_values($lines);
    }

    /**
     * Generate the Excel workbook with multiple tabs.
     */
    protected function generateExcel(array $data, string $path): void
    {
        // We'll use a Python script via shell since openpyxl
        // produces far better Excel files than any PHP library.
        $jsonPath = tempnam(sys_get_temp_dir(), 'tax_').'.json';
        file_put_contents($jsonPath, json_encode($data));

        $scriptPath = resource_path('scripts/generate_tax_excel.py');
        $cmd = sprintf(
            'python3 %s %s %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($jsonPath),
            escapeshellarg($path)
        );

        $output = shell_exec($cmd);
        unlink($jsonPath);

        if (! file_exists($path)) {
            throw new \RuntimeException("Excel generation failed: {$output}");
        }
    }

    /**
     * Generate the PDF summary cover sheet.
     */
    protected function generatePDF(array $data, string $path): void
    {
        $jsonPath = tempnam(sys_get_temp_dir(), 'tax_').'.json';
        file_put_contents($jsonPath, json_encode($data));

        $scriptPath = resource_path('scripts/generate_tax_pdf.py');
        $cmd = sprintf(
            'python3 %s %s %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($jsonPath),
            escapeshellarg($path)
        );

        $output = shell_exec($cmd);
        unlink($jsonPath);

        if (! file_exists($path)) {
            throw new \RuntimeException("PDF generation failed: {$output}");
        }
    }

    /**
     * Generate a flat CSV of all deductible transactions.
     */
    protected function generateCSV(array $data, string $path): void
    {
        $fp = fopen($path, 'w');

        // Header
        fputcsv($fp, [
            'Date', 'Merchant', 'Description', 'Amount', 'Category',
            'Tax Category', 'Expense Type', 'Account', 'Account Type',
            'Business Name', 'AI Confidence', 'User Confirmed',
        ]);

        // Transaction rows
        foreach ($data['deductible_transactions'] as $tx) {
            fputcsv($fp, [
                $tx['date'],
                $tx['merchant'],
                $tx['description'],
                $tx['amount'],
                $tx['category'],
                $tx['tax_category'],
                $tx['expense_type'],
                $tx['account'].($tx['account_mask'] ? " ····{$tx['account_mask']}" : ''),
                $tx['account_purpose'],
                $tx['business_name'] ?? '',
                $tx['confidence'] ? round($tx['confidence'] * 100).'%' : '',
                $tx['user_confirmed'] ? 'Yes' : 'AI',
            ]);
        }

        // Itemized order items
        foreach ($data['deductible_items'] as $item) {
            fputcsv($fp, [
                $item['date'],
                $item['merchant'],
                $item['product']." (Order #{$item['order_number']})",
                $item['amount'],
                $item['category'],
                $item['tax_category'],
                'business',
                'Email Receipt',
                'email',
                '',
                '',
                'AI',
            ]);
        }

        fclose($fp);
    }
}
