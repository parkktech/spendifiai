<?php

namespace App\Listeners;

use App\Events\OptimizationProfileBuilt;
use App\Events\TaxDocumentExtracted;
use App\Jobs\GenerateOptimizationReport;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationReport;
use App\Services\ReportStalenessPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Dispatch a debounced, unique report generation job on staleness-triggering events.
 *
 * Decision 13 (2026-07-02) — REPLACES the old dispatch-on-every-event behaviour.
 *
 * This listener handles:
 *   - TaxDocumentExtracted:     USER_ACTION → always dispatch regen (document ready)
 *   - OptimizationProfileBuilt: DATA_CHURN  → dispatch ONLY if outside freshness
 *                                             window OR material change detected
 *                                             (mirrors MarkOptimizationReportStale D13 gate)
 *
 * The 30-second delay + GenerateOptimizationReport's ShouldBeUnique(user:taxYear)
 * coalesce a burst of events (e.g., 20-page paystub upload firing 20 events) into
 * exactly ONE report-generation job (Pitfall 4 / thundering-herd prevention).
 *
 * IMPORTANT: This listener does NOT handle UserAnsweredQuestion.
 * Interview answers trigger MarkOptimizationReportStale (flag flip) only.
 * Report regeneration from answers happens lazily on the next API call.
 *
 * SEPARATION OF CONCERNS:
 *   MarkOptimizationReportStale  → immediate flag flip (applies D13 gate)
 *   DispatchReportGeneration     → debounced job dispatch (applies same D13 gate)
 */
class DispatchReportGeneration implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Handle TaxDocumentExtracted (USER_ACTION): always dispatch a debounced regen job.
     *
     * User-uploaded documents are immediate-stale triggers — regenerate promptly (D13 §2).
     */
    public function handleTaxDocumentExtracted(TaxDocumentExtracted $event): void
    {
        $document = $event->document;

        $this->dispatchDebounced($document->user_id, $document->tax_year, 'TaxDocumentExtracted:user_action');
    }

    /**
     * Handle OptimizationProfileBuilt (DATA_CHURN): apply D13 gate before dispatching.
     *
     * Routine scheduled rebuilds and bank-sync chains fire this event frequently.
     * Within the freshness window, dispatch is suppressed unless a material change
     * in income/savings aggregates is detected vs. the built_against snapshot (D13 §1, §3).
     */
    public function handleOptimizationProfileBuilt(OptimizationProfileBuilt $event): void
    {
        $userId = $event->userId;
        $taxYear = $event->taxYear;

        // Load current report to check freshness and built_against snapshot
        $report = OptimizationReport::forUser($userId)
            ->where('tax_year', $taxYear)
            ->first();

        if ($report === null) {
            // No report exists yet — dispatch so it gets created
            $this->dispatchDebounced($userId, $taxYear, 'OptimizationProfileBuilt:no_report');

            return;
        }

        // Apply D13 DATA_CHURN gate (same policy as MarkOptimizationReportStale)
        $currentProfile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->first();

        if (! ReportStalenessPolicy::shouldChurnStale($report, $currentProfile)) {
            Log::info('DispatchReportGeneration: suppressed (within freshness window, no material change)', [
                'user_id' => $userId,
                'tax_year' => $taxYear,
                'trigger' => 'OptimizationProfileBuilt',
                'rebuilt_at' => $report->rebuilt_at?->toIso8601String(),
            ]);

            return;
        }

        $reason = ReportStalenessPolicy::isFresh($report) ? 'material_change' : 'window_expired';
        $this->dispatchDebounced($userId, $taxYear, "OptimizationProfileBuilt:{$reason}");
    }

    /**
     * Dispatch GenerateOptimizationReport with a 30-second delay.
     *
     * The delay debounces event bursts. GenerateOptimizationReport implements
     * ShouldBeUnique with uniqueId="{userId}:{taxYear}" — multiple dispatches
     * within the uniqueness TTL are coalesced into one job execution.
     */
    private function dispatchDebounced(int $userId, int $taxYear, string $trigger): void
    {
        GenerateOptimizationReport::dispatch($userId, $taxYear)
            ->delay(now()->addSeconds(30));

        Log::info('DispatchReportGeneration: queued debounced job', [
            'user_id' => $userId,
            'tax_year' => $taxYear,
            'trigger' => $trigger,
            'delay_seconds' => 30,
        ]);
    }
}
