<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantAlias extends Model
{
    protected $fillable = [
        'bank_name',
        'normalized_name',
        'email_domain',
        'source',
        'match_count',
    ];
}
