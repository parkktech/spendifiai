<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentAnnotation extends Model
{
    protected $fillable = [
        'tax_document_id',
        'user_id',
        'parent_id',
        'body',
    ];

    protected $with = ['author'];

    // ─── Relationships ───

    public function document(): BelongsTo
    {
        return $this->belongsTo(TaxDocument::class, 'tax_document_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }
}
