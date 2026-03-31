<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AccountingFirm extends Model
{
    protected $fillable = [
        'name',
        'address',
        'phone',
        'logo_url',
        'primary_color',
        'invite_token',
    ];

    protected $hidden = [
        'invite_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (AccountingFirm $firm) {
            if (empty($firm->invite_token)) {
                $firm->invite_token = Str::random(64);
            }
        });
    }

    // ─── Relationships ───

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'accounting_firm_id');
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }
}
