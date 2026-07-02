<?php

namespace App\Listeners;

use App\Enums\QuestionType;
use App\Events\OptimizationProfileBuilt;
use App\Events\TaxDocumentExtracted;
use App\Events\UserAnsweredQuestion;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationReport;
use App\Services\ReportStalenessPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Mark the user's OptimizationReport as stale (flag flip only — no inline generation).
 *
 * Decision 13 (2026-07-02) — REPLACES the old flag-on-every-event behaviour.
 *
 * Trigger classification (ReportStalenessPolicy::TRIGGER_CLASSIFICATION):
 *
 *   USER_ACTION  — TaxDocumentExtracted, UserAnsweredQuestion(optimization)
 *                  → ALWAYS flag stale; no freshness window check.
 *
 *   DATA_CHURN   — OptimizationProfileBuilt (scheduled rebuild / bank sync chain)
 *                  → flag stale ONLY when:
 *                    (a) report was rebuilt ≥ freshness_days ago (window expired), OR
 *                    (b) current profile aggregates differ materially from the
 *                        built_against snapshot (income ±5% or savings ±10%).
 *                  Absent built_against → treated as material (conservative default).
 *
 * Unknown events default to DATA_CHURN (conservative path — never over-regenerate).
 *
 * CRITICAL: This listener performs a single DB UPDATE (flag flip) only.
 * It NEVER dispatches GenerateOptimizationReport — that is the responsibility
 * of DispatchReportGeneration (handles debounced job dispatch with the same D13 gate).
 *
 * No thundering-herd risk: flag flip is O(1) regardless of event volume.
 *
 * SECURITY (T-12-04-04): scopeForUser() ensures cross-user isolation.
 */
class MarkOptimizationReportStale implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Handle TaxDocumentExtracted (USER_ACTION): always flag stale.
     *
     * A document upload is a deliberate user action — they expect the next
     * report to reflect it immediately (D13 §2).
     */
    public function handleTaxDocumentExtracted(TaxDocumentExtracted $event): void
    {
        $document = $event->document;

        $this->markStale($document->user_id, $document->tax_year, 'TaxDocumentExtracted:user_action');
    }

    /**
     * Handle OptimizationProfileBuilt (DATA_CHURN): apply D13 freshness + material-change gate.
     *
     * Bank syncs and scheduled profile rebuilds fire this event frequently. Within the
     * freshness window, only a material change in income/savings aggregates triggers
     * a stale flag — routine churn is suppressed (D13 §1, §3, §4).
     */
    public function handleOptimizationProfileBuilt(OptimizationProfileBuilt $event): void
    {
        $userId = $event->userId;
        $taxYear = $event->taxYear;

        $report = OptimizationReport::forUser($userId)
            ->where('tax_year', $taxYear)
            ->first();

        if ($report === null) {
            // No report exists — nothing to stale
            return;
        }

        // Apply D13 DATA_CHURN gate via the shared policy helper
        $currentProfile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        if (! ReportStalenessPolicy::shouldChurnStale($report, $currentProfile)) {
            Log::info('MarkOptimizationReportStale: suppressed (within freshness window, no material change)', [
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'trigger' => 'OptimizationProfileBuilt',
                'rebuilt_at' => $report->rebuilt_at?->toIso8601String(),
                'freshness_days' => (int) config('optimization-report.freshness_days', 30),
            ]);

            return;
        }

        // Outside window OR material change → mark stale
        $reason = ReportStalenessPolicy::isFresh($report) ? 'material_change' : 'window_expired';
        $this->markStale($userId, $taxYear, "OptimizationProfileBuilt:{$reason}");
    }

    /**
     * Handle UserAnsweredQuestion (USER_ACTION for optimization questions only).
     *
     * Non-optimization question answers (transaction categorization, etc.) are
     * silently ignored — they do not affect the optimization report.
     * Mirror the FEED-04 guard pattern from UpdateOptimizationFromAnswer.
     */
    public function handleUserAnsweredQuestion(UserAnsweredQuestion $event): void
    {
        $question = $event->question;

        // Only optimization questions affect the optimization report.
        if ($question->question_type !== QuestionType::Optimization) {
            return;
        }

        $user = $event->user;
        $taxYear = now()->year;

        $this->markStale($user->id, $taxYear, 'UserAnsweredQuestion:user_action');
    }

    /**
     * Flip is_stale=true for the given user + tax year (flag flip only).
     *
     * Uses OptimizationReport::forUser() scope (T-12-04-04 cross-user isolation).
     * Only updates rows that exist — no insert (fetchOrInit is the API layer's job).
     */
    private function markStale(int $userId, int $taxYear, string $trigger): void
    {
        $updated = OptimizationReport::forUser($userId)
            ->where('tax_year', $taxYear)
            ->update([
                'is_stale' => true,
                'stale_since' => now(),
            ]);

        Log::info('MarkOptimizationReportStale: flagged', [
            'user_id' => $userId,
            'tax_year' => $taxYear,
            'trigger' => $trigger,
            'rows_updated' => $updated,
        ]);
    }
}
