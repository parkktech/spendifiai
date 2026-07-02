<?php

namespace App\Services\Scanners;

use App\Models\Transaction;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * SafeHarborBenchmark — FLAG-18 (REFRAMED per D10, INTEGRATION-MAP §8 SH-6)
 *
 * REFRAMED BOUNDARY WORDING (D10, approved default ruling, non-negotiable):
 *  - Output is a "safe-harbor benchmark" — NEVER "your estimated taxes"
 *  - Arithmetic uses ONLY:
 *    (a) user-supplied or vault-extracted prior-year federal tax liability
 *        (UserTaxFact key: 'prior_year.federal_liability_cents')
 *    (b) detected IRS payment outflows (Transaction merchant patterns)
 *  - Detected business inflows ARE a surfacing TRIGGER only — they NEVER enter
 *    the computation (Threat T-11-07-01 mitigation)
 *  - Output wording: "to meet the 100%/110% prior-year safe harbor, total payments
 *    of $X would be needed; detected so far: $Y; remaining gap: $Z —
 *    a penalty-avoidance benchmark, not your tax bill"
 *
 * SILENCE CONDITIONS:
 *  - No 'prior_year.federal_liability_cents' fact → silent
 *  - Prior-year liability = $0 → silent
 *
 * SAFE-03: estimated_value_cents never assigned by this class.
 * SAFE-04: No Claude calls; no HTTP. Pure deterministic PHP.
 */
class SafeHarborBenchmark
{
    /**
     * IRS payment outflow merchant patterns.
     * These are the ONLY inputs to the gap computation (besides prior-year liability).
     */
    protected const IRS_PAYMENT_PATTERNS = [
        'irs usataxpymt', 'irs payment', 'us treasury', 'united states treasury',
        'irs.gov', 'tax payment', 'estimated tax', 'irs eftps', 'eftps',
        '1040-es', 'form 1040-es',
    ];

    /**
     * Prior-year AGI threshold above which 110% safe harbor applies ($150,000 MFJ).
     * Source: IRC §6654(d)(1)(B)(ii); INTEGRATION-MAP config table.
     * Stored in cents.
     */
    protected const SAFE_HARBOR_HIGH_INCOME_CENTS = 15_000_000; // $150,000

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
        // ── 1. Get prior-year liability (ONLY computation input from income side) ──
        $priorYear = $taxYear - 1;
        $liabilityFact = UserTaxFact::currentFact($userId, 'prior_year.federal_liability_cents', null, $priorYear);

        if ($liabilityFact === null) {
            return []; // Silence: no prior-year data
        }

        $priorLiabilityCents = (int) $liabilityFact->value;

        if ($priorLiabilityCents <= 0) {
            return []; // Silence: zero liability
        }

        // ── 2. Determine safe-harbor tier (100% or 110%) ─────────────────────
        // 110% applies if prior-year AGI > $150,000 (or $75,000 MFS)
        $priorAgi = UserTaxFact::currentFact($userId, 'prior_year.agi_cents', null, $priorYear);
        $priorAgiVal = $priorAgi ? (int) $priorAgi->value : 0;

        $safeHarborPct = ($priorAgiVal > self::SAFE_HARBOR_HIGH_INCOME_CENTS) ? 110 : 100;
        $benchmarkCents = (int) round($priorLiabilityCents * $safeHarborPct / 100);

        // ── 3. Detect IRS payment outflows (ONLY transaction input to computation) ─
        // NOTE: business inflows are deliberately excluded here (Threat T-11-07-01).
        $detectedPaymentsCents = $this->detectIrsPayments($userId, $taxYear);

        // ── 4. Compute gap ────────────────────────────────────────────────────
        $gapCents = max(0, $benchmarkCents - $detectedPaymentsCents);

        // ── 5. Format treatment with REFRAMED wording ────────────────────────
        $benchmarkDollars = number_format($benchmarkCents / 100, 0);
        $detectedDollars = number_format($detectedPaymentsCents / 100, 0);
        $gapDollars = number_format($gapCents / 100, 0);

        $treatment = "To meet the {$safeHarborPct}% prior-year safe harbor, total estimated-tax "
            ."payments of \${$benchmarkDollars} would be needed for {$taxYear}. "
            ."We have detected approximately \${$detectedDollars} in IRS payments so far. "
            .($gapCents > 0
                ? "Remaining gap: \${$gapDollars} — consider making an estimated-tax payment "
                    .'by the next quarterly due date to stay within the benchmark. '
                : 'You appear to be on track with the benchmark. ')
            .'This is a penalty-avoidance benchmark — not a liability estimate — '
            .'a tax professional could confirm your actual liability.';

        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'safe_harbor_benchmark',
            findingType: 'safe_harbor_benchmark',
            band: 'conditional',
            treatment: $treatment,
            legalBasis: 'IRC §6654(d)(1) (safe-harbor exception to underpayment penalty); IRS Publication 505',
            ruleId: 'safe_harbor_benchmark',
            electionFacts: $electionFacts,
        );

        return $key !== null ? [$key] : [];
    }

    // ── IRS payment detection ─────────────────────────────────────────────────

    /**
     * Sum detected IRS estimated-tax payment outflows for the given tax year.
     *
     * IMPORTANT: This method returns ONLY detected IRS payment amounts.
     * Business inflows are NEVER included — not queried, not passed, not present.
     * This is the enforcement point for Threat T-11-07-01.
     *
     * @return int Total detected IRS payments in cents
     */
    protected function detectIrsPayments(int $userId, int $taxYear): int
    {
        $startDate = "{$taxYear}-01-01";
        $endDate = date('Y-m-d'); // today, for YTD view

        $irsPayments = Transaction::where('user_id', $userId)
            ->where('amount', '>', 0) // debits only (positive = spending in Plaid convention)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->where(function ($q) {
                foreach (self::IRS_PAYMENT_PATTERNS as $pattern) {
                    $q->orWhere('merchant_normalized', 'ILIKE', "%{$pattern}%")
                        ->orWhere('merchant_name', 'ILIKE', "%{$pattern}%");
                }
            })
            ->sum('amount');

        return (int) round((float) $irsPayments * 100);
    }
}
