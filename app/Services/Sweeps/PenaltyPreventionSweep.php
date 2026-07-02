<?php

namespace App\Services\Sweeps;

use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;
use App\Services\TaxRulesEngineService;
use Carbon\Carbon;

/**
 * PenaltyPreventionSweep — FLAG-26
 *
 * Covers the transaction-observable subset of penalty-prevention sweeps (TD-v2Δ §8).
 * Runs as a continuous/activity-gated scheduled task (routes/console.php).
 *
 * Observable sweeps implemented (others require data planes not yet ingested):
 *  1. Excess IRA/Roth/HSA contributions — inverted headroom from TaxRulesEngineService
 *  2. Roth income-limit breach awareness — recharacterization education
 *  3. HSA-near-Medicare 6-month lookback heuristic — Part A enrollment timing
 *  4. 1099-K/deposit mismatch — educational only (no CrossSourceReviewService dependency)
 *
 * FRAMING: warn-and-educate ONLY throughout — never directive, never "you must".
 * SAFE-03: estimated_value_cents never assigned here.
 */
class PenaltyPreventionSweep
{
    public function __construct(
        protected TaxRulesEngineService $engine,
    ) {}

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

        // ── Sweep 1: Excess IRA/Roth/HSA contributions ───────────────────────
        $iraKeys = $this->checkExcessIraContributions($userId, $taxYear, $service, $electionFacts);
        $emitted = array_merge($emitted, $iraKeys);

        // ── Sweep 2: Roth income-limit breach awareness ───────────────────────
        $rothKeys = $this->checkRothIncomeLimitBreach($userId, $taxYear, $service, $electionFacts);
        $emitted = array_merge($emitted, $rothKeys);

        // ── Sweep 3: HSA-near-Medicare 6-month lookback heuristic ────────────
        $hsaMedicareKeys = $this->checkHsaMedicareLookback($userId, $taxYear, $service, $electionFacts);
        $emitted = array_merge($emitted, $hsaMedicareKeys);

