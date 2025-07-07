<?php

namespace App\Mail\Onboarding;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable //implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $name;
    public string $accountType;

    /**
     * Create a new message instance.
     */
    public function __construct(string $name, string $accountType)
    {
        $this->name = $name;
        $this->accountType = $accountType;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Sbareads-Library',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.onboarding.welcome',
            text: 'emails.onboarding.welcome-text',
            with: [
                'name' => $this->name,
                'accountType' => $this->accountType,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
