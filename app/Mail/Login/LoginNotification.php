<?php

namespace App\Mail\Login;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class LoginNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $provider;
    public $ipAddress;

    /**
     * Create a new message instance.
     */
    public function __construct($user, $provider, $ipAddress)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->ipAddress = $ipAddress;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('New Login Notification')
                    ->view('emails.login_notification')
                    ->with([
                        'name' => $this->user->name,
                        'provider' => ucfirst($this->provider),
                        'time' => now()->toDateTimeString(),
                        'ipAddress' => $this->ipAddress,
                    ]);
    }
}
