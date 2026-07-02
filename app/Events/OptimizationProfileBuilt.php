<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by BuildIncomeOptimizationProfile after the snapshot is assembled,
 * cross-source findings are upserted, and the pipeline is complete.
 *
 * Phase 11 listeners attach here to run red-flag detectors and AI narration.
 * This event carries only primitive values (not Eloquent models) so it is
 * safely serialisable and re-dispatchable from queue workers.
 *
 * Event contract (stable across phases — do not rename properties):
 *   userId:      the user whose profile was rebuilt
 *   taxYear:     the tax year of the snapshot (e.g. 2026)
 *   findingCount: number of OptimizationFinding rows upserted (0 = clean snapshot)
 */
class OptimizationProfileBuilt
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $taxYear,
        public readonly int $findingCount,
    ) {}
}
