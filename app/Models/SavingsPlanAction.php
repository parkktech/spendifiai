<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsPlanAction extends Model
{
    protected $fillable = [
        'user_id', 'savings_target_id', 'title', 'description', 'how_to',
        'monthly_savings', 'current_spending', 'recommended_spending', 'category',
        'difficulty', 'impact', 'priority', 'is_essential_cut',
        'related_merchants', 'related_subscription_ids',
        'status', 'user_response', 'accepted_at', 'rejected_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'current_spending' => 'decimal:2',
            'recommended_spending' => 'decimal:2',
            'monthly_savings' => 'decimal:2',
            'is_essential_cut' => 'boolean',
            'related_merchants' => 'array',
            'related_subscription_ids' => 'array',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function savingsTarget(): BelongsTo
    {
        return $this->belongsTo(SavingsTarget::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
