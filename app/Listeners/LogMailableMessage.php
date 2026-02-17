<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MailFailed;
use Psr\Log\LoggerInterface;

class LogMailableMessage
{
    public function __construct(protected LoggerInterface $logger) {}

    public function handleSending(MessageSending $event): void
    {
        $to = [];
        foreach ($event->message->getTo() as $address) {
            $to[] = $address->getAddress();
        }

        $this->logger->info('📧 Email sending attempt', [
            'to' => implode(', ', $to),
            'subject' => $event->message->getSubject(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    public function handleSent(MessageSent $event): void
    {
        $to = [];
        foreach ($event->message->getTo() as $address) {
            $to[] = $address->getAddress();
        }

        $this->logger->info('✅ Email sent successfully', [
            'to' => implode(', ', $to),
            'subject' => $event->message->getSubject(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    public function handleFailed(MailFailed $event): void
    {
        $to = [];
        foreach ($event->message->getTo() as $address) {
            $to[] = $address->getAddress();
        }

        $this->logger->error('❌ Email failed to send', [
            'to' => implode(', ', $to),
            'subject' => $event->message->getSubject(),
            'exception' => $event->exception->getMessage(),
        ]);
    }
}
