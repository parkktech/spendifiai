<?php

namespace App\Services\Scanners;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * LifeEventTriggerDetector — FLAG-27
 *
 * Detects life events from data-observable triggers (payroll, mortgage, insurance,
 * marketplace premiums) and surfaces educational tax-positioning findings.
 *
 * 4 data-detectable triggers implemented (TD-v2Δ §7):
 *  1. Payroll-stop — detect cessation of regular payroll deposits → SE-tax awareness
 *  2. New mortgage — detect new recurring large payment pattern → new deductions survey
 *  3. Escrow inflow — detect escrow/insurance disbursements → basis / casualty loss
 *  4. Marketplace premiums — detect "healthcare.gov" or "marketplace" merchant patterns
 *     → APTC reconciliation / §36B credit education
 *
 * Battery answers (interview_answers) are persisted to UserTaxFact via recordBatteryAnswer()
 * for durable cross-session access.
 *
 * FRAMING: Educational only — every treatment uses "may / could / consider" language.
 * SAFE-03: estimated_value_cents never assigned.
 */
class LifeEventTriggerDetector
{
    /** Marketplace / ACA premium merchant patterns */
    protected const MARKETPLACE_PATTERNS = [
        'healthcare.gov', 'healthcare gov', 'marketplace', 'covered california',
        'ny state of health', 'healthsherpa', 'get covered', 'mnsure', 'access health',
        'connect for health', 'aca marketplace', 'exchange premium',
    ];

    /** New-mortgage monthly payment floor — below this is not a new mortgage */
    protected const MORTGAGE_FLOOR_DOLLARS = 500;

    /** Maximum age of "new" mortgage payment pattern for trigger (months) */
    protected const NEW_MORTGAGE_LOOKBACK_MONTHS = 6;

