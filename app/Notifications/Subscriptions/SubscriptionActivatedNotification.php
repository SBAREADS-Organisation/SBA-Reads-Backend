<?php

namespace App\Notifications\Subscriptions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionActivatedNotification extends Notification
{
    use Queueable;

    protected $subscription;

    /**
     * Create a new notification instance.
     */
    public function __construct($subscription)
    {
        //
        $this->subscription = $subscription;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('Subscription Activated.')
            // ->action('View Subscription', url('/app/subscriptions'))
            ->line('Your subscription has been successfully activated.');
    }

    /**
     * Get the array representation of the notification for FCM.
     *
     * @return array<string, mixed>
     */
    // public function toFcm(object $notifiable): array
    // {
    //     return [
    //         'title' => 'Subscription Activated',
    //         'body' => 'Your subscription has been successfully activated.',
    //         'data' => [
    //             'subscription_id' => $this->subscription->id,
    //         ],
    //     ];
    // }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Subscription successfully activated.',
            'subscription_id' => $this->subscription->id,
        ];
    }
}
