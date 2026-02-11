<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $tx): bool { return $user->id === $tx->user_id; }
    public function update(User $user, Transaction $tx): bool { return $user->id === $tx->user_id; }
    public function delete(User $user, Transaction $tx): bool { return $user->id === $tx->user_id; }
}
