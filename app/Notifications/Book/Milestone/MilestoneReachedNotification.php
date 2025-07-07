<?php

namespace App\Notifications\Book\Milestone;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MilestoneReachedNotification extends Notification
{
    use Queueable;

    protected $book;
    protected $milestone;

    /**
     * Create a new notification instance.
     */
    public function __construct($book, $milestone)
    {
        $this->book = $book;
        $this->milestone = $milestone;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail']; // or ['database', 'broadcast', etc.]
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject("🎉 {$this->milestone}% Milestone Reached in '{$this->book->title}'!")
            // ->subject("Milestone Reached!")
            ->markdown('emails.milestone', [
                'book' => $this->book,
                'milestone' => $this->milestone,
            ]);
    }


    /**
     * Get the array representation of the notification.
     * Optional: Database notification or broadcast
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            // 'book_id' => $this->book->id,
            // 'milestone' => $this->milestone,
            // 'title' => $this->book->title,
        ];
    }
}
