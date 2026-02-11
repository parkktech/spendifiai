<?php

namespace App\Policies;

use App\Models\SavingsRecommendation;
use App\Models\User;

class SavingsRecommendationPolicy
{
    public function view(User $user, SavingsRecommendation $rec): bool
    {
        return $user->id === $rec->user_id;
    }

    public function update(User $user, SavingsRecommendation $rec): bool
    {
        return $user->id === $rec->user_id;
    }
}
