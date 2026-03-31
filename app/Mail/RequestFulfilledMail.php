<?php

namespace App\Mail;

use App\Models\DocumentRequest;
use App\Models\TaxDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RequestFulfilledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public DocumentRequest $request,
        public TaxDocument $document,
    ) {}

    public function envelope(): Envelope
    {
        $clientName = $this->request->client->name ?? 'A client';

        return new Envelope(
            subject: "Document request fulfilled by {$clientName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.request-fulfilled',
        );
    }
}
