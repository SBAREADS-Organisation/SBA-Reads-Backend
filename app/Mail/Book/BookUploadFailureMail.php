<?php

namespace App\Mail\Book;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookUploadFailureMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $authorName,
        public readonly array  $bookTitles,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action Required: Please Re-upload Your Book File',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.books.upload-failure',
            with: [
                'authorName' => $this->authorName,
                'bookTitles' => $this->bookTitles,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
