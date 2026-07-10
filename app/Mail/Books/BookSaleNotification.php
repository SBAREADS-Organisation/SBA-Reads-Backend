<?php

namespace App\Mail\Books;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookSaleNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $authorName,
        public readonly string $bookTitle,
        public readonly string $buyerName,
        public readonly string $amount,
        public readonly string $bookType, // 'digital' | 'audio'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New Sale — {$this->bookTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.books.sale-notification');
    }

    public function attachments(): array
    {
        return [];
    }
}
