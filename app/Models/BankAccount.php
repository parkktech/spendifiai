<?php

namespace App\Models;

use App\Enums\AccountPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    protected $fillable = [
        'user_id', 'bank_connection_id', 'plaid_account_id', 'name', 'official_name',
        'type', 'subtype', 'mask', 'purpose', 'nickname', 'business_name',
        'tax_entity_type', 'ein', 'include_in_spending', 'include_in_tax_tracking',
        'current_balance', 'available_balance', 'is_active',
    ];

    protected $hidden = [
        'plaid_account_id',  // Internal Plaid identifier — never expose to frontend
        'ein',               // Federal tax ID — encrypted, only used in tax export
        'bank_connection_id',
    ];

    protected function casts(): array
    {
        return [
            'purpose'              => AccountPurpose::class,
            'ein'                  => 'encrypted',  // Federal EIN — AES-256 at rest
            'current_balance'      => 'decimal:2',
            'available_balance'    => 'decimal:2',
            'include_in_spending'  => 'boolean',
            'include_in_tax_tracking' => 'boolean',
            'is_active'            => 'boolean',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function bankConnection(): BelongsTo { return $this->belongsTo(BankConnection::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }

    public function scopeActive($q) { return $q->where('is_active', true); }
    public function scopeBusiness($q) { return $q->where('purpose', AccountPurpose::Business); }
    public function scopePersonal($q) { return $q->where('purpose', AccountPurpose::Personal); }
}
