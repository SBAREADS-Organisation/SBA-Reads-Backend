<?php

namespace App\Mail\Book;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookDeclined extends Mailable
{
    use Queueable, SerializesModels;

    protected $book;

    protected $reason;

    /**
     * Create a new message instance.
     */
    public function __construct($book, $reason)
    {
        $this->book = $book;
        $this->reason = $reason;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Book Declined',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.books.declined',
            with: [
                'book' => $this->book,
                'reason' => $this->reason,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
