<?php

namespace App\Policies;

use App\Models\InterviewSession;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Owner-or-household policy for InterviewSession (T-11-04-03 / V4 access control).
 *
 * Standard pattern: user_id === resource->user_id (same as all existing policies).
 * Household members can view their own sessions only — sessions are NOT shared
 * across household members (each member has their own financial profile).
 */
class InterviewSessionPolicy
{
    use HandlesAuthorization;

    public function view(User $user, InterviewSession $session): bool
    {
        return $user->id === $session->user_id;
    }

    public function update(User $user, InterviewSession $session): bool
    {
        return $user->id === $session->user_id;
    }

    public function delete(User $user, InterviewSession $session): bool
    {
        return $user->id === $session->user_id;
    }
}
