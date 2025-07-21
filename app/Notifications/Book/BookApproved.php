<?php

namespace App\Notifications\Book;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookApproved extends Notification
{
    use Queueable;

    protected $book;

    /**
     * Create a new notification instance.
     */
    public function __construct($book)
    {
        //
        $this->book = $book;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Book Has Been Approved')
        // ->action('Notification Action', url('/'))
            ->line("Congratulations! Your book '{$this->book->title}' has been approved and is now live.");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
