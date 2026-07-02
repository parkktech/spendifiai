<?php

namespace App\Policies;

use App\Models\OptimizationChecklistItem;
use App\Models\User;

/**
 * OptimizationChecklistItemPolicy — user_id ownership enforcement (T-14-08-02).
 *
 * Cross-user item access via route-model binding is blocked here.
 * All policy methods verify $user->id === $item->user_id.
 */
class OptimizationChecklistItemPolicy
{
    public function view(User $user, OptimizationChecklistItem $item): bool
    {
        return $user->id === $item->user_id;
    }

    public function update(User $user, OptimizationChecklistItem $item): bool
    {
        return $user->id === $item->user_id;
    }
}
