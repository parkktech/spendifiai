<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active    = 'active';
    case Unused    = 'unused';
    case Cancelled = 'cancelled';
}
