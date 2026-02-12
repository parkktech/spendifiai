<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'description', 'monthly_savings', 'annual_savings',
        'difficulty', 'impact', 'category', 'status',
        'action_steps', 'related_merchants',
        'generated_at', 'applied_at', 'dismissed_at',
        'response_type', 'response_data', 'actual_monthly_savings',
        'ai_alternatives', 'alternatives_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'monthly_savings' => 'decimal:2',
            'annual_savings' => 'decimal:2',
            'actual_monthly_savings' => 'decimal:2',
            'action_steps' => 'array',
            'related_merchants' => 'array',
            'response_data' => 'array',
            'ai_alternatives' => 'array',
            'generated_at' => 'datetime',
            'applied_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'alternatives_generated_at' => 'datetime',
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
