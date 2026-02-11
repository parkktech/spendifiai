<?php

namespace App\Listeners;

use App\Events\TransactionCategorized;
use App\Services\SubscriptionDetectorService;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateSubscriptionDetection implements ShouldQueue
{
    public function handle(TransactionCategorized $event): void
    {
        $detector = app(SubscriptionDetectorService::class);
        $detector->detectSubscriptions($event->user->id);
    }
}
