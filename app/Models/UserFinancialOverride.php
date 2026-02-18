<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFinancialOverride extends Model
{
    protected $fillable = [
        'user_id',
        'override_type',
        'override_key',
        'classification',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all overrides for a user, grouped by type.
     *
     * @return array{income_source?: array<string, string>, expense_category?: array<string, string>}
     */
    public static function getOverridesFor(int $userId): array
    {
        return self::where('user_id', $userId)
            ->get()
            ->groupBy('override_type')
            ->map(fn ($group) => $group->pluck('classification', 'override_key')->toArray())
            ->toArray();
    }
}
