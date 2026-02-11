<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UnusedSubscriptionAlert extends Notification
{
    use Queueable;

    public function __construct(
        public readonly array $subscriptions,
        public readonly float $totalMonthlyCost,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->subscriptions);
        $names = implode(', ', array_slice($this->subscriptions, 0, 5));

        $mail = (new MailMessage)
            ->subject("You may have {$count} unused subscriptions")
            ->line("We detected {$count} subscriptions that appear unused:")
            ->line($names)
            ->line("Total monthly cost: \${$this->totalMonthlyCost}")
            ->action('Review Subscriptions', url('/subscriptions'))
            ->line('Canceling unused subscriptions could save you money.');

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'unused_subscriptions',
            'count'         => count($this->subscriptions),
            'monthly_cost'  => $this->totalMonthlyCost,
            'subscriptions' => $this->subscriptions,
        ];
    }
}
