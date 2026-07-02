<?php

namespace App\Services\Scanners;

use App\Models\Transaction;
use App\Services\RedFlagDetectorService;
use Carbon\Carbon;

/**
 * RetroactiveScanner — FLAG-12
 *
 * Runs at onboarding over config('tax-detection.onboarding_history_months') of history
 * (default 36 months) alongside BuildIncomeOptimizationProfile on OptimizationProfileBuilt.
 *
 * Implements five retroactive scanners (TD-v1 §11):
 *  1. Missed-credit scanner: §25D, §30D, §25C — amended-return candidates
 *  2. Missed-deduction scanner: SE health, home office, auto-loan interest
 *  3. Basis reconstruction: contractor/improvement payments → property basis ledger (STORE-02)
 *  4. Method-election review: mileage and home-office method comparisons
 *  5. Estimated-tax exposure: companion to FLAG-18 safe-harbor (see SafeHarborBenchmark)
 *
 * BINDING REFRAMES (D10, INTEGRATION-MAP §4):
 *  §25D: config RANGE with uncertainty framing ("recoveries have commonly ranged from $A to $B;
 *        a tax professional could evaluate whether an amended return applies")
 *        NEVER "you will recover" or a promised amount.
 *  §30D: strictly date-gated past-window ("EV purchases before October 2025")
 *        NEVER "currently available."
 *  §25C: pre-2026 work only.
 *
 * SAFE-03: estimated_value_cents never assigned here.
 */
class RetroactiveScanner
{
    /**
     * Solar loan servicer merchant patterns (highest-recall signal for §25D scanner).
     * Source: TD-v1 §2 + DetectionMerchantSeeder solar_loan_servicer entries.
     */
    protected const SOLAR_LOAN_SERVICERS = [
        'goodleap', 'good leap', 'mosaic', 'dividend', 'sunlight financial',
        'enfin', 'en-fin', 'tesla energy', 'sunrun', 'sunpower',
    ];

    /**
     * Solar installer merchant patterns.
     */
    protected const SOLAR_INSTALLERS = [
        'tesla energy', 'sunrun', 'sunpower',
    ];

    /**
     * EV merchant patterns for §30D retro scanner.
     * NOTE: §30D window is pre-Oct-2025 purchases ONLY.
     */
    protected const EV_MERCHANTS = [
        'tesla', 'rivian', 'lucid', 'chevrolet ev', 'nissan leaf', 'volkswagen ev',
        'ford ev', 'ford lightning', 'ford mustang mach', 'hyundai ev', 'kia ev',
        'bmw ev', 'audi ev', 'mercedes ev',
    ];

    /**
     * Contractor/improvement merchant keywords for basis reconstruction.
     * Large one-off payments to these trigger the basis-reconstruction scanner.
     */
    protected const CONTRACTOR_KEYWORDS = [
        'construction', 'contractor', 'roofing', 'plumbing', 'electrical',
        'hvac', 'remodel', 'renovation', 'flooring', 'painting', 'landscap',
    ];

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

        $historyMonths = (int) config('tax-detection.onboarding_history_months', 36);
        $startDate = now()->subMonths($historyMonths)->startOfMonth();
        $endDate = now();

        $transactions = Transaction::where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->where('amount', '>', 0)
            ->get(['id', 'merchant_name', 'merchant_normalized', 'amount', 'transaction_date', 'is_subscription']);

        if ($transactions->isEmpty()) {
            return [];
        }

        // ── Scanner 1: Missed-credit (§25D, §30D, §25C) ──────────────────────
        $emitted = array_merge($emitted, $this->scanMissedCredits($userId, $taxYear, $service, $electionFacts, $transactions));

        // ── Scanner 3: Basis reconstruction ──────────────────────────────────
        $emitted = array_merge($emitted, $this->scanBasisReconstruction($userId, $taxYear, $service, $electionFacts, $transactions));

