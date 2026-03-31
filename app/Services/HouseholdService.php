<?php

namespace App\Services;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Support\Str;

class HouseholdService
{
    /**
     * Create a new household and set the user as owner.
     */
    public function createHousehold(User $user, string $name = 'My Household'): Household
    {
        $household = Household::create([
            'name' => $name,
            'created_by_user_id' => $user->id,
        ]);

        $user->update([
            'household_id' => $household->id,
            'household_role' => 'owner',
        ]);

        return $household;
    }

    /**
     * Create an invitation link. Auto-creates household if user doesn't have one.
     */
    public function createInvitation(User $user, ?string $email = null): HouseholdInvitation
    {
        if (! $user->household_id) {
            $this->createHousehold($user, $user->name."'s Household");
            $user->refresh();
        }

        return HouseholdInvitation::create([
            'household_id' => $user->household_id,
            'invited_by_user_id' => $user->id,
            'email' => $email,
            'token' => Str::random(64),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * Accept an invitation and join the household.
     */
    public function acceptInvitation(User $user, HouseholdInvitation $invitation): void
    {
        if ($invitation->isExpired()) {
            throw new \InvalidArgumentException('This invitation has expired.');
        }

        if ($invitation->status !== 'pending') {
            throw new \InvalidArgumentException('This invitation is no longer valid.');
        }

        if ($user->household_id) {
            throw new \InvalidArgumentException('You are already in a household. Leave your current household first.');
        }

        $user->update([
            'household_id' => $invitation->household_id,
            'household_role' => 'member',
        ]);

        $invitation->update([
            'status' => 'accepted',
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ]);

        $this->invalidateHouseholdCaches($invitation->household_id);
    }

    /**
     * Owner removes a member from the household.
     */
    public function removeMember(User $owner, User $member): void
    {
        if ($owner->household_id !== $member->household_id) {
            throw new \InvalidArgumentException('User is not in your household.');
        }

        if ($owner->household_role !== 'owner') {
            throw new \InvalidArgumentException('Only the household owner can remove members.');
        }

        if ($owner->id === $member->id) {
            throw new \InvalidArgumentException('You cannot remove yourself. Use leave instead.');
        }

        $householdId = $member->household_id;

        $member->update([
            'household_id' => null,
            'household_role' => 'member',
        ]);

        $this->invalidateHouseholdCaches($householdId);
    }

    /**
     * Member leaves the household voluntarily.
     */
    public function leaveHousehold(User $user): void
    {
        if (! $user->household_id) {
            throw new \InvalidArgumentException('You are not in a household.');
        }

        if ($user->household_role === 'owner') {
            throw new \InvalidArgumentException('Owners cannot leave. Transfer ownership or delete the household first.');
        }

        $householdId = $user->household_id;

        $user->update([
            'household_id' => null,
            'household_role' => 'member',
        ]);

        // If no members left, delete the household
        $remaining = User::where('household_id', $householdId)->count();
        if ($remaining === 0) {
            Household::where('id', $householdId)->delete();
        }

        $this->invalidateHouseholdCaches($householdId);
    }

    /**
     * Invalidate dashboard caches for all household members.
     */
    public function invalidateHouseholdCaches(int $householdId): void
    {
        $memberIds = User::where('household_id', $householdId)->pluck('id');

        foreach ($memberIds as $memberId) {
            $pattern = "dashboard:{$memberId}:";
            // Clear known cache keys — Laravel's Redis driver supports pattern-based flush
            \Illuminate\Support\Facades\Cache::forget($pattern);
        }
    }
}
