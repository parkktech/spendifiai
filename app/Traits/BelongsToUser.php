<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait BelongsToUser
 *
 * Provides a standard user() relationship and scopeForUser() security scope
 * for models that belong to a User via the user_id foreign key.
 *
 * Usage: add `use BelongsToUser;` to any model with a user_id column, then
 * remove its local user() and scopeForUser() definitions.
 */
trait BelongsToUser
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * SECURITY: Always scope queries through this method to enforce cross-user isolation.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
