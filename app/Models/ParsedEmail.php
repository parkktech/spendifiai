<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ParsedEmail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'email_connection_id', 'email_message_id', 'email_thread_id',
        'is_purchase', 'is_refund', 'is_subscription', 'raw_parsed_data',
        'parse_status', 'parse_error', 'email_date',
        'retry_count', 'last_retry_at', 'search_source',
    ];

    protected $hidden = [
        'raw_parsed_data',       // Parsed email data — encrypted, internal only
        'email_message_id',      // Internal email identifier
        'email_thread_id',
        'email_connection_id',
        'parse_error',
    ];

    protected function casts(): array
    {
        return [
            'email_date' => 'datetime',
            'is_purchase' => 'boolean',
            'is_refund' => 'boolean',
            'is_subscription' => 'boolean',
            'raw_parsed_data' => 'encrypted:array',  // Parsed financial data — AES-256 encrypted JSON
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailConnection(): BelongsTo
    {
        return $this->belongsTo(EmailConnection::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
