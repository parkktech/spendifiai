<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Sync Plaid transactions every 30 minutes
        $schedule->command('plaid:sync')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->onFailure(function () {
                \Log::error('Plaid sync job failed');
            });

        // Categorize pending transactions every 15 minutes
        $schedule->command('transactions:categorize')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onFailure(function () {
                \Log::error('Transaction categorization job failed');
            });

        // Detect subscriptions every 2 hours
        $schedule->command('subscriptions:detect')
            ->everyTwoHours()
            ->withoutOverlapping();

        // Process queued emails every 10 minutes
        $schedule->command('queue:work redis --max-jobs=50 --max-time=300 --stop-when-empty')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->onFailure(function () {
                \Log::error('Queue worker job failed');
            });

        // Generate email receipts from Gmail every hour
        $schedule->command('emails:sync')
            ->hourly()
            ->withoutOverlapping();

        // Clean up old logs monthly
        $schedule->command('log:clear')
            ->monthlyOn(1, '02:00');

        // Health check: ensure Redis is available
        $schedule->call(function () {
            try {
                \Illuminate\Support\Facades\Cache::store('redis')->put('health_check', now(), 60);
            } catch (\Exception $e) {
                \Log::error('Redis health check failed: ' . $e->getMessage());
            }
        })->everyFiveMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
