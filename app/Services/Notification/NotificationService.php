<?php

namespace App\Services\Notification;

use Illuminate\Support\Str;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Mail\Generic\GenericAppNotification;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Model;

class NotificationService
{
    public function send(
        User $user,
        string $title,
        string $message,
        array $channels = ['in-app'],
        ?Model $notifiable = null,
        $mailable = null,
        $notificationType = 'individual', // e.g., 'individual', 'group', 'system'
        /**
         * Whether to send the notification immediately or queue it.
         * Defaults to true, meaning it will be sent immediately.
         */
        bool $sendImmediately = true
    ): Notification {
        $notifiableType = $notifiable ? $this->getMorphAlias($notifiable) : null;
        $notification = Notification::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'channels' => $channels,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiable?->id,
            'status' => 'pending',
            'type' => $notificationType,
            'data' => [
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiable?->id,
            ],
        ]);

        // $preferences = $user->notification_preferences;
        // $allowedChannels = array_filter($channels, fn($ch) => $preferences[$ch] ?? false);

        $this->dispatchChannels($notification, $channels, $mailable);

        return $notification;
    }

    protected function dispatchChannels(Notification $notification, array $channels, $mailable = null)
    {
        foreach ($channels as $channel) {
            try {
                match ($channel) {
                    'email' => $this->sendEmail($notification, $mailable),
                    'in-app' => $this->sendInApp($notification),
                    'push' => $this->sendPush($notification),
                    default => null,
                };
            } catch (\Exception $e) {
                report($e);
            }
        }

        $notification->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    protected function sendEmail(Notification $notification, $mailable = null)
    {
        // If a custom mailable is set on the notification, use it; otherwise, use the generic one
        if (isset($mailable)/* && $notification->mailable instanceof \Illuminate\Mail\Mailable*/) {
            Mail::to($notification->user->email)->queue($mailable);
        } else {
            Mail::to($notification->user->email)->queue(
                new GenericAppNotification($notification->title, $notification->message)
            );
        }
    }

    protected function sendInApp(Notification $notification)
    {
        // No-op: stored already.
    }

    protected function sendPush(Notification $notification)
    {
        // Placeholder for push logic (e.g., Firebase or OneSignal)
    }

    public function markAsRead(Notification $notification): void
    {
        $notification->with('notifiable');
        $notification->update(['read_at' => now()]);
    }
    /**
     * Mark all notifications for a user as read.
     *
     * @param User $user
     */
    public function markAllAsRead(User $user): void
    {
        Notification::where('user_id', $user->id)
            ->where('read', false)
            ->update(['read' => true, 'read_at' => now()]);
    }

    /**
     * Get all notifications for a user with pagination, filtering, and search.
     *
     * @param User $user
     * @param array $filters ['status' => 'sent|pending', 'channel' => 'email|in-app|push', ...]
     * @param string|null $search
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getUserNotifications(
        User $user,
        array $filters = [],
        ?string $search = null,
        int $perPage = 15
    ) {
        $query = Notification::where('user_id', $user->id);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['read'])) {
            $query->where('read', $filters['read'] === 'true');
        }

        if (!empty($filters['channel'])) {
            $query->whereJsonContains('channels', $filters['channel']);
        }

        if (!empty($filters['notifiable_type'])) {
            $query->where('notifiable_type', $filters['notifiable_type']);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                ->orWhere('message', 'like', "%{$search}%");
            });
        }

        // Eager load the notifiable relation
        $query->with('notifiable');

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
    * Get morph alias for a given model instance.
    */
    protected function getMorphAlias($model): string|null
    {
        $map = Relation::morphMap();

        // If no map defined, fallback to class name
        if (empty($map)) {
            return get_class($model);
        }

        // Flip the morphMap to get alias by class
        $reversed = array_flip($map);
        return $reversed[get_class($model)] ?? null;
    }
}
