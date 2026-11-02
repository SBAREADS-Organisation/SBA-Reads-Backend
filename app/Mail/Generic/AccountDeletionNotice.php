<?php

namespace App\Mail\Generic;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountDeletionNotice extends Mailable
{
    use Queueable;

    /** @param array<int, string> $lines */
    public function __construct(public string $heading, public array $lines, public ?string $code = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading . ' — SBA Reads');
    }

    public function content(): Content
    {
        $html = '<h2>' . e($this->heading) . '</h2>';
        foreach ($this->lines as $line) {
            $html .= '<p>' . e($line) . '</p>';
        }
        if ($this->code) {
            $html .= '<p style="font-size:28px;font-weight:700;letter-spacing:6px;">' . e($this->code) . '</p>';
        }

        return new Content(htmlString: $html);
    }
}
