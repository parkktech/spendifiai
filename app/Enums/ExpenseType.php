<?php

namespace App\Enums;

enum ExpenseType: string
{
    case Personal = 'personal';
    case Business = 'business';
    case Mixed    = 'mixed';
}