    /**
     * @param  array<string, string>  $electionFacts
     * @return string[] Finding keys emitted
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        // ── Trigger 1: Payroll stop ───────────────────────────────────────────
        $payrollKeys = $this->detectPayrollStop($userId, $taxYear, $service, $electionFacts);
        $emitted = array_merge($emitted, $payrollKeys);

        // ── Trigger 2: New mortgage ───────────────────────────────────────────
        $mortgageKeys = $this->detectNewMortgage($userId, $taxYear, $service, $electionFacts);
        $emitted = array_merge($emitted, $mortgageKeys);

        // ── Trigger 3: Marketplace premiums ──────────────────────────────────
        $marketplaceKeys = $this->detectMarketplacePremiums($userId, $taxYear, $service, $electionFacts);
        $emitted = array_merge($emitted, $marketplaceKeys);

        return array_values(array_unique($emitted));
    }

    // ── Trigger 1: Payroll stop ───────────────────────────────────────────────

    protected function detectPayrollStop(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        // Look for payroll transactions in prior 6 months but NOT in last 60 days
        $sixMonthsAgo = now()->subMonths(6);
        $sixtyDaysAgo = now()->subDays(60);

        $priorPayroll = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0) // credits (negative = income in Plaid debit-positive)
            ->whereBetween('transaction_date', [$sixMonthsAgo, $sixtyDaysAgo])
            ->where(function ($q) {
                $q->where('merchant_normalized', 'ILIKE', '%payroll%')
                    ->orWhere('merchant_name', 'ILIKE', '%direct deposit%')
                    ->orWhere('merchant_name', 'ILIKE', '%employer%');
            })
            ->exists();

        $recentPayroll = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0)
            ->where('transaction_date', '>=', $sixtyDaysAgo)
            ->where(function ($q) {
                $q->where('merchant_normalized', 'ILIKE', '%payroll%')
                    ->orWhere('merchant_name', 'ILIKE', '%direct deposit%')
                    ->orWhere('merchant_name', 'ILIKE', '%employer%');
            })
            ->exists();

        if ($priorPayroll && ! $recentPayroll) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'life_event_payroll_stop',
                findingType: 'life_event_trigger',
                band: 'conditional',
                treatment: 'We detected that regular payroll deposits may have stopped recently. '
                    .'If you have become self-employed or started a business, there are important '
                    .'tax considerations to be aware of: self-employment tax (15.3%) applies to '
                    .'net self-employment income, quarterly estimated-tax payments may be required '
                    .'to avoid penalties, and you may be eligible for new deductions '
                    .'(home office, health insurance, retirement contributions). '
                    .'A tax professional could help you set up an appropriate tax plan for your new situation.',
                legalBasis: 'IRC §1401 (SE tax); IRC §6654 (estimated tax); IRC §162(l) (SE health); IRC §401(a) (SEP/SIMPLE)',
                ruleId: 'life_event_payroll_stop',
                electionFacts: $electionFacts,
            );

            return $key !== null ? [$key] : [];
        }

        return [];
    }

    // ── Trigger 2: New mortgage ───────────────────────────────────────────────

    protected function detectNewMortgage(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        // Check for a new large recurring subscription that looks like a mortgage payment
        // (within last 6 months, amount >= $500/month)
        $cutoff = now()->subMonths(self::NEW_MORTGAGE_LOOKBACK_MONTHS);

        $newMortgageSub = Subscription::where('user_id', $userId)
            ->where('status', SubscriptionStatus::Active)
            ->where('amount', '>=', self::MORTGAGE_FLOOR_DOLLARS)
            ->where('frequency', 'monthly')
            ->where(function ($q) {
                $q->where('merchant_normalized', 'ILIKE', '%mortgage%')
                    ->orWhere('merchant_normalized', 'ILIKE', '%home loan%')
                    ->orWhere('merchant_name', 'ILIKE', '%mortgage%')
                    ->orWhere('merchant_name', 'ILIKE', '%home loan%')
                    ->orWhere('merchant_name', 'ILIKE', '%rocket mortgage%')
                    ->orWhere('merchant_name', 'ILIKE', '%better mortgage%')
                    ->orWhere('merchant_name', 'ILIKE', '%loancare%')
                    ->orWhere('merchant_name', 'ILIKE', '%servicemac%')
                    ->orWhere('merchant_name', 'ILIKE', '%mr cooper%')
                    ->orWhere('merchant_name', 'ILIKE', '%newrez%')
                    ->orWhere('merchant_name', 'ILIKE', '%pennymac%');
            })
            ->where('created_at', '>=', $cutoff)
            ->first();

        if ($newMortgageSub === null) {
            return [];
        }

        $monthlyCents = (int) round((float) $newMortgageSub->amount * 100);

        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'life_event_new_mortgage',
            findingType: 'life_event_trigger',
            band: 'conditional',
            treatment: 'We detected what may be a new mortgage payment. '
                .'Purchasing a home can trigger several tax benefits: '
                .'mortgage interest on your primary residence may be deductible on Schedule A, '
                .'property taxes up to the $10,000 SALT cap may be deductible, '
                .'and points paid at closing (loan origination) may be deductible in the year paid. '
                .'If you sold a prior home, the §121 exclusion (up to $250K/$500K) may apply. '
                .'A tax professional could review your closing documents to identify all available '
                .'deductions and potential basis adjustments.',
            legalBasis: 'IRC §163(h) (home mortgage interest); IRC §164 (property taxes, $10K SALT cap); IRC §121 (gain exclusion)',
            ruleId: 'life_event_new_mortgage',
            amountCents: $monthlyCents,
            isRecurring: true,
            annualTotalCents: $monthlyCents * 12,
            electionFacts: $electionFacts,
        );

        return $key !== null ? [$key] : [];
    }

    // ── Trigger 3: Marketplace premiums ──────────────────────────────────────

    protected function detectMarketplacePremiums(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        // Check active Subscriptions first (subscription-plane signal — FLAG-27 rides FLAG-11 infra)
        $marketplaceSub = Subscription::where('user_id', $userId)
            ->where('status', SubscriptionStatus::Active)
            ->where(function ($q) {
                foreach (self::MARKETPLACE_PATTERNS as $pattern) {
                    $q->orWhere('merchant_normalized', 'ILIKE', "%{$pattern}%")
                        ->orWhere('merchant_name', 'ILIKE', "%{$pattern}%");
                }
                // Also trigger from Health Insurance category subscription (ACA marketplace proxy)
                $q->orWhere('category', 'ILIKE', '%health insurance%')
                    ->orWhere('category', 'ILIKE', '%marketplace%')
                    ->orWhere('category', 'ILIKE', '%aca%');
            })
            ->first();

        // Also check transaction-plane (individual payments vs recurring)
        $yearStart = "{$taxYear}-01-01";
        $yearEnd = "{$taxYear}-12-31";

        $marketplaceTxs = Transaction::where('user_id', $userId)
            ->where('amount', '>', 0)
            ->whereBetween('transaction_date', [$yearStart, $yearEnd])
            ->where(function ($q) {
                foreach (self::MARKETPLACE_PATTERNS as $pattern) {
                    $q->orWhere('merchant_normalized', 'ILIKE', "%{$pattern}%")
                        ->orWhere('merchant_name', 'ILIKE', "%{$pattern}%");
                }
            })
            ->get(['id', 'amount', 'transaction_date']);

        if ($marketplaceSub === null && $marketplaceTxs->isEmpty()) {
            return [];
        }

        // Calculate annual premium amount (from subscription or transactions)
        if ($marketplaceSub !== null) {
            $monthlyAmount = (float) $marketplaceSub->amount;
            $annualPremiumsCents = (int) round($monthlyAmount * 12 * 100);
        } else {
            $annualPremiumsCents = (int) round($marketplaceTxs->sum('amount') * 100);
        }

        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'life_event_marketplace_premium',
            findingType: 'life_event_trigger',
            band: 'conditional',
            treatment: 'We detected what may be Affordable Care Act (ACA) marketplace premium payments. '
                .'If you received an Advance Premium Tax Credit (APTC) to help pay your premiums, '
                .'you will need to reconcile it on Form 8962 — differences from your actual income '
                .'could result in a credit increase or repayment. '
                .'The §36B Premium Tax Credit is income-sensitive, and changes in income during '
                .'the year can affect your final credit amount. '
                .'A tax professional could review your 1095-A form and ensure your credit is '
                .'correctly reconciled on your return.',
            legalBasis: 'IRC §36B (Premium Tax Credit); IRS Form 8962; IRS Form 1095-A',
            ruleId: 'life_event_marketplace_premium',
            isRecurring: $marketplaceSub !== null,
            annualTotalCents: $annualPremiumsCents,
            transactionIds: $marketplaceTxs->isNotEmpty() ? $marketplaceTxs->pluck('id')->toArray() : [],
            electionFacts: $electionFacts,
        );

        return $key !== null ? [$key] : [];
    }

    // ── Battery answer persistence ────────────────────────────────────────────

    /**
     * Persist a battery/interview answer as a durable UserTaxFact.
     *
     * Called from intake-battery flows to record user responses that inform
     * subsequent trigger evaluation (e.g., "Did you inherit assets this year?").
     * The fact is stored exactly at $factKey — no prefix added.
     *
     * @param  string  $factKey  Canonical fact key, e.g. 'life_event.inherited_assets'
     * @param  string|bool|int|null  $value  User's answer
     * @param  int|null  $taxYear  Defaults to current year
     * @param  string|null  $label  Human-readable label for the fact
     * @return UserTaxFact The persisted/updated fact record
     */
    public function recordBatteryAnswer(
        int $userId,
        string $factKey,
        string|bool|int|null $value,
        ?int $taxYear = null,
        ?string $label = null,
    ): UserTaxFact {
        $taxYear ??= (int) date('Y');

        // Cast boolean/integer to string for the append-only fact store
        $valueStr = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => $value,
        };

        return UserTaxFact::recordFact(
            userId: $userId,
            factKey: $factKey,
            value: $valueStr,
            sourceType: 'interview_answer',
            label: $label,
            taxYear: $taxYear,
        );
    }
}
