<?php

namespace App\Console\Commands;

use App\Jobs\SyncBankTransactions;
use App\Models\BankConnection;
use Illuminate\Console\Command;

class BackfillTransactions extends Command
{
    protected $signature = 'plaid:backfill
                            {--user= : User ID to backfill (omit for all active connections)}
                            {--sync : Dispatch sync job to queue instead of running inline}';

    protected $description = 'Reset sync cursors and re-fetch transaction history back to Jan 1 of previous year';

    public function handle(): int
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

        $this->info("Resetting sync cursor for {$connections->count()} connection(s)...");

        foreach ($connections as $connection) {
            $connection->update(['sync_cursor' => null]);

            $this->line("  [{$connection->id}] {$connection->institution_name} — cursor reset");

            if ($this->option('sync')) {
                SyncBankTransactions::dispatch($connection);
                $this->line('      → Queued for sync');
            }
        }

        if (! $this->option('sync')) {
            $this->info('Cursors reset. Run with --sync to also dispatch sync jobs, or trigger manually.');
        } else {
            $this->info('Done. Sync jobs dispatched to queue.');
        }

        return self::SUCCESS;
    }
}
