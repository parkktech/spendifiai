<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'parent_slug', 'icon', 'color', 'is_typically_deductible', 'is_essential', 'tax_schedule_line', 'keywords', 'sort_order'];

    protected function casts(): array
    {
        return ['keywords' => 'array', 'is_system' => 'boolean', 'is_typically_deductible' => 'boolean', 'is_essential' => 'boolean'];
    }
}
