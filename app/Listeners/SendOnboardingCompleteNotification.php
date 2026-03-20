<?php

namespace App\Listeners;

use App\Events\OnboardingComplete;
use App\Notifications\OnboardingCompleteNotification;

class SendOnboardingCompleteNotification
{
    public function handle(OnboardingComplete $event): void
    {
        $event->user->notify(new OnboardingCompleteNotification($event->stats));
    }
}
