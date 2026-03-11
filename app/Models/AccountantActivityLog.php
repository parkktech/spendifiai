<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountantActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'accountant_id',
        'client_id',
        'action',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected $hidden = [
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function accountant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accountant_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
