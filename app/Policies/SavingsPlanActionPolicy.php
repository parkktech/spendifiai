<?php

namespace App\Policies;

use App\Models\SavingsPlanAction;
use App\Models\User;

class SavingsPlanActionPolicy
{
    public function update(User $user, SavingsPlanAction $action): bool
    {
        return $action->savingsTarget && $action->savingsTarget->user_id === $user->id;
    }
}
