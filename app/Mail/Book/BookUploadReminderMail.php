<?php

namespace App\Mail\Book;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookUploadReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $authorName,
        public readonly array  $bookTitles,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: Your Book File Still Needs to Be Re-uploaded',
            replyTo: [new Address('admin@sbareads.com', 'SBA Reads Support')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.books.upload-reminder',
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
