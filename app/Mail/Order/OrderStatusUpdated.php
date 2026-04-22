<?php

namespace App\Mail\Order;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $order, public string $userName, public string $status) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Order Update — ' . $this->order->tracking_number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.status-updated',
            with: [
                'userName'       => $this->userName,
                'status'         => $this->status,
                'trackingNumber' => $this->order->tracking_number,
                'totalAmount'    => $this->order->total_amount,
                'deliveryType'   => $this->order->delivery_type ?? 'delivery',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
