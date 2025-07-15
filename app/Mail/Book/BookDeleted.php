<?php

namespace App\Mail\Book;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookDeleted extends Mailable
{
    use Queueable, SerializesModels;
    protected $book;
    protected $reason;
    protected $author;
    /**
     * Create a new message instance.
     */
    public function __construct($book, $reason, $author)
    {
        $this->book = $book;
        $this->reason = $reason;
        $this->author = $author;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Book Deleted',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.books.deleted',
            with: [
                'book' => $this->book,
                'reason' => $this->reason,
                'author' => $this->author,
            ],
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
