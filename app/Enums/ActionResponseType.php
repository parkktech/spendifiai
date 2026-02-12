<?php

namespace App\Enums;

enum ActionResponseType: string
{
    case Cancelled = 'cancelled';
    case Reduced = 'reduced';
    case Kept = 'kept';
}
