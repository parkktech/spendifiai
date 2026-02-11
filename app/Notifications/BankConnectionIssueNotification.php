<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BankConnectionIssueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $issueType,
        public readonly string $institutionName,
        public readonly string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->issueType === 'error'
            ? "Issue with your {$this->institutionName} connection"
            : "Your {$this->institutionName} connection needs attention";

        return (new MailMessage)
            ->subject($subject)
            ->line($this->message)
            ->action('Reconnect Bank', url('/connect'))
            ->line('Please re-authenticate to continue syncing transactions.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'             => 'bank_connection_issue',
            'issue_type'       => $this->issueType,
            'institution_name' => $this->institutionName,
            'message'          => $this->message,
        ];
    }
}
