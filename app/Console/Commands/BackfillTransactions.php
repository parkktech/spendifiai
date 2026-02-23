<?php

namespace App\Console\Commands;

use App\Jobs\CategorizePendingTransactions;
use App\Models\BankConnection;
use App\Services\PlaidService;
use Illuminate\Console\Command;

class BackfillTransactions extends Command
{
    protected $signature = 'plaid:backfill
                            {--user= : User ID to backfill (omit for all active connections)}
                            {--from= : Start date (YYYY-MM-DD, defaults to Jan 1 of previous year)}
                            {--to= : End date (YYYY-MM-DD, defaults to today)}
                            {--categorize : Also run AI categorization on new transactions}
                            {--sync : Legacy: reset sync cursor and dispatch sync job instead}';

    protected $description = 'Fetch historical transactions using Plaid /transactions/get for reliable date-range backfill';

    public function handle(PlaidService $plaidService): int
    {
        $query = BankConnection::where('status', 'active');

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->warn('No active bank connections found.');

            return self::SUCCESS;
        }

        // Legacy mode: just reset cursor
        if ($this->option('sync')) {
            return $this->legacyCursorReset($connections);
        }

        $startDate = $this->option('from') ?? now()->subYear()->startOfYear()->format('Y-m-d');
        $endDate = $this->option('to') ?? now()->format('Y-m-d');

        $this->info("Backfilling transactions from {$startDate} to {$endDate} for {$connections->count()} connection(s)...");
        $this->newLine();

        $totalAdded = 0;

        foreach ($connections as $connection) {
            $this->line("  [{$connection->id}] {$connection->institution_name} (user #{$connection->user_id})");

            try {
                $result = $plaidService->getTransactionsByDateRange($connection, $startDate, $endDate);

                $this->line("      Fetched: {$result['total_fetched']} | New: {$result['added']} | Available: {$result['total_available']}");

                if ($result['skipped'] > 0) {
                    $this->warn("      Skipped {$result['skipped']} (no matching bank account)");
                }

                $totalAdded += $result['added'];

                if ($this->option('categorize') && $result['added'] > 0) {
                    $this->line('      → Running AI categorization...');
                    CategorizePendingTransactions::dispatchSync($connection->user_id);
                    $this->line('      → Categorization complete');
                }
            } catch (\Exception $e) {
                $this->error("      Error: {$e->getMessage()}");
            }

            $this->newLine();
        }

        $this->info("Done. {$totalAdded} new transactions added.");

        return self::SUCCESS;
    }

    private function legacyCursorReset($connections): int
    {
        $this->info("Resetting sync cursor for {$connections->count()} connection(s)...");

        foreach ($connections as $connection) {
            $connection->update(['sync_cursor' => null]);
            $this->line("  [{$connection->id}] {$connection->institution_name} — cursor reset");
        }

        $this->info('Cursors reset. Trigger a sync manually or wait for the scheduled job.');

        return self::SUCCESS;
    }
}
