<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountantInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $accountant,
        public User $client,
    ) {}

    public function envelope(): Envelope
    {
        $accountantName = $this->accountant->company_name ?: $this->accountant->name;

        return new Envelope(
            subject: "{$accountantName} wants to manage your finances on ".config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.accountant-invite',
        );
    }
}
