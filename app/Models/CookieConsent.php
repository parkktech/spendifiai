<?php

namespace App\Models;

use App\Enums\ConsentRegion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CookieConsent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'visitor_id',
        'consent_version',
        'region',
        'necessary',
        'analytics',
        'marketing',
        'ip_address',
        'user_agent',
        'action',
        'admin_user_id',
        'created_at',
    ];

    protected $hidden = [
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'necessary' => 'boolean',
            'analytics' => 'boolean',
            'marketing' => 'boolean',
            'region' => ConsentRegion::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function scopeLatestForVisitor($query, string $visitorId)
    {
        return $query->where('visitor_id', $visitorId)->latest('created_at');
    }

    public function scopeLatestForUser($query, int $userId)
    {
        return $query->where('user_id', $userId)->latest('created_at');
    }
}
