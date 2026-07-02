<?php

namespace App\Services;

use App\Models\IncomeOptimizationProfile;
use App\Models\User;

/**
 * Deterministic cross-source discrepancy detector for the Optimize My Income feature.
 *
 * Compares document-sourced income figures (W-2 wages, SE income) from the
 * IncomeOptimizationProfile snapshot against bank deposit totals using fixed
 * business tolerances. Emits finding-data arrays for the calling job to persist.
 *
 * ZERO AI/HTTP calls in Phase 10. The `description` field in each finding
 * payload is intentionally left null — Phase 11 listeners will use AI narration
 * to generate human-readable descriptions and populate them via updateOrCreate().
 *
 * Tolerances are business decisions, not IRS figures:
 *   W2_DEPOSIT_TOLERANCE = 15%  — accounts for pre-tax 401k/HSA deductions and timing
 *   SE_INCOME_TOLERANCE  = 20%  — SE income is often deposited irregularly across accounts
 *
 * SECURITY (T-10-10): Tolerances are fixed class constants; the service contains
 * zero AI/HTTP references. Verified by a grep gate over this file.
 */
class CrossSourceReviewService
{
    /**
     * W-2 vs bank-deposit tolerance: gap ≤ 15% is acceptable.
     * Accounts for pre-tax deductions (401k, HSA, health insurance) and
     * timing differences between payroll cycles and deposit clearing.
     */
    public const W2_DEPOSIT_TOLERANCE = 0.15;

    /**
     * SE-income vs bank-deposit tolerance: gap ≤ 20% is acceptable.
     * Self-employment income is often received irregularly, deposited to multiple
     * accounts, or retained in a business entity before personal transfer.
     */
    public const SE_INCOME_TOLERANCE = 0.20;

    /**
     * Run the deterministic cross-source review.
     *
     * Reads encrypted cent values from the profile (Laravel cast handles decryption),
     * applies tolerance-based comparison logic, and returns an array of finding-data
     * arrays. Each element is ready for OptimizationFinding::updateOrCreate().
     *
     * @return array<int, array{key: string, finding_type: string, severity: string, details: array, description: null}>
     */
    public function review(IncomeOptimizationProfile $profile, User $user, int $taxYear): array
    {
        $findings = [];

        $w2Finding = $this->compareW2VsDeposits($profile);
        if ($w2Finding !== null) {
            $findings[] = $w2Finding;
        }

        $seIncomeFinding = $this->compareSEIncomeVsDeposits($profile);
        if ($seIncomeFinding !== null) {
            $findings[] = $seIncomeFinding;
        }

        return $findings;
    }

    /**
     * Compare W-2 wages against bank deposit total.
     *
     * Guard: if either value is 0 (absent/unknown), return null — insufficient data.
     * Flag: gap_pct > W2_DEPOSIT_TOLERANCE → emit 'w2_deposit_mismatch' finding.
     *
     * @return array{key: string, finding_type: string, severity: string, details: array, description: null}|null
     */
    protected function compareW2VsDeposits(IncomeOptimizationProfile $profile): ?array
    {
        // Access via model attribute (Laravel 'encrypted' cast decrypts automatically)
        $w2Cents = $profile->w2_wages !== null ? (int) $profile->w2_wages : 0;
        $bankCents = $profile->bank_deposit_total !== null ? (int) $profile->bank_deposit_total : 0;

        // Insufficient data guard — both must be positive to compare
        if ($w2Cents === 0 || $bankCents === 0) {
            return null;
        }

        $gapCents = abs($w2Cents - $bankCents);
        $gapPct = $gapCents / max($w2Cents, $bankCents);

        if (! $this->exceedsTolerance($gapPct, self::W2_DEPOSIT_TOLERANCE)) {
            return null;
        }

        return [
            'key' => 'w2_deposit_mismatch',
            'finding_type' => 'income_discrepancy',
            'severity' => $this->severityFromGapPct($gapPct),
            'details' => [
                'w2_cents' => $w2Cents,
                'bank_cents' => $bankCents,
                'gap_cents' => $gapCents,
                'gap_pct' => $gapPct,
                'tolerance' => self::W2_DEPOSIT_TOLERANCE,
            ],
            'description' => null,  // Phase 11 AI narration fills this
        ];
    }

    /**
     * Compare self-employment income against bank deposit total.
     *
     * Guard: both SE income AND bank deposits must be > 0 to flag.
     * Flag: gap_pct > SE_INCOME_TOLERANCE → emit 'se_income_deposit_mismatch' finding.
     *
     * @return array{key: string, finding_type: string, severity: string, details: array, description: null}|null
     */
    protected function compareSEIncomeVsDeposits(IncomeOptimizationProfile $profile): ?array
    {
        $seIncomeCents = $profile->self_employment_income !== null ? (int) $profile->self_employment_income : 0;
        $bankCents = $profile->bank_deposit_total !== null ? (int) $profile->bank_deposit_total : 0;

        // Both values must be positive to compare
        if ($seIncomeCents === 0 || $bankCents === 0) {
            return null;
        }

        $gapCents = abs($seIncomeCents - $bankCents);
        $gapPct = $gapCents / max($seIncomeCents, $bankCents);

        if (! $this->exceedsTolerance($gapPct, self::SE_INCOME_TOLERANCE)) {
            return null;
        }

        return [
            'key' => 'se_income_deposit_mismatch',
            'finding_type' => 'income_discrepancy',
            'severity' => $this->severityFromGapPct($gapPct),
            'details' => [
                'se_income_cents' => $seIncomeCents,
                'bank_cents' => $bankCents,
                'gap_cents' => $gapCents,
                'gap_pct' => $gapPct,
                'tolerance' => self::SE_INCOME_TOLERANCE,
            ],
            'description' => null,  // Phase 11 AI narration fills this
        ];
    }

    /**
     * True when the gap percentage exceeds the tolerance threshold.
     * Divide-by-zero is already guarded upstream (both values checked > 0).
     */
    protected function exceedsTolerance(float $gapPct, float $tolerance): bool
    {
        return $gapPct > $tolerance;
    }

    /**
     * Derive a severity label from the gap percentage.
     * These bands are advisory; Phase 11 detectors may override them.
     */
    protected function severityFromGapPct(float $gapPct): string
    {
        if ($gapPct >= 0.40) {
            return 'high';
        }

        if ($gapPct >= 0.25) {
            return 'medium';
        }

        return 'low';
    }
}
