<?php

namespace App\Enums;

enum QuestionStatus: string
{
    case Pending  = 'pending';
    case Answered = 'answered';
    case Skipped  = 'skipped';
    case Expired  = 'expired';
}
