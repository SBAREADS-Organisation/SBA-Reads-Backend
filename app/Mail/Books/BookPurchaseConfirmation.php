<?php

namespace App\Mail\Books;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookPurchaseConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $readerName,
        public readonly array  $bookTitles,
        public readonly string $amount,
        public readonly string $bookType, // 'digital' | 'audio'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Purchase Confirmed — SBA Reads');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.books.purchase-confirmation');
    }

    public function attachments(): array
    {
        return [];
    }
}
