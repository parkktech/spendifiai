<?php

namespace App\Listeners;

use App\Events\OptimizationProfileBuilt;
use App\Events\TaxDocumentExtracted;
use App\Jobs\GenerateOptimizationReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Dispatch a debounced, unique report generation job on staleness-triggering events.
 *
 * This listener handles:
 *   - TaxDocumentExtracted:     document ready → new profile facts available
 *   - OptimizationProfileBuilt: profile rebuilt → findings changed
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
 *   MarkOptimizationReportStale  → immediate flag flip (always)
 *   DispatchReportGeneration     → debounced job dispatch (doc extraction + profile rebuild)
 */
class DispatchReportGeneration implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Handle TaxDocumentExtracted: dispatch a debounced report generation job.
     */
    public function handleTaxDocumentExtracted(TaxDocumentExtracted $event): void
    {
        $document = $event->document;

        $this->dispatchDebounced($document->user_id, $document->tax_year, 'TaxDocumentExtracted');
    }

    /**
     * Handle OptimizationProfileBuilt: dispatch a debounced report generation job.
     */
    public function handleOptimizationProfileBuilt(OptimizationProfileBuilt $event): void
    {
        $this->dispatchDebounced($event->userId, $event->taxYear, 'OptimizationProfileBuilt');
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
