<?php

namespace App\Policies;

use App\Models\Dependent;
use App\Models\User;

class DependentPolicy
{
    public function view(User $user, Dependent $dependent): bool
    {
        return $user->id === $dependent->user_id || $user->isInSameHousehold($dependent->user_id);
    }

    public function update(User $user, Dependent $dependent): bool
    {
        return $user->id === $dependent->user_id || $user->isInSameHousehold($dependent->user_id);
    }

    public function delete(User $user, Dependent $dependent): bool
    {
        return $user->id === $dependent->user_id || $user->isInSameHousehold($dependent->user_id);
    }
}
