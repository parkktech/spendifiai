<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsPlanAction extends Model
{
    protected $fillable = ['savings_target_id','title','description','category','current_spending','recommended_spending','monthly_savings','difficulty','status','user_response'];
    protected function casts(): array { return ['current_spending'=>'decimal:2','recommended_spending'=>'decimal:2','monthly_savings'=>'decimal:2']; }
    public function savingsTarget(): BelongsTo { return $this->belongsTo(SavingsTarget::class); }
}
