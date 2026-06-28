<?php

namespace App\Mail\KYC;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KycVerifiedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Author Identity Has Been Verified — SBA Reads');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.kyc.verified', with: ['user' => $this->user]);
    }

    public function attachments(): array
    {
        return [];
    }
}
