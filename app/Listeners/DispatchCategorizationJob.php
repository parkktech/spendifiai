<?php

namespace App\Listeners;

use App\Events\TransactionsImported;
use App\Jobs\CategorizePendingTransactions;
use Illuminate\Contracts\Queue\ShouldQueue;

class DispatchCategorizationJob implements ShouldQueue
{
    public function handle(TransactionsImported $event): void
    {
        CategorizePendingTransactions::dispatch($event->connection->user_id);
    }
}
