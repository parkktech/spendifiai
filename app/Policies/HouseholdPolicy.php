<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $user->household_id === $household->id;
    }

    public function update(User $user, Household $household): bool
    {
        return $user->household_id === $household->id && $user->household_role === 'owner';
    }

    public function delete(User $user, Household $household): bool
    {
        return $user->household_id === $household->id && $user->household_role === 'owner';
    }
}
