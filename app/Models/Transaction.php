<?php

namespace App\Models;

use App\Enums\AccountPurpose;
use App\Enums\ExpenseType;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'bank_account_id', 'plaid_transaction_id', 'account_purpose',
        'merchant_name', 'merchant_normalized', 'description', 'amount',
        'transaction_date', 'authorized_date', 'payment_channel',
        'plaid_category', 'plaid_detailed_category', 'plaid_metadata',
        'ai_category', 'ai_confidence', 'user_category', 'expense_type',
        'tax_deductible', 'tax_category', 'review_status', 'is_subscription',
        'matched_order_id', 'is_reconciled',
    ];

    protected $hidden = [
        'plaid_transaction_id',   // Internal Plaid identifier
        'plaid_metadata',         // Raw Plaid payload — encrypted, internal only
        'plaid_category',         // Raw Plaid categorization
        'plaid_detailed_category',
        'bank_account_id',        // Internal FK — frontend uses account relationship
    ];

    protected function casts(): array
    {
        return [
            'transaction_date'  => 'date',
            'authorized_date'   => 'date',
            'amount'            => 'decimal:2',
            'ai_confidence'     => 'decimal:2',
            'plaid_metadata'    => 'encrypted:array',  // Raw Plaid data — AES-256 encrypted JSON
            'tax_deductible'    => 'boolean',
            'is_subscription'   => 'boolean',
            'expense_type'      => ExpenseType::class,
            'review_status'     => ReviewStatus::class,
            'account_purpose'   => AccountPurpose::class,
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function aiQuestion(): HasOne { return $this->hasOne(AIQuestion::class); }
    public function matchedOrder(): BelongsTo { return $this->belongsTo(Order::class, 'matched_order_id'); }

    // Query scopes
    public function scopeDeductible($q) { return $q->where('tax_deductible', true); }
    public function scopeBusiness($q) { return $q->where('account_purpose', AccountPurpose::Business); }
    public function scopePersonal($q) { return $q->where('account_purpose', AccountPurpose::Personal); }
    public function scopeNeedsReview($q) { return $q->whereIn('review_status', [ReviewStatus::NeedsReview, ReviewStatus::PendingAI, ReviewStatus::AIUncertain]); }
    public function scopeByCategory($q, string $cat) { return $q->where(fn($q) => $q->where('user_category', $cat)->orWhere('ai_category', $cat)); }
    public function scopeSpending($q) { return $q->where('amount', '>', 0); }
    public function scopeIncome($q) { return $q->where('amount', '<', 0); }

    // Accessor: resolved category (user override > AI)
    public function getCategoryAttribute(): string
    {
        return $this->user_category ?? $this->ai_category ?? 'Uncategorized';
    }
}
