<?php

namespace App\Jobs;

use App\Events\OptimizationProfileBuilt;
use App\Models\OptimizationFinding;
use App\Models\User;
use App\Services\CrossSourceReviewService;
use App\Services\IncomeOptimizerDataAssemblerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Assemble the per-user income optimization snapshot and run cross-source review.
 *
 * Pipeline:
 *   1. Resolve the User model from primitive $userId
 *   2. IncomeOptimizerDataAssemblerService::buildProfile() — builds/refreshes the snapshot
 *   3. CrossSourceReviewService::review() — deterministic discrepancy comparison (no AI)
 *   4. Upsert each finding via OptimizationFinding::updateOrCreate()
 *   5. Fire OptimizationProfileBuilt so Phase 11 listeners can attach
 *
 * Constructor takes primitive scalar IDs (int, not Eloquent models) per project
 * job convention — avoids stale model issues with SerializesModels.
 *
 * Queue: default (house convention — the running worker uses queue:work redis with no --queue flag)
 * Tries: 3 / Timeout: 180s
 */
class BuildIncomeOptimizationProfile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int $userId,
        public readonly int $taxYear,
    ) {}

    public function handle(
        IncomeOptimizerDataAssemblerService $assembler,
        CrossSourceReviewService $crossSource,
    ): void {
        Log::info('BuildIncomeOptimizationProfile starting', [
            'user_id' => $this->userId,
            'tax_year' => $this->taxYear,
        ]);

        $user = User::findOrFail($this->userId);

        // Step 1: Build/refresh the encrypted income snapshot from all sources
        $profile = $assembler->buildProfile($user, $this->taxYear);

        // Step 2: Run deterministic cross-source review (zero AI calls in Phase 10)
        $findings = $crossSource->review($profile, $user, $this->taxYear);

        // Step 3: Upsert findings keyed on (user_id, tax_year, finding_key)
        // updateOrCreate ensures idempotency — re-running the job overwrites stale findings
        foreach ($findings as $finding) {
            OptimizationFinding::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'tax_year' => $this->taxYear,
                    'finding_key' => $finding['key'],
                ],
                array_merge($finding, [
                    'user_id' => $user->id,
                    'tax_year' => $this->taxYear,
                    'finding_key' => $finding['key'],
                ])
            );
        }

        // Step 4: Fire event — Phase 11 listeners attach here for AI narration and red-flag detection
        event(new OptimizationProfileBuilt($user->id, $this->taxYear, count($findings)));

        Log::info('BuildIncomeOptimizationProfile complete', [
            'user_id' => $this->userId,
            'tax_year' => $this->taxYear,
            'findings_upserted' => count($findings),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('BuildIncomeOptimizationProfile failed', [
            'user_id' => $this->userId,
            'tax_year' => $this->taxYear,
            'error' => $exception->getMessage(),
        ]);
    }
}
