<?php

namespace App\Mail;

use App\Models\AccountingFirm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FirmInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AccountingFirm $firm,
        public string $inviteUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->firm->name} invites you to ".config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.firm-invite',
        );
    }
}
