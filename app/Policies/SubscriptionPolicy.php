<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function view(User $user, Subscription $sub): bool { return $user->id === $sub->user_id; }
    public function update(User $user, Subscription $sub): bool { return $user->id === $sub->user_id; }
}
