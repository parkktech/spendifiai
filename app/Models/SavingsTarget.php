<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'monthly_target', 'motivation', 'target_start_date',
        'target_end_date', 'goal_total', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_target'    => 'decimal:2',
            'goal_total'        => 'decimal:2',
            'target_start_date' => 'date',
            'target_end_date'   => 'date',
            'is_active'         => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SavingsPlanAction::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(SavingsProgress::class);
    }
}
