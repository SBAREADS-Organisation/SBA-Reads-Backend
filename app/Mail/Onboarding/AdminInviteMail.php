<?php

namespace App\Mail\Onboarding;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $inviteUrl,
        public string $role,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been invited to SBA Reads Admin");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.onboarding.admin-invite',
            with: [
                'name'      => $this->user->name,
                'inviteUrl' => $this->inviteUrl,
                'role'      => $this->role,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
