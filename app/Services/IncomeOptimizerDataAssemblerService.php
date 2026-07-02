<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\IncomeOptimizationProfile;
use App\Models\TaxDocument;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserTaxFact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * IncomeOptimizerDataAssemblerService
 *
 * Assembles the per-user IncomeOptimizationProfile snapshot from three sources:
 *   1. UserFinancialProfile — employment flags (filing_status, has_hsa, has_ira, etc.)
 *   2. TaxDocument.extracted_data — grouped by TaxDocumentCategory, status=Ready
 *   3. Transaction — direct calendar-year query (Jan 1–Dec 31), NOT IncomeDetectorService
 *      rolling window (avoids the pitfall where rolling 3-month window gives partial-year totals)
 *
 * ZERO Claude / HTTP calls. Pure DB aggregation.
 *
 * Convention: extracted_data values are float DOLLAR strings (e.g. "72500.00").
 * Always convert to cents: (int) round((float) $value * 100).
 * Store in IncomeOptimizationProfile columns as encrypted cent strings.
 */
class IncomeOptimizerDataAssemblerService
{
    /**
     * Build (or rebuild) the IncomeOptimizationProfile for the given user and tax year.
     * Upserts keyed on user_id + tax_year — safe to call multiple times.
     */
    public function buildProfile(User $user, int $taxYear): IncomeOptimizationProfile
    {
        // Source 1: UserFinancialProfile flags
        $flags = $this->readProfileFlags($user);

        // Source 2: TaxDocument extracted_data (status=Ready, grouped by category)
        [$docData, $docIds, $docCount] = $this->sumFromDocuments($user, $taxYear);

        // Source 3: Bank deposits from direct calendar-year Transaction query
        $bankDepositCents = $this->sumBankDeposits($user, $taxYear);

        // Build staleness hash from current inputs
        $profileHash = $this->computeProfileHash($user->id, $taxYear, $docIds);

        // §A.6.4 additive backfill: estimated_age from the person.birth_year fact when
        // present (taxYear − birth_year). No column/API change — code ignoring the
        // column keeps ignoring it. Merged before the base payload so it only sets a
        // value when a valid birth_year fact exists (otherwise the column is untouched).
        $ageOverride = $this->estimatedAgeFromBirthYear($user->id, $taxYear);

        // Persist (upsert keyed on user_id + tax_year)
        $profile = IncomeOptimizationProfile::updateOrCreate(
            ['user_id' => $user->id, 'tax_year' => $taxYear],
            array_merge($flags, $docData, $ageOverride, [
                'bank_deposit_total' => $bankDepositCents > 0 ? (string) $bankDepositCents : null,
                'data_sources' => [
                    'doc_ids' => $docIds,
                    'transaction_range' => [
                        'start' => Carbon::create($taxYear, 1, 1)->toDateString(),
                        'end' => Carbon::create($taxYear, 12, 31)->toDateString(),
                    ],
                ],
                'doc_count' => $docCount,
                'profile_hash' => $profileHash,
                'built_at' => Carbon::now(),
            ])
        );

        return $profile;
    }

    /**
     * §A.6.4 — estimated_age = taxYear − person.birth_year (confirmed fact only).
     * Returns ['estimated_age' => int] when a valid birth_year fact exists, else []
     * (so updateOrCreate leaves the column untouched).
     *
     * @return array<string, int>
     */
    protected function estimatedAgeFromBirthYear(int $userId, int $taxYear): array
    {
        $fact = UserTaxFact::currentFact($userId, 'person.birth_year', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'person.birth_year');

        if ($fact === null || ! ctype_digit((string) $fact->value)) {
            return [];
        }

        $age = $taxYear - (int) $fact->value;
        if ($age < 0 || $age > 120) {
            return [];
        }

        return ['estimated_age' => $age];
    }

