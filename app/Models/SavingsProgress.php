<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsProgress extends Model
{
    protected $table = 'savings_progress';

    protected $fillable = [
        'user_id', 'savings_target_id', 'month',
        'income', 'total_spending', 'actual_savings', 'target_savings',
        'gap', 'cumulative_saved', 'cumulative_target',
        'target_met', 'category_breakdown', 'plan_adherence',
    ];

    protected function casts(): array
    {
        return [
            'income'             => 'decimal:2',
            'total_spending'     => 'decimal:2',
            'actual_savings'     => 'decimal:2',
            'target_savings'     => 'decimal:2',
            'gap'                => 'decimal:2',
            'cumulative_saved'   => 'decimal:2',
            'cumulative_target'  => 'decimal:2',
            'target_met'         => 'boolean',
            'category_breakdown' => 'array',
            'plan_adherence'     => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savingsTarget(): BelongsTo
    {
        return $this->belongsTo(SavingsTarget::class);
    }
}
