<?php

namespace App\Console\Commands;

use App\Jobs\QueueSmokeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OptimizerQueueSmokeCommand — D24 reliability hardening (Work 2).
 *
 * Proves that an async dispatch reaches a running worker end-to-end.
 * Exits 0 on success, 1 on failure (safe for CI pipelines).
 *
 * Root-cause: The "vanished async dispatch" (D24 Work 2) occurred because:
 *   1. GenerateOptimizationReport (and BuildIncomeOptimizationProfile,
 *      ExtractProfileFacts) previously called ->onQueue('optimization') in
 *      their constructors, dispatching jobs to a named 'optimization' queue.
 *   2. The production queue worker was started as:
 *        php artisan queue:work redis
 *      which consumes ONLY the 'default' queue by default.
 *   3. Jobs in the 'optimization' queue sat unprocessed with no error, no
 *      failed_jobs entry, and no log line — because failing to consume is not
 *      a failure, just silence.
 *   4. Bus::dispatchSync() bypassed the queue entirely, which is why it worked.
 *   The fix (committed in OptimizationQueueFixTest era): remove onQueue()
 *   from all three job classes so they dispatch to 'default'.
 *
 * Additionally: ShouldBeUnique uses the cache store (CACHE_STORE) to hold the
 * unique lock. If a prior GenerateOptimizationReport run crashed before
 * releasing its lock (the lock TTL is the job timeout = 300s), subsequent
 * dispatches for the same user+year are silently dropped for up to 5 minutes.
 * Run `php artisan cache:clear` to force-release stuck locks in development.
 *
 * Usage:
 *   php artisan optimizer:queue-smoke              # async (requires running worker)
 *   php artisan optimizer:queue-smoke --sync       # synchronous (worker not needed)
 *   php artisan optimizer:queue-smoke --timeout=30 # custom poll timeout in seconds
 *
 * Environment requirements:
 *   - QUEUE_CONNECTION must match the connection the running worker uses.
 *   - CACHE_STORE must be writable (redis or database; NOT 'array').
 *   - A queue worker must be running (unless --sync is used).
 */
class OptimizerQueueSmokeCommand extends Command
{
    protected $signature = 'optimizer:queue-smoke
        {--sync : Dispatch synchronously (no worker needed; tests dispatch only)}
        {--timeout=20 : Seconds to wait for async job execution (default 20)}';

    protected $description = 'Smoke-test the queue: dispatch a marker job and verify it executes end-to-end.';

    public function handle(): int
    {
        $sync = (bool) $this->option('sync');
        $timeout = max(5, (int) $this->option('timeout'));

        $smokeKey = 'optimizer_queue_smoke_'.Str::random(12);

        $this->info('D24 queue smoke test');
        $this->line('  QUEUE_CONNECTION : '.config('queue.default'));
        $this->line('  CACHE_STORE      : '.config('cache.default'));
        $this->line('  Mode             : '.($sync ? 'sync (Bus::dispatchSync)' : 'async (dispatch)'));
        $this->line('  Smoke key        : '.$smokeKey);
        $this->newLine();

        if ($sync) {
            Bus::dispatchSync(new QueueSmokeJob($smokeKey));
            $hit = Cache::get($smokeKey, false);

            if ($hit) {
                $this->info('[PASS] Sync dispatch executed immediately.');

                return Command::SUCCESS;
            }

            $this->error('[FAIL] dispatchSync ran but cache key was not set. Cache driver may be misconfigured.');

            return Command::FAILURE;
        }

        // Async mode: dispatch, then poll.
        $this->line('Dispatching async...');
        QueueSmokeJob::dispatch($smokeKey);
        $this->line('Job dispatched. Polling for execution (max '.$timeout.'s)...');

        $elapsed = 0;
        $interval = 1; // poll every second
        while ($elapsed < $timeout) {
            sleep($interval);
            $elapsed += $interval;

            if (Cache::get($smokeKey, false)) {
                $this->info("[PASS] Job executed after {$elapsed}s — queue worker is running and consuming jobs.");
                $this->newLine();
                $this->line('Root-cause note: if this test ever fails asynchronously,');
                $this->line('  1. Verify QUEUE_CONNECTION matches what queue:work uses.');
                $this->line('  2. Check for stuck ShouldBeUnique locks (run cache:clear).');
                $this->line('  3. Verify the worker consumes the right queue (no --queue=optimization).');

                return Command::SUCCESS;
            }

            $this->line("  {$elapsed}s elapsed — waiting...");
        }

        $this->newLine();
        $this->error('[FAIL] Job was not executed within '.$timeout.'s.');
        $this->newLine();
        $this->line('Diagnosis checklist:');
        $this->line('  1. Is a queue worker running?  php artisan queue:work redis');
        $this->line('  2. Does it match QUEUE_CONNECTION? (current: '.config('queue.default').')');
        $this->line('  3. Does it consume the default queue? (worker should NOT use --queue=optimization)');
        $this->line('  4. Is CACHE_STORE reachable? (current: '.config('cache.default').')');
        $this->line('  5. Any stuck ShouldBeUnique locks?  php artisan cache:clear');

        return Command::FAILURE;
    }
}
