<?php

namespace App\Policies;

use App\Models\CancellationProvider;
use App\Models\User;

class CancellationProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, CancellationProvider $provider): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, CancellationProvider $provider): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, CancellationProvider $provider): bool
    {
        return $user->isAdmin();
    }
}
