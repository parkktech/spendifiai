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
}
