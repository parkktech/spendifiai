<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoPage extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'meta_description',
        'h1',
        'category',
        'keywords',
        'excerpt',
        'content',
        'faq_items',
        'featured_image',
        'featured_image_alt',
        'is_published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'faq_items' => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function getUrlAttribute(): string
    {
        return "/blog/{$this->slug}";
    }

    public function getReadTimeAttribute(): int
    {
        $wordCount = str_word_count(strip_tags($this->content ?? ''));

        return max(1, (int) ceil($wordCount / 200));
    }
}
