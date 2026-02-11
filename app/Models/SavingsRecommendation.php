<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsRecommendation extends Model
{
    protected $fillable = [
        'user_id', 'title', 'description', 'monthly_savings', 'annual_savings',
        'difficulty', 'impact', 'category', 'status',
        'action_steps', 'related_merchants',
        'generated_at', 'applied_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'monthly_savings' => 'decimal:2',
            'annual_savings' => 'decimal:2',
            'action_steps' => 'array',
            'related_merchants' => 'array',
            'generated_at' => 'datetime',
            'applied_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }
}
