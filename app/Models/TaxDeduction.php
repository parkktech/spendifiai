<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'description', 'category', 'subcategory',
        'max_amount', 'max_amount_mfj', 'is_credit', 'is_refundable',
        'irs_form', 'irs_line', 'eligibility_rules', 'detection_method',
        'transaction_keywords', 'question_text', 'question_options',
        'help_text', 'irs_url', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'eligibility_rules' => 'array',
            'transaction_keywords' => 'array',
            'question_options' => 'array',
            'max_amount' => 'decimal:2',
            'max_amount_mfj' => 'decimal:2',
            'is_credit' => 'boolean',
            'is_refundable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function userDeductions(): HasMany
    {
        return $this->hasMany(UserTaxDeduction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeDetectable($query)
    {
        return $query->whereIn('detection_method', ['transaction_scan', 'both']);
    }

    public function scopeQuestionnaire($query)
    {
        return $query->whereIn('detection_method', ['profile_question', 'both', 'manual'])
            ->whereNotNull('question_text');
    }
}
