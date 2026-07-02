<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\OptimizationReportGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generate (or regenerate) the optimization report for a user + tax year.
 *
 * Implements ShouldBeUnique to prevent thundering-herd rebuilds (Pitfall 4):
 * uniqueId() = "{userId}:{taxYear}" — only one job per user+year runs at a time.
 * Multiple dispatches for the same user+year coalesce into a single execution.
 *
 * Queue: default (house convention — the running worker uses queue:work redis with no --queue flag)
 * Timeout: 300s (narrating 4 sections × 45s timeout + executive summary buffer)
 *
 * Constructor takes primitive scalar IDs (not Eloquent models) per project
 * job convention — avoids stale model issues with SerializesModels.
 */
class GenerateOptimizationReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $userId,
        public readonly int $taxYear,
    ) {}

    /**
     * Unique key prevents thundering-herd when multiple events fire simultaneously.
     *
     * Laravel will not allow a second job with the same uniqueId to enter the
     * queue while one is already pending or processing.
     */
    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->taxYear}";
    }

    /**
     * Handle the job.
     *
     * Injects OptimizationReportGeneratorService which orchestrates:
     *  - Loading OptimizationFindings (reading estimated_value_cents for ranking)
     *  - Grouping into 4 topical + 3 wrapper + year-end + glossary sections
     *  - Calling OptimizationReportNarratorService (3rd Claude call site)
     *  - Upserting the OptimizationReport model
     *
     * Sets ini_set memory_limit before heavy operations (Pitfall 5 guard).
     */
    public function handle(OptimizationReportGeneratorService $generator): void
    {
        // Memory guard for dompdf and report assembly (Pitfall 5)
        ini_set('memory_limit', '512M');

        // D17 activity gate (DRIFT-06): skip AI dispatch for inactive users on
        // background/scheduled paths. Mirrors the commit 4e75c46 console.php
        // precedent. Does NOT apply to on-demand endpoints (users hitting an
        // endpoint are by definition active).
        $thresholdDays = config('spendifiai.sync.active_threshold_days', 28);
        $gateUser = User::find($this->userId);
        if ($gateUser && $gateUser->last_active_at && $gateUser->last_active_at->lt(now()->subDays($thresholdDays))) {
            Log::info('GenerateOptimizationReport: skipped inactive user', [
                'user_id' => $this->userId,
                'tax_year' => $this->taxYear,
            ]);

            return;
        }

        Log::info('GenerateOptimizationReport: starting', [
            'user_id' => $this->userId,
            'tax_year' => $this->taxYear,
        ]);

        $user = User::findOrFail($this->userId);

        $report = $generator->generate($user, $this->taxYear);

        Log::info('GenerateOptimizationReport: complete', [
            'user_id' => $this->userId,
            'tax_year' => $this->taxYear,
            'report_id' => $report->id,
            'section_count' => count($report->sections ?? []),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GenerateOptimizationReport: failed', [
            'user_id' => $this->userId,
            'tax_year' => $this->taxYear,
            'error' => $exception->getMessage(),
        ]);
    }
}
