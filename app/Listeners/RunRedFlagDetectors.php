<?php

namespace App\Listeners;

use App\Events\OptimizationProfileBuilt;
use App\Services\RedFlagDetectorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listen for OptimizationProfileBuilt and run all red-flag detectors.
 *
 * This listener is queued so that detector execution does not block the
 * request that triggered the profile build.
 *
 * SEQUENCE NOTE: NarrateOptimizationFindings runs after this listener
 * because narration queries existing null-description findings — meaning
 * it will naturally pick up findings created by this listener on any
 * subsequent OptimizationProfileBuilt dispatch (or on a queued delay).
 * No explicit ordering dependency is needed.
 */
class RunRedFlagDetectors implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        protected RedFlagDetectorService $detectorService,
    ) {}

    public function handle(OptimizationProfileBuilt $event): void
    {
        Log::info('RunRedFlagDetectors: starting detector run', [
            'user_id' => $event->userId,
            'tax_year' => $event->taxYear,
            'profile_finding_count' => $event->findingCount,
        ]);

        $emittedKeys = $this->detectorService->detectAll($event->userId, $event->taxYear);

        Log::info('RunRedFlagDetectors: complete', [
            'user_id' => $event->userId,
            'tax_year' => $event->taxYear,
            'emitted_keys' => count($emittedKeys),
        ]);
    }
}
