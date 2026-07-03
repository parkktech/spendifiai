<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * QueueSmokeJob — D24 reliability hardening (Work 2).
 *
 * Lightweight marker job used exclusively by optimizer:queue-smoke to prove
 * that an async dispatch reaches a running worker end-to-end.
 *
 * Design:
 *  - Writes a cache key with a 60-second TTL when it executes.
 *  - The smoke command polls for that key to confirm execution.
 *  - No business logic — purely an infrastructure health probe.
 *  - Queues to 'default' (no onQueue() override) — same as GenerateOptimizationReport.
 *
 * Root-cause context (D24): The prior "vanished dispatch" was traced to jobs
 * dispatching to onQueue('optimization') while queue:work consumed 'default'
 * only. This job is intentionally minimal so the smoke command can distinguish
 * "never reached worker" from "reached worker but business logic failed".
 */
class QueueSmokeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $smokeKey,
    ) {}

    public function handle(): void
    {
        Cache::put($this->smokeKey, true, 60);

        Log::info('QueueSmokeJob: executed', ['smoke_key' => $this->smokeKey]);
    }
}
