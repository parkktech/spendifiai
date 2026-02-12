<?php

namespace App\Models;

use App\Enums\SavingsLedgerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsLedger extends Model
{
    protected $table = 'savings_ledger';

    protected $fillable = [
        'user_id', 'source_type', 'source_id', 'action_taken',
        'monthly_savings', 'previous_amount', 'new_amount',
        'status', 'month', 'notes', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'monthly_savings' => 'decimal:2',
            'previous_amount' => 'decimal:2',
            'new_amount' => 'decimal:2',
            'status' => SavingsLedgerStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
