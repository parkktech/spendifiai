<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case PendingAI       = 'pending_ai';
    case NeedsReview     = 'needs_review';
    case UserConfirmed   = 'user_confirmed';
    case AIUncertain     = 'ai_uncertain';
    case AutoCategorized = 'auto_categorized';

    public function isResolved(): bool
    {
        return in_array($this, [self::UserConfirmed, self::AutoCategorized]);
    }
}
