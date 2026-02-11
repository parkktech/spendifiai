<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklySavingsDigest extends Notification
{
    use Queueable;

    public function __construct(
        public readonly User $targetUser,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $recommendations = $this->targetUser->savingsRecommendations()
            ->where('created_at', '>=', now()->subWeek())
            ->get();

        $count = $recommendations->count();
        $potentialSavings = $recommendations->sum('estimated_savings');

        $target = $this->targetUser->savingsTarget;
        $progressLine = $target
            ? "Savings target progress: \${$target->current_amount} of \${$target->target_amount}"
            : 'Set a savings target to track your progress!';

        return (new MailMessage)
            ->subject('Your Weekly Savings Summary')
            ->line("Here's your savings update for this week:")
            ->line("{$count} active savings recommendations")
            ->line("Potential monthly savings: \${$potentialSavings}")
            ->line($progressLine)
            ->action('View Savings', url('/savings'))
            ->line('Keep up the great work on your financial goals!');
    }

    public function toArray(object $notifiable): array
    {
        $recommendations = $this->targetUser->savingsRecommendations()
            ->where('created_at', '>=', now()->subWeek())
            ->get();

        return [
            'type'                  => 'weekly_savings_digest',
            'recommendations_count' => $recommendations->count(),
            'potential_savings'     => $recommendations->sum('estimated_savings'),
        ];
    }
}
