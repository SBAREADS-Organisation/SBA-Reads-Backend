<?php

namespace App\Mail\KYC;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KycDelayNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User    $user,
        public readonly string  $firstName,
        public readonly ?string $customMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Update on Your Identity Verification — SBA Reads');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kyc.delay-notice');
    }

    public function attachments(): array
    {
        return [];
    }
}
