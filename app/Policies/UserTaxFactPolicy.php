<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserTaxFact;

/**
 * V4 Access-control policy for UserTaxFact.
 *
 * Mirrors AIQuestionPolicy (same household-aware pattern from 11-PATTERNS.md).
 * Household access: a household member may view/update their own and
 * household partners' facts (same design as AIQuestion, BankAccount, etc.).
 */
class UserTaxFactPolicy
{
    public function view(User $user, UserTaxFact $fact): bool
    {
        return $user->id === $fact->user_id || $user->isInSameHousehold($fact->user_id);
    }

    public function update(User $user, UserTaxFact $fact): bool
    {
        return $user->id === $fact->user_id || $user->isInSameHousehold($fact->user_id);
    }

    public function confirm(User $user, UserTaxFact $fact): bool
    {
        return $user->id === $fact->user_id || $user->isInSameHousehold($fact->user_id);
    }

    public function delete(User $user, UserTaxFact $fact): bool
    {
        // Delete is not normally allowed (append-only); only GDPR cascade handles deletion.
        // Policy returns false to prevent accidental API deletions.
        return false;
    }
}
