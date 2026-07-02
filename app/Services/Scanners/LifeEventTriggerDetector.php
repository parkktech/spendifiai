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

        // ── Trigger 4: Escrow / title company inflow ──────────────────────────
        $escrowKeys = $this->detectEscrowInflow($userId, $taxYear, $service, $electionFacts);
        $emitted = array_merge($emitted, $escrowKeys);

        // ── Annual battery: surface unanswered life-event questions ───────────
        $batteryKeys = $this->surfaceBatteryQuestions($userId, $taxYear, $service, $electionFacts);
        $emitted = array_merge($emitted, $batteryKeys);

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

    // ── Trigger 4: Escrow / title company inflow ─────────────────────────────

    /**
     * Detect escrow/title company inflows (home sale proceeds) → §121 education.
     *
     * A large credit from an escrow or title company is a strong signal of a home sale.
     * Strategy window: §121 gain exclusion (up to $250K/$500K MFJ), basis-ledger settlement,
     * capital-gain harvesting in low-income year if exclusion is unavailable.
     *
     * TD-v2Δ §7: "Escrow/title company inflow → Home sale → §121 exclusion + basis-ledger settlement"
     * SAFE-03: estimated_value_cents never assigned.
     */
    protected function detectEscrowInflow(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        /** Minimum inflow to consider as potential home sale proceeds */
        $minInflowDollars = 10_000;

        $escrowPatterns = [
            'escrow', 'title company', 'title co', 'first american title', 'fidelity national title',
            'old republic title', 'chicago title', 'stewart title', 'land title',
            'attorney trust', 'closing proceeds', 'settlement proceeds',
        ];

        $yearStart = "{$taxYear}-01-01";
        $yearEnd = "{$taxYear}-12-31";

        $escrowQuery = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0)                          // credits / inflows
            ->where('amount', '<=', -$minInflowDollars)        // significant inflow
            ->whereBetween('transaction_date', [$yearStart, $yearEnd])
            ->where(function ($q) use ($escrowPatterns) {
                foreach ($escrowPatterns as $pattern) {
                    $q->orWhere('merchant_normalized', 'ILIKE', "%{$pattern}%")
                        ->orWhere('merchant_name', 'ILIKE', "%{$pattern}%");
                }
            });

        $escrowTxs = $escrowQuery->get(['id', 'amount', 'transaction_date', 'merchant_name']);

        if ($escrowTxs->isEmpty()) {
            return [];
        }

        $totalInflowCents = (int) round(abs($escrowTxs->sum('amount')) * 100);

        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'life_event_escrow_inflow',
            findingType: 'life_event_trigger',
            band: 'conditional',
            treatment: 'We detected what may be a significant inflow from a title company or escrow. '
                .'This often signals a home sale. If you sold a home, you may want to be aware of '
                .'the §121 exclusion — which can allow you to exclude up to $250,000 of gain '
                .'($500,000 if married filing jointly) from the sale of your primary residence, '
                .'provided you met the ownership and use tests (2 of the last 5 years). '
                .'If you operated a home office or had any rental use of the home, '
                .'depreciation recapture and a partial §121 exclusion may apply. '
                .'A tax professional could review your closing documents and basis records '
                .'to determine your net gain and applicable exclusions.',
            legalBasis: 'IRC §121 (§121 primary-home gain exclusion, $250K/$500K); IRC §1250 (depreciation recapture)',
            ruleId: 'life_event_escrow_inflow',
            annualTotalCents: $totalInflowCents,
            transactionIds: $escrowTxs->pluck('id')->toArray(),
            electionFacts: $electionFacts,
        );

        return $key !== null ? [$key] : [];
    }

    // ── Annual Battery: life-event interview questions ────────────────────────

    /**
     * Battery question definitions: [finding_key, fact_key, question_label, treatment, legal_basis].
     *
     * Each entry represents one annual battery question. The detector checks whether the user
     * has already answered (fact exists in UserTaxFact) and skips if so. Otherwise, the question
     * surfaces as an OptimizationFinding, which the existing SurfaceHighPriorityRedFlags listener
     * converts to an AIQuestion in the feed — satisfying the "interview/feed bridge" requirement.
     *
     * Answers are persisted via recordBatteryAnswer() → UserTaxFact (source_type='interview_answer').
     */
    protected const ANNUAL_BATTERY = [
        [
            'finding_key' => 'battery_marriage_status',
            'fact_key' => 'life_event.marital_status_changed',
            'label' => 'Did your marital status change this year?',
            'treatment' => 'A change in marital status (marriage, divorce, or separation) can '
                .'significantly affect your filing status, standard deduction, tax brackets, '
                .'and eligibility for certain credits. '
                .'Marriage may allow "married filing jointly" (often more advantageous) or trigger '
                .'the "marriage penalty" for dual high-income earners. '
                .'Divorce changes your dependency deductions and may affect alimony taxation (pre-2019 '
                .'agreements), QDRO division of retirement accounts, and filing-status eligibility. '
                .'A tax professional could review the tax implications of your specific situation.',
            'legal_basis' => 'IRC §2 (filing status); IRC §1 (tax tables); IRC §71 (alimony — pre-TCJA agreements)',
        ],
        [
            'finding_key' => 'battery_birth_adoption',
            'fact_key' => 'life_event.birth_or_adoption',
            'label' => 'Did you have or adopt a child this year?',
            'treatment' => 'Adding a dependent through birth or adoption may qualify you for '
                .'the Child Tax Credit (up to $2,200 per child under current law), '
                .'the Child and Dependent Care Credit if you incur childcare costs, '
                .'and potentially a higher Earned Income Tax Credit. '
                .'Adoption expenses may also qualify for the Adoption Tax Credit (up to $17,280 in 2026). '
                .'A tax professional could review your new dependent qualifications and applicable credits.',
            'legal_basis' => 'IRC §24 (Child Tax Credit); IRC §21 (Dependent Care Credit); IRC §23 (Adoption Credit)',
        ],
        [
            'finding_key' => 'battery_job_change',
            'fact_key' => 'life_event.job_change',
            'label' => 'Did you start, leave, or change a job this year?',
            'treatment' => 'A job change can trigger several tax planning considerations: '
                .'multiple W-4s in one year may result in under-withholding (check your combined withholding), '
                .'severance pay is taxable income (often with higher withholding), '
                .'retirement plan rollovers from a prior employer should be handled carefully '
                .'to avoid taxable distributions, '
                .'and net unrealized appreciation (NUA) on employer stock may be worth preserving before a rollover. '
                .'A tax professional could review your transition and identify any withholding adjustments needed.',
            'legal_basis' => 'IRC §402 (rollover); IRC §402(e)(4) (NUA); IRC §3405 (withholding on distributions)',
        ],
        [
            'finding_key' => 'battery_inheritance',
            'fact_key' => 'life_event.inherited_assets_this_year',
            'label' => 'Did you inherit assets or receive assets from an estate this year?',
            'treatment' => 'If you inherited assets this year, documenting the step-up in cost basis NOW '
                .'is critically important — date-of-death valuations are straightforward today but '
                .'expensive to reconstruct years later when you eventually sell the inherited asset. '
                .'Inherited assets generally receive a stepped-up basis to the fair market value at '
                .'the date of the decedent\'s death, which can significantly reduce or eliminate capital gain. '
                .'IRAs inherited from non-spouse beneficiaries are subject to the 10-year distribution rule '
                .'(post-SECURE Act). '
                .'A tax professional could help you document the basis and plan distributions.',
            'legal_basis' => 'IRC §1014 (step-up in basis at death); IRC §401(a)(9)(H) (10-year rule, SECURE Act)',
        ],
        [
            'finding_key' => 'battery_medicare_enrollment',
            'fact_key' => 'life_event.medicare_enrollment_this_year',
            'label' => 'Did you or a spouse enroll in Medicare this year?',
            'treatment' => 'Enrolling in Medicare Part A triggers an important HSA contribution rule: '
                .'you can no longer contribute to an HSA once you are enrolled in any part of Medicare. '
                .'Additionally, Medicare Part A applies a 6-month lookback — contributions made in '
                .'the 6 months before your Medicare effective date may be considered excess contributions '
                .'(subject to a 6% excise tax). '
                .'You may want to stop HSA contributions and plan any remaining balance for qualified expenses. '
                .'A tax professional could review the timing and help you avoid excess-contribution penalties.',
            'legal_basis' => 'IRC §223(b)(7) (HSA ineligibility on Medicare enrollment); IRC §4973(d) (6% excise)',
        ],
    ];

    /**
     * Surface unanswered annual battery questions as interview/feed findings.
     *
     * For each battery question defined in ANNUAL_BATTERY:
     *  1. Check if the user has already answered via UserTaxFact (skip if answered).
     *  2. If not answered, register a finding — which the existing SurfaceHighPriorityRedFlags
     *     listener converts to an AIQuestion in the feed (no framework changes required).
     *
     * Answers are persisted by recordBatteryAnswer() after the user responds.
     *
     * @param  array<string, string>  $electionFacts
     * @return string[] Finding keys emitted
     */
    public function surfaceBatteryQuestions(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        foreach (self::ANNUAL_BATTERY as $battery) {
            // Skip if already answered for this tax year
            $existingFact = UserTaxFact::currentFact($userId, $battery['fact_key'], null, $taxYear);
            if ($existingFact !== null) {
                continue;
            }

            // Surface as finding → SurfaceHighPriorityRedFlags converts to AIQuestion in feed
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: $battery['finding_key'],
                findingType: 'battery_question',
                band: 'conditional',
                treatment: $battery['treatment'],
                legalBasis: $battery['legal_basis'],
                ruleId: null,  // battery questions have no expiring rule; bypass validateRule()
                electionFacts: $electionFacts,
            );

            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
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