        return array_values(array_unique($emitted));
    }

    // ── Sweep 1: Excess IRA/Roth/HSA contributions ───────────────────────────

    protected function checkExcessIraContributions(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        // IRA contributions (combined Roth + Traditional)
        $rothFact = UserTaxFact::currentFact($userId, 'ira.roth_ytd_contribution_cents', null, $taxYear);
        $tradFact = UserTaxFact::currentFact($userId, 'ira.traditional_ytd_contribution_cents', null, $taxYear);

        if ($rothFact === null && $tradFact === null) {
            // No IRA facts — skip
        } else {
            $rothCents = $rothFact ? (int) $rothFact->value : 0;
            $tradCents = $tradFact ? (int) $tradFact->value : 0;
            $combined = $rothCents + $tradCents;

            // Full annual IRA limit (pass ytd=0 to get the ceiling, then compare combined)
            $annualLimit = $this->engine->remainingIraRoomCents(0, null, $taxYear);

            if ($combined > $annualLimit) {
                // Over-contribution detected
                $key = $service->registerFinding(
                    userId: $userId,
                    taxYear: $taxYear,
                    findingKey: 'penalty_excess_ira_contribution',
                    findingType: 'penalty_prevention',
                    band: 'conditional',
                    treatment: 'Your IRA contributions for the year may exceed the annual limit. '
                        .'Excess IRA contributions are subject to a 6% excise tax each year '
                        .'until the excess is withdrawn or recharacterized. '
                        .'A tax professional could review your contributions and advise on the '
                        .'best way to address any excess before the filing deadline.',
                    legalBasis: 'IRC §4973 (6% excise tax on excess IRA contributions); IRC §408(d)(4) (correction methods)',
                    ruleId: 'penalty_excess_ira_contribution',
                    electionFacts: $electionFacts,
                );

                if ($key !== null) {
                    $emitted[] = $key;
                }
            }
        }

        // HSA excess contributions
        $hsaFact = UserTaxFact::currentFact($userId, 'hsa.ytd_contribution_cents', null, $taxYear);

        if ($hsaFact !== null) {
            $hsaContrib = (int) $hsaFact->value;
            // Get the full annual HSA limit (self_only, no age catch-up) then compare
            $hsaLimit = $this->engine->remainingHsaRoomCents(0, 'self_only', null, $taxYear);

            if ($hsaContrib > $hsaLimit) {
                $key = $service->registerFinding(
                    userId: $userId,
                    taxYear: $taxYear,
                    findingKey: 'penalty_excess_hsa_contribution',
                    findingType: 'penalty_prevention',
                    band: 'conditional',
                    treatment: 'Your HSA contributions for the year may exceed the annual limit. '
                        .'Excess HSA contributions are subject to a 6% excise tax. '
                        .'You may be able to withdraw the excess and earnings before the filing deadline '
                        .'to avoid the penalty — a tax professional could advise on your specific situation.',
                    legalBasis: 'IRC §4973(d) (6% excise on excess HSA contributions); IRC §223(f)(3) (excess withdrawal)',
                    ruleId: 'penalty_excess_hsa_contribution',
                    electionFacts: $electionFacts,
                );

                if ($key !== null) {
                    $emitted[] = $key;
                }
            }
        }

        return $emitted;
    }

    // ── Sweep 2: Roth income-limit breach awareness ───────────────────────────

    protected function checkRothIncomeLimitBreach(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        // We need MAGI and filing status facts
        $magiFact = UserTaxFact::currentFact($userId, 'profile.estimated_magi_cents', null, $taxYear);
        $filingStatusFact = UserTaxFact::currentFact($userId, 'profile.filing_status', null, $taxYear);
        $rothFact = UserTaxFact::currentFact($userId, 'ira.roth_ytd_contribution_cents', null, $taxYear);

        if ($magiFact === null || $rothFact === null) {
            return []; // Not enough data
        }

        $magiCents = (int) $magiFact->value;
        $filingStatus = $filingStatusFact ? (string) $filingStatusFact->value : 'single';
        $rothContrib = (int) $rothFact->value;

        if ($rothContrib === 0) {
            return [];
        }

        $eligibility = $this->engine->rothIraEligibility($magiCents, $filingStatus, $taxYear);

        if (! $eligibility['eligible'] && $rothContrib > 0) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'penalty_roth_income_limit_breach',
                findingType: 'penalty_prevention',
                band: 'conditional',
                treatment: 'Your estimated income may exceed the Roth IRA eligibility limit. '
                    .'Roth IRA contributions made when your income is above the limit result in '
                    .'excess contributions (6% excise tax). You may be able to recharacterize '
                    .'the Roth contribution as a Traditional IRA contribution before the filing deadline. '
                    .'A tax professional could advise on recharacterization or the backdoor Roth approach '
                    .'for your situation.',
                legalBasis: 'IRC §408A(c)(3) (Roth income limits); IRC §408(d)(6) (recharacterization)',
                ruleId: 'penalty_roth_income_limit',
                electionFacts: $electionFacts,
            );

            return $key !== null ? [$key] : [];
        }

        return [];
    }

    // ── Sweep 3: HSA-near-Medicare 6-month lookback heuristic ────────────────

    protected function checkHsaMedicareLookback(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $medicareFact = UserTaxFact::currentFact($userId, 'medicare.enrollment_date');
        if ($medicareFact === null) {
            return [];
        }

        $hsaFact = UserTaxFact::currentFact($userId, 'hsa.ytd_contribution_cents', null, $taxYear);
        if ($hsaFact === null || (int) $hsaFact->value === 0) {
            return [];
        }

        $medicareDate = Carbon::parse((string) $medicareFact->value);
        $hsaContribCents = (int) $hsaFact->value;

        // If Medicare enrollment is within 6 months and HSA contributions exist → warn
        if ($medicareDate->lte(now()) && $hsaContribCents > 0) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'penalty_hsa_medicare_lookback',
                findingType: 'penalty_prevention',
                band: 'conditional',
                treatment: 'You appear to have enrolled in Medicare recently. '
                    .'When you enroll in Medicare Part A, the IRS applies a 6-month lookback period, '
                    .'meaning HSA contributions made in the 6 months before your Medicare effective date '
                    .'may be considered excess contributions. '
                    .'A tax professional could review the timing of your Medicare enrollment and HSA '
                    .'contributions to determine if any corrections are needed.',
                legalBasis: 'IRC §223(b)(7) (HSA ineligibility from Medicare enrollment); IRS Publication 969',
                ruleId: 'penalty_hsa_medicare',
                electionFacts: $electionFacts,
            );

            return $key !== null ? [$key] : [];
        }

        return [];
    }
}
