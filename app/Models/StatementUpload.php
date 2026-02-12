<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatementUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'bank_account_id', 'file_name', 'original_file_name',
        'file_path', 'file_type', 'bank_name', 'account_type',
        'status', 'total_extracted', 'duplicates_found', 'transactions_imported',
        'date_range_from', 'date_range_to', 'processing_notes', 'error_message',
    ];

    protected $hidden = [
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'processing_notes' => 'array',
            'date_range_from' => 'date',
            'date_range_to' => 'date',
            'total_extracted' => 'integer',
            'duplicates_found' => 'integer',
            'transactions_imported' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
