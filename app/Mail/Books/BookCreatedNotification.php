<?php

namespace App\Mail\Books;

use App\Models\Book;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookCreatedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public Book $book;
    public string $recipientName;

    /**
     * Create a new message instance.
     */
    public function __construct(Book $book, string $recipientName = '')
    {
        $this->book = $book;
        $this->recipientName = $recipientName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Book Created Notification',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.books.created',
            with: [
                'title' => "Your book '{$this->book->title}' has been created",
                'message' => 'A new book has been created successfully.',
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
