<?php

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;

class OrderItemPolicy
{
    public function update(User $user, OrderItem $item): bool
    {
        return $item->user_id === $user->id;
    }
}
