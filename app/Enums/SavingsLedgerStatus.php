<?php

namespace App\Enums;

enum SavingsLedgerStatus: string
{
    case Claimed = 'claimed';
    case Verified = 'verified';
}
