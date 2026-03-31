<?php

namespace App\Traits;

use App\Models\Household;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToHousehold
{
    /**
     * Get all user IDs in this user's household.
     * Returns [$this->id] if user has no household (identical to pre-household behavior).
     * Cached per-request to avoid repeated queries.
     */
    public function householdUserIds(): array
    {
        if (! $this->household_id) {
            return [$this->id];
        }

        static $cache = [];

        if (isset($cache[$this->household_id])) {
            return $cache[$this->household_id];
        }

        $cache[$this->household_id] = Household::find($this->household_id)?->memberUserIds() ?? [$this->id];

        return $cache[$this->household_id];
    }

    /**
     * Check if this user is in the same household as another user.
     */
    public function isInSameHousehold(int $userId): bool
    {
        if ($this->id === $userId) {
            return true;
        }

        if (! $this->household_id) {
            return false;
        }

        return in_array($userId, $this->householdUserIds());
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
