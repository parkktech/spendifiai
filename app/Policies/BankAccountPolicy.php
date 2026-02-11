<?php

namespace App\Policies;

use App\Models\BankAccount;
use App\Models\User;

class BankAccountPolicy
{
    public function view(User $user, BankAccount $account): bool { return $user->id === $account->user_id; }
    public function update(User $user, BankAccount $account): bool { return $user->id === $account->user_id; }
}
