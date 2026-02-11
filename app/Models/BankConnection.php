<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'plaid_item_id', 'plaid_access_token', 'institution_name',
        'institution_id', 'status', 'last_synced_at', 'sync_cursor',
        'error_code', 'error_message',
    ];

    protected $hidden = [
        'plaid_access_token',  // Encrypted Plaid token — NEVER expose
        'plaid_item_id',       // Internal Plaid identifier
        'sync_cursor',         // Sync cursor — internal use only
        'error_code',          // Internal diagnostics
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status'              => ConnectionStatus::class,
            'plaid_access_token'  => 'encrypted',  // AES-256-CBC via Laravel encrypt()
            'last_synced_at'      => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function accounts(): HasMany { return $this->hasMany(BankAccount::class); }

    public function scopeActive($q) { return $q->where('status', ConnectionStatus::Active); }
}
