<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AIQuestionsReady extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $questionCount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->questionCount} transactions need your input")
            ->line('AI has categorized some transactions but needs your help to confirm.')
            ->line("{$this->questionCount} transactions are waiting for your review.")
            ->action('Review Questions', url('/questions'))
            ->line('Your input helps improve future categorization accuracy.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'ai_questions_ready',
            'count'   => $this->questionCount,
            'message' => "{$this->questionCount} transactions need your review",
        ];
    }
}