    /**
     * Detect whether the stored snapshot is stale relative to the current inputs.
     * Recomputes the hash from current TaxDocuments and compares to stored hash.
     */
    public function isStale(IncomeOptimizationProfile $profile): bool
    {
        $docIds = TaxDocument::forUser($profile->user_id)
            ->byYear($profile->tax_year)
            ->byStatus(DocumentStatus::Ready)
            ->pluck('id')
            ->toArray();

        $currentHash = $this->computeProfileHash($profile->user_id, $profile->tax_year, $docIds);

        return $currentHash !== $profile->profile_hash;
    }

    // ─── Source 1: UserFinancialProfile flags ────────────────────────────────

    /**
     * Read non-sensitive flags from UserFinancialProfile.
     * Maps profile fields to the IncomeOptimizationProfile column names.
     *
     * @return array<string, mixed>
     */
    protected function readProfileFlags(User $user): array
    {
        $profile = $user->financialProfile ?? null;

        if (! $profile) {
            return [
                'filing_status' => null,
                'has_hsa_eligible_plan' => false,
                'has_ira' => false,
                'ira_type' => null,
                'has_home_office' => false,
                'has_self_employment' => false,
                'employment_type' => null,
                // FACT-CHECK FIX (INT-03): entity/housing gates in Phase 11b detectors need these.
                // Previously omitted — added additively; no existing key shape changes.
                'business_type' => null,
                'housing_status' => null,
            ];
        }

        // Normalise filing_status: UserFinancialProfile uses 'married_jointly', we normalise
        // to 'married_joint' to match TaxRulesEngineService config keys.
        $filingStatus = $this->normaliseFilingStatus($profile->tax_filing_status);

        // Derive has_self_employment from employment_type field
        $selfEmployedTypes = ['self_employed', '1099_contractor', 'business_owner', 'freelancer'];
        $hasSelfEmployment = in_array($profile->employment_type, $selfEmployedTypes, true);

        return [
            'filing_status' => $filingStatus,
            'has_hsa_eligible_plan' => (bool) ($profile->has_hsa ?? false),
            'has_ira' => (bool) ($profile->has_ira ?? false),
            'ira_type' => $profile->ira_type ?? null,
            'has_home_office' => (bool) ($profile->has_home_office ?? false),
            'has_self_employment' => $hasSelfEmployment,
            'employment_type' => $profile->employment_type ?? null,
            // FACT-CHECK FIX (INT-03): additively added; no existing key shape changes.
            // These gate entity/housing detectors in wave 11b (D3/CONTEXT.md).
            'business_type' => $profile->business_type ?? null,
            'housing_status' => $profile->housing_status ?? null,
        ];
    }

