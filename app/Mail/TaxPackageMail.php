<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TaxPackageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public int $year,
        public array $summary,
        public array $files,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('app.name')." Tax Export — {$this->year} for {$this->user->name}",
            replyTo: [$this->user->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.tax-package',
        );
    }

    public function attachments(): array
    {
        $attachments = [];

        if (isset($this->files['xlsx']) && file_exists($this->files['xlsx'])) {
            $attachments[] = Attachment::fromPath($this->files['xlsx'])
                ->as("SpendifiAI_Tax_{$this->year}.xlsx")
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        if (isset($this->files['pdf']) && file_exists($this->files['pdf'])) {
            $attachments[] = Attachment::fromPath($this->files['pdf'])
                ->as("SpendifiAI_Tax_Summary_{$this->year}.pdf")
                ->withMime('application/pdf');
        }

        if (isset($this->files['csv']) && file_exists($this->files['csv'])) {
            $attachments[] = Attachment::fromPath($this->files['csv'])
                ->as("SpendifiAI_Transactions_{$this->year}.csv")
                ->withMime('text/csv');
        }

        return $attachments;
    }
}
