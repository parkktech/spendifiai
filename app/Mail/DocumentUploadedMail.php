<?php

namespace App\Mail;

use App\Models\TaxDocument;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentUploadedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TaxDocument $document,
        public User $client,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->client->name} uploaded a document",
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.document-uploaded',
        );
    }
}
