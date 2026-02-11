<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetGoal extends Model
{
    protected $fillable = ['user_id','category','monthly_limit','alert_threshold'];
    protected function casts(): array { return ['monthly_limit'=>'decimal:2','alert_threshold'=>'decimal:2']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
