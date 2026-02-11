<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaidWebhookLog extends Model
{
    protected $fillable = [
        'webhook_type',
        'webhook_code',
        'item_id',
        'payload',
        'status',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
