<?php

namespace App\Mail\Onboarding;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StripeOnboardingMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $url;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, $url)
    {
        $this->user = $user;
        // Accept either a string URL or a Stripe AccountLink object
        $this->url = is_string($url) ? $url : ($url->url ?? '');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Complete your Stripe onboarding',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.onboarding.stripe-onboarding-mail',
            with: [
                'user' => $this->user,
                'url' => $this->url,
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
