<?php

namespace App\Services;

use App\Events\OptimizationProfileBuilt;
use App\Events\TaxDocumentExtracted;
use App\Events\UserAnsweredQuestion;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationReport;

/**
 * ReportStalenessPolicy — Decision 13 (2026-07-02).
 *
 * Encapsulates the report staleness policy shared between
 * MarkOptimizationReportStale and DispatchReportGeneration listeners.
 *
 * Policy summary:
 *   USER_ACTION triggers → always flag stale / dispatch regen.
 *   DATA_CHURN triggers  → only flag/dispatch if:
 *     (a) report rebuilt_at is outside the freshness window, OR
 *     (b) current profile aggregates differ materially from built_against snapshot.
 *
 * Trigger classification map:
 *   TaxDocumentExtracted       → USER_ACTION (user uploaded a document)
 *   UserAnsweredQuestion       → USER_ACTION (optimization interview answer)
 *   OptimizationProfileBuilt   → DATA_CHURN  (scheduled rebuild / bank sync chain)
 *
 * If an event class is not in the classification map, the conservative
 * DATA_CHURN path is used — we never over-regenerate (D13 §1 rationale).
 *
 * Material-change thresholds (config optimization-report.material_change.*):
 *   income_pct  — income delta percentage to trigger within-window staleness
 *   savings_pct — savings contributions delta percentage threshold
 *
 * See app/Listeners/MarkOptimizationReportStale.php and DispatchReportGeneration.php
 * for the listeners that consume this policy.
 */
class ReportStalenessPolicy
{
    public const TRIGGER_USER_ACTION = 'user_action';

    public const TRIGGER_DATA_CHURN = 'data_churn';

    /**
     * Event class → trigger type classification.
     *
     * Unknown events default to DATA_CHURN (conservative — never over-regen).
     */
    public const TRIGGER_CLASSIFICATION = [
        TaxDocumentExtracted::class => self::TRIGGER_USER_ACTION,
        UserAnsweredQuestion::class => self::TRIGGER_USER_ACTION,
        OptimizationProfileBuilt::class => self::TRIGGER_DATA_CHURN,
    ];

    /**
     * True when the report was rebuilt recently enough to suppress routine churn.
     *
     * "Fresh" means rebuilt_at is within the configured freshness_days window.
     * A null rebuilt_at (never built, or unknown) is treated as NOT fresh so
     * that the first churn event after initialization always triggers the policy check.
     */
    public static function isFresh(OptimizationReport $report): bool
    {
        if ($report->rebuilt_at === null) {
            return false;
        }

        $freshnessDays = (int) config('optimization-report.freshness_days', 30);

        // diffInDays is absolute; fresh = strictly less than the threshold
        return $report->rebuilt_at->diffInDays(now()) < $freshnessDays;
    }

    /**
     * True when current profile aggregates differ materially from the
     * built_against snapshot stored at last generation time.
     *
     * "Absent built_against" → treated as material (conservative; D13 §3).
     * This happens on first generation or after migration (existing rows have
     * null built_against until the next report generation fills it).
     *
     * Thresholds come from config:
     *   optimization-report.material_change.income_pct  (default 5)
     *   optimization-report.material_change.savings_pct (default 10)
     */
    public static function isMaterialChange(
        OptimizationReport $report,
        ?IncomeOptimizationProfile $currentProfile
    ): bool {
        $snapshot = $report->built_against;

        // Absent snapshot → conservative treatment: always material (D13 §3)
        if (empty($snapshot)) {
            return true;
        }

        $incomePct = (float) config('optimization-report.material_change.income_pct', 5);
        $savingsPct = (float) config('optimization-report.material_change.savings_pct', 10);

        // ── Income comparison ──────────────────────────────────────────────
        $snapshotIncome = (int) ($snapshot['income_cents'] ?? 0);
        $currentIncome = self::computeIncomeCents($currentProfile);

        if ($snapshotIncome > 0) {
            $incomeDelta = abs($currentIncome - $snapshotIncome) / $snapshotIncome * 100;
            if ($incomeDelta >= $incomePct) {
                return true;
            }
        } elseif ($currentIncome > 0) {
            // Snapshot had zero income, now has income → material (new data arrived)
            return true;
        }

        // ── Savings / contributions comparison ────────────────────────────
        $snapshotSavings = (int) ($snapshot['savings_cents'] ?? 0);
        $currentSavings = self::computeSavingsCents($currentProfile);

        if ($snapshotSavings > 0) {
            $savingsDelta = abs($currentSavings - $snapshotSavings) / $snapshotSavings * 100;
            if ($savingsDelta >= $savingsPct) {
                return true;
            }
        } elseif ($currentSavings > 0) {
            // Snapshot had zero savings, now has savings → material
            return true;
        }

        return false;
    }

    /**
     * Compute the primary income aggregate from the current profile.
     *
     * Uses bank_deposit_total as the primary signal (most comprehensive —
     * captures all income types). Falls back to w2_wages + self_employment_income
     * if bank_deposit_total is not yet populated.
     *
     * All fields are encrypted cent strings; (int) cast gives the integer cents.
     */
    public static function computeIncomeCents(?IncomeOptimizationProfile $profile): int
    {
        if ($profile === null) {
            return 0;
        }

        // Prefer bank_deposit_total (broadest signal)
        if ($profile->bank_deposit_total !== null) {
            return (int) $profile->bank_deposit_total;
        }

        // Fallback: doc-sourced income signals
        return (int) ($profile->w2_wages ?? 0)
            + (int) ($profile->self_employment_income ?? 0);
    }

    /**
     * Compute total retirement / HSA savings contributions from the current profile.
     *
     * Covers: traditional 401k, Roth 401k, IRA, HSA (the four contribution
     * levers the optimization report focuses on). All are encrypted cent strings.
     */
    public static function computeSavingsCents(?IncomeOptimizationProfile $profile): int
    {
        if ($profile === null) {
            return 0;
        }

        return (int) ($profile->traditional_401k_ytd ?? 0)
            + (int) ($profile->roth_401k_ytd ?? 0)
            + (int) ($profile->ira_ytd ?? 0)
            + (int) ($profile->hsa_ytd ?? 0);
    }

    /**
     * Convenience: should a DATA_CHURN event stale the report?
     *
     * Returns true when EITHER:
     *   (a) The report is outside the freshness window, OR
     *   (b) A material change is detected vs. the built_against snapshot.
     */
    public static function shouldChurnStale(
        OptimizationReport $report,
        ?IncomeOptimizationProfile $currentProfile
    ): bool {
        if (! self::isFresh($report)) {
            return true;  // (a) window expired
        }

        return self::isMaterialChange($report, $currentProfile);  // (b) material change
    }
}
