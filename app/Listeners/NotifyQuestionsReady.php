<?php

namespace App\Listeners;

use App\Events\TransactionCategorized;
use App\Notifications\AIQuestionsReady;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyQuestionsReady implements ShouldQueue
{
    public function handle(TransactionCategorized $event): void
    {
        if ($event->questionsCreated > 0) {
            $event->user->notify(new AIQuestionsReady($event->questionsCreated));
        }
    }
}
