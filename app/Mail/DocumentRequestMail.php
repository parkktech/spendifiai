<?php

namespace App\Mail;

use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public DocumentRequest $request,
        public User $accountant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your accountant requested a document',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.document-request',
        );
    }
}
