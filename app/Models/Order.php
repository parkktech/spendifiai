<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','parsed_email_id','merchant','merchant_normalized','order_number','order_date','subtotal','tax','shipping','total','currency','matched_transaction_id','is_reconciled'];
    protected function casts(): array { return ['order_date'=>'date','subtotal'=>'decimal:2','tax'=>'decimal:2','shipping'=>'decimal:2','total'=>'decimal:2']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function parsedEmail(): BelongsTo { return $this->belongsTo(ParsedEmail::class); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function matchedTransaction(): BelongsTo { return $this->belongsTo(Transaction::class, 'matched_transaction_id'); }
}
