<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BudgetThresholdReached extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $category,
        public readonly float $spent,
        public readonly float $budget,
        public readonly bool $exceeded = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $percentage = round(($this->spent / $this->budget) * 100);

        if ($this->exceeded) {
            return (new MailMessage)
                ->subject("You've exceeded your {$this->category} budget")
                ->line("You've spent \${$this->spent} of your \${$this->budget} {$this->category} budget this month.")
                ->line("That's {$percentage}% of your monthly limit.")
                ->action('View Budget', url('/dashboard'))
                ->line('Consider reviewing your spending in this category.');
        }

        return (new MailMessage)
            ->subject("You've reached {$percentage}% of your {$this->category} budget")
            ->line("You've spent \${$this->spent} of your \${$this->budget} {$this->category} budget this month.")
            ->line("That's {$percentage}% of your monthly limit.")
            ->action('View Budget', url('/dashboard'))
            ->line('Keep an eye on your spending to stay within budget.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'budget_threshold',
            'category'   => $this->category,
            'spent'      => $this->spent,
            'budget'     => $this->budget,
            'exceeded'   => $this->exceeded,
            'percentage' => round(($this->spent / $this->budget) * 100),
        ];
    }
}
