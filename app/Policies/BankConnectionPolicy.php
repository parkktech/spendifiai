<?php

namespace App\Policies;

use App\Models\BankConnection;
use App\Models\User;

class BankConnectionPolicy
{
    public function view(User $user, BankConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    public function delete(User $user, BankConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }
}
