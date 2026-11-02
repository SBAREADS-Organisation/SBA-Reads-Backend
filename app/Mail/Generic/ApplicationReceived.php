<?php

namespace App\Mail\Generic;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationReceived extends Mailable
{
    use Queueable;

    /** @param array<int, array{name: string, url: string}> $fileLinks */
    public function __construct(public Application $application, public array $fileLinks = [])
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New ' . $this->application->type . ' application #' . $this->application->id,
            replyTo: [$this->application->email],
        );
    }

    public function content(): Content
    {
        $rows = [
            'Type' => $this->application->type,
            'Book title' => $this->application->book_title,
            "Author's name" => $this->application->author_name,
            'Email' => $this->application->email,
            ...($this->application->details ?? []),
        ];

        $html = '<h2>New ' . e($this->application->type) . ' application</h2>';
        foreach ($rows as $label => $value) {
            $html .= '<p><strong>' . e(str_replace('_', ' ', (string) $label)) . ':</strong> ' . e((string) $value) . '</p>';
        }
        if ($this->fileLinks) {
            $html .= '<p><strong>Files (links expire in 7 days):</strong></p><ul>';
            foreach ($this->fileLinks as $file) {
                $html .= '<li><a href="' . e($file['url']) . '">' . e($file['name']) . '</a></li>';
            }
            $html .= '</ul>';
        }

        return new Content(htmlString: $html);
    }
}
