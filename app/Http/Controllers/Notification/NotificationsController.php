<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\Notification\NotificationService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationsController extends Controller
{
    protected ?NotificationService $notificationService;

    use ApiResponse, AuthorizesRequests;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get all notifications for a user, with pagination, filter, and search.
     */
    public function index(Request $request)
    {
        try {
            // Get query params for pagination, filter, and search
            $user = $request->user();
            $perPage = $request->input('per_page', 15);
            $filters = $request->only(['type', 'status']); // example filters
            $search = $request->input('search');

            $notifications = $this->notificationService->getUserNotifications(
                $user,
                $filters,
                $search,
                $perPage,
            );

            return $this->success(
                $notifications,
                'Notifications retrieved successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->error('Notifications not found.', 404, null, $e);
        } catch (\Exception $e) {
            return $this->error('An error occurred while retrieving notifications.', 500, null, $e);
        }
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, string $id)
    {
        try {
            $notification = Notification::findOrFail($id);
            $this->authorize('view', $notification);
            $notification->update([
                'read' => true,
                'status' => 'read',
                // 'read_at' => now(),
            ]);
            $this->notificationService->markAsRead($notification);

            return $this->success(
                $notification,
                'Notification marked as read successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->error('Notification not found.', 404, null, $e);
        } catch (\Exception $e) {
            return $this->error('An error occurred while marking notification as read.', 500, null, $e);
        }
    }

    /**
     * Mark all notifications for a user as read.
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $user = $request->user();
            $this->notificationService->markAllAsRead($user);

            return $this->success(
                [],
                'All notifications marked as read successfully.'
            );
        } catch (\Exception $e) {
            return $this->error('An error occurred while marking all notifications as read.', 500, null, $e);
        }
    }
}
