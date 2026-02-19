<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CancellationProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name', 'slug', 'aliases', 'cancellation_url',
        'cancellation_phone', 'cancellation_instructions',
        'difficulty', 'category', 'is_essential', 'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'is_essential' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $provider) {
            if (empty($provider->slug)) {
                $provider->slug = Str::slug($provider->company_name);
            }
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
