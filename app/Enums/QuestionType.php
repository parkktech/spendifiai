<?php

namespace App\Enums;

enum QuestionType: string
{
    case Category         = 'category';
    case BusinessPersonal = 'business_personal';
    case Split            = 'split';
    case Confirm          = 'confirm';
}
