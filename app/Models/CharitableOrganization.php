<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CharitableOrganization extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'website_url', 'donate_url',
        'category', 'ein', 'is_featured', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $org) {
            if (empty($org->slug)) {
                $org->slug = Str::slug($org->name);
            }
        });
    }
}
