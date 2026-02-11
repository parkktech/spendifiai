<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['user_id','order_id','product_name','product_description','quantity','unit_price','total_price','ai_category','user_category','tax_category','tax_deductible','tax_deductible_confidence','expense_type','ai_metadata'];
    protected function casts(): array { return ['unit_price'=>'decimal:2','total_price'=>'decimal:2','tax_deductible'=>'boolean','tax_deductible_confidence'=>'decimal:2']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
