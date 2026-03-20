<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingCompleteNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected array $stats = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $transactionsImported = $this->stats['transactions_imported'] ?? 0;
        $subscriptionsDetected = $this->stats['subscriptions_detected'] ?? 0;
        $monthlyCost = $this->stats['monthly_subscription_cost'] ?? 0;
        $deductionsFound = $this->stats['deductions_found'] ?? 0;
        $estimatedSavings = $this->stats['estimated_tax_savings'] ?? 0;
        $emailsMatched = $this->stats['emails_matched'] ?? 0;

        $mail = (new MailMessage)
            ->subject('Your finances are organized — here\'s what we found')
            ->greeting('Hey '.$notifiable->name.'!')
            ->line('We\'ve finished analyzing your financial data. Here\'s a summary:');

        if ($transactionsImported > 0) {
            $mail->line("**{$transactionsImported} transactions** imported and categorized");
        }

        if ($subscriptionsDetected > 0) {
            $costFormatted = number_format($monthlyCost, 2);
            $mail->line("**{$subscriptionsDetected} subscriptions** detected (\${$costFormatted}/month)");
        }

        if ($deductionsFound > 0) {
            $savingsFormatted = number_format($estimatedSavings, 2);
            $mail->line("**{$deductionsFound} potential tax deductions** found (~\${$savingsFormatted} estimated savings)");
        }

        if ($emailsMatched > 0) {
            $mail->line("**{$emailsMatched} email receipts** matched to transactions");
        }

        return $mail
            ->action('View Your Dashboard', url('/dashboard'))
            ->line('Your personalized financial dashboard is ready!');
    }
}
