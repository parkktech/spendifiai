<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTaxDeduction extends Model
{
    protected $fillable = [
        'user_id', 'tax_deduction_id', 'tax_year',
        'status', 'estimated_amount', 'actual_amount',
        'answer', 'detected_from', 'detection_confidence', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'answer' => 'array',
            'estimated_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'detection_confidence' => 'decimal:2',
            'tax_year' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taxDeduction(): BelongsTo
    {
        return $this->belongsTo(TaxDeduction::class);
    }

    /**
     * Alias for taxDeduction — serializes as "deduction" in JSON for frontend.
     */
    public function deduction(): BelongsTo
    {
        return $this->belongsTo(TaxDeduction::class, 'tax_deduction_id');
    }
}
