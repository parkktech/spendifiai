<?php

namespace App\Services\Support;

/**
 * Unified income type classification logic.
 * Consolidates IncomeDetectorService and TaxDocumentIntelligenceService classification.
 *
 * Supports two modes:
 * - distinguishDividends=false (default): 'Dividends'→'interest' (IncomeDetectorService style)
 * - distinguishDividends=true: 'Dividends'→'dividend' (TaxDocumentIntelligenceService style)
 */
class IncomeTypeClassifier
{
    /**
     * Plaid detailed category to income type mapping.
     */
    protected const array PLAID_TYPE_MAP = [
        'INCOME_WAGES' => 'employment',
        'INCOME_SALARY' => 'employment',
        'INCOME_DIVIDENDS' => 'interest', // May map to 'dividend' if distinguishDividends
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

    /**
     * Plaid primary category to income type fallback.
     */
    protected const array PLAID_PRIMARY_MAP = [
        'INCOME' => 'employment',
        'TRANSFER_IN' => 'transfer',
    ];

    /**
     * AI/user category to income type mapping.
     */
    protected const array AI_TYPE_MAP = [
        'Salary & Wages' => 'employment',
        'Income (Salary)' => 'employment',
        'Payroll' => 'employment',
        'Direct Deposit' => 'employment',
        'Contractor Income' => 'contractor',
        'Freelance Income' => 'contractor',
        'Income (Freelance)' => 'contractor',
        'Income (1099)' => 'contractor',
        'Interest Income' => 'interest',
        'Dividends' => 'interest', // May map to 'dividend' if distinguishDividends
        'Investment Income' => 'interest',
        'Income (Investment)' => 'interest',
        'Rental Income' => 'other',
        'Refund' => 'other',
        'Tax Refund' => 'other',
    ];

    public function __construct(private bool $distinguishDividends = false) {}

    /**
     * Classify a transaction's income type.
     *
     * @param  object  $tx  Transaction object with plaid_category, plaid_detailed_category, merchant_name, resolved_category
     */
    public function classify(object $tx): string
    {
        // 1. Plaid detailed category (most specific)
        if ($tx->plaid_detailed_category ?? false) {
            $mapped = $this->mapPlaidDetailed($tx->plaid_detailed_category);
            if ($mapped) {
                return $mapped;
            }
        }

        // 2. Plaid primary category
        if ($tx->plaid_category ?? false) {
            if (isset(self::PLAID_PRIMARY_MAP[$tx->plaid_category])) {
                return self::PLAID_PRIMARY_MAP[$tx->plaid_category];
            }
        }

        // 3. AI/user category
        $resolved = $tx->resolved_category ?? null;
        if ($resolved) {
            $mapped = $this->mapAiType($resolved);
            if ($mapped) {
                return $mapped;
            }
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
        if ($this->distinguishDividends && str_contains($merchant, 'DIVIDEND')) {
            return 'dividend';
        }
        if (str_contains($merchant, 'INTEREST')) {
            return 'interest';
        }

        return 'other';
    }

    /**
     * Normalize merchant name for grouping income sources.
     */
    public function normalizeMerchant(?string $name, string $type): string
    {
        if (! $name) {
            return match ($type) {
                'employment' => 'Employment Income',
                'contractor' => 'Contractor Income',
                'interest' => 'Interest Income',
                'dividend' => 'Dividend Income',
                'mortgage' => 'Mortgage Lender',
                'transfer' => 'Peer Transfers',
                default => 'Other Income',
            };
        }

        $upper = strtoupper(trim($name));

        // Group all Zelle transfers together
        if (str_contains($upper, 'ZELLE')) {
            return 'Peer Transfers (Zelle)';
        }
        if (str_contains($upper, 'VENMO')) {
            return 'Peer Transfers (Venmo)';
        }
        if (str_contains($upper, 'CASHAPP') || str_contains($upper, 'CASH APP')) {
            return 'Peer Transfers (Cash App)';
        }

        // Clean up merchant name
        $clean = preg_replace('/[#*]+\d*\s*$/', '', $upper);
        $clean = preg_replace('/\s+\d{3,}$/', '', $clean);
        $clean = preg_replace('/\s+(DIRECT|DIR)\s*(DEP|DEPOSIT).*$/i', '', $clean);
        $clean = preg_replace('/\s+PAYROLL.*$/i', '', $clean);
        $clean = preg_replace('/\s+SALARY.*$/i', '', $clean);

        return trim($clean) ?: $name;
    }

    /**
     * Map Plaid detailed category to income type, handling dividend distinction.
     */
    private function mapPlaidDetailed(string $category): ?string
    {
        $mapped = self::PLAID_TYPE_MAP[$category] ?? null;
        if ($mapped === 'interest' && $category === 'INCOME_DIVIDENDS' && $this->distinguishDividends) {
            return 'dividend';
        }

        return $mapped;
    }

    /**
     * Map AI/user category to income type, handling dividend distinction.
     */
    private function mapAiType(string $category): ?string
    {
        $mapped = self::AI_TYPE_MAP[$category] ?? null;
        if ($mapped === 'interest' && $category === 'Dividends' && $this->distinguishDividends) {
            return 'dividend';
        }

        return $mapped;
    }
}
