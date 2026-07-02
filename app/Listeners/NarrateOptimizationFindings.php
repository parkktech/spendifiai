<?php

namespace App\Listeners;

use App\Events\OptimizationProfileBuilt;
use App\Services\NarrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listen for OptimizationProfileBuilt and narrate null-description findings via Claude.
 *
 * ORDER NOTE: This listener queries existing null-description findings from the DB rather
 * than accepting findings as input, making it naturally order-independent with respect to
 * RunRedFlagDetectors. Both listeners fire on OptimizationProfileBuilt and this one will
 * pick up any findings RunRedFlagDetectors just created (assuming the queue processes them).
 * In practice, since both are queued, RunRedFlagDetectors runs first (registered first in
 * AppServiceProvider::boot()) and NarrateOptimizationFindings picks up its output.
 *
 * SECURITY: NarrationService never receives estimated_value_cents (T-11-03-03).
 */
class NarrateOptimizationFindings implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        protected NarrationService $narrationService,
    ) {}

    public function handle(OptimizationProfileBuilt $event): void
    {
        Log::info('NarrateOptimizationFindings: starting narration', [
            'user_id' => $event->userId,
            'tax_year' => $event->taxYear,
        ]);

        $count = $this->narrationService->narratePendingFindings($event->userId, $event->taxYear);

        Log::info('NarrateOptimizationFindings: complete', [
            'user_id' => $event->userId,
            'tax_year' => $event->taxYear,
            'narrated' => $count,
        ]);
    }
}
