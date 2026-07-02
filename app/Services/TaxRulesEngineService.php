<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Deterministic federal tax-math engine for the Optimize My Income feature.
 *
 * IMPORTANT: This class makes ZERO Claude/HTTP calls. Every dollar amount, rate,
 * and threshold it returns traces to a key in config/tax-rules.php. No IRS figure
 * is hardcoded in this file. Change a config value and the computation updates automatically.
 *
 * All monetary inputs and outputs are in INTEGER CENTS.
 * Config values are stored as plain-integer DOLLARS; this service converts to cents internally.
 */
class TaxRulesEngineService
{
    /** @var list<string> */
    protected array $allowedStatuses = [
        'single',
        'married_joint',
        'married_separate',
        'head_of_household',
    ];

    // ── Input Validation ─────────────────────────────────────────────────

    /**
     * @throws InvalidArgumentException
     */
    protected function validateFilingStatus(string $filingStatus, int $year): void
    {
        if (! in_array($filingStatus, $this->allowedStatuses, true)) {
            throw new InvalidArgumentException(
                "Unknown filing status: {$filingStatus}. Allowed: ".implode(', ', $this->allowedStatuses)
            );
        }

        if (! config("tax-rules.{$year}") !== false && config("tax-rules.{$year}") === null) {
            throw new InvalidArgumentException("Unsupported tax year: {$year}");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function validateIncome(int $incomeCents): void
    {
        if ($incomeCents < 0) {
            throw new InvalidArgumentException('Income must be >= 0 cents.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function validateYear(int $year): void
    {
        if (config("tax-rules.{$year}") === null) {
            throw new InvalidArgumentException("Unsupported tax year: {$year}. No config found.");
        }
    }

    // ── TAX-02: Federal Income Tax ────────────────────────────────────────

    /**
     * Compute total federal income tax on taxable income (in cents).
     *
     * @throws InvalidArgumentException
     */
    public function computeTax(int $taxableIncomeCents, string $filingStatus, int $year = 2026): int
    {
        $this->validateYear($year);
        $this->validateFilingStatus($filingStatus, $year);
        $this->validateIncome($taxableIncomeCents);

        $brackets = config("tax-rules.{$year}.brackets.{$filingStatus}");

        return $this->computeBracketTax($taxableIncomeCents, $brackets);
    }

    /**
     * Return the marginal rate — the rate of the top bracket the income reaches.
     *
     * @throws InvalidArgumentException
     */
    public function marginalRate(int $taxableIncomeCents, string $filingStatus, int $year = 2026): float
    {
        $this->validateYear($year);
        $this->validateFilingStatus($filingStatus, $year);
        $this->validateIncome($taxableIncomeCents);

        $brackets = config("tax-rules.{$year}.brackets.{$filingStatus}");
        $marginal = (float) $brackets[0]['rate'];

        foreach ($brackets as $bracket) {
            $fromCents = $bracket['from'] * 100;
            if ($taxableIncomeCents > $fromCents) {
                $marginal = (float) $bracket['rate'];
            } else {
                break;
            }
        }

        return $marginal;
    }

    /**
     * Effective rate = total tax / taxable income. Returns 0.0 for zero income.
     *
     * @throws InvalidArgumentException
     */
    public function effectiveRate(int $taxableIncomeCents, string $filingStatus, int $year = 2026): float
    {
        $this->validateYear($year);
        $this->validateFilingStatus($filingStatus, $year);
        $this->validateIncome($taxableIncomeCents);

        if ($taxableIncomeCents === 0) {
            return 0.0;
        }

        $tax = $this->computeTax($taxableIncomeCents, $filingStatus, $year);

        return $tax / $taxableIncomeCents;
    }

    // ── TAX-03: Standard vs Itemized ──────────────────────────────────────

    /**
     * Standard deduction in cents for a filing status, optionally adding senior addition for age >= 65.
     *
     * @throws InvalidArgumentException
     */
    public function standardDeductionCents(string $filingStatus, ?int $age = null, int $year = 2026): int
    {
        $this->validateYear($year);
        $this->validateFilingStatus($filingStatus, $year);

        $baseDollars = config("tax-rules.{$year}.standard_deduction.{$filingStatus}");
        $cents = $baseDollars * 100;

        if ($age !== null && $age >= 65) {
            $seniorDollars = config("tax-rules.{$year}.standard_deduction_senior_addition.{$filingStatus}");
            $cents += $seniorDollars * 100;
        }

        return $cents;
    }

    /**
     * Compare standard deduction vs itemized total; recommend the larger one.
     *
     * @return array{recommendation: string, standard_cents: int, itemized_cents: int, difference_cents: int}
     *
     * @throws InvalidArgumentException
     */
    public function compareStandardVsItemized(
        int $itemizedTotalCents,
        string $filingStatus,
        ?int $age = null,
        int $year = 2026
    ): array {
        $standardCents = $this->standardDeductionCents($filingStatus, $age, $year);
        $differenceCents = $itemizedTotalCents - $standardCents;

        return [
            'recommendation' => $itemizedTotalCents > $standardCents ? 'itemized' : 'standard',
            'standard_cents' => $standardCents,
            'itemized_cents' => $itemizedTotalCents,
            'difference_cents' => $differenceCents,
        ];
    }

    // ── TAX-04: Contribution Headroom ─────────────────────────────────────

    /**
     * Remaining 401(k) employee deferral room in cents.
     * Age 60–63 gets SECURE 2.0 §109 super catch-up; age 50–59 and 64+ get standard catch-up.
     *
     * @throws InvalidArgumentException
     */
    public function remaining401kRoomCents(int $ytdContribCents, ?int $age = null, int $year = 2026): int
    {
        $this->validateYear($year);

        $cfg = config("tax-rules.{$year}.401k");
        $totalLimitCents = $cfg['employee_deferral'] * 100;

        if ($age !== null && $age >= 50) {
            $catchupKey = ($age >= 60 && $age <= 63)
                ? 'catchup_age_60_to_63'
                : 'catchup_age_50_plus';
            $totalLimitCents += $cfg[$catchupKey] * 100;
        }

        return max(0, $totalLimitCents - $ytdContribCents);
    }

    /**
     * Remaining IRA contribution room in cents.
     * Age 50+ gets catch-up addition.
     *
     * @throws InvalidArgumentException
     */
    public function remainingIraRoomCents(int $ytdContribCents, ?int $age = null, int $year = 2026): int
    {
        $this->validateYear($year);

        $cfg = config("tax-rules.{$year}.ira");
        $totalLimitCents = $cfg['annual_limit'] * 100;

        if ($age !== null && $age >= 50) {
            $totalLimitCents += $cfg['catchup_age_50_plus'] * 100;
        }

        return max(0, $totalLimitCents - $ytdContribCents);
    }

    /**
     * Remaining HSA contribution room in cents.
     * Coverage type: 'self_only' or 'family'. Age 55+ gets statutory catch-up.
     *
     * @throws InvalidArgumentException
     */
    public function remainingHsaRoomCents(
        int $ytdContribCents,
        string $coverageType = 'self_only',
        ?int $age = null,
        int $year = 2026
    ): int {
        $this->validateYear($year);

        $cfg = config("tax-rules.{$year}.hsa");

        if (! isset($cfg[$coverageType])) {
            throw new InvalidArgumentException("Unknown HSA coverage type: {$coverageType}. Use 'self_only' or 'family'.");
        }

        $totalLimitCents = $cfg[$coverageType] * 100;

        if ($age !== null && $age >= 55) {
            $totalLimitCents += $cfg['catchup_age_55_plus'] * 100;
        }

        return max(0, $totalLimitCents - $ytdContribCents);
    }

    // ── TAX-05: Roth vs Traditional ───────────────────────────────────────

    /**
     * Recommend 'roth', 'traditional', or 'split' based on marginal rate.
     *
     * @throws InvalidArgumentException
     */
    public function rothVsTraditionalBand(float $marginalRate, int $year = 2026): string
    {
        $this->validateYear($year);

        $cfg = config("tax-rules.{$year}.roth_optimization");

        if ($marginalRate <= $cfg['prefer_roth_at_or_below']) {
            return 'roth';
        }

        if ($marginalRate >= $cfg['prefer_traditional_at_or_above']) {
            return 'traditional';
        }

        return 'split';
    }

    /**
     * Determine Roth IRA eligibility based on MAGI.
     *
     * @return array{eligible: bool, limit_cents: int, phase_out_pct: float}
     *
     * @throws InvalidArgumentException
     */
    public function rothIraEligibility(int $magiCents, string $filingStatus, int $year = 2026): array
    {
        $this->validateYear($year);
        $this->validateFilingStatus($filingStatus, $year);

        $cfg = config("tax-rules.{$year}.ira");
        $phaseout = $cfg['roth_phaseout'][$filingStatus];
        $fromCents = $phaseout['from'] * 100;
        $toCents = $phaseout['to'] * 100;
        $fullLimitCents = ($cfg['annual_limit']) * 100;

        if ($magiCents <= $fromCents) {
            return ['eligible' => true, 'limit_cents' => $fullLimitCents, 'phase_out_pct' => 0.0];
        }

        if ($magiCents >= $toCents) {
            return ['eligible' => false, 'limit_cents' => 0, 'phase_out_pct' => 1.0];
        }

        $window = $toCents - $fromCents;
        $phaseOutPct = ($magiCents - $fromCents) / $window;
        $reducedLimitCents = (int) round($fullLimitCents * (1 - $phaseOutPct));

        return [
            'eligible' => $reducedLimitCents > 0,
            'limit_cents' => max(0, $reducedLimitCents),
            'phase_out_pct' => $phaseOutPct,
        ];
    }

    /**
     * SECURE 2.0 §603: returns true if prior-year FICA wages meet or exceed the threshold,
     * meaning catch-up contributions MUST be made as Roth.
     *
     * NOTE: The threshold value in config is flagged [ASSUMED] — confirm exact 2026 indexed
     * amount from IRS final regulations before Phase 13.
     *
     * @throws InvalidArgumentException
     */
    public function requiresMandatoryRothCatchup(int $priorYearFicaWagesCents, int $year = 2026): bool
    {
        $this->validateYear($year);

        $thresholdCents = config("tax-rules.{$year}.401k.mandatory_roth_catchup_threshold") * 100;

        return $priorYearFicaWagesCents >= $thresholdCents;
    }

    /**
     * Determine whether traditional IRA contributions are fully deductible.
     *
     * @return array{deductible: bool, partial_limit_cents: int|null}
     *
     * @throws InvalidArgumentException
     */
    public function traditionalIraDeductibility(
        int $magiCents,
        string $filingStatus,
        bool $coveredByPlan,
        bool $spouseCoveredByPlan,
        int $year = 2026
    ): array {
        $this->validateYear($year);
        $this->validateFilingStatus($filingStatus, $year);

        $cfg = config("tax-rules.{$year}.ira");

        // Determine which phase-out range applies
        if ($coveredByPlan) {
            $phaseoutKey = 'traditional_deduction_phaseout_covered';
            if (! isset($cfg[$phaseoutKey][$filingStatus])) {
                // Filing status not in the covered phase-out table — fully deductible
                return ['deductible' => true, 'partial_limit_cents' => null];
            }
            $phaseout = $cfg[$phaseoutKey][$filingStatus];
        } elseif ($spouseCoveredByPlan && $filingStatus === 'married_joint') {
            $phaseoutKey = 'traditional_deduction_phaseout_spouse_covered';
            $phaseout = $cfg[$phaseoutKey]['married_joint'];
        } else {
            // Neither covered: fully deductible
            return ['deductible' => true, 'partial_limit_cents' => null];
        }

        $fromCents = $phaseout['from'] * 100;
        $toCents = $phaseout['to'] * 100;

        if ($magiCents <= $fromCents) {
            return ['deductible' => true, 'partial_limit_cents' => null];
        }

        if ($magiCents >= $toCents) {
            return ['deductible' => false, 'partial_limit_cents' => 0];
        }

        $window = $toCents - $fromCents;
        $phaseOutFraction = ($magiCents - $fromCents) / $window;
        $annualLimitCents = $cfg['annual_limit'] * 100;
        $partialCents = (int) round($annualLimitCents * (1 - $phaseOutFraction));

        return [
            'deductible' => $partialCents > 0,
            'partial_limit_cents' => max(0, $partialCents),
        ];
    }

    // ── TAX-06: Self-Employment Tax & QBI ─────────────────────────────────

    /**
     * Compute self-employment (SECA) tax.
     *
     * @return array{se_tax_cents: int, deductible_half_cents: int}
     *
     * @throws InvalidArgumentException
     */
    public function selfEmploymentTax(int $netSelfEmploymentProfitCents, int $year = 2026): array
    {
        $this->validateYear($year);

        if ($netSelfEmploymentProfitCents < 0) {
            throw new InvalidArgumentException('Net self-employment profit must be >= 0 cents.');
        }

        $cfg = config("tax-rules.{$year}.se_tax");

        $netEarningsCents = (int) round($netSelfEmploymentProfitCents * $cfg['net_earnings_multiplier']);
        $wageBaseCents = $cfg['ss_wage_base'] * 100;

        $ssTaxCents = (int) round(min($netEarningsCents, $wageBaseCents) * $cfg['ss_rate']);
        $medicareTaxCents = (int) round($netEarningsCents * $cfg['medicare_rate']);
        $seTaxCents = $ssTaxCents + $medicareTaxCents;
        $deductibleHalfCents = (int) round($seTaxCents * $cfg['deductible_fraction']);

        return [
            'se_tax_cents' => $seTaxCents,
            'deductible_half_cents' => $deductibleHalfCents,
        ];
    }

    /**
     * Compute QBI (§199A) deduction eligibility.
     *
     * Phase 10 scope: below-threshold estimate + eligibility flag.
     * Above-threshold non-SSTB returns eligible=true with null deduction (W-2 wage limitation
     * out of scope for Phase 10 — surface as professional-review finding in Phase 11).
     *
     * @return array{eligible: bool, deduction_cents: int|null, reason: string}
     *
     * @throws InvalidArgumentException
     */
    public function qbiDeduction(
        int $qualifiedBusinessIncomeCents,
        int $taxableIncomeCents,
        string $filingStatus,
        bool $isSstb,
        int $year = 2026
    ): array {
        $this->validateYear($year);
        $this->validateFilingStatus($filingStatus, $year);

        if ($qualifiedBusinessIncomeCents < 0) {
            throw new InvalidArgumentException('Qualified business income must be >= 0 cents.');
        }

        $this->validateIncome($taxableIncomeCents);

        $cfg = config("tax-rules.{$year}.qbi");

        // Determine phase-out start and window by filing status
        if (in_array($filingStatus, ['married_joint'], true)) {
            $phaseOutStartCents = $cfg['phase_out_start_joint'] * 100;
            $phaseOutWindowCents = $cfg['phase_out_window_joint'] * 100;
        } else {
            $phaseOutStartCents = $cfg['phase_out_start_single'] * 100;
            $phaseOutWindowCents = $cfg['phase_out_window_single'] * 100;
        }

        $phaseOutEndCents = $phaseOutStartCents + $phaseOutWindowCents;

        // Below phase-out start: full 20% deduction (capped at taxable income)
        if ($taxableIncomeCents <= $phaseOutStartCents) {
            $rawDeductionCents = (int) round(min($qualifiedBusinessIncomeCents, $taxableIncomeCents) * $cfg['rate']);

            // Apply OBBBA minimum deduction floor ($400 if QBI >= $1,000)
            $minimumFloorCents = $cfg['minimum_deduction'] * 100;
            $minimumQbiCents = $cfg['minimum_qbi_for_floor'] * 100;
            if ($qualifiedBusinessIncomeCents >= $minimumQbiCents && $rawDeductionCents < $minimumFloorCents) {
                $rawDeductionCents = $minimumFloorCents;
            }

            return [
                'eligible' => true,
                'deduction_cents' => $rawDeductionCents,
                'reason' => 'below_phase_out_threshold',
            ];
        }

        // SSTB above full phase-out end: no deduction
        if ($isSstb && $taxableIncomeCents >= $phaseOutEndCents) {
            return [
                'eligible' => false,
                'deduction_cents' => 0,
                'reason' => 'sstb_fully_phased_out',
            ];
        }

        // SSTB in phase-out range: prorate reduction
        if ($isSstb) {
            $phaseOutFraction = ($taxableIncomeCents - $phaseOutStartCents) / $phaseOutWindowCents;
            $reducedQbiCents = (int) round($qualifiedBusinessIncomeCents * (1 - $phaseOutFraction));
            $deductionCents = (int) round(min($reducedQbiCents, $taxableIncomeCents) * $cfg['rate']);

            return [
                'eligible' => $deductionCents > 0,
                'deduction_cents' => max(0, $deductionCents),
                'reason' => 'sstb_partial_phase_out',
            ];
        }

        // Non-SSTB above phase-out start: W-2 wage limitation applies — out of scope for Phase 10
        return [
            'eligible' => true,
            'deduction_cents' => null,
            'reason' => 'above_threshold_requires_professional_review',
        ];
    }

    // ── General: Tax Savings Estimate ─────────────────────────────────────

    /**
     * Estimate tax saved from a deduction by multiplying it by the marginal rate.
     *
     * @throws InvalidArgumentException
     */
    public function taxSavingsFromDeductionCents(
        int $deductionCents,
        int $taxableIncomeCents,
        string $filingStatus,
        int $year = 2026
    ): int {
        $this->validateYear($year);
        $this->validateFilingStatus($filingStatus, $year);
        $this->validateIncome($taxableIncomeCents);

        $rate = $this->marginalRate($taxableIncomeCents, $filingStatus, $year);

        return (int) round($deductionCents * $rate);
    }

    // ── TAX-09: Versioned Rule Validator ─────────────────────────────────────

    /**
     * Validate a rule from the config/tax-detection.php rule registry.
     *
     * Returns an array with:
     *   - suppressed (bool): true if effective_end has passed OR band is suppress|hard_block
     *   - band (string):     the rule's band value (auto|conditional|specialist|suppress|hard_block)
     *   - status (string):   'expired' if past effective_end, otherwise the rule's own status field
     *   - stale (bool):      true if now minus last_verified exceeds config('tax-detection.staleness_days')
     *
     * This is the single enforcement point — every Phase 11 detector calls this before emitting
     * a finding. A suppressed=true result must short-circuit emission entirely.
     *
     * ZERO HTTP calls are made by this method (no-Claude guard for rule validation).
     *
     * @throws InvalidArgumentException if rule_id is not found in the registry
     */
    public function validateRule(string $ruleId): array
    {
        $rule = config("tax-detection.rules.{$ruleId}");

        if ($rule === null) {
            throw new InvalidArgumentException("Unknown rule: {$ruleId}. Check config/tax-detection.php rules registry.");
        }

        $today = now()->toDateString();

        $expired = $rule['effective_end'] !== null && $today > $rule['effective_end'];

        $staleDays = config('tax-detection.staleness_days', 90);
        $lastVerified = Carbon::parse($rule['last_verified']);
        $stale = now()->diffInDays($lastVerified, true) > $staleDays;

        return [
            'suppressed' => $expired || in_array($rule['band'], ['suppress', 'hard_block'], true),
            'band' => $rule['band'],
            'status' => $expired ? 'expired' : $rule['status'],
            'stale' => $stale,
        ];
    }

    // ── FLAG-08: Config-Driven Materiality Gate ───────────────────────────────

    /**
     * Determine whether a transaction / pattern passes the materiality gate for interrogation.
     *
     * Gates read EXCLUSIVELY from config('tax-detection.materiality') — never from literals.
     * A Pest test greps this method's region and fails if any raw threshold literal is present.
     *
     * Logic:
     *  1. address-matched or loan-servicer → always interrogate (returns true)
     *  2. below the auto-floor → never interrogate (returns false)
     *  3. recurring pattern → passes if annual total meets the recurring threshold
     *  4. single transaction → passes if amount meets the interrogate threshold
     *
     * All amounts are in INTEGER CENTS.
     *
     * @param  int  $amountCents  The single-transaction amount in cents.
     * @param  bool  $isRecurring  Whether this is a recurring-payee pattern.
     * @param  int  $annualTotalCents  Annualized total for a recurring pattern (in cents).
     * @param  bool  $addressMatch  True if the transaction is address-matched.
     * @param  bool  $loanServicer  True if the transaction comes from a loan servicer.
     */
    public function passesMaterialityGate(
        int $amountCents,
        bool $isRecurring,
        int $annualTotalCents,
        bool $addressMatch = false,
        bool $loanServicer = false
    ): bool {
        $cfg = config('tax-detection.materiality');

        // Always interrogate: address-matched and loan-servicer per D2 / FLAG-08
        if ($addressMatch || $loanServicer) {
            return true;
        }

        // Recurring pattern: gate on annualized total — auto-floor does NOT apply to recurring
        // (D2 spec: "$100 single-txn floor UNLESS recurring"; recurring has its own annual gate)
        if ($isRecurring) {
            return $annualTotalCents >= $cfg['recurring_pattern_annual_cents'];
        }

        // Single transaction: auto-floor applies — below $100 is never interrogated
        if ($amountCents < $cfg['single_txn_auto_floor_cents']) {
            return false;
        }

        // Single transaction at or above auto-floor: interrogate when it meets the interrogate threshold
        return $amountCents >= $cfg['single_txn_interrogate_cents'];
    }

    // ── FLAG-06: Band-to-Severity Mapper ─────────────────────────────────────

    /**
     * Map a rule band to the OptimizationFinding severity vocabulary.
     *
     * Band → Severity mapping (consumed by FLAG-06 in Plan 11-03):
     *   auto       → 'high'        (surfaced prominently; confidence sufficient for pre-fill)
     *   conditional → 'medium'     (additional verification required before surfacing)
     *   specialist  → 'medium'     (pro-review routing; same display tier as conditional)
     *   suppress    → 'suppressed' (never rendered; findings blocked by suppress band)
     *   hard_block  → 'blocked'    (Phase 13 SAFE-06 hard-block; finding refused at emission)
     *
     * @throws InvalidArgumentException for unknown band values
     */
    public function bandToSeverity(string $band): string
    {
        return match ($band) {
            'auto' => 'high',
            'conditional' => 'medium',
            'specialist' => 'medium',
            'suppress' => 'suppressed',
            'hard_block' => 'blocked',
            // FLAG-16: time-critical alarms (83b, QOF, QSBS) route at highest severity
            'time_critical' => 'critical',
            default => throw new InvalidArgumentException(
                "Unknown band: {$band}. Allowed: auto, conditional, specialist, suppress, hard_block, time_critical."
            ),
        };
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCENARIOS-SPEC §B.3 — Optimize-My-Income scenario math (SCN-01…SCN-07)
    //
    // Pure, integer-cents, config-only. Zero Claude / zero HTTP. Every threshold
    // traces to config/tax-rules.php or config/optimizer-scenarios.php — a Pest
    // grep guard (NoLiteralGuardTest) fails if any raw IRS literal appears in the
    // method bodies below. Small structural constants (0/1/2 bounds, the /100 pct
    // divisor, the *100 cents converter) are the only permitted literals.
    // ══════════════════════════════════════════════════════════════════════

    /** W-4 Step 1(c) statuses. 'single_or_mfs' maps to the 'single' tables (Pub 15-T column). */
    protected array $allowedW4Statuses = ['single_or_mfs', 'married_joint', 'head_of_household'];

    /**
     * SCN-01 — Per-paycheck federal withholding approximation (Pub 15-T percentage method).
     *
     * DECISION-SUPPORT ESTIMATE (labeled as such in UI copy). W-4 Step 2 unchecked;
     * extra withholding beyond the explicit knob is handled by callers.
     *
     * Note [M11]: 'single_or_mfs' is computed with the 'single' bracket + standard-deduction
     * tables per the Pub 15-T "Single or Married filing separately" column convention. The
     * config single/MFS tables diverge only at the 37% threshold ($640,600 vs $384,350) —
     * immaterial to withholding at the wage levels this feature operates. Annual-tax math
     * (computeScenarioOutcome Step 3) always uses the true CONFIRMED filing status.
     *
     * @throws InvalidArgumentException
     */
    public function estimatePeriodWithholdingCents(
        int $periodGrossCents,
        int $periodPreTaxCents,
        string $w4FilingStatus,
        int $dependentsUnder17,
        int $otherDependents,
        int $payPeriodsPerYear,
        int $year = 2026,
    ): int {
        $this->validateYear($year);
        $status = $this->mapW4ToTableStatus($w4FilingStatus);

        $annualWages = ($periodGrossCents - $periodPreTaxCents) * $payPeriodsPerYear;
        $adjusted = max(0, $annualWages - $this->standardDeductionCents($status, null, $year));

        $brackets = config("tax-rules.{$year}.brackets.{$status}");
        $tentative = $this->computeBracketTax($adjusted, $brackets);

        $ctcCents = config("tax-rules.{$year}.detection.ctc_amount") * 100;
        $odcCents = config("tax-rules.{$year}.detection.odc_amount") * 100;
        $credits = $dependentsUnder17 * $ctcCents + $otherDependents * $odcCents;

        return max(0, (int) round(($tentative - $credits) / $payPeriodsPerYear));
    }

    /**
     * SCN-02 — Employee-share FICA on annual FICA wages.
     *
     * Employee pays half of the combined FICA rate (ss_rate/2 + medicare_rate/2). Social
     * Security is capped at ss_wage_base; Medicare is uncapped. The Additional Medicare
     * surtax (0.9%) is INTENTIONALLY EXCLUDED from per-paycheck estimates (documented
     * assumption; high earners get a caution line elsewhere — never silent wrongness).
     *
     * Callers pass wages already net of §125 cafeteria reducers (payroll HSA is FICA-exempt;
     * 401(k) deferrals are NOT — they still incur FICA).
     *
     * @return array{social_security_cents:int, medicare_cents:int, total_cents:int}
     *
     * @throws InvalidArgumentException
     */
    public function employeeFicaCents(int $annualFicaWagesCents, int $year = 2026): array
    {
        $this->validateYear($year);

        $cfg = config("tax-rules.{$year}.se_tax");
        $wageBaseCents = $cfg['ss_wage_base'] * 100;

        $ssWages = min($annualFicaWagesCents, $wageBaseCents);
        $ss = (int) round($ssWages * ($cfg['ss_rate'] / 2));
        $medicare = (int) round($annualFicaWagesCents * ($cfg['medicare_rate'] / 2));

        return [
            'social_security_cents' => $ss,
            'medicare_cents' => $medicare,
            'total_cents' => $ss + $medicare,
        ];
    }

    /**
     * SCN-03 — Employer match capture.
     *
     * match = gross × min(contributionPct, thresholdPct) × matchPct, with percentages
     * expressed as 0–100 floats. Contribution below the threshold yields the reduced
     * capture; at/above the threshold yields the full match.
     */
    public function matchCaptureCents(
        int $annualGrossCents,
        float $contributionPct,
        float $matchPct,
        float $matchThresholdPct,
    ): int {
        $effectivePct = min($contributionPct, $matchThresholdPct);

        return (int) round($annualGrossCents * ($effectivePct / 100) * ($matchPct / 100));
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    /**
     * Map a W-4 Step 1(c) status to the withholding-table filing status.
     * 'single_or_mfs' → 'single' (Pub 15-T column convention, §B.1 K1 [M11]).
     * Also tolerates the confirmed-status vocabulary ('single'/'married_separate') so
     * callers passing a W-4-on-file value in either form resolve consistently.
     *
     * @throws InvalidArgumentException
     */
    private function mapW4ToTableStatus(string $w4FilingStatus): string
    {
        return match ($w4FilingStatus) {
            'single_or_mfs', 'single', 'married_separate' => 'single',
            'married_joint' => 'married_joint',
            'head_of_household' => 'head_of_household',
            default => throw new InvalidArgumentException(
                "Unknown W-4 filing status: {$w4FilingStatus}. Allowed: ".implode(', ', $this->allowedW4Statuses)
            ),
        };
    }

    /**
     * Iterate over bracket array to compute tax. Config values are in dollars; converts internally.
     *
     * @param  array<int, array{rate: float, from: int, to: int|null}>  $brackets
     */
    private function computeBracketTax(int $incomeCents, array $brackets): int
    {
        $tax = 0;

        foreach ($brackets as $bracket) {
            $fromCents = $bracket['from'] * 100;
            $toCents = $bracket['to'] !== null ? $bracket['to'] * 100 : PHP_INT_MAX;

            if ($incomeCents <= $fromCents) {
                break;
            }

            $taxableInBracket = min($incomeCents, $toCents) - $fromCents;
            $tax += (int) round($taxableInBracket * $bracket['rate']);
        }

        return $tax;
    }
}
