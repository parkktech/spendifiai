<?php

namespace App\Listeners;

use App\Events\UserAnsweredQuestion;
use App\Services\SubscriptionDetectorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class UpdateTransactionCategory implements ShouldQueue
{
    /**
     * Handle post-answer side effects only.
     *
     * The AIQuestionController already updates the transaction via
     * TransactionCategorizerService::handleUserAnswer(). This listener
     * does NOT duplicate that logic. Instead it handles:
     *   1. Re-run subscription detection (transaction is now categorized)
     *   2. Log the user answer for analytics
     */
    public function handle(UserAnsweredQuestion $event): void
    {
        // Re-check subscription patterns now that the transaction is categorized
        $detector = app(SubscriptionDetectorService::class);
        $detector->detectSubscriptions($event->user->id);

        Log::info('User answered AI question', [
            'user_id'     => $event->user->id,
            'question_id' => $event->question->id,
            'answer'      => $event->question->user_answer,
        ]);
    }
}
