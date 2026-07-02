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

    /**
     * SCN-04 — Long-horizon illustration (D9.7): ordinary-annuity future value at the config
     * growth-rate LOW and HIGH bounds. Returns a labeled RANGE with an assumptions array —
     * NEVER a single guaranteed figure. Zero/negative horizon → zeros with horizon_years=0
     * (the UI omits the illustration block).
     *
     * @return array{low_cents:int, high_cents:int, horizon_years:int,
     *               growth_rate_low:float, growth_rate_high:float, assumptions:string[]}
     */
    public function futureValueRangeCents(int $annualContribCents, int $horizonYears, int $year = 2026): array
    {
        $growthLow = config('optimizer-scenarios.assumptions.illustrative_growth_rate_low');
        $growthHigh = config('optimizer-scenarios.assumptions.illustrative_growth_rate_high');

        $assumptions = [
            'Illustration only — not a guarantee or projection of actual returns.',
            'Assumes a constant annual contribution at the amount shown for the full horizon.',
            'Growth is shown as a range from '.($growthLow * 100).'% to '.($growthHigh * 100).'% per year, compounded annually.',
            'Actual results vary with markets, fees, timing, and future contribution changes.',
        ];

        if ($horizonYears <= 0) {
            return [
                'low_cents' => 0,
                'high_cents' => 0,
                'horizon_years' => 0,
                'growth_rate_low' => $growthLow,
                'growth_rate_high' => $growthHigh,
                'assumptions' => $assumptions,
            ];
        }

        $a = $this->annuityFutureValueCents($annualContribCents, $growthLow, $horizonYears);
        $b = $this->annuityFutureValueCents($annualContribCents, $growthHigh, $horizonYears);

        return [
            'low_cents' => min($a, $b),
            'high_cents' => max($a, $b),
            'horizon_years' => $horizonYears,
            'growth_rate_low' => $growthLow,
            'growth_rate_high' => $growthHigh,
            'assumptions' => $assumptions,
        ];
    }

    /**
     * SCN-05 — Projected MAGI under a knob vector (educational approximation).
     * MAGI ≈ gross + SE income − trad401k − HSA − deductible traditional IRA (partial-cap aware).
     * Roth contributions do NOT reduce MAGI. Standard deduction is NOT applied (MAGI ≈ AGI).
     */
    public function projectedMagiCents(array $baseline, array $knobs, int $year = 2026): int
    {
        $a = $this->deriveKnobAmounts($baseline, $this->normalizeKnobs($knobs));

        $gross = (int) ($baseline['annual_gross_cents'] ?? 0);
        $se = (int) ($baseline['se_income_cents'] ?? 0);

        $magiBeforeIra = max(0, $gross + $se - $a['trad401k'] - $a['hsa']);
        $deductibleIra = $this->deductibleTradIraCents($baseline, $a['iraTrad'], $a['trad401k'], $magiBeforeIra, $year);

        return max(0, $magiBeforeIra - $deductibleIra);
    }

    /**
     * SCN-06 — ACA cliff headroom. Positive = MAGI room below the 400%-FPL cliff; negative = over.
     * Threshold selection mirrors AcaCliffMonitor: married_joint → family-of-4 threshold, else single.
     *
     * @throws InvalidArgumentException
     */
    public function acaCliffHeadroomCents(int $projectedMagiCents, string $filingStatus, int $year = 2026): int
    {
        $this->validateYear($year);

        $cfg = config("tax-rules.{$year}.detection");
        $thresholdDollars = $filingStatus === 'married_joint'
            ? $cfg['aca_fpl_threshold_family4']
            : $cfg['aca_fpl_threshold_single'];

        return ($thresholdDollars * 100) - $projectedMagiCents;
    }

    /**
     * SCN-07 — The core: one knob vector → three outcome metrics (§B.4 algorithm, §B.6 shape).
     *
     * Clamps a COPY of the knob vector (never trusts/mutates input — Pitfall 4 / security boundary),
     * runs the ACA cliff-before-Roth guard AFTER limit clamps and BEFORE tax math, then computes
     * annual federal tax on the CONFIRMED filing status, per-paycheck take-home on the W-4 status,
     * and retirement deltas. Subsidy/clawback dollars are NEVER computed (FLAG-22 rail).
     *
     * @return array §B.6 outcome shape (includes the clamped/guarded knob vector + guards.clamps[])
     *
     * @throws InvalidArgumentException
     */
    public function computeScenarioOutcome(array $baseline, array $knobs, int $year = 2026): array
    {
        $this->validateYear($year);

        $status = $baseline['filing_status'];
        $this->validateFilingStatus($status, $year);

        $age = $baseline['age'] ?? null;
        $gross = (int) ($baseline['annual_gross_cents'] ?? 0);
        $se = (int) ($baseline['se_income_cents'] ?? 0);
        $periods = max(1, (int) ($baseline['pay_periods_per_year'] ?? 0));

        $clamps = [];
        $guards = [
            'aca_cliff_applied' => false,
            'aca_need_remaining_cents' => 0,
            'mandatory_roth_catchup_applied' => false,
            'medicare_hsa_guard' => false,
            'safe_harbor_floor_applied' => false,
            'clamps' => [],
        ];

        // Work exclusively on a normalized COPY — hostile input never leaks into the math.
        $v = $this->normalizeKnobs($knobs);

        // ── Step 1: ordered clamps ─────────────────────────────────────────
        // 1a. 401(k) full-year deferral limit incl. catch-ups.
        $deferralCents = (int) round($gross * $v['k401']['deferral_pct'] / 100);
        $limit401k = $this->full401kLimitCents($age, $year);
        if ($deferralCents > $limit401k) {
            $deferralCents = $limit401k;
            $v['k401']['deferral_pct'] = $gross > 0 ? $deferralCents / $gross * 100 : 0;
            $clamps[] = '401k_annual_limit';
        }
        $roth401k = (int) round($deferralCents * $v['k401']['roth_share_pct'] / 100);
        $trad401k = $deferralCents - $roth401k;

        // 1b. Mandatory Roth catch-up (SECURE 2.0 §603): force the catch-up excess to Roth.
        $baseDeferralLimit = config("tax-rules.{$year}.401k.employee_deferral") * 100;
        $priorFica = $baseline['prior_year_fica_wages_cents'] ?? null;
        if ($priorFica !== null
            && $this->requiresMandatoryRothCatchup($priorFica, $year)
            && $deferralCents > $baseDeferralLimit
        ) {
            $excess = $deferralCents - $baseDeferralLimit;
            if ($roth401k < $excess) {
                $roth401k = $excess;
                $trad401k = $deferralCents - $roth401k;
                $guards['mandatory_roth_catchup_applied'] = true;
                $clamps[] = 'mandatory_roth_catchup';
            }
        }

        // 1c. HSA coverage-limit clamp + Medicare lookback hard stop.
        $hsaLimit = $this->fullHsaLimitCents($baseline['hsa_coverage_type'] ?? 'self_only', $age, $year);
        $hsa = (int) $v['hsa']['annual_election_cents'];
        if ($hsa > $hsaLimit) {
            $hsa = $hsaLimit;
            $clamps[] = 'hsa_annual_limit';
        }
        if ($this->medicareHsaBlocked($baseline, $age, $year)) {
            $hsa = (int) ($baseline['current']['hsa_cents'] ?? 0);
            $guards['medicare_hsa_guard'] = true;
            $clamps[] = 'medicare_hsa_lookback';
        }
        $v['hsa']['annual_election_cents'] = $hsa;

        // 1d. IRA: Roth phase-out cap, trad deductibility cap, then combined shared-room clamp.
        $iraTradYtd = (int) ($baseline['current']['ira_trad_ytd_cents'] ?? 0);
        $iraRothYtd = (int) ($baseline['current']['ira_roth_ytd_cents'] ?? 0);
        $iraRoom = $this->remainingIraRoomCents($iraTradYtd + $iraRothYtd, $age, $year);
        $iraTrad = (int) $v['ira']['traditional_cents'];
        $iraRoth = (int) $v['ira']['roth_cents'];
        $magiBeforeIra = max(0, $gross + $se - $trad401k - $hsa);

        $rothElig = $this->rothIraEligibility($magiBeforeIra, $status, $year);
        $rothAllowed = max(0, $rothElig['limit_cents'] - $iraRothYtd);
        if ($iraRoth > $rothAllowed) {
            $iraRoth = $rothAllowed;
            $clamps[] = 'roth_ira_phaseout';
        }

        $covered = $this->coveredByPlan($baseline, $trad401k);
        $dedCap = $this->tradIraDeductibleCapCents($baseline, $magiBeforeIra, $covered, $year);
        if ($iraTrad > $dedCap) {
            $iraTrad = $dedCap;
            $clamps[] = 'trad_ira_deductibility';
        }

        if ($iraTrad + $iraRoth > $iraRoom) {
            $iraTrad = min($iraTrad, $iraRoom);
            $iraRoth = min($iraRoth, max(0, $iraRoom - $iraTrad));
            $clamps[] = 'ira_shared_limit';
        }
        $v['ira']['traditional_cents'] = $iraTrad;
        $v['ira']['roth_cents'] = $iraRoth;

        // 1e. Auto-transfer surplus cap (K6).
        $transferCap = $this->perPeriodSurplusCapCents($baseline, $periods);
        $transfer = (int) $v['transfer']['per_period_cents'];
        if ($transfer > $transferCap) {
            $transfer = max(0, $transferCap);
            $clamps[] = 'transfer_surplus_cap';
        }
        $v['transfer']['per_period_cents'] = $transfer;

        // Persist the clamped 401(k) split back onto the vector.
        $v['k401']['roth_share_pct'] = $deferralCents > 0 ? $roth401k / $deferralCents * 100 : $v['k401']['roth_share_pct'];

        // ── Step 2: ACA cliff guard (cliff-before-Roth; AFTER limits, BEFORE tax math) ──
        if ($baseline['is_marketplace_enrollee'] ?? false) {
            $buffer = config('optimizer-scenarios.assumptions.aca_cliff_buffer_cents');
            // iraTrad is already deductible-capped in Step 1d, so it reduces MAGI dollar-for-dollar.
            $magi = max(0, $gross + $se - $trad401k - $hsa - $iraTrad);
            $need = $buffer - $this->acaCliffHeadroomCents($magi, $status, $year);

            if ($need > 0) {
                // (a) Roth 401(k) → Traditional (dollar-for-dollar MAGI reducer).
                $shift = min($need, $roth401k);
                $roth401k -= $shift;
                $trad401k += $shift;
                $need -= $shift;

                // (b) Roth IRA → Traditional IRA, up to the deductible headroom.
                if ($need > 0 && $iraRoth > 0) {
                    $magiAfterA = max(0, $gross + $se - $trad401k - $hsa);
                    $dedCapA = $this->tradIraDeductibleCapCents($baseline, $magiAfterA, $covered, $year);
                    $dedHeadroom = max(0, $dedCapA - $iraTrad);
                    $shift2 = min($need, $iraRoth, $dedHeadroom);
                    $iraRoth -= $shift2;
                    $iraTrad += $shift2;
                    $need -= $shift2;
                }

                // (c) Still short → drop any remaining Roth allocations entirely (invariant §B.5:
                //     both Roth knobs at 0 when flagged) and flag the residual need.
                if ($need > 0) {
                    $roth401k = 0;
                    $iraRoth = 0;
                    $guards['aca_need_remaining_cents'] = $need;
                }

                $v['k401']['roth_share_pct'] = $deferralCents > 0 ? $roth401k / $deferralCents * 100 : 0;
                $v['ira']['traditional_cents'] = $iraTrad;
                $v['ira']['roth_cents'] = $iraRoth;
                $guards['aca_cliff_applied'] = true;
                $clamps[] = 'aca_cliff_guard';
            }
        }

        // ── Step 3: annual federal tax (CONFIRMED status) ──────────────────
        $curTrad401k = (int) ($baseline['current']['trad_401k_cents'] ?? 0);
        $curRoth401k = (int) ($baseline['current']['roth_401k_cents'] ?? 0);
        $curHsa = (int) ($baseline['current']['hsa_cents'] ?? 0);
        $curIraTrad = $iraTradYtd;
        $curIraRoth = $iraRothYtd;

        $stdDeduction = $this->standardDeductionCents($status, $age, $year);

        $curMagiBeforeIra = max(0, $gross + $se - $curTrad401k - $curHsa);
        $curDedIra = $this->deductibleTradIraCents($baseline, $curIraTrad, $curTrad401k, $curMagiBeforeIra, $year);
        $curPretax = $curTrad401k + $curHsa + $curDedIra;
        $curTaxable = max(0, $gross + $se - $curPretax - $stdDeduction);
        $curTax = $this->computeTax($curTaxable, $status, $year);

        $scnDedIra = $iraTrad; // already capped at the deductible amount in Step 1d.
        $scnPretax = $trad401k + $hsa + $scnDedIra;
        $scnTaxable = max(0, $gross + $se - $scnPretax - $stdDeduction);
        $scnTax = $this->computeTax($scnTaxable, $status, $year);

        $federalTaxAnnualDelta = $scnTax - $curTax;

        // ── Step 4: per-paycheck take-home ─────────────────────────────────
        $periodGross = (int) round($gross / $periods);

        $scnWH = $this->estimatePeriodWithholdingCents(
            $periodGross,
            (int) round(($trad401k + $hsa) / $periods),
            $v['w4']['filing_status'],
            (int) $v['w4']['dependents_under_17'],
            (int) $v['w4']['other_dependents'],
            $periods,
            $year,
        );
        $scnFica = (int) round($this->employeeFicaCents(max(0, $gross - $hsa), $year)['total_cents'] / $periods);
        $scnTakeHome = $periodGross - (int) round(($trad401k + $roth401k + $hsa) / $periods) - $scnWH - $scnFica;

        $w4OnFile = $baseline['w4_on_file'] ?? [];
        $w4Known = ! empty($w4OnFile['filing_status']);
        if ($w4Known) {
            $curWH = $this->estimatePeriodWithholdingCents(
                $periodGross,
                (int) round(($curTrad401k + $curHsa) / $periods),
                $w4OnFile['filing_status'],
                (int) ($w4OnFile['dependents_claimed'] ?? 0),
                0,
                $periods,
                $year,
            );
            $w4DeltaIncluded = true;
        } elseif (($baseline['annual_withholding_cents'] ?? null) !== null) {
            $curWH = (int) round($baseline['annual_withholding_cents'] / $periods);
            $w4DeltaIncluded = false;
        } else {
            // No W-4 evidence: align baseline withholding to the scenario W-4 (K1 delta = 0).
            $curWH = $this->estimatePeriodWithholdingCents(
                $periodGross,
                (int) round(($curTrad401k + $curHsa) / $periods),
                $v['w4']['filing_status'],
                (int) $v['w4']['dependents_under_17'],
                (int) $v['w4']['other_dependents'],
                $periods,
                $year,
            );
            $w4DeltaIncluded = false;
        }
        $curFica = (int) round($this->employeeFicaCents(max(0, $gross - $curHsa), $year)['total_cents'] / $periods);
        $curTakeHome = $periodGross - (int) round(($curTrad401k + $curRoth401k + $curHsa) / $periods) - $curWH - $curFica;

        $perPaycheckDelta = $scnTakeHome - $curTakeHome;

        // Safe-harbor guardrail (T8): absent prior-year liability, never propose withholding
        // below current-year computed tax — flag the conservative floor.
        if (($baseline['prior_year_federal_liability_cents'] ?? null) === null) {
            $floorPerPeriod = (int) ceil($scnTax / $periods);
            if ($scnWH < $floorPerPeriod) {
                $guards['safe_harbor_floor_applied'] = true;
            }
        }

        // ── Step 5: retirement deltas + illustration ───────────────────────
        $scnContrib = $trad401k + $roth401k + $iraTrad + $iraRoth;
        $curContrib = $curTrad401k + $curRoth401k + $curIraTrad + $curIraRoth;
        $contribDelta = $scnContrib - $curContrib;

        $matchPct = (float) ($baseline['employer']['match_pct'] ?? 0);
        $threshPct = (float) ($baseline['employer']['match_threshold_pct'] ?? 0);
        $scnMatch = $this->matchCaptureCents($gross, (float) $v['k401']['deferral_pct'], $matchPct, $threshPct);
        $curMatch = $this->matchCaptureCents($gross, (float) ($baseline['current']['deferral_pct'] ?? 0), $matchPct, $threshPct);
        $matchDelta = $scnMatch - $curMatch;

        $hsaDelta = $hsa - $curHsa;

        $targetAge = (int) ($baseline['target_retirement_age'] ?? config('optimizer-scenarios.assumptions.default_retirement_age'));
        $horizon = $age !== null ? max(0, $targetAge - $age) : 0;
        $illustration = ($age !== null && $horizon > 0)
            ? $this->futureValueRangeCents(max(0, $contribDelta + $matchDelta), $horizon, $year)
            : null;

        $guards['clamps'] = $clamps;

        return [
            'knobs' => $v,
            'take_home' => [
                'per_paycheck_delta_cents' => $perPaycheckDelta,
                'annual_delta_cents' => $perPaycheckDelta * $periods,
                'w4_delta_included' => $w4DeltaIncluded,
            ],
            'federal_tax' => ['annual_delta_cents' => $federalTaxAnnualDelta],
            'retirement' => [
                'annual_contributions_delta_cents' => $contribDelta,
                'employer_match_delta_cents' => $matchDelta,
                'hsa_annual_delta_cents' => $hsaDelta,
                'illustration' => $illustration,
            ],
            'guards' => $guards,
        ];
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    /**
     * Ordinary-annuity future value in cents: P × ((1+g)^n − 1) / g. Structural math helper
     * (outside the no-literal-guarded SCN method bodies).
     */
    private function annuityFutureValueCents(int $paymentCents, float $growthRate, int $years): int
    {
        if ($growthRate == 0.0) {
            return $paymentCents * $years;
        }

        $factor = (pow(1 + $growthRate, $years) - 1) / $growthRate;

        return (int) round($paymentCents * $factor);
    }

    /**
     * Normalize an untrusted knob vector into a full, well-typed COPY with all keys present.
     * The engine never mutates or trusts caller input (Pitfall 4 / security boundary T-14-02-01).
     */
    private function normalizeKnobs(array $knobs): array
    {
        return [
            'w4' => [
                'filing_status' => $knobs['w4']['filing_status'] ?? 'single_or_mfs',
                'dependents_under_17' => max(0, (int) ($knobs['w4']['dependents_under_17'] ?? 0)),
                'other_dependents' => max(0, (int) ($knobs['w4']['other_dependents'] ?? 0)),
            ],
            'k401' => [
                'deferral_pct' => max(0, (float) ($knobs['k401']['deferral_pct'] ?? 0)),
                'roth_share_pct' => max(0, (float) ($knobs['k401']['roth_share_pct'] ?? 0)),
            ],
            'hsa' => [
                'annual_election_cents' => max(0, (int) ($knobs['hsa']['annual_election_cents'] ?? 0)),
            ],
            'ira' => [
                'traditional_cents' => max(0, (int) ($knobs['ira']['traditional_cents'] ?? 0)),
                'roth_cents' => max(0, (int) ($knobs['ira']['roth_cents'] ?? 0)),
            ],
            'transfer' => [
                'per_period_cents' => max(0, (int) ($knobs['transfer']['per_period_cents'] ?? 0)),
            ],
        ];
    }

    /**
     * Derive absolute cent amounts (401k trad/roth split, HSA, IRA) from a normalized knob vector.
     *
     * @return array{gross:int, deferralCents:int, roth401k:int, trad401k:int, hsa:int, iraTrad:int, iraRoth:int}
     */
    private function deriveKnobAmounts(array $baseline, array $normalizedKnobs): array
    {
        $gross = (int) ($baseline['annual_gross_cents'] ?? 0);
        $deferralCents = (int) round($gross * $normalizedKnobs['k401']['deferral_pct'] / 100);
        $roth401k = (int) round($deferralCents * $normalizedKnobs['k401']['roth_share_pct'] / 100);

        return [
            'gross' => $gross,
            'deferralCents' => $deferralCents,
            'roth401k' => $roth401k,
            'trad401k' => $deferralCents - $roth401k,
            'hsa' => (int) $normalizedKnobs['hsa']['annual_election_cents'],
            'iraTrad' => (int) $normalizedKnobs['ira']['traditional_cents'],
            'iraRoth' => (int) $normalizedKnobs['ira']['roth_cents'],
        ];
    }

    /** Full-year 401(k) employee-deferral limit incl. catch-ups (reuses the shipped headroom helper). */
    private function full401kLimitCents(?int $age, int $year): int
    {
        return $this->remaining401kRoomCents(0, $age, $year);
    }

    /** Full-year HSA limit for the coverage type incl. 55+ catch-up (reuses the shipped helper). */
    private function fullHsaLimitCents(string $coverageType, ?int $age, int $year): int
    {
        return $this->remainingHsaRoomCents(0, $coverageType, $age, $year);
    }

    /** Is the taxpayer an active workplace-plan participant (for IRA deductibility)? */
    private function coveredByPlan(array $baseline, int $scenarioTrad401k): bool
    {
        return $scenarioTrad401k > 0
            || (int) ($baseline['current']['trad_401k_cents'] ?? 0) > 0
            || (int) ($baseline['current']['roth_401k_cents'] ?? 0) > 0
            || (bool) ($baseline['employer']['has_401k'] ?? false);
    }

    /**
     * Deductible-contribution cap for a traditional IRA at a given MAGI. Returns the max deductible
     * cents (0 when fully non-deductible; the shared annual limit when fully deductible).
     */
    private function tradIraDeductibleCapCents(array $baseline, int $magiBeforeIra, bool $covered, int $year): int
    {
        $status = $baseline['filing_status'];
        $spouseCovered = (bool) ($baseline['spouse_covered_by_plan'] ?? false);

        $ded = $this->traditionalIraDeductibility($magiBeforeIra, $status, $covered, $spouseCovered, $year);

        if (! $ded['deductible']) {
            return 0;
        }

        // Fully deductible (null partial limit) → cap at the shared annual limit incl. catch-up.
        return $ded['partial_limit_cents'] ?? $this->remainingIraRoomCents(0, $baseline['age'] ?? null, $year);
    }

    /** Deductible portion of a traditional IRA contribution, capped at the deductible amount. */
    private function deductibleTradIraCents(array $baseline, int $iraTrad, int $scenarioTrad401k, int $magiBeforeIra, int $year): int
    {
        $covered = $this->coveredByPlan($baseline, $scenarioTrad401k);
        $cap = $this->tradIraDeductibleCapCents($baseline, $magiBeforeIra, $covered, $year);

        return min($iraTrad, $cap);
    }

    /**
     * Medicare/HSA hard stop: enrollment within the lookback window (or age 65+ with unknown
     * enrollment, or already enrolled) blocks new HSA contributions (IRS Notice 2004-2 Q&A 28).
     * Age 65 is the statutory Medicare eligibility age (structural constant, out of SCN scope).
     */
    private function medicareHsaBlocked(array $baseline, ?int $age, int $year): bool
    {
        $lookbackMonths = config("tax-rules.{$year}.detection.medicare_hsa_lookback_months");
        $enrollDate = $baseline['medicare_enrollment_date'] ?? null;

        if ($enrollDate === null) {
            return $age !== null && $age >= 65;
        }

        $enroll = Carbon::parse($enrollDate);

        // Already enrolled, or enrollment within the lookback window of now.
        return $enroll->lessThanOrEqualTo(now())
            || now()->diffInMonths($enroll, true) <= $lookbackMonths;
    }

    /** Per-period auto-transfer cap: max-surplus-share × annualized monthly surplus ÷ periods. */
    private function perPeriodSurplusCapCents(array $baseline, int $periods): int
    {
        $monthsPerYear = 12;
        $annualSurplus = (int) ($baseline['monthly_surplus_cents'] ?? 0) * $monthsPerYear;
        $maxShare = config('optimizer-scenarios.assumptions.auto_transfer_max_surplus_share');

        return max(0, (int) round($annualSurplus / $periods * $maxShare));
    }

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
