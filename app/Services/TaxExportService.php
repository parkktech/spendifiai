<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Mail\TaxPackageMail;
use App\Models\BankAccount;
use App\Models\OrderItem;
use App\Models\Subscription;
use App\Models\TaxDocument;
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
    public function __construct(
        private readonly TaxCategoryNormalizerService $normalizer,
    ) {}

    /**
     * Generate the complete tax package and optionally email it.
     *
     * Returns paths to generated files.
     */
    public function generate(User $user, int $year, ?string $accountantEmail = null): array
    {
        $data = $this->gatherTaxData($user, $year);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "SpendifiAI_Tax_{$year}_{$timestamp}";
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

        // Generate TXF (TurboTax / H&R Block / Lacerte / ProSeries)
        $txfPath = "{$dir}/{$baseName}.txf";
        $this->generateTXF($data, Storage::disk('local')->path($txfPath));

        // Generate QuickBooks Online CSV (3-column format)
        $qboCsvPath = "{$dir}/{$baseName}_QuickBooks.csv";
        $this->generateQuickBooksCSV($data, Storage::disk('local')->path($qboCsvPath));

        // Generate OFX (Xero / Wave / accounting software)
        $ofxPath = "{$dir}/{$baseName}.ofx";
        $this->generateOFX($data, Storage::disk('local')->path($ofxPath));

        $files = [
            'xlsx' => Storage::disk('local')->path($xlsxPath),
            'pdf' => Storage::disk('local')->path($pdfPath),
            'csv' => Storage::disk('local')->path($csvPath),
            'txf' => Storage::disk('local')->path($txfPath),
            'qbo_csv' => Storage::disk('local')->path($qboCsvPath),
            'ofx' => Storage::disk('local')->path($ofxPath),
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
        $userIds = $user->householdUserIds();
        $profile = UserFinancialProfile::where('user_id', $user->id)->first();

        // ── Deductible Transactions by Tax Category ──
        // toBase() avoids the model's `category` accessor overriding COALESCE alias
        $deductionsByCategory = Transaction::whereIn('user_id', $userIds)
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
        $deductibleTransactions = Transaction::whereIn('user_id', $userIds)
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
                'donation_note' => $tx->donation_note,
            ])
            ->toArray();

        // ── Deductible Order Items (from email parsing) ──
        $deductibleItems = OrderItem::whereIn('user_id', $userIds)
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
        $spendingByPurpose = Transaction::whereIn('user_id', $userIds)
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
        $businessSubs = Subscription::whereIn('user_id', $userIds)
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

        // ── Tax Vault Documents (W-2, 1099, 1098 extracted data) ──
        // Gathered early so 1098 data can be injected into Schedule A
        $vaultData = $this->gatherVaultDocumentData($userIds, $year);

        // ── Schedule C/A Line Mapping (via normalizer) ──
        $scheduleCLines = $this->mapToScheduleC($deductionsByCategory);

        // ── Build complete Schedule C form (all lines, even $0) ──
        $allScheduleC = $this->normalizer->getAllScheduleCLines();
        $allScheduleA = $this->normalizer->getAllScheduleALines();

        foreach ($scheduleCLines as $mapped) {
            $key = $mapped['line'];
            if ($mapped['schedule'] === 'C' && isset($allScheduleC[$key])) {
                $allScheduleC[$key]['total'] = $mapped['total'];
                $allScheduleC[$key]['categories'] = $mapped['categories'];
            } elseif ($mapped['schedule'] === 'A' && isset($allScheduleA[$key])) {
                $allScheduleA[$key]['total'] = $mapped['total'];
                $allScheduleA[$key]['categories'] = $mapped['categories'];
            }
        }

        // ── Inject vault 1098 data into Schedule A lines ──
        $vt = $vaultData['totals'];
        if (($vt['mortgage_1098_interest'] ?? 0) > 0 || ($vt['mortgage_1098_points'] ?? 0) > 0) {
            $mortgageTotal = ($vt['mortgage_1098_interest'] ?? 0) + ($vt['mortgage_1098_points'] ?? 0);
            $allScheduleA['Sch A-mortgage']['total'] += $mortgageTotal;
            $allScheduleA['Sch A-mortgage']['categories'][] = [
                'name' => 'Mortgage Interest + Points (1098)',
                'total' => $mortgageTotal,
                'items' => 1,
            ];
        }
        if (($vt['mortgage_1098_property_tax'] ?? 0) > 0) {
            $allScheduleA['Sch A-salt']['total'] += $vt['mortgage_1098_property_tax'];
            $allScheduleA['Sch A-salt']['categories'][] = [
                'name' => 'Property Tax (1098)',
                'total' => $vt['mortgage_1098_property_tax'],
                'items' => 1,
            ];
        }

        $scheduleCTotal = array_sum(array_column($allScheduleC, 'total'));
        $scheduleATotal = array_sum(array_column($allScheduleA, 'total'));

        // ── Group deductible transactions by normalized IRS line ──
        $transactionsByLine = [];
        foreach ($deductibleTransactions as $tx) {
            $cat = $tx['tax_category'] ?? 'Uncategorized';
            $normalized = $this->normalizer->normalize($cat);
            $lineLabel = $normalized['schedule'] === 'C'
                ? "Line {$normalized['line']} — {$normalized['label']}"
                : "{$normalized['line']} — {$normalized['label']}";

            $transactionsByLine[$lineLabel][] = $tx;
        }
        ksort($transactionsByLine);

        // ── Summary Totals ──
        $totalDeductible = array_sum(array_column($deductionsByCategory, 'total'));
        $totalItemized = array_sum(array_column($deductibleItems, 'amount'));
        $grandTotal = $totalDeductible + $totalItemized;

        // Auto-calculate tax bracket from vault income + filing status
        // Fall back to profile's manual bracket if no vault income documents
        $vaultIncome = $vaultData['totals']['total_income'] ?? 0;
        if ($vaultIncome > 0) {
            $filingStatus = $profile?->tax_filing_status ?? 'single';
            $autoTaxBracket = $this->calculateTaxBracket($vaultIncome, $filingStatus);
        } else {
            $autoTaxBracket = $profile?->estimated_tax_bracket ?? 22;
        }
        $estRate = $autoTaxBracket / 100;

        // ── Account Summary ──
        $accounts = BankAccount::whereIn('user_id', $userIds)
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
                'schedule_c_total' => round($scheduleCTotal, 2),
                'schedule_a_total' => round($scheduleATotal, 2),
                'generated_at' => now()->toIso8601String(),
                'vault_document_count' => count($vaultData['documents']),
            ] + collect($vaultData['totals'])->mapWithKeys(fn ($v, $k) => ["vault_{$k}" => $v])->toArray(),
            'deductions_by_category' => $deductionsByCategory,
            'deductible_transactions' => $deductibleTransactions,
            'deductible_items' => $deductibleItems,
            'spending_by_purpose' => $spendingByPurpose,
            'business_subscriptions' => $businessSubs,
            'schedule_c_mapping' => $scheduleCLines,
            'all_schedule_c_lines' => array_values($allScheduleC),
            'all_schedule_a_lines' => array_values($allScheduleA),
            'transactions_by_line' => $transactionsByLine,
            'accounts' => $accounts,
            'vault_documents' => $vaultData['documents'],
            'vault_totals' => $vaultData['totals'],
            'logo_base64' => $this->getLogoBase64(),
        ];
    }

    /**
     * Gather extracted data from Tax Vault documents (W-2, 1099, 1098, etc.).
     */
    protected function gatherVaultDocumentData(array $userIds, int $year): array
    {
        $docs = TaxDocument::whereIn('user_id', $userIds)
            ->where('tax_year', $year)
            ->where('status', DocumentStatus::Ready->value)
            ->whereNotNull('extracted_data')
            ->get();

        $documents = [];
        foreach ($docs as $doc) {
            $fields = $doc->extracted_data['fields'] ?? [];

            $entry = [
                'id' => $doc->id,
                'filename' => $doc->original_filename,
                'category' => $doc->category?->value,
                'category_label' => $doc->category?->label(),
                'confidence' => $doc->classification_confidence,
                'fields' => [],
            ];

            foreach ($fields as $name => $fieldData) {
                $entry['fields'][$name] = [
                    'value' => $fieldData['value'] ?? null,
                    'confidence' => $fieldData['confidence'] ?? 0,
                    'verified' => $fieldData['verified'] ?? false,
                ];
            }

            $documents[] = $entry;
        }

        return [
            'documents' => $documents,
            'totals' => $this->aggregateVaultTotals($docs),
        ];
    }

    /**
     * Aggregate vault totals from a collection of TaxDocument models.
     * Public so TaxController can reuse the same logic.
     */
    public function aggregateVaultTotals($docs): array
    {
        $totals = $this->initVaultTotals();

        foreach ($docs as $doc) {
            $fields = $doc->extracted_data['fields'] ?? [];
            $category = $doc->category?->value;

            match ($category) {
                'w2' => $this->aggregateW2($fields, $totals),
                '1099_nec' => $this->aggregate1099NEC($fields, $totals),
                '1099_int' => $this->aggregate1099INT($fields, $totals),
                '1099_misc' => $this->aggregate1099Misc($fields, $totals),
                '1099_div' => $this->aggregate1099DIV($fields, $totals),
                '1099_k' => $this->aggregate1099K($fields, $totals),
                '1099_s' => $this->aggregate1099S($fields, $totals),
                '1099_r' => $this->aggregate1099R($fields, $totals),
                '1099_g' => $this->aggregate1099G($fields, $totals),
                '1099_b' => $this->aggregate1099B($fields, $totals),
                '1098' => $this->aggregate1098($fields, $totals),
                default => null,
            };
        }

        $totals['total_income'] = $totals['w2_wages']
            + $totals['nec_1099_income'] + $totals['int_1099_income']
            + $totals['misc_1099_income'] + $totals['div_1099_ordinary']
            + $totals['k_1099_gross'] + $totals['s_1099_proceeds']
            + $totals['r_1099_taxable'] + $totals['g_1099_unemployment']
            + $totals['b_1099_gain_loss'];

        // Add W-2 withholding to overall withholding totals
        $totals['total_federal_withheld'] += $totals['w2_federal_withheld'];
        $totals['total_state_withheld'] += $totals['w2_state_withheld'];

        // Calculate linked business expenses (contract labor, etc.) from vault doc pivot
        $linkedExpenseTotal = 0;
        foreach ($docs as $doc) {
            if (in_array($doc->category?->value, ['1099_nec', '1099_k'])) {
                $linkedExpenseTotal += $doc->transactions()
                    ->where('tax_deductible', true)
                    ->sum('amount');
            }
        }
        $totals['linked_business_expenses'] = round((float) $linkedExpenseTotal, 2);

        // SE tax on NET self-employment income (gross minus linked expenses)
        $totals['se_tax_income'] = $totals['nec_1099_income'] + $totals['k_1099_gross'];
        $totals['se_net_income'] = max(0, $totals['se_tax_income'] - $totals['linked_business_expenses']);
        if ($totals['se_net_income'] > 0) {
            $totals['se_tax_estimated'] = round($totals['se_net_income'] * 0.9235 * 0.153, 2);
        }

        foreach ($totals as $key => $val) {
            $totals[$key] = round((float) $val, 2);
        }

        return $totals;
    }

    private function initVaultTotals(): array
    {
        return [
            'w2_wages' => 0, 'w2_federal_withheld' => 0, 'w2_state_withheld' => 0,
            'w2_social_security_wages' => 0, 'w2_social_security_tax' => 0,
            'w2_medicare_wages' => 0, 'w2_medicare_tax' => 0,
            'nec_1099_income' => 0, 'int_1099_income' => 0, 'misc_1099_income' => 0,
            'div_1099_ordinary' => 0, 'div_1099_qualified' => 0, 'div_1099_capital_gain' => 0,
            'k_1099_gross' => 0, 's_1099_proceeds' => 0,
            'r_1099_gross_distribution' => 0, 'r_1099_taxable' => 0,
            'g_1099_unemployment' => 0, 'g_1099_state_refund' => 0,
            'b_1099_proceeds' => 0, 'b_1099_cost_basis' => 0, 'b_1099_gain_loss' => 0,
            'mortgage_1098_interest' => 0, 'mortgage_1098_property_tax' => 0, 'mortgage_1098_points' => 0,
            'total_income' => 0, 'total_federal_withheld' => 0, 'total_state_withheld' => 0,
            'linked_business_expenses' => 0, 'se_tax_income' => 0, 'se_net_income' => 0, 'se_tax_estimated' => 0,
        ];
    }

    /**
     * Calculate marginal tax bracket from income and filing status.
     * Uses 2025 IRS tax brackets.
     */
    public function calculateTaxBracket(float $income, string $filingStatus): int
    {
        // 2025 Federal Income Tax Brackets
        $brackets = match ($filingStatus) {
            'married', 'married_filing_jointly' => [
                [23850, 10], [96950, 12], [206700, 22], [394600, 24],
                [501050, 32], [751600, 35], [PHP_INT_MAX, 37],
            ],
            'married_filing_separately' => [
                [11925, 10], [48475, 12], [103350, 22], [197300, 24],
                [250525, 32], [375800, 35], [PHP_INT_MAX, 37],
            ],
            'head_of_household' => [
                [17000, 10], [63100, 12], [100500, 22], [191950, 24],
                [243725, 32], [609350, 35], [PHP_INT_MAX, 37],
            ],
            default => [ // single
                [11925, 10], [48475, 12], [103350, 22], [197300, 24],
                [250525, 32], [626350, 35], [PHP_INT_MAX, 37],
            ],
        };

        if ($income <= 0) {
            return 10;
        }

        foreach ($brackets as [$threshold, $rate]) {
            if ($income <= $threshold) {
                return $rate;
            }
        }

        return 37;
    }

    private function safeFloat(array $fields, string $key): float
    {
        $val = $fields[$key]['value'] ?? 0;

        return (float) (is_numeric($val) ? $val : 0);
    }

    /**
     * Mask an EIN/TIN to show only last 4 digits: XX-XXX6789
     */
    private function maskEin(?string $ein): string
    {
        if (! $ein) {
            return '';
        }
        $digits = preg_replace('/\D/', '', $ein);
        if (strlen($digits) < 4) {
            return $ein;
        }

        return 'XX-XXX'.substr($digits, -4);
    }

    /**
     * Check if a field name contains sensitive identifiers that should be masked.
     */
    private function isSensitiveField(string $fieldName): bool
    {
        return str_contains($fieldName, 'ssn') || str_contains($fieldName, 'tin') || str_contains($fieldName, 'ein');
    }

    /**
     * Mask a field value if it's a sensitive identifier.
     */
    private function maskFieldValue(string $fieldName, mixed $value): mixed
    {
        if (! $this->isSensitiveField($fieldName) || ! is_string($value)) {
            return $value;
        }
        if (str_contains($fieldName, 'ssn')) {
            return '***-**-'.substr(preg_replace('/\D/', '', $value), -4);
        }

        return $this->maskEin($value);
    }

    private function aggregateW2(array $fields, array &$totals): void
    {
        $totals['w2_wages'] += $this->safeFloat($fields, 'wages');
        $totals['w2_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
        $totals['w2_state_withheld'] += $this->safeFloat($fields, 'state_tax_withheld');
        $totals['w2_social_security_wages'] += $this->safeFloat($fields, 'social_security_wages');
        $totals['w2_social_security_tax'] += $this->safeFloat($fields, 'social_security_tax');
        $totals['w2_medicare_wages'] += $this->safeFloat($fields, 'medicare_wages');
        $totals['w2_medicare_tax'] += $this->safeFloat($fields, 'medicare_tax');
    }

    private function aggregate1099NEC(array $fields, array &$totals): void
    {
        $totals['nec_1099_income'] += $this->safeFloat($fields, 'nonemployee_compensation');
        $totals['total_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
        $totals['total_state_withheld'] += $this->safeFloat($fields, 'state_tax_withheld');
    }

    private function aggregate1099INT(array $fields, array &$totals): void
    {
        $totals['int_1099_income'] += $this->safeFloat($fields, 'interest_income');
        $totals['total_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
        $totals['total_state_withheld'] += $this->safeFloat($fields, 'state_tax_withheld');
    }

    private function aggregate1099Misc(array $fields, array &$totals): void
    {
        $totals['misc_1099_income'] += $this->safeFloat($fields, 'total_amount');
        $totals['total_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
        $totals['total_state_withheld'] += $this->safeFloat($fields, 'state_tax_withheld');
    }

    private function aggregate1099DIV(array $fields, array &$totals): void
    {
        $totals['div_1099_ordinary'] += $this->safeFloat($fields, 'ordinary_dividends');
        $totals['div_1099_qualified'] += $this->safeFloat($fields, 'qualified_dividends');
        $totals['div_1099_capital_gain'] += $this->safeFloat($fields, 'capital_gain_distributions');
        $totals['total_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
    }

    private function aggregate1099K(array $fields, array &$totals): void
    {
        $totals['k_1099_gross'] += $this->safeFloat($fields, 'gross_amount');
        $totals['total_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
        $totals['total_state_withheld'] += $this->safeFloat($fields, 'state_tax_withheld');
    }

    private function aggregate1099S(array $fields, array &$totals): void
    {
        $totals['s_1099_proceeds'] += $this->safeFloat($fields, 'gross_proceeds');
    }

    private function aggregate1099R(array $fields, array &$totals): void
    {
        $totals['r_1099_gross_distribution'] += $this->safeFloat($fields, 'gross_distribution');
        $totals['r_1099_taxable'] += $this->safeFloat($fields, 'taxable_amount');
        $totals['total_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
        $totals['total_state_withheld'] += $this->safeFloat($fields, 'state_tax_withheld');
    }

    private function aggregate1099G(array $fields, array &$totals): void
    {
        $totals['g_1099_unemployment'] += $this->safeFloat($fields, 'unemployment_compensation');
        $totals['g_1099_state_refund'] += $this->safeFloat($fields, 'state_tax_refund');
        $totals['total_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
    }

    private function aggregate1099B(array $fields, array &$totals): void
    {
        $totals['b_1099_proceeds'] += $this->safeFloat($fields, 'proceeds');
        $totals['b_1099_cost_basis'] += $this->safeFloat($fields, 'cost_basis');
        $totals['b_1099_gain_loss'] += $this->safeFloat($fields, 'proceeds') - $this->safeFloat($fields, 'cost_basis');
        $totals['total_federal_withheld'] += $this->safeFloat($fields, 'federal_tax_withheld');
    }

    private function aggregate1098(array $fields, array &$totals): void
    {
        $totals['mortgage_1098_interest'] += $this->safeFloat($fields, 'mortgage_interest');
        $totals['mortgage_1098_property_tax'] += $this->safeFloat($fields, 'property_tax');
        $totals['mortgage_1098_points'] += $this->safeFloat($fields, 'points_paid');
    }

    /**
     * Map deduction categories to IRS Schedule C/A lines via the normalizer.
     */
    protected function mapToScheduleC(array $categories): array
    {
        return $this->normalizer->mapCategoriesToLines($categories);
    }

    /**
     * Generate the Excel workbook with multiple tabs.
     */
    protected function generateExcel(array $data, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle("SpendifiAI Tax Export — {$data['year']}")
            ->setCreator('SpendifiAI');

        $this->createSummarySheet($spreadsheet, $data);
        $this->createIncomeDocumentsSheet($spreadsheet, $data);
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
        $ws->setCellValue('A1', "SpendifiAI Tax Export — {$data['year']}");
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

        // Income section (from Tax Vault documents)
        if (($s['vault_total_income'] ?? 0) > 0) {
            $ws->setCellValue("A{$row}", 'INCOME SUMMARY (from Tax Documents)');
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('1a5276'));
            $row++;

            $blueFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'e3f2fd']];
            $incomeRows = [];
            if (($s['vault_total_w2_wages'] ?? 0) > 0) {
                $incomeRows[] = ['W-2 Wages', $s['vault_total_w2_wages']];
            }
            if (($s['vault_total_1099_nec_income'] ?? 0) > 0) {
                $incomeRows[] = ['1099-NEC (Self-Employment)', $s['vault_total_1099_nec_income']];
            }
            if (($s['vault_total_1099_int_income'] ?? 0) > 0) {
                $incomeRows[] = ['1099-INT (Interest)', $s['vault_total_1099_int_income']];
            }
            foreach ($incomeRows as [$label, $val]) {
                $ws->setCellValue("A{$row}", $label);
                $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(10);
                $ws->setCellValue("B{$row}", $val);
                $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $row++;
            }
            $ws->setCellValue("A{$row}", 'Total Gross Income');
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
            $ws->setCellValue("B{$row}", $s['vault_total_income']);
            $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $ws->getStyle("B{$row}")->getFont()->setBold(true)->setSize(13)->setColor(new Color('1565c0'));
            $ws->getStyle("A{$row}")->getFill()->applyFromArray($blueFill);
            $ws->getStyle("B{$row}")->getFill()->applyFromArray($blueFill);
            $row++;

            if (($s['vault_total_federal_withheld'] ?? 0) > 0) {
                $ws->setCellValue("A{$row}", 'Federal Tax Withheld');
                $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(10);
                $ws->setCellValue("B{$row}", $s['vault_total_federal_withheld']);
                $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $row++;
            }
            if (($s['vault_total_w2_state_withheld'] ?? 0) > 0) {
                $ws->setCellValue("A{$row}", 'State Tax Withheld');
                $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(10);
                $ws->setCellValue("B{$row}", $s['vault_total_w2_state_withheld']);
                $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $row++;
            }
            $row++;
        }

        $greenFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'e8f5e9']];
        $summaryRows = [
            ['Total Deductible (Bank Transactions)', $s['total_deductible_transactions'], false],
            ['Total Deductible (Email Order Items)', $s['total_deductible_items'], false],
            ['Grand Total Deductible', $s['grand_total_deductible'], true],
            ['Estimated Tax Savings', $s['estimated_tax_savings'], true],
        ];

        // Add mortgage interest if present
        if (($s['vault_total_1098_mortgage_interest'] ?? 0) > 0) {
            $summaryRows[] = ['Mortgage Interest (1098)', $s['vault_total_1098_mortgage_interest'], false];
        }
        if (($s['vault_total_1098_property_tax'] ?? 0) > 0) {
            $summaryRows[] = ['Property Tax (1098)', $s['vault_total_1098_property_tax'], false];
        }

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

    protected function createIncomeDocumentsSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $vaultDocs = $data['vault_documents'] ?? [];
        if (empty($vaultDocs)) {
            return; // No vault documents — skip sheet
        }

        $ws = $spreadsheet->createSheet();
        $ws->setTitle('Income & Tax Documents');
        $ws->getTabColor()->setRGB('1565c0');

        $ws->mergeCells('A1:H1');
        $ws->setCellValue('A1', "Income & Tax Documents — {$data['year']}");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('1565c0'));

        $ws->setCellValue('A2', 'Extracted from uploaded W-2, 1099, 1098, and other tax documents');
        $ws->getStyle('A2')->getFont()->setSize(10)->setItalic(true)->setColor(new Color('666666'));

        // Totals banner
        $vt = $data['vault_totals'] ?? [];
        $row = 4;

        $ws->setCellValue("A{$row}", 'INCOME TOTALS');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('1565c0'));
        $row++;

        $blueFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'e3f2fd']];
        $totalsDisplay = [
            ['W-2 Wages', $vt['w2_wages'] ?? 0],
            ['1099-NEC (Self-Employment)', $vt['nec_1099_income'] ?? 0],
            ['1099-INT (Interest)', $vt['int_1099_income'] ?? 0],
            ['1099-MISC', $vt['misc_1099_income'] ?? 0],
            ['1099-DIV (Dividends)', $vt['div_1099_income'] ?? 0],
            ['Other 1099 Income', $vt['other_1099_income'] ?? 0],
        ];
        foreach ($totalsDisplay as [$label, $val]) {
            if ($val > 0) {
                $ws->setCellValue("A{$row}", $label);
                $ws->getStyle("A{$row}")->getFont()->setBold(true);
                $ws->setCellValue("B{$row}", $val);
                $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $row++;
            }
        }

        // Grand total income
        $ws->setCellValue("A{$row}", 'Total Gross Income');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $ws->setCellValue("B{$row}", $vt['total_income'] ?? 0);
        $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $ws->getStyle("B{$row}")->getFont()->setBold(true)->setSize(13)->setColor(new Color('1565c0'));
        $ws->getStyle("A{$row}")->getFill()->applyFromArray($blueFill);
        $ws->getStyle("B{$row}")->getFill()->applyFromArray($blueFill);
        $row += 2;

        // Withholding totals
        $ws->setCellValue("A{$row}", 'TAX WITHHOLDING');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('1565c0'));
        $row++;
        $withholdingRows = [
            ['Federal Tax Withheld', $vt['total_federal_withheld'] ?? 0],
            ['State Tax Withheld', $vt['total_state_withheld'] ?? 0],
            ['Social Security Tax', $vt['w2_social_security_tax'] ?? 0],
            ['Medicare Tax', $vt['w2_medicare_tax'] ?? 0],
        ];
        foreach ($withholdingRows as [$label, $val]) {
            if ($val > 0) {
                $ws->setCellValue("A{$row}", $label);
                $ws->getStyle("A{$row}")->getFont()->setBold(true);
                $ws->setCellValue("B{$row}", $val);
                $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $row++;
            }
        }

        // Mortgage / deduction items
        if (($vt['mortgage_1098_interest'] ?? 0) > 0 || ($vt['mortgage_1098_property_tax'] ?? 0) > 0) {
            $row++;
            $ws->setCellValue("A{$row}", 'SCHEDULE A DEDUCTIONS (from 1098)');
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('2e7d32'));
            $row++;
            if (($vt['mortgage_1098_interest'] ?? 0) > 0) {
                $ws->setCellValue("A{$row}", 'Mortgage Interest');
                $ws->setCellValue("B{$row}", $vt['mortgage_1098_interest']);
                $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $row++;
            }
            if (($vt['mortgage_1098_property_tax'] ?? 0) > 0) {
                $ws->setCellValue("A{$row}", 'Property Tax');
                $ws->setCellValue("B{$row}", $vt['mortgage_1098_property_tax']);
                $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $row++;
            }
        }

        // Individual document details
        $row += 2;
        $ws->setCellValue("A{$row}", 'DOCUMENT DETAILS');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->setColor(new Color('1565c0'));
        $row++;

        // Group by category
        $grouped = [];
        foreach ($vaultDocs as $doc) {
            $cat = $doc['category_label'] ?? $doc['category'] ?? 'Other';
            $grouped[$cat][] = $doc;
        }

        foreach ($grouped as $catLabel => $docs) {
            $ws->setCellValue("A{$row}", strtoupper($catLabel));
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11)->setColor(new Color('37474f'));
            $ws->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('eceff1');
            $row++;

            foreach ($docs as $doc) {
                $ws->setCellValue("A{$row}", '  '.$doc['filename']);
                $ws->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9)->setColor(new Color('666666'));
                $row++;

                foreach ($doc['fields'] as $fieldName => $field) {
                    $label = ucwords(str_replace('_', ' ', $fieldName));
                    $ws->setCellValue("B{$row}", $label);
                    $ws->getStyle("B{$row}")->getFont()->setSize(10);

                    $val = $this->maskFieldValue($fieldName, $field['value']);
                    if (is_numeric($val) && ! $this->isSensitiveField($fieldName)) {
                        $ws->setCellValue("C{$row}", (float) $val);
                        $ws->getStyle("C{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
                    } else {
                        $ws->setCellValue("C{$row}", $val);
                    }

                    // Confidence indicator
                    $conf = $field['confidence'] ?? 0;
                    $ws->setCellValue("D{$row}", round($conf * 100).'%');
                    if ($conf >= 0.95) {
                        $ws->getStyle("D{$row}")->getFont()->setColor(new Color('2e7d32'));
                    } elseif ($conf >= 0.80) {
                        $ws->getStyle("D{$row}")->getFont()->setColor(new Color('f57f17'));
                    } else {
                        $ws->getStyle("D{$row}")->getFont()->setColor(new Color('c62828'));
                    }

                    $row++;
                }
                $row++; // Spacer between documents
            }
        }

        $ws->getColumnDimension('A')->setWidth(40);
        $ws->getColumnDimension('B')->setWidth(30);
        $ws->getColumnDimension('C')->setWidth(20);
        $ws->getColumnDimension('D')->setWidth(10);
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

        $ws->mergeCells('A1:M1');
        $ws->setCellValue('A1', "Complete Deductible Transaction Detail — {$data['year']}");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('e65100'));

        $txCount = count($data['deductible_transactions'] ?? []);
        $itemCount = count($data['deductible_items'] ?? []);
        $ws->setCellValue('A2', "{$txCount} bank transactions + {$itemCount} itemized email receipts");
        $ws->getStyle('A2')->getFont()->setSize(10)->setItalic(true)->setColor(new Color('666666'));

        $headers = ['Date', 'Merchant', 'Description', 'Amount', 'Category', 'Tax Category',
            'Type', 'Account', 'Account Purpose', 'Business Entity', 'Confidence', 'Verified', 'Donation Note'];
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
            if (! empty($tx['donation_note'])) {
                $ws->setCellValue("M{$row}", $tx['donation_note']);
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
            'Business Name', 'AI Confidence', 'User Confirmed', 'Donation Note',
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
                $tx['donation_note'] ?? '',
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
                '',
            ]);
        }

        // Vault document income/withholding rows
        foreach ($data['vault_documents'] ?? [] as $doc) {
            $cat = $doc['category_label'] ?? $doc['category'] ?? '';
            $fields = $doc['fields'] ?? [];

            // Build a summary description from key fields
            $source = '';
            foreach (['employer_name', 'payer_name', 'lender_name', 'issuer_name'] as $nameField) {
                if (isset($fields[$nameField]['value'])) {
                    $source = $fields[$nameField]['value'];
                    break;
                }
            }

            // Write each monetary field as a row
            foreach ($fields as $fieldName => $field) {
                $val = $field['value'] ?? null;
                if (! is_numeric($val) || $this->isSensitiveField($fieldName)) {
                    continue;
                }
                $label = ucwords(str_replace('_', ' ', $fieldName));
                fputcsv($fp, [
                    $data['year'].'-01-01',
                    $source,
                    "{$cat}: {$label}",
                    $val,
                    $cat,
                    $cat,
                    'income',
                    'Tax Document',
                    'vault',
                    '',
                    round(($field['confidence'] ?? 0) * 100).'%',
                    ($field['verified'] ?? false) ? 'Yes' : 'AI',
                    "Source: {$doc['filename']}",
                ]);
            }
        }

        fclose($fp);
    }

    /**
     * Generate TXF (Tax Exchange Format v042) file for TurboTax / H&R Block / Lacerte.
     */
    public function generateTXF(array $data, string $path): void
    {
        $lines = [];

        // TXF header
        $lines[] = 'V042';
        $lines[] = 'ASpendifiAI';
        $lines[] = 'D'.now()->format('m/d/Y');

        // Aggregate by normalized IRS line
        $lineAggregates = [];
        foreach ($data['deductible_transactions'] ?? [] as $tx) {
            $cat = $tx['tax_category'] ?? 'Uncategorized';
            $normalized = $this->normalizer->normalize($cat);

            // Only Schedule C lines go into TXF
            if ($normalized['schedule'] !== 'C') {
                continue;
            }

            $key = $normalized['line'];
            if (! isset($lineAggregates[$key])) {
                $lineAggregates[$key] = [
                    'line' => $normalized['line'],
                    'label' => $normalized['label'],
                    'total' => 0,
                    'descriptions' => [],
                ];
            }

            $lineAggregates[$key]['total'] += (float) $tx['amount'];
            $desc = $tx['merchant'].($tx['description'] ? ': '.$tx['description'] : '');
            $lineAggregates[$key]['descriptions'][] = $desc;
        }

        // Write each line as a TXF record
        foreach ($lineAggregates as $agg) {
            $ref = $this->normalizer->getTxfRefNumber($agg['line']);
            if (! $ref) {
                continue;
            }

            $lines[] = '^';
            $lines[] = 'TD';
            $lines[] = $ref;
            $lines[] = 'C1';
            $lines[] = 'L1';
            $lines[] = '$-'.number_format($agg['total'], 2, '.', '');

            // Line 27a and 30 use format 3 (amount + description required by IRS)
            if (in_array($agg['line'], ['27a', '30'])) {
                $lines[] = 'P'.$agg['label'];
                $uniqueDescs = array_unique(array_slice($agg['descriptions'], 0, 5));
                $lines[] = 'X'.implode('; ', $uniqueDescs);
            } else {
                $lines[] = 'P'.$agg['label'];
            }
        }

        // Vault document income records
        $vt = $data['vault_totals'] ?? [];

        // W-2 wages (TXF ref N301 = Wages, salaries, tips)
        if (($vt['w2_wages'] ?? 0) > 0) {
            $lines[] = '^';
            $lines[] = 'TD';
            $lines[] = 'N301';
            $lines[] = 'C1';
            $lines[] = 'L1';
            $lines[] = '$'.number_format($vt['w2_wages'], 2, '.', '');
            $lines[] = 'PWages and Salaries (W-2)';
        }

        // 1099-NEC self-employment (TXF ref N553 = Schedule C gross receipts)
        if (($vt['nec_1099_income'] ?? 0) > 0) {
            $lines[] = '^';
            $lines[] = 'TD';
            $lines[] = 'N553';
            $lines[] = 'C1';
            $lines[] = 'L1';
            $lines[] = '$'.number_format($vt['nec_1099_income'], 2, '.', '');
            $lines[] = 'P1099-NEC Self-Employment Income';
        }

        // 1099-INT interest (TXF ref N310 = Interest income)
        if (($vt['int_1099_income'] ?? 0) > 0) {
            $lines[] = '^';
            $lines[] = 'TD';
            $lines[] = 'N310';
            $lines[] = 'C1';
            $lines[] = 'L1';
            $lines[] = '$'.number_format($vt['int_1099_income'], 2, '.', '');
            $lines[] = 'PInterest Income (1099-INT)';
        }

        // 1099-DIV dividends (TXF ref N320 = Ordinary dividends)
        if (($vt['div_1099_ordinary'] ?? 0) > 0) {
            $lines[] = '^';
            $lines[] = 'TD';
            $lines[] = 'N320';
            $lines[] = 'C1';
            $lines[] = 'L1';
            $lines[] = '$'.number_format($vt['div_1099_ordinary'], 2, '.', '');
            $lines[] = 'POrdinary Dividends (1099-DIV)';
        }

        // 1098 mortgage interest (TXF ref N292 = Schedule A mortgage interest)
        if (($vt['mortgage_1098_interest'] ?? 0) > 0) {
            $lines[] = '^';
            $lines[] = 'TD';
            $lines[] = 'N292';
            $lines[] = 'C1';
            $lines[] = 'L1';
            $lines[] = '$-'.number_format($vt['mortgage_1098_interest'], 2, '.', '');
            $lines[] = 'PMortgage Interest (1098)';
        }

        // 1098 property tax (TXF ref N290 = Schedule A SALT)
        if (($vt['mortgage_1098_property_tax'] ?? 0) > 0) {
            $lines[] = '^';
            $lines[] = 'TD';
            $lines[] = 'N290';
            $lines[] = 'C1';
            $lines[] = 'L1';
            $lines[] = '$-'.number_format($vt['mortgage_1098_property_tax'], 2, '.', '');
            $lines[] = 'PProperty Tax (1098)';
        }

        // Federal withholding (TXF ref N340 = Federal tax withheld)
        if (($vt['total_federal_withheld'] ?? 0) > 0) {
            $lines[] = '^';
            $lines[] = 'TD';
            $lines[] = 'N340';
            $lines[] = 'C1';
            $lines[] = 'L1';
            $lines[] = '$'.number_format($vt['total_federal_withheld'], 2, '.', '');
            $lines[] = 'PFederal Income Tax Withheld';
        }

        $lines[] = '^';

        file_put_contents($path, implode("\r\n", $lines));
    }

    /**
     * Generate QuickBooks Online CSV (3-column format).
     */
    public function generateQuickBooksCSV(array $data, string $path): void
    {
        $fp = fopen($path, 'w');

        fputcsv($fp, ['Date', 'Description', 'Amount']);

        foreach ($data['deductible_transactions'] ?? [] as $tx) {
            $desc = $tx['merchant'];
            if (! empty($tx['description']) && $tx['description'] !== $tx['merchant']) {
                $desc .= ' - '.$tx['description'];
            }

            fputcsv($fp, [
                date('m/d/Y', strtotime($tx['date'])),
                $desc,
                '-'.number_format((float) $tx['amount'], 2, '.', ''),
            ]);
        }

        // Vault income entries
        foreach ($data['vault_documents'] ?? [] as $doc) {
            $fields = $doc['fields'] ?? [];
            $source = $fields['employer_name']['value']
                ?? $fields['payer_name']['value']
                ?? $fields['pse_name']['value']
                ?? $fields['lender_name']['value']
                ?? $doc['filename'] ?? '';

            foreach ($fields as $fieldName => $field) {
                $val = $field['value'] ?? null;
                if (! is_numeric($val) || $this->isSensitiveField($fieldName)) {
                    continue;
                }
                $label = ucwords(str_replace('_', ' ', $fieldName));
                fputcsv($fp, [
                    "01/01/{$data['year']}",
                    "{$doc['category_label']}: {$source} - {$label}",
                    number_format((float) $val, 2, '.', ''),
                ]);
            }
        }

        fclose($fp);
    }

    /**
     * Generate OFX (Open Financial Exchange v2.2) file for Xero / Wave / accounting software.
     */
    public function generateOFX(array $data, string $path): void
    {
        $year = $data['year'];
        $now = now()->format('YmdHis');
        $startDate = "{$year}0101120000";
        $endDate = "{$year}1231120000";

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<?OFX OFXHEADER="200" VERSION="220" SECURITY="NONE" OLDFILEUID="NONE" NEWFILEUID="NONE"?>'."\n";
        $xml .= "<OFX>\n";
        $xml .= "  <SIGNONMSGSRSV1>\n";
        $xml .= "    <SONRS>\n";
        $xml .= "      <STATUS><CODE>0</CODE><SEVERITY>INFO</SEVERITY></STATUS>\n";
        $xml .= "      <DTSERVER>{$now}</DTSERVER>\n";
        $xml .= "      <LANGUAGE>ENG</LANGUAGE>\n";
        $xml .= "      <FI><ORG>SpendifiAI</ORG></FI>\n";
        $xml .= "    </SONRS>\n";
        $xml .= "  </SIGNONMSGSRSV1>\n";
        $xml .= "  <BANKMSGSRSV1>\n";
        $xml .= "    <STMTTRNRS>\n";
        $xml .= "      <STATUS><CODE>0</CODE><SEVERITY>INFO</SEVERITY></STATUS>\n";
        $xml .= "      <STMTRS>\n";
        $xml .= "        <CURDEF>USD</CURDEF>\n";
        $xml .= "        <BANKACCTFROM>\n";
        $xml .= "          <BANKID>000000000</BANKID>\n";
        $xml .= "          <ACCTID>SPENDIFIAI</ACCTID>\n";
        $xml .= "          <ACCTTYPE>CHECKING</ACCTTYPE>\n";
        $xml .= "        </BANKACCTFROM>\n";
        $xml .= "        <BANKTRANLIST>\n";
        $xml .= "          <DTSTART>{$startDate}</DTSTART>\n";
        $xml .= "          <DTEND>{$endDate}</DTEND>\n";

        $counter = 1;
        foreach ($data['deductible_transactions'] ?? [] as $tx) {
            $date = date('YmdHis', strtotime($tx['date']));
            $amount = '-'.number_format((float) $tx['amount'], 2, '.', '');
            $fitid = $year.str_pad((string) $counter, 8, '0', STR_PAD_LEFT);
            $name = htmlspecialchars(substr($tx['merchant'], 0, 32), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $memo = htmlspecialchars(substr($tx['description'] ?? '', 0, 80), ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "          <STMTTRN>\n";
            $xml .= "            <TRNTYPE>DEBIT</TRNTYPE>\n";
            $xml .= "            <DTPOSTED>{$date}</DTPOSTED>\n";
            $xml .= "            <TRNAMT>{$amount}</TRNAMT>\n";
            $xml .= "            <FITID>{$fitid}</FITID>\n";
            $xml .= "            <NAME>{$name}</NAME>\n";
            if ($memo) {
                $xml .= "            <MEMO>{$memo}</MEMO>\n";
            }
            $xml .= "          </STMTTRN>\n";

            $counter++;
        }

        // Vault document income entries
        foreach ($data['vault_documents'] ?? [] as $doc) {
            $fields = $doc['fields'] ?? [];
            $source = $fields['employer_name']['value']
                ?? $fields['payer_name']['value']
                ?? $fields['pse_name']['value']
                ?? $doc['filename'] ?? 'Tax Document';
            $catLabel = $doc['category_label'] ?? $doc['category'] ?? '';

            // Pick the primary monetary field per document type
            $amount = match ($doc['category'] ?? '') {
                'w2' => $this->safeFloat($fields, 'wages'),
                '1099_nec' => $this->safeFloat($fields, 'nonemployee_compensation'),
                '1099_int' => $this->safeFloat($fields, 'interest_income'),
                '1099_k' => $this->safeFloat($fields, 'gross_amount'),
                '1099_div' => $this->safeFloat($fields, 'ordinary_dividends'),
                '1099_r' => $this->safeFloat($fields, 'taxable_amount'),
                '1099_g' => $this->safeFloat($fields, 'unemployment_compensation'),
                '1099_s' => $this->safeFloat($fields, 'gross_proceeds'),
                default => $this->safeFloat($fields, 'total_amount'),
            };

            if ($amount > 0) {
                $date = "{$year}0115120000";
                $fitid = $year.str_pad((string) $counter, 8, '0', STR_PAD_LEFT);
                $name = htmlspecialchars(substr("{$catLabel}: {$source}", 0, 32), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $memo = htmlspecialchars("Income from {$catLabel}", ENT_XML1 | ENT_QUOTES, 'UTF-8');

                $xml .= "          <STMTTRN>\n";
                $xml .= "            <TRNTYPE>CREDIT</TRNTYPE>\n";
                $xml .= "            <DTPOSTED>{$date}</DTPOSTED>\n";
                $xml .= '            <TRNAMT>'.number_format($amount, 2, '.', '')."</TRNAMT>\n";
                $xml .= "            <FITID>{$fitid}</FITID>\n";
                $xml .= "            <NAME>{$name}</NAME>\n";
                $xml .= "            <MEMO>{$memo}</MEMO>\n";
                $xml .= "          </STMTTRN>\n";

                $counter++;
            }
        }

        $xml .= "        </BANKTRANLIST>\n";
        $xml .= "      </STMTRS>\n";
        $xml .= "    </STMTTRNRS>\n";
        $xml .= "  </BANKMSGSRSV1>\n";
        $xml .= "</OFX>\n";

        file_put_contents($path, $xml);
    }

    /**
     * Get the SpendifiAI icon as a base64-encoded data URI for PDF embedding.
     */
    protected function getLogoBase64(): string
    {
        $logoPath = public_path('images/spendifiai-icon.png');
        if (! file_exists($logoPath)) {
            return '';
        }

        $contents = file_get_contents($logoPath);

        return 'data:image/png;base64,'.base64_encode($contents);
    }
}
