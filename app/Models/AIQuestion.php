<?php

namespace App\Models;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIQuestion extends Model
{
    protected $table = 'ai_questions';

    protected $fillable = [
        'user_id', 'transaction_id', 'question', 'options', 'question_type',
        'ai_confidence', 'ai_best_guess', 'user_answer', 'status', 'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'options'       => 'array',
            'ai_confidence' => 'decimal:2',
            'question_type' => QuestionType::class,
            'status'        => QuestionStatus::class,
            'answered_at'   => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }

    public function scopePending($q) { return $q->where('status', QuestionStatus::Pending); }
    public function scopeAnswered($q) { return $q->where('status', QuestionStatus::Answered); }
}
