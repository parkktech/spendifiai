<?php

namespace App\Events;

use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BankConnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BankConnection $connection,
        public readonly User $user,
    ) {}
}
