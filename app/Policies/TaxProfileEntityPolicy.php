<?php

namespace App\Policies;

use App\Models\TaxProfileEntity;
use App\Models\User;

/**
 * V4 Access-control policy for TaxProfileEntity.
 *
 * Mirrors AIQuestionPolicy (same household-aware pattern from 11-PATTERNS.md).
 */
class TaxProfileEntityPolicy
{
    public function view(User $user, TaxProfileEntity $entity): bool
    {
        return $user->id === $entity->user_id || $user->isInSameHousehold($entity->user_id);
    }

    public function update(User $user, TaxProfileEntity $entity): bool
    {
        return $user->id === $entity->user_id || $user->isInSameHousehold($entity->user_id);
    }

    public function delete(User $user, TaxProfileEntity $entity): bool
    {
        return $user->id === $entity->user_id || $user->isInSameHousehold($entity->user_id);
    }
}
