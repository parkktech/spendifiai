<?php

namespace App\Enums;

enum QuestionType: string
{
    case Category = 'category';
    case BusinessPersonal = 'business_personal';
    case Split = 'split';
    case Confirm = 'confirm';
    // Phase 11 — AI-feed bridge (FEED-01/D7):
    // Additive case for tax-optimization questions surfaced by SurfaceHighPriorityRedFlags.
    // These questions have no transaction_id and their answers write to UserTaxFact,
    // not to Transaction. No migration needed — question_type is VARCHAR.
    case Optimization = 'optimization';
}