    /**
     * Normalise filing status values to match tax-rules.php config keys.
     */
    protected function normaliseFilingStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match ($status) {
            'married_jointly', 'married_filing_jointly' => 'married_joint',
            'married_separately', 'married_filing_separately' => 'married_separate',
            'head_of_household' => 'head_of_household',
            'single' => 'single',
            default => $status,
        };
    }

    // ─── Source 2: TaxDocument.extracted_data ────────────────────────────────

    /**
     * Sum extracted_data fields from Ready TaxDocuments grouped by category.
     * All values in extracted_data are float dollar strings — convert to cents.
     *
     * @return array{0: array<string, string|null>, 1: int[], 2: int}
     *                                                                [0] => column_name → cent string (or null if no docs for that category)
     *                                                                [1] => contributing doc IDs (sorted)
     *                                                                [2] => total doc count
     */
    protected function sumFromDocuments(User $user, int $taxYear): array
    {
        $docs = TaxDocument::forUser($user->id)
            ->byYear($taxYear)
            ->byStatus(DocumentStatus::Ready)
            ->get();

        $totals = [
            'w2_wages' => 0,
            'self_employment_income' => 0,
            'interest_income' => 0,
            'dividend_income' => 0,
            'retirement_distributions' => 0,
            'mortgage_interest' => 0,
            'property_tax_paid' => 0,
            'student_loan_interest' => 0,
            'charitable_contributions' => 0,
            'traditional_401k_ytd' => 0,
            'roth_401k_ytd' => 0,      // [P12] added for PayStub arm
            'ira_ytd' => 0,
            'hsa_ytd' => 0,
        ];

        $hasValue = array_fill_keys(array_keys($totals), false);
        $docIds = [];

        foreach ($docs as $doc) {
            $data = $doc->extracted_data ?? [];
            if (empty($data)) {
                continue;
            }

            $docIds[] = $doc->id;

            switch ($doc->category) {
                case TaxDocumentCategory::W2:
                    if (isset($data['wages'])) {
                        $totals['w2_wages'] += $this->dollarsToCents($data['wages']);
                        $hasValue['w2_wages'] = true;
                    }
                    break;

                case TaxDocumentCategory::NEC_1099:
                    // 1099-NEC: nonemployee compensation
                    $field = $data['nonemployee_compensation'] ?? $data['amount'] ?? null;
                    if ($field !== null) {
                        $totals['self_employment_income'] += $this->dollarsToCents($field);
                        $hasValue['self_employment_income'] = true;
                    }
                    break;

                case TaxDocumentCategory::MISC_1099:
                    // 1099-MISC: other_income, rents, royalties all → SE income
                    foreach (['other_income', 'rents', 'royalties', 'amount'] as $f) {
                        if (isset($data[$f])) {
                            $totals['self_employment_income'] += $this->dollarsToCents($data[$f]);
                            $hasValue['self_employment_income'] = true;
                            break;
                        }
                    }
                    break;

                case TaxDocumentCategory::INT_1099:
                    if (isset($data['interest_income'])) {
                        $totals['interest_income'] += $this->dollarsToCents($data['interest_income']);
                        $hasValue['interest_income'] = true;
                    }
                    break;

                case TaxDocumentCategory::DIV_1099:
                    if (isset($data['ordinary_dividends'])) {
                        $totals['dividend_income'] += $this->dollarsToCents($data['ordinary_dividends']);
                        $hasValue['dividend_income'] = true;
                    }
                    break;

                case TaxDocumentCategory::R_1099:
                    if (isset($data['gross_distribution'])) {
                        $totals['retirement_distributions'] += $this->dollarsToCents($data['gross_distribution']);
                        $hasValue['retirement_distributions'] = true;
                    }
                    break;

                case TaxDocumentCategory::Mortgage_1098:
                    if (isset($data['mortgage_interest_received'])) {
                        $totals['mortgage_interest'] += $this->dollarsToCents($data['mortgage_interest_received']);
                        $hasValue['mortgage_interest'] = true;
                    } elseif (isset($data['interest_received'])) {
                        $totals['mortgage_interest'] += $this->dollarsToCents($data['interest_received']);
                        $hasValue['mortgage_interest'] = true;
                    }
                    break;

                case TaxDocumentCategory::PropertyTax:
                    if (isset($data['amount'])) {
                        $totals['property_tax_paid'] += $this->dollarsToCents($data['amount']);
                        $hasValue['property_tax_paid'] = true;
                    }
                    break;

                case TaxDocumentCategory::E_1098:
                    // 1098-E: student loan interest
                    if (isset($data['interest'])) {
                        $totals['student_loan_interest'] += $this->dollarsToCents($data['interest']);
                        $hasValue['student_loan_interest'] = true;
                    }
                    break;

                case TaxDocumentCategory::CharitableDonation:
                    if (isset($data['amount'])) {
                        $totals['charitable_contributions'] += $this->dollarsToCents($data['amount']);
                        $hasValue['charitable_contributions'] = true;
                    }
                    break;

                case TaxDocumentCategory::HSA_5498:
                    if (isset($data['contributions_made'])) {
                        $totals['hsa_ytd'] += $this->dollarsToCents($data['contributions_made']);
                        $hasValue['hsa_ytd'] = true;
                    }
                    break;

                case TaxDocumentCategory::IRA_5498:
                    if (isset($data['contributions_made'])) {
                        $totals['ira_ytd'] += $this->dollarsToCents($data['contributions_made']);
                        $hasValue['ira_ytd'] = true;
                    }
                    break;

                    // [P12 DOC-03] PayStub arm — accumulates YTD deduction signals into profile.
                    //
                    // PLAN-CHECK FIX (per 12-RESEARCH.md Pitfall 2, lines 328-347):
                    // Use the DEFENSIVE NESTED-WITH-FALLBACK pattern, NOT the legacy flat access
                    // of the W2/1099 arms above. Runtime shape is:
                    //   extracted_data['fields']['field_name']['value'] (nested with confidence)
                    // but we fall back gracefully if a flat value is stored.
                    // DO NOT copy the legacy flat-access pattern ($data['wages']) into these new arms.
                case TaxDocumentCategory::PayStub:
                    // Defensive nested-with-fallback read (Pitfall 2):
                    // Prefers $data['fields']['field_name']['value']; falls back to flat.
                    $fields = $data['fields'] ?? $data;
                    if (isset($fields['traditional_401k_deduction'])) {
                        $totals['traditional_401k_ytd'] += $this->dollarsToCents(
                            $fields['traditional_401k_deduction']['value'] ?? $fields['traditional_401k_deduction']
                        );
                        $hasValue['traditional_401k_ytd'] = true;
                    }
                    if (isset($fields['roth_401k_deduction'])) {
                        $totals['roth_401k_ytd'] += $this->dollarsToCents(
                            $fields['roth_401k_deduction']['value'] ?? $fields['roth_401k_deduction']
                        );
                        $hasValue['roth_401k_ytd'] = true;
                    }
                    if (isset($fields['hsa_deduction'])) {
                        $totals['hsa_ytd'] += $this->dollarsToCents(
                            $fields['hsa_deduction']['value'] ?? $fields['hsa_deduction']
                        );
                        $hasValue['hsa_ytd'] = true;
                    }
                    if (isset($fields['gross_pay'])) {
                        $totals['w2_wages'] += $this->dollarsToCents(
                            $fields['gross_pay']['value'] ?? $fields['gross_pay']
                        );
                        $hasValue['w2_wages'] = true;
                    }
                    break;

                    // [P12 DOC-07] BenefitsGuide arm — availability metadata only, no dollar accumulation.
                    // The guide informs the snapshot that certain plan types exist (HDHP, 401k, etc.)
                    // but does not contribute dollar totals. The D4 UserTaxFact proposals carry the
                    // per-field data — the assembler does not duplicate that into cent columns.
                case TaxDocumentCategory::BenefitsGuide:
                    // Intentional no-op: BenefitsGuide facts flow via the UserTaxFact proposal path
                    // (ExtractProfileFacts → PaystubFactExtractorService → BENEFITS_FACT_MAP).
                    // The assembler does not accumulate dollar values from benefits guides.
                    break;
            }
        }

        sort($docIds);

        // Build column data: only set columns that had actual data (null otherwise)
        $columnData = [];
        foreach ($totals as $column => $cents) {
            $columnData[$column] = $hasValue[$column] ? (string) $cents : null;
        }

        return [$columnData, $docIds, count($docIds)];
    }

    // ─── Source 3: Bank deposits (direct calendar-year query) ────────────────

    /**
     * Sum all income-type bank transactions for the full calendar year (Jan 1–Dec 31).
     *
     * IMPORTANT: DO NOT use IncomeDetectorService::analyze() here — that method uses a
     * rolling monthsBack window from "now" which produces partial-year totals when called
     * mid-year. Instead, query Transaction directly for the tax year's calendar range.
     *
     * Income classification mirrors IncomeDetectorService::classifyType() logic:
     * includes employment, contractor, interest, other income types;
     * excludes transfers.
     */
    protected function sumBankDeposits(User $user, int $taxYear): int
    {
        $start = Carbon::create($taxYear, 1, 1)->startOfDay();
        $end = Carbon::create($taxYear, 12, 31)->endOfDay();

        // Fetch all negative-amount transactions (money in) in the calendar year.
        // We mirror the same field selection as IncomeDetectorService::analyze()
        // so we can apply the same classifyType logic.
        $transactions = Transaction::where('user_id', $user->id)
            ->where('amount', '<', 0)
            ->whereBetween('transaction_date', [$start, $end])
            ->select(
                'id',
                'merchant_name',
                'amount',
                'transaction_date',
                'plaid_category',
                'plaid_detailed_category',
                DB::raw('COALESCE(user_category, ai_category) as resolved_category')
            )
            ->get();

        if ($transactions->isEmpty()) {
            return 0;
        }

        $totalCents = 0;

        // Type maps mirror IncomeDetectorService protected maps (inlined for zero coupling)
        $plaidTypeMap = [
            'INCOME_WAGES' => 'employment',
            'INCOME_SALARY' => 'employment',
            'INCOME_DIVIDENDS' => 'interest',
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

        $plaidPrimaryMap = [
            'INCOME' => 'employment',
            'TRANSFER_IN' => 'transfer',
        ];

        $aiTypeMap = [
            'Salary & Wages' => 'employment',
            'Income (Salary)' => 'employment',
            'Payroll' => 'employment',
            'Direct Deposit' => 'employment',
            'Contractor Income' => 'contractor',
            'Freelance Income' => 'contractor',
            'Income (Freelance)' => 'contractor',
            'Income (1099)' => 'contractor',
            'Interest Income' => 'interest',
            'Income (Investment)' => 'interest',
            'Dividends' => 'interest',
            'Investment Income' => 'interest',
            'Rental Income' => 'other',
            'Refund' => 'other',
            'Tax Refund' => 'other',
        ];

        foreach ($transactions as $tx) {
            $type = $this->classifyTransactionType($tx, $plaidTypeMap, $plaidPrimaryMap, $aiTypeMap);

            // Exclude transfers — all other income types count toward bank deposit total
            if ($type === 'transfer') {
                continue;
            }

            $totalCents += (int) round(abs((float) $tx->amount) * 100);
        }

        return $totalCents;
    }

    /**
     * Classify a transaction into an income type.
     * Mirrors IncomeDetectorService::classifyType() — kept here to avoid
     * coupling to the protected method.
     */
    protected function classifyTransactionType(
        object $tx,
        array $plaidTypeMap,
        array $plaidPrimaryMap,
        array $aiTypeMap
    ): string {
        // 1. Plaid detailed category (most specific)
        if ($tx->plaid_detailed_category && isset($plaidTypeMap[$tx->plaid_detailed_category])) {
            return $plaidTypeMap[$tx->plaid_detailed_category];
        }

        // 2. Plaid primary category
        if ($tx->plaid_category && isset($plaidPrimaryMap[$tx->plaid_category])) {
            return $plaidPrimaryMap[$tx->plaid_category];
        }

        // 3. AI/user category
        $resolved = $tx->resolved_category ?? null;
        if ($resolved && isset($aiTypeMap[$resolved])) {
            return $aiTypeMap[$resolved];
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
        if (str_contains($merchant, 'INTEREST')) {
            return 'interest';
        }

        return 'other';
    }

    // ─── Staleness hash ───────────────────────────────────────────────────────

    /**
     * SHA-256 of user_id + tax_year + sorted contributing doc IDs.
     * When the document set changes (new doc added, doc deleted), this hash
     * changes and isStale() returns true.
     */
    protected function computeProfileHash(int $userId, int $taxYear, array $docIds): string
    {
        $sorted = $docIds;
        sort($sorted);

        return hash('sha256', implode('|', [
            $userId,
            $taxYear,
            implode(',', $sorted),
        ]));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Convert a dollar float string from extracted_data to integer cents.
     * extracted_data values are always float dollar strings (e.g. "72500.00").
     * NEVER use intval() directly — always round after float multiplication.
     */
    protected function dollarsToCents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
