<?php

namespace App\Mail;

use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HouseholdInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $inviteUrl;

    public function __construct(
        public User $inviter,
        public HouseholdInvitation $invitation,
    ) {
        $this->inviteUrl = config('app.url').'/household/join/'.$invitation->token;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->inviter->name.' invited you to share finances on '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.household-invite',
        );
    }
}
