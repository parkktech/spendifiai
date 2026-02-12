<?php

namespace App\Services;

use App\Mail\TaxPackageMail;
use App\Models\BankAccount;
use App\Models\OrderItem;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
                'expense_type' => $tx->expense_type?->value ?? $tx->expense_type,
                'account' => $tx->bankAccount?->nickname ?? $tx->bankAccount?->name,
                'account_mask' => $tx->bankAccount?->mask,
                'account_purpose' => $tx->account_purpose?->value ?? $tx->account_purpose,
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
                'purpose' => $a->purpose?->value ?? $a->purpose,
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
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle("LedgerIQ Tax Export — {$data['year']}")
            ->setCreator('LedgerIQ');

        $this->createSummarySheet($spreadsheet, $data);
        $this->createScheduleCSheet($spreadsheet, $data);
        $this->createCategorySheet($spreadsheet, $data);
        $this->createTransactionsSheet($spreadsheet, $data);
        $this->createSubscriptionsSheet($spreadsheet, $data);

        // Set print settings for all sheets
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
            $sheet->getPageSetup()->setFitToWidth(1);
            $sheet->getPageSetup()->setFitToHeight(0);
            $sheet->setPrintGridlines(false);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        if (! file_exists($path)) {
            throw new \RuntimeException('Excel generation failed');
        }
    }

    protected function createSummarySheet(Spreadsheet $spreadsheet, array $data): void
    {
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Tax Summary');
        $ws->getTabColor()->setRGB('1a5276');

        $s = $data['summary'];
        $p = $data['profile'];
        $u = $data['user'];

        // Title
        $ws->mergeCells('A1:F1');
        $ws->setCellValue('A1', "LedgerIQ Tax Export — {$data['year']}");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(18)->setColor(new Color('1a5276'));

        $ws->mergeCells('A2:F2');
        $ws->setCellValue('A2', "Prepared for {$u['name']} ({$u['email']}) on ".substr($s['generated_at'], 0, 10));
        $ws->getStyle('A2')->getFont()->setSize(10)->setColor(new Color('666666'));

        // Profile section
        $row = 4;
        $ws->setCellValue("A{$row}", 'TAXPAYER PROFILE');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('1a5276'));
        $row++;

        $profileFields = [
            ['Employment Type', $p['employment_type'] ?? '—'],
            ['Business Type', $p['business_type'] ?? '—'],
            ['Filing Status', $p['filing_status'] ?? '—'],
            ['Est. Tax Bracket', ($p['tax_bracket'] ?? 22).'%'],
            ['Home Office', ($p['has_home_office'] ?? false) ? 'Yes' : 'No'],
        ];

        foreach ($profileFields as [$label, $val]) {
            $ws->setCellValue("A{$row}", $label);
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(10);
            $ws->setCellValue("B{$row}", $val);
            $row++;
        }

        // Totals section
        $row++;
        $ws->setCellValue("A{$row}", 'DEDUCTION SUMMARY');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('1a5276'));
        $row++;

        $greenFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'e8f5e9']];
        $summaryRows = [
            ['Total Deductible (Bank Transactions)', $s['total_deductible_transactions'], false],
            ['Total Deductible (Email Order Items)', $s['total_deductible_items'], false],
            ['Grand Total Deductible', $s['grand_total_deductible'], true],
            ['Estimated Tax Savings', $s['estimated_tax_savings'], true],
        ];

        foreach ($summaryRows as [$label, $val, $highlight]) {
            $ws->setCellValue("A{$row}", $label);
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
            $ws->setCellValue("B{$row}", $val);
            $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $ws->getStyle("B{$row}")->getFont()->setBold(true)->setSize(11);

            if ($highlight) {
                $ws->getStyle("A{$row}")->getFill()->applyFromArray($greenFill);
                $ws->getStyle("B{$row}")->getFill()->applyFromArray($greenFill);
                $ws->getStyle("B{$row}")->getFont()->setSize(13)->setColor(new Color('2e7d32'));
            }
            $row++;
        }

        $ws->setCellValue("A{$row}", 'Based on '.($s['effective_rate_used'] * 100).'% effective rate');
        $ws->getStyle("A{$row}")->getFont()->setSize(9)->setItalic(true)->setColor(new Color('999999'));
        $row += 2;

        // Accounts used
        $ws->setCellValue("A{$row}", 'LINKED ACCOUNTS');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('1a5276'));
        $row++;

        $headers = ['Institution', 'Account', 'Type', 'Last 4', 'Purpose', 'Business Name', 'Entity Type', 'EIN'];
        foreach ($headers as $col => $h) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($col + 1).$row, $h);
        }
        $this->styleHeaderRow($ws, $row, count($headers), '34495e');
        $row++;

        foreach ($data['accounts'] ?? [] as $acct) {
            $ws->setCellValue("A{$row}", $acct['institution'] ?? '');
            $ws->setCellValue("B{$row}", $acct['account'] ?? '');
            $ws->setCellValue("C{$row}", $acct['type'] ?? '');
            $ws->setCellValue("D{$row}", $acct['mask'] ?? '');
            $ws->setCellValue("E{$row}", $acct['purpose'] ?? '');
            if (($acct['purpose'] ?? '') === 'business') {
                $ws->getStyle("E{$row}")->getFont()->setBold(true)->setColor(new Color('1a5276'));
            }
            $ws->setCellValue("F{$row}", $acct['business_name'] ?? '');
            $ws->setCellValue("G{$row}", $acct['entity_type'] ?? '');
            $ws->setCellValue("H{$row}", $acct['ein'] ?? '');
            $row++;
        }

        $ws->getColumnDimension('A')->setWidth(35);
        $ws->getColumnDimension('B')->setWidth(25);
        $this->autoWidth($ws, 'C', 'H');
    }

    protected function createScheduleCSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $ws = $spreadsheet->createSheet();
        $ws->setTitle('Schedule C Mapping');
        $ws->getTabColor()->setRGB('2e7d32');

        $ws->mergeCells('A1:E1');
        $ws->setCellValue('A1', "IRS Schedule C Line Mapping — {$data['year']}");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('2e7d32'));

        $ws->setCellValue('A2', 'Maps your deductible expenses to IRS Schedule C (Form 1040) lines');
        $ws->getStyle('A2')->getFont()->setSize(10)->setItalic(true)->setColor(new Color('666666'));

        $headers = ['Schedule C Line', 'Description', 'Amount', '# Items', 'Categories Included'];
        $row = 4;
        foreach ($headers as $col => $h) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($col + 1).$row, $h);
        }
        $this->styleHeaderRow($ws, $row, count($headers), '2e7d32');

        $row = 5;
        $grandTotal = 0;
        foreach ($data['schedule_c_mapping'] ?? [] as $line) {
            $ws->setCellValue("A{$row}", "Line {$line['line']}");
            $ws->getStyle("A{$row}")->getFont()->setBold(true);

            $label = $line['label'];
            if ($line['line'] === '24b') {
                $label .= ' ⚠️ 50% deductible';
                $ws->getStyle("B{$row}")->getFont()->setItalic(true)->setColor(new Color('e65100'));
            }
            $ws->setCellValue("B{$row}", $label);

            $ws->setCellValue("C{$row}", $line['total']);
            $ws->getStyle("C{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $ws->getStyle("C{$row}")->getFont()->setBold(true);

            $totalItems = collect($line['categories'] ?? [])->sum('items');
            $ws->setCellValue("D{$row}", $totalItems);

            $catNames = collect($line['categories'] ?? [])->pluck('name')->implode(', ');
            $ws->setCellValue("E{$row}", $catNames);

            $grandTotal += $line['total'];
            $row++;
        }

        $row++;
        $ws->setCellValue("A{$row}", 'TOTAL');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $ws->setCellValue("C{$row}", $grandTotal);
        $ws->getStyle("C{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $ws->getStyle("C{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('2e7d32'));
        $ws->getStyle("C{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('e8f5e9');

        $this->autoWidth($ws, 'A', 'E');
    }

    protected function createCategorySheet(Spreadsheet $spreadsheet, array $data): void
    {
        $ws = $spreadsheet->createSheet();
        $ws->setTitle('Deductions by Category');
        $ws->getTabColor()->setRGB('1565c0');

        $ws->mergeCells('A1:D1');
        $ws->setCellValue('A1', "Deductions by Category — {$data['year']}");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('1565c0'));

        $headers = ['Tax Category', 'Total Amount', '# Transactions', 'Date Range'];
        $row = 3;
        foreach ($headers as $col => $h) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($col + 1).$row, $h);
        }
        $this->styleHeaderRow($ws, $row, count($headers), '1565c0');

        $row = 4;
        $startRow = $row;
        foreach ($data['deductions_by_category'] ?? [] as $cat) {
            $ws->setCellValue("A{$row}", $cat['tax_category']);
            $ws->setCellValue("B{$row}", $cat['total']);
            $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $ws->getStyle("B{$row}")->getFont()->setBold(true);
            $ws->setCellValue("C{$row}", $cat['item_count']);
            $ws->setCellValue("D{$row}", ($cat['first_date'] ?? '').' — '.($cat['last_date'] ?? ''));
            $row++;
        }

        $row++;
        $ws->setCellValue("A{$row}", 'TOTAL');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $lastDataRow = $row - 2;
        $ws->setCellValue("B{$row}", "=SUM(B{$startRow}:B{$lastDataRow})");
        $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $ws->getStyle("B{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('1565c0'));

        $this->autoWidth($ws, 'A', 'D');
    }

    protected function createTransactionsSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $ws = $spreadsheet->createSheet();
        $ws->setTitle('All Deductible Transactions');
        $ws->getTabColor()->setRGB('e65100');

        $ws->mergeCells('A1:L1');
        $ws->setCellValue('A1', "Complete Deductible Transaction Detail — {$data['year']}");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('e65100'));

        $txCount = count($data['deductible_transactions'] ?? []);
        $itemCount = count($data['deductible_items'] ?? []);
        $ws->setCellValue('A2', "{$txCount} bank transactions + {$itemCount} itemized email receipts");
        $ws->getStyle('A2')->getFont()->setSize(10)->setItalic(true)->setColor(new Color('666666'));

        $headers = ['Date', 'Merchant', 'Description', 'Amount', 'Category', 'Tax Category',
            'Type', 'Account', 'Account Purpose', 'Business Entity', 'Confidence', 'Verified'];
        $row = 4;
        foreach ($headers as $col => $h) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($col + 1).$row, $h);
        }
        $this->styleHeaderRow($ws, $row, count($headers), 'e65100');

        $row = 5;
        $startRow = $row;
        foreach ($data['deductible_transactions'] ?? [] as $tx) {
            $ws->setCellValue("A{$row}", $tx['date']);
            $ws->setCellValue("B{$row}", $tx['merchant']);
            $ws->setCellValue("C{$row}", $tx['description'] ?? '');
            $ws->setCellValue("D{$row}", $tx['amount']);
            $ws->getStyle("D{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $ws->setCellValue("E{$row}", $tx['category'] ?? '');
            $ws->setCellValue("F{$row}", $tx['tax_category'] ?? '');
            $ws->setCellValue("G{$row}", $tx['expense_type'] ?? '');
            $ws->setCellValue("H{$row}", $tx['account'] ?? '');
            $ws->setCellValue("I{$row}", $tx['account_purpose'] ?? '');
            $ws->setCellValue("J{$row}", $tx['business_name'] ?? '');

            if ($tx['confidence'] ?? null) {
                $ws->setCellValue("K{$row}", $tx['confidence']);
                $ws->getStyle("K{$row}")->getNumberFormat()->setFormatCode('0%');
            }

            $verified = ($tx['user_confirmed'] ?? false) ? '✓ User' : 'AI';
            $ws->setCellValue("L{$row}", $verified);
            if ($tx['user_confirmed'] ?? false) {
                $ws->getStyle("L{$row}")->getFont()->setBold(true)->setColor(new Color('2e7d32'));
            }
            $row++;
        }

        // Email order items
        if (! empty($data['deductible_items'])) {
            $row++;
            $ws->setCellValue("A{$row}", 'ITEMIZED EMAIL RECEIPTS');
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11)->setColor(new Color('e65100'));
            $row++;

            foreach ($data['deductible_items'] as $item) {
                $ws->setCellValue("A{$row}", $item['date']);
                $ws->setCellValue("B{$row}", $item['merchant']);
                $desc = $item['product'];
                if (! empty($item['order_number'])) {
                    $desc .= " (Order #{$item['order_number']})";
                }
                $ws->setCellValue("C{$row}", $desc);
                $ws->setCellValue("D{$row}", $item['amount']);
                $ws->getStyle("D{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $ws->setCellValue("E{$row}", $item['category'] ?? '');
                $ws->setCellValue("F{$row}", $item['tax_category'] ?? '');
                $ws->setCellValue("G{$row}", 'business');
                $ws->setCellValue("H{$row}", 'Email Receipt');
                $ws->setCellValue("L{$row}", 'AI');
                $row++;
            }
        }

        // Grand total
        $row++;
        $ws->setCellValue("C{$row}", 'GRAND TOTAL');
        $ws->getStyle("C{$row}")->getFont()->setBold(true)->setSize(12);
        $lastDataRow = $row - 2;
        $ws->setCellValue("D{$row}", "=SUM(D{$startRow}:D{$lastDataRow})");
        $ws->getStyle("D{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $ws->getStyle("D{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('e65100'));

        $this->autoWidth($ws, 'A', 'L');
    }

    protected function createSubscriptionsSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $ws = $spreadsheet->createSheet();
        $ws->setTitle('Business Subscriptions');
        $ws->getTabColor()->setRGB('6a1b9a');

        $ws->setCellValue('A1', "Recurring Business Expenses — {$data['year']}");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('6a1b9a'));

        $headers = ['Service', 'Monthly Cost', 'Annual Cost', 'Category', 'Frequency'];
        $row = 3;
        foreach ($headers as $col => $h) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($col + 1).$row, $h);
        }
        $this->styleHeaderRow($ws, $row, count($headers), '6a1b9a');

        $row = 4;
        $startRow = $row;
        foreach ($data['business_subscriptions'] ?? [] as $sub) {
            $ws->setCellValue("A{$row}", $sub['service']);
            $ws->setCellValue("B{$row}", $sub['monthly_cost']);
            $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $ws->setCellValue("C{$row}", $sub['annual_cost']);
            $ws->getStyle("C{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $ws->setCellValue("D{$row}", $sub['category']);
            $ws->setCellValue("E{$row}", $sub['frequency']);
            $row++;
        }

        $row++;
        $ws->setCellValue("A{$row}", 'TOTAL');
        $ws->getStyle("A{$row}")->getFont()->setBold(true);
        $lastDataRow = $row - 2;
        $ws->setCellValue("B{$row}", "=SUM(B{$startRow}:B{$lastDataRow})");
        $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $ws->setCellValue("C{$row}", "=SUM(C{$startRow}:C{$lastDataRow})");
        $ws->getStyle("C{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');

        $this->autoWidth($ws, 'A', 'E');
    }

    protected function styleHeaderRow(Worksheet $ws, int $row, int $cols, string $fillColor): void
    {
        $fromCol = Coordinate::stringFromColumnIndex(1);
        $toCol = Coordinate::stringFromColumnIndex($cols);
        $range = "{$fromCol}{$row}:{$toCol}{$row}";

        $style = $ws->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fillColor);
        $style->getFont()->setBold(true)->setColor(new Color('FFFFFF'))->setSize(11);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->setColor(new Color('333333'));
    }

    protected function autoWidth(Worksheet $ws, string $fromCol = 'A', string $toCol = 'Z'): void
    {
        $from = Coordinate::columnIndexFromString($fromCol);
        $to = Coordinate::columnIndexFromString($toCol);
        for ($col = $from; $col <= $to; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $ws->getColumnDimension($letter)->setAutoSize(true);
        }
    }

    /**
     * Generate the PDF summary cover sheet.
     */
    protected function generatePDF(array $data, string $path): void
    {
        $pdf = Pdf::loadView('pdf.tax-summary', $data);
        $pdf->setPaper('letter', 'portrait');
        $pdf->save($path);

        if (! file_exists($path)) {
            throw new \RuntimeException('PDF generation failed');
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
