<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidStatement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'bank_connection_id', 'bank_account_id',
        'plaid_statement_id', 'plaid_account_id',
        'month', 'year', 'date_posted',
        'file_path', 'content_hash', 'status',
        'total_extracted', 'duplicates_found', 'transactions_imported',
        'error_message', 'processing_notes',
        'date_range_from', 'date_range_to',
    ];

    protected $hidden = [
        'file_path',
        'plaid_statement_id',
        'plaid_account_id',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'processing_notes' => 'array',
            'date_posted' => 'date',
            'date_range_from' => 'date',
            'date_range_to' => 'date',
            'month' => 'integer',
            'year' => 'integer',
            'total_extracted' => 'integer',
            'duplicates_found' => 'integer',
            'transactions_imported' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
