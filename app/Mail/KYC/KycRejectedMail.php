<?php

namespace App\Mail\KYC;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KycRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Action Required: Identity Verification Unsuccessful — SBA Reads');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.kyc.rejected', with: ['user' => $this->user]);
    }

    public function attachments(): array
    {
        return [];
    }
}
