<?php

namespace App\Mail\Order;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPlacedAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New Order Received — ' . $this->order->tracking_number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.placed-admin',
            with: ['order' => $this->order],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
