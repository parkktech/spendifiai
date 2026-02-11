<?php

namespace App\Events;

use App\Models\BankConnection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionsImported
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BankConnection $connection,
        public readonly int $count,
    ) {}
}