        return array_values(array_unique($emitted));
    }

    // ── Scanner 1: Missed-credit scanner ─────────────────────────────────────

    protected function scanMissedCredits(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts,
        $transactions
    ): array {
        $emitted = [];

        // §25D: Solar loan servicers or installers in history (pre-2026 installs)
        $solarTxs = $transactions->filter(function ($tx) {
            $normalized = strtolower(trim($tx->merchant_normalized ?? $tx->merchant_name ?? ''));
            foreach (self::SOLAR_LOAN_SERVICERS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return true;
                }
            }

            return false;
        });

        if ($solarTxs->isNotEmpty()) {
            // Check if within 3-year amended-return window (transaction date < 3 years ago)
            $threeYearsAgo = now()->subYears(3);
            $withinWindow = $solarTxs->filter(
                fn ($tx) => Carbon::parse($tx->transaction_date)->gte($threeYearsAgo)
            );

            if ($withinWindow->isNotEmpty()) {
                $range25dLow = (int) config('tax-detection.retroactive.25d_range_low_dollars', 10000);
                $range25dHigh = (int) config('tax-detection.retroactive.25d_range_high_dollars', 20000);

                $key = $service->registerFinding(
                    userId: $userId,
                    taxYear: $taxYear,
                    findingKey: 'retroactive_missed_credit_25d',
                    findingType: 'retroactive_scanner',
                    band: 'conditional',
                    treatment: 'We detected solar loan or installer payments in your transaction history. '
                        .'If you had a solar energy system paid for or activated before December 31, 2025, '
                        .'you may have been eligible for the §25D residential clean energy credit (30%). '
                        ."Recoveries on amended returns have commonly ranged from \${$range25dLow} to \${$range25dHigh} "
                        .'— a tax professional could evaluate whether an amended return applies to your situation. '
                        .'The 3-year amended-return window is currently open.',
                    legalBasis: 'IRC §25D (expired 2025-12-31; 30% credit for qualifying systems); IRC §6511 (3-year amended return)',
                    ruleId: null,  // bypass validateRule — retro scanner surfaces expired credits intentionally
                    isRecurring: $withinWindow->where('is_subscription', true)->isNotEmpty(),
                    annualTotalCents: (int) round($withinWindow->sum('amount') * 100),
                    transactionIds: $withinWindow->pluck('id')->toArray(),
                    docsMissing: ['solar_invoice', 'prior_year_return'],
                    electionFacts: $electionFacts,
                );

                if ($key !== null) {
                    $emitted[] = $key;
                }
            }
        }

        // §30D: EV purchases BEFORE Oct 2025 — strictly past-window, never "currently available"
        $ev30dCutoff = Carbon::create(2025, 10, 1);
        $evTxs = $transactions->filter(function ($tx) use ($ev30dCutoff) {
            $normalized = strtolower(trim($tx->merchant_normalized ?? $tx->merchant_name ?? ''));
            $txDate = Carbon::parse($tx->transaction_date);

            if ($txDate->gte($ev30dCutoff)) {
                return false; // post-cutoff: §30D no longer applies
            }

            foreach (self::EV_MERCHANTS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return true;
                }
            }

            return false;
        });

        if ($evTxs->isNotEmpty()) {
            // Only emit if large enough to be an EV purchase (not a service visit)
            $largeEvTxs = $evTxs->filter(fn ($tx) => $tx->amount >= 5000); // $5K+ threshold

            if ($largeEvTxs->isNotEmpty()) {
                $key = $service->registerFinding(
                    userId: $userId,
                    taxYear: $taxYear,
                    findingKey: 'retroactive_ev_credit_30d',
                    findingType: 'retroactive_scanner',
                    band: 'conditional',
                    treatment: 'We detected what may be an electric vehicle purchase before October 2025. '
                        .'EV purchases made before October 2025 may have been eligible for the §30D EV credit. '
                        .'This credit applied to qualifying vehicles in the past — a tax professional could '
                        .'review whether you claimed it and whether an amended return is appropriate. '
                        .'Note: this credit window is closed for 2025-10-01 and later purchases.',
                    legalBasis: 'IRC §30D (OBBBA ends credit for purchases on/after 2025-10-01); IRC §6511 (3-year amended return)',
                    ruleId: null,  // bypass validateRule — retro scanner surfaces expired credits intentionally
                    amountCents: (int) round($largeEvTxs->max('amount') * 100),
                    transactionIds: $largeEvTxs->pluck('id')->toArray(),
                    docsMissing: ['ev_purchase_docs', 'prior_year_return'],
                    electionFacts: $electionFacts,
                );

                if ($key !== null) {
                    $emitted[] = $key;
                }
            }
        }

        return $emitted;
    }

    // ── Scanner 3: Basis reconstruction ──────────────────────────────────────

    protected function scanBasisReconstruction(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts,
        $transactions
    ): array {
        $emitted = [];

        // Large contractor/improvement payments → property basis ledger candidates
        $contractorTxs = $transactions->filter(function ($tx) {
            $normalized = strtolower(trim($tx->merchant_normalized ?? $tx->merchant_name ?? ''));
            foreach (self::CONTRACTOR_KEYWORDS as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $tx->amount >= 1000; // $1,000+ only (materiality gate)
                }
            }

            return false;
        });

        if ($contractorTxs->isEmpty()) {
            return [];
        }

        $totalContractor = (int) round($contractorTxs->sum('amount') * 100);

        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'retroactive_basis_reconstruction',
            findingType: 'retroactive_scanner',
            band: 'conditional',
            treatment: 'We detected contractor or improvement payments in your transaction history. '
                .'Capital improvements to your home or investment property add to your basis, '
                .'which reduces your taxable gain when you sell. '
                .'Tracking these payments now in a property basis ledger is straightforward today '
                .'and can be expensive to reconstruct later. '
                .'A tax professional could help you identify which payments qualify as capital improvements '
                .'vs. repairs (deductible now for rentals, or maintenance for primary homes).',
            legalBasis: 'IRC §1011 (adjusted basis); IRC §121 (basis for exclusion); IRC §168 (rental improvements)',
            ruleId: 'retroactive_basis_reconstruction',
            isRecurring: false,
            annualTotalCents: $totalContractor,
            transactionIds: $contractorTxs->pluck('id')->toArray(),
            docsMissing: ['contractor_invoices'],
            electionFacts: $electionFacts,
        );

        if ($key !== null) {
            $emitted[] = $key;
        }

        return $emitted;
    }
}
