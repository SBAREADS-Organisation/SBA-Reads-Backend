<?php

namespace App\Notifications\SlackWebhook;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlackWebhookNotification extends Notification
{
    use Queueable;
    protected string $message;
    protected object $content;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $message, object $content)
    {
        $this->message = $message;
        $this->content = $content;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [/*'mail', */'slack'];
    }

    /**
     * Get the mail representation of the notification.
     */
    // public function toMail(object $notifiable): MailMessage
    // {
    //     return (new MailMessage)
    //         ->line('The introduction to the notification.')
    //         ->action('Notification Action', url('/'))
    //         ->line('Thank you for using our application!');
    // }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    // public function toArray(object $notifiable): array
    // {
    //     return [
    //         //
    //     ];
    // }

    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack($notifiable)
    {
        return [];
    }
}
