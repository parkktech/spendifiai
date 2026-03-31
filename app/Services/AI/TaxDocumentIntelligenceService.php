<?php

namespace App\Services\AI;

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TaxDocumentIntelligenceService
{
    /**
     * Plaid detailed category to income type mapping.
     * Replicates IncomeDetectorService maps with dividend distinction.
     */
    protected array $plaidTypeMap = [
        'INCOME_WAGES' => 'employment',
        'INCOME_SALARY' => 'employment',
        'INCOME_DIVIDENDS' => 'dividend',
        'INCOME_INTEREST_EARNED' => 'interest',
        'INCOME_RETIREMENT_PENSION' => 'employment',
        'INCOME_TAX_REFUND' => 'other',
        'INCOME_UNEMPLOYMENT' => 'employment',
        'INCOME_OTHER_INCOME' => 'other',
        'TRANSFER_IN_ACCOUNT_TRANSFER' => 'transfer',
        'TRANSFER_IN_CASH_ADVANCES_AND_LOANS' => 'other',
        'TRANSFER_IN_DEPOSIT' => 'other',
        'TRANSFER_IN_INVESTMENT_AND_RETIREMENT_FUNDS' => 'other',
        'TRANSFER_IN_SAVINGS' => 'transfer',
        'TRANSFER_IN_TRANSFER_IN_FROM_APPS' => 'transfer',
    ];

    protected array $plaidPrimaryMap = [
        'INCOME' => 'employment',
        'TRANSFER_IN' => 'transfer',
    ];

    protected array $aiTypeMap = [
        'Salary & Wages' => 'employment',
        'Payroll' => 'employment',
        'Direct Deposit' => 'employment',
        'Contractor Income' => 'contractor',
        'Freelance Income' => 'contractor',
        'Interest Income' => 'interest',
        'Dividends' => 'dividend',
        'Investment Income' => 'interest',
        'Rental Income' => 'other',
        'Refund' => 'other',
        'Tax Refund' => 'other',
    ];

    /**
     * Run full intelligence analysis for a user and tax year.
     *
     * @return array{missing_documents: array, anomalies: array, transaction_links: array}
     */
    public function analyze(int $userId, int $taxYear): array
    {
        $cacheKey = "tax_intelligence_{$userId}_{$taxYear}";
        $cacheHours = config('spendifiai.intelligence.cache_hours', 4);

        return Cache::remember($cacheKey, now()->addHours($cacheHours), function () use ($userId, $taxYear) {
            return [
                'missing_documents' => $this->detectMissingDocuments($userId, $taxYear),
                'anomalies' => $this->detectAnomalies($userId, $taxYear),
                'transaction_links' => $this->linkTransactions($userId, $taxYear),
            ];
        });
    }

    /**
     * Invalidate cached intelligence results.
     */
    public static function invalidateCache(int $userId, int $taxYear): void
    {
        Cache::forget("tax_intelligence_{$userId}_{$taxYear}");
    }

    /**
     * Detect missing tax documents based on transaction patterns.
     */
    protected function detectMissingDocuments(int $userId, int $taxYear): array
    {
        $minThreshold = config('spendifiai.intelligence.min_income_threshold', 600);
        $incomeTypeToDoc = config('spendifiai.intelligence.income_type_to_document', []);
        $expenseTypeToDoc = config('spendifiai.intelligence.expense_type_to_document', []);

        // Get income transactions for the year
        $incomeTransactions = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0)
            ->whereYear('transaction_date', $taxYear)
            ->select('id', 'merchant_name', 'amount', 'transaction_date', 'plaid_category', 'plaid_detailed_category', DB::raw('COALESCE(user_category, ai_category) as resolved_category'))
            ->get();

        // Classify and group income
        $incomeGroups = [];
        foreach ($incomeTransactions as $tx) {
            $type = $this->classifyIncomeType($tx);
            if ($type === 'transfer' || $type === 'other') {
                continue;
            }
            $merchant = $this->normalizeMerchant($tx->merchant_name, $type);
            $key = "{$type}|{$merchant}";
            if (! isset($incomeGroups[$key])) {
                $incomeGroups[$key] = ['type' => $type, 'merchant' => $merchant, 'total' => 0];
            }
            $incomeGroups[$key]['total'] += abs((float) $tx->amount);
        }

        // Get expense transactions for mortgage detection
        $mortgageTransactions = Transaction::where('user_id', $userId)
            ->where('amount', '>', 0)
            ->whereYear('transaction_date', $taxYear)
            ->where(function ($q) {
                $q->where('plaid_category', 'like', '%LOAN_PAYMENTS%')
                    ->orWhere('merchant_name', 'like', '%MORTGAGE%')
                    ->orWhere('merchant_name', 'like', '%HOME LOAN%');
            })
            ->select('id', 'merchant_name', 'amount')
            ->get();

        if ($mortgageTransactions->isNotEmpty()) {
            $mortgageTotal = $mortgageTransactions->sum(fn ($tx) => abs((float) $tx->amount));
            $mortgageMerchant = $this->normalizeMerchant($mortgageTransactions->first()->merchant_name, 'mortgage');
            $incomeGroups["mortgage|{$mortgageMerchant}"] = [
                'type' => 'mortgage',
                'merchant' => $mortgageMerchant,
                'total' => $mortgageTotal,
            ];
        }

        // Check existing documents
        $existingDocs = TaxDocument::forUser($userId)
            ->byYear($taxYear)
            ->pluck('category')
            ->map(fn ($cat) => $cat instanceof TaxDocumentCategory ? $cat->value : $cat)
            ->toArray();

        $allTypeToDoc = array_merge($incomeTypeToDoc, $expenseTypeToDoc);
        $missing = [];

        foreach ($incomeGroups as $group) {
            $expectedCategory = $allTypeToDoc[$group['type']] ?? null;
            if (! $expectedCategory) {
                continue;
            }

            // Skip if below threshold (except mortgage which has no minimum)
            if ($group['type'] !== 'mortgage' && $group['total'] < $minThreshold) {
                continue;
            }

            // Skip if document already exists
            if (in_array($expectedCategory, $existingDocs)) {
                continue;
            }

            $categoryEnum = TaxDocumentCategory::tryFrom($expectedCategory);
            $docLabel = $categoryEnum ? $categoryEnum->label() : strtoupper($expectedCategory);
            $formattedAmount = number_format($group['total'], 2);

            $missing[] = [
                'message' => "Missing {$docLabel} from {$group['merchant']}",
                'details' => "Based on \${$formattedAmount} in {$group['type']} income from {$group['merchant']}, we expect a {$docLabel}.",
                'category' => $expectedCategory,
                'severity' => 'warning',
                'merchant' => $group['merchant'],
                'total_amount' => round($group['total'], 2),
            ];
        }

        return $missing;
    }

    /**
     * Detect anomalies between documents and transactions.
     */
    protected function detectAnomalies(int $userId, int $taxYear): array
    {
        $tolerance = config('spendifiai.intelligence.anomaly_tolerance', 0.05);

        $documents = TaxDocument::forUser($userId)
            ->byYear($taxYear)
            ->byStatus(DocumentStatus::Ready)
            ->whereNotNull('extracted_data')
            ->get();

        $anomalies = [];

        foreach ($documents as $doc) {
            $fields = $doc->extracted_data['fields'] ?? [];
            $category = $doc->category instanceof TaxDocumentCategory ? $doc->category->value : $doc->category;

            $result = match ($category) {
                'w2' => $this->checkW2Anomaly($doc, $fields, $userId, $taxYear, $tolerance),
                '1099_nec' => $this->check1099NECANomaly($doc, $fields, $userId, $taxYear, $tolerance),
                '1098' => $this->check1098Anomaly($doc, $fields, $userId, $taxYear, $tolerance),
                default => null,
            };

            if ($result) {
                $anomalies[] = $result;
            }
        }

        return $anomalies;
    }

    /**
     * Check W-2 wage anomalies.
     * Net pay deposits will normally be LOWER than gross W-2 wages (taxes withheld).
     * Flag when deposits are significantly HIGHER (unreported income) or less than 50% (missing account).
     */
    protected function checkW2Anomaly(TaxDocument $doc, array $fields, int $userId, int $taxYear, float $tolerance): ?array
    {
        $wagesValue = $fields['wages']['value'] ?? $fields['gross_wages']['value'] ?? null;
        if (! $wagesValue) {
            return null;
        }

        $documentAmount = (float) $wagesValue;
        if ($documentAmount <= 0) {
            return null;
        }

        $employerName = $fields['employer_name']['value'] ?? null;

        $depositTotal = $this->getIncomeTotal($userId, $taxYear, 'employment', $employerName);
        if ($depositTotal <= 0) {
            return null;
        }

        // Deposits higher than document amount = possible unreported income
        if ($depositTotal > $documentAmount * (1 + $tolerance)) {
            $variance = ($depositTotal - $documentAmount) / $documentAmount;

            return [
                'message' => "W-2 mismatch for {$employerName}",
                'details' => sprintf(
                    'Bank deposits ($%s) exceed W-2 wages ($%s) by %.0f%%. This may indicate additional unreported income.',
                    number_format($depositTotal, 2),
                    number_format($documentAmount, 2),
                    $variance * 100,
                ),
                'severity' => $variance > 0.20 ? 'alert' : 'warning',
                'document_id' => $doc->id,
            ];
        }

        // Deposits less than 50% of document = possible missing bank account
        if ($depositTotal < $documentAmount * 0.50) {
            return [
                'message' => "W-2 mismatch for {$employerName}",
                'details' => sprintf(
                    'Bank deposits ($%s) are less than 50%% of W-2 wages ($%s). A payroll account may not be connected.',
                    number_format($depositTotal, 2),
                    number_format($documentAmount, 2),
                ),
                'severity' => 'info',
                'document_id' => $doc->id,
            ];
        }

        return null;
    }

    /**
     * Check 1099-NEC anomalies.
     */
    protected function check1099NECANomaly(TaxDocument $doc, array $fields, int $userId, int $taxYear, float $tolerance): ?array
    {
        $compensationValue = $fields['nonemployee_compensation']['value'] ?? $fields['compensation']['value'] ?? null;
        if (! $compensationValue) {
            return null;
        }

        $documentAmount = (float) $compensationValue;
        if ($documentAmount <= 0) {
            return null;
        }

        $payerName = $fields['payer_name']['value'] ?? $fields['company_name']['value'] ?? null;

        $depositTotal = $this->getIncomeTotal($userId, $taxYear, 'contractor', $payerName);
        if ($depositTotal <= 0) {
            return null;
        }

        $variance = abs($depositTotal - $documentAmount) / $documentAmount;

        if ($variance > 0.20) {
            return [
                'message' => "1099-NEC mismatch for {$payerName}",
                'details' => sprintf(
                    'Bank deposits ($%s) vs 1099-NEC ($%s) differ by %.0f%%.',
                    number_format($depositTotal, 2),
                    number_format($documentAmount, 2),
                    $variance * 100,
                ),
                'severity' => 'alert',
                'document_id' => $doc->id,
            ];
        }

        if ($variance > $tolerance) {
            return [
                'message' => "1099-NEC mismatch for {$payerName}",
                'details' => sprintf(
                    'Bank deposits ($%s) vs 1099-NEC ($%s) differ by %.0f%%.',
                    number_format($depositTotal, 2),
                    number_format($documentAmount, 2),
                    $variance * 100,
                ),
                'severity' => 'warning',
                'document_id' => $doc->id,
            ];
        }

        return null;
    }

    /**
     * Check 1098 mortgage interest anomalies.
     */
    protected function check1098Anomaly(TaxDocument $doc, array $fields, int $userId, int $taxYear, float $tolerance): ?array
    {
        $interestValue = $fields['mortgage_interest']['value'] ?? $fields['interest_paid']['value'] ?? null;
        if (! $interestValue) {
            return null;
        }

        $documentAmount = (float) $interestValue;
        if ($documentAmount <= 0) {
            return null;
        }

        // Mortgage payments include principal + interest, so deposits will be higher
        $paymentTotal = Transaction::where('user_id', $userId)
            ->where('amount', '>', 0)
            ->whereYear('transaction_date', $taxYear)
            ->where(function ($q) {
                $q->where('plaid_category', 'like', '%LOAN_PAYMENTS%')
                    ->orWhere('merchant_name', 'like', '%MORTGAGE%')
                    ->orWhere('merchant_name', 'like', '%HOME LOAN%');
            })
            ->sum('amount');

        $paymentTotal = abs((float) $paymentTotal);
        if ($paymentTotal <= 0) {
            return null;
        }

        // Interest should be less than total payments (principal + interest)
        // Flag if interest exceeds payments (impossible) or if payments are much less than interest
        if ($paymentTotal < $documentAmount * (1 - $tolerance)) {
            $variance = ($documentAmount - $paymentTotal) / $documentAmount;

            return [
                'message' => '1098 mortgage interest mismatch',
                'details' => sprintf(
                    'Mortgage payments ($%s) are less than reported interest ($%s). Payments may be missing.',
                    number_format($paymentTotal, 2),
                    number_format($documentAmount, 2),
                ),
                'severity' => $variance > 0.20 ? 'alert' : 'warning',
                'document_id' => $doc->id,
            ];
        }

        return null;
    }

    /**
     * Link transactions to matching tax documents.
     */
    protected function linkTransactions(int $userId, int $taxYear): array
    {
        $documents = TaxDocument::forUser($userId)
            ->byYear($taxYear)
            ->byStatus(DocumentStatus::Ready)
            ->get();

        $links = [];

        foreach ($documents as $doc) {
            $fields = $doc->extracted_data['fields'] ?? [];
            $category = $doc->category instanceof TaxDocumentCategory ? $doc->category->value : $doc->category;

            $matchingTransactions = $this->findMatchingTransactions($userId, $taxYear, $category, $fields);

            if ($matchingTransactions->isEmpty()) {
                continue;
            }

            // Sync with pivot data
            $syncData = [];
            $linkReason = $this->getLinkReason($category);
            foreach ($matchingTransactions as $tx) {
                $syncData[$tx->id] = ['link_reason' => $linkReason];
            }
            $doc->transactions()->sync($syncData);

            $links[] = [
                'document_id' => $doc->id,
                'document_name' => $doc->original_filename,
                'transaction_count' => $matchingTransactions->count(),
                'total_amount' => round($matchingTransactions->sum(fn ($tx) => abs((float) $tx->amount)), 2),
            ];
        }

        return $links;
    }

    /**
     * Find transactions matching a document category.
     */
    protected function findMatchingTransactions(int $userId, int $taxYear, string $category, array $fields): \Illuminate\Support\Collection
    {
        return match ($category) {
            'w2' => $this->findEmploymentTransactions($userId, $taxYear, $fields),
            '1099_nec' => $this->findContractorTransactions($userId, $taxYear, $fields),
            '1099_int' => $this->findInterestTransactions($userId, $taxYear),
            '1098' => $this->findMortgageTransactions($userId, $taxYear),
            default => collect(),
        };
    }

    /**
     * Find employment income transactions matching an employer.
     */
    protected function findEmploymentTransactions(int $userId, int $taxYear, array $fields): \Illuminate\Support\Collection
    {
        $employerName = $fields['employer_name']['value'] ?? null;

        $query = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0)
            ->whereYear('transaction_date', $taxYear);

        if ($employerName) {
            $normalized = strtoupper(trim($employerName));
            $query->where(function ($q) use ($normalized) {
                $q->whereRaw('UPPER(merchant_name) LIKE ?', ["%{$normalized}%"])
                    ->orWhereRaw('UPPER(merchant_normalized) LIKE ?', ["%{$normalized}%"]);
            });
        } else {
            // Fallback: match employment-type income
            $query->where(function ($q) {
                $q->where('plaid_detailed_category', 'like', 'INCOME_WAGES%')
                    ->orWhere('plaid_detailed_category', 'like', 'INCOME_SALARY%')
                    ->orWhere('plaid_category', 'INCOME');
            });
        }

        return $query->get();
    }

    /**
     * Find contractor income transactions.
     */
    protected function findContractorTransactions(int $userId, int $taxYear, array $fields): \Illuminate\Support\Collection
    {
        $payerName = $fields['payer_name']['value'] ?? $fields['company_name']['value'] ?? null;

        $query = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0)
            ->whereYear('transaction_date', $taxYear);

        if ($payerName) {
            $normalized = strtoupper(trim($payerName));
            $query->where(function ($q) use ($normalized) {
                $q->whereRaw('UPPER(merchant_name) LIKE ?', ["%{$normalized}%"])
                    ->orWhereRaw('UPPER(merchant_normalized) LIKE ?', ["%{$normalized}%"]);
            });
        }

        return $query->get();
    }

    /**
     * Find interest income transactions.
     */
    protected function findInterestTransactions(int $userId, int $taxYear): \Illuminate\Support\Collection
    {
        return Transaction::where('user_id', $userId)
            ->where('amount', '<', 0)
            ->whereYear('transaction_date', $taxYear)
            ->where(function ($q) {
                $q->where('plaid_detailed_category', 'INCOME_INTEREST_EARNED')
                    ->orWhere('merchant_name', 'like', '%INTEREST%')
                    ->orWhereIn(DB::raw('COALESCE(user_category, ai_category)'), ['Interest Income', 'Dividends', 'Investment Income']);
            })
            ->get();
    }

    /**
     * Find mortgage payment transactions.
     */
    protected function findMortgageTransactions(int $userId, int $taxYear): \Illuminate\Support\Collection
    {
        return Transaction::where('user_id', $userId)
            ->where('amount', '>', 0)
            ->whereYear('transaction_date', $taxYear)
            ->where(function ($q) {
                $q->where('plaid_category', 'like', '%LOAN_PAYMENTS%')
                    ->orWhere('merchant_name', 'like', '%MORTGAGE%')
                    ->orWhere('merchant_name', 'like', '%HOME LOAN%');
            })
            ->get();
    }

    /**
     * Get total income amount for a type and optional merchant.
     */
    protected function getIncomeTotal(int $userId, int $taxYear, string $incomeType, ?string $merchantName): float
    {
        $transactions = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0)
            ->whereYear('transaction_date', $taxYear)
            ->select('id', 'merchant_name', 'amount', 'plaid_category', 'plaid_detailed_category', DB::raw('COALESCE(user_category, ai_category) as resolved_category'))
            ->get();

        $total = 0;
        foreach ($transactions as $tx) {
            $type = $this->classifyIncomeType($tx);
            if ($type !== $incomeType) {
                continue;
            }

            if ($merchantName) {
                $normalizedMerchant = strtoupper(trim($merchantName));
                $txMerchant = strtoupper(trim($tx->merchant_name ?? ''));
                if (! str_contains($txMerchant, $normalizedMerchant) && ! str_contains($normalizedMerchant, $txMerchant)) {
                    continue;
                }
            }

            $total += abs((float) $tx->amount);
        }

        return $total;
    }

    /**
     * Classify a transaction's income type.
     * Replicates IncomeDetectorService logic with dividend distinction.
     */
    protected function classifyIncomeType(object $tx): string
    {
        // 1. Plaid detailed category (most specific)
        if ($tx->plaid_detailed_category && isset($this->plaidTypeMap[$tx->plaid_detailed_category])) {
            return $this->plaidTypeMap[$tx->plaid_detailed_category];
        }

        // 2. Plaid primary category
        if ($tx->plaid_category && isset($this->plaidPrimaryMap[$tx->plaid_category])) {
            return $this->plaidPrimaryMap[$tx->plaid_category];
        }

        // 3. AI/user category
        $resolved = $tx->resolved_category ?? null;
        if ($resolved && isset($this->aiTypeMap[$resolved])) {
            return $this->aiTypeMap[$resolved];
        }

        // 4. Merchant name heuristics
        $merchant = strtoupper($tx->merchant_name ?? '');
        if (str_contains($merchant, 'PAYROLL') || str_contains($merchant, 'DIRECT DEP')
            || str_contains($merchant, 'SALARY') || str_contains($merchant, 'PAYCHECK')) {
            return 'employment';
        }
        if (str_contains($merchant, 'ZELLE') || str_contains($merchant, 'VENMO')
            || str_contains($merchant, 'CASHAPP') || str_contains($merchant, 'CASH APP')) {
            return 'transfer';
        }
        if (str_contains($merchant, 'DIVIDEND')) {
            return 'dividend';
        }
        if (str_contains($merchant, 'INTEREST')) {
            return 'interest';
        }

        return 'other';
    }

    /**
     * Normalize merchant name for grouping.
     */
    protected function normalizeMerchant(?string $name, string $type): string
    {
        if (! $name) {
            return match ($type) {
                'employment' => 'Employment Income',
                'contractor' => 'Contractor Income',
                'interest' => 'Interest Income',
                'dividend' => 'Dividend Income',
                'mortgage' => 'Mortgage Lender',
                default => 'Other Income',
            };
        }

        $upper = strtoupper(trim($name));

        $clean = preg_replace('/[#*]+\d*\s*$/', '', $upper);
        $clean = preg_replace('/\s+\d{3,}$/', '', $clean);
        $clean = preg_replace('/\s+(DIRECT|DIR)\s*(DEP|DEPOSIT).*$/i', '', $clean);
        $clean = preg_replace('/\s+PAYROLL.*$/i', '', $clean);
        $clean = preg_replace('/\s+SALARY.*$/i', '', $clean);

        return trim($clean) ?: $name;
    }

    /**
     * Get the link reason string for a document category.
     */
    protected function getLinkReason(string $category): string
    {
        return match ($category) {
            'w2' => 'employer_match',
            '1099_nec' => 'contractor_match',
            '1099_int' => 'income_type_match',
            '1099_div' => 'income_type_match',
            '1098' => 'expense_type_match',
            default => 'category_match',
        };
    }
}
