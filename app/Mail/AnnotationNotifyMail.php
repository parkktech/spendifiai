<?php

namespace App\Mail;

use App\Models\DocumentAnnotation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnnotationNotifyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public DocumentAnnotation $annotation,
        public User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        $documentName = $this->annotation->document->original_filename ?? 'a document';

        return new Envelope(
            subject: "New comment on {$documentName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.annotation-notify',
        );
    }
}
