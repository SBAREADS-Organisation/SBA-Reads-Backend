<?php

namespace App\Mail\Order;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPlaced extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $order, public string $userName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Order Confirmed — ' . $this->order->tracking_number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.placed',
            with: ['order' => $this->order, 'userName' => $this->userName],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
