<?php

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Events\TransactionsImported;
use App\Models\BankConnection;
use App\Services\PlaidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBankTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(
        public BankConnection $connection,
    ) {}

    public function handle(PlaidService $plaidService): void
    {
        try {
            $result = $plaidService->syncTransactions($this->connection);

            Log::info('Bank sync completed', [
                'connection_id' => $this->connection->id,
                'added'         => $result['added'],
                'modified'      => $result['modified'],
                'removed'       => $result['removed'],
            ]);

            if ($result['added'] > 0) {
                TransactionsImported::dispatch($this->connection, $result['added']);
            }
        } catch (\Exception $e) {
            Log::error('Bank sync failed', [
                'connection_id' => $this->connection->id,
                'error'         => $e->getMessage(),
            ]);

            $this->connection->update([
                'status'        => ConnectionStatus::Error,
                'error_code'    => 'SYNC_FAILED',
                'error_message' => $e->getMessage(),
            ]);

            throw $e; // Re-throw so the job retries
        }
    }
}
