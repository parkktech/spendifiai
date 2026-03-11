<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SyncDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        $added = $this->summary['sync']['added'] ?? 0;
        $monthName = $this->summary['month_name'] ?? now()->format('F');
        $spending = $this->summary['spending']['current_month'] ?? 0;

        $subject = sprintf(
            'Your %s finances: %d new transaction%s, $%s spent so far',
            $monthName,
            $added,
            $added === 1 ? '' : 's',
            number_format($spending, 0),
        );

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.sync-digest',
        );
    }
}
