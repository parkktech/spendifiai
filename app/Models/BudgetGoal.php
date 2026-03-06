<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_slug',
        'monthly_limit',
        'period',
        'notify_at_80_pct',
        'notify_at_100_pct',
    ];

    protected function casts(): array
    {
        return [
            'monthly_limit' => 'decimal:2',
            'notify_at_80_pct' => 'boolean',
            'notify_at_100_pct' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
