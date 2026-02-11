<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'merchant_name', 'merchant_normalized', 'amount',
        'frequency', 'category', 'status', 'is_essential',
        'last_charge_date', 'next_expected_date', 'last_used_at',
        'annual_cost', 'charge_history',
    ];

    protected function casts(): array
    {
        return [
            'amount'          => 'decimal:2',
            'annual_cost'     => 'decimal:2',
            'status'          => SubscriptionStatus::class,
            'is_essential'    => 'boolean',
            'charge_history'  => 'array',
            'last_charge_date'   => 'date',
            'next_expected_date' => 'date',
            'last_used_at'    => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function scopeActive($q) { return $q->where('status', SubscriptionStatus::Active); }
    public function scopeUnused($q) { return $q->where('status', SubscriptionStatus::Unused); }
    public function scopeEssential($q) { return $q->where('is_essential', true); }
}
