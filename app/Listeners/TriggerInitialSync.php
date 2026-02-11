<?php

namespace App\Listeners;

use App\Events\BankConnected;
use App\Jobs\SyncBankTransactions;
use Illuminate\Contracts\Queue\ShouldQueue;

class TriggerInitialSync implements ShouldQueue
{
    public function handle(BankConnected $event): void
    {
        SyncBankTransactions::dispatch($event->connection);
    }
}
