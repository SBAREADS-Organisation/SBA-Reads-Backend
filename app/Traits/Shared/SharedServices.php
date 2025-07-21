<?php

namespace App\Traits\Shared;

use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\Notification\NotificationService;

trait SharedServices
{
    //
    protected ?CloudinaryMediaUploadService $cloudinaryService = null;

    protected ?NotificationService $notificationService = null;

    /**
     * Get the Notification service instance.
     */
    public function notifier(): NotificationService
    {
        if (! $this->notificationService) {
            $this->notificationService = app(NotificationService::class);
        }

        return $this->notificationService;
    }

    /**
     * Get the Cloudinary media upload service instance.
     */
    public function mediaUploader(): CloudinaryMediaUploadService
    {
        if (! $this->cloudinaryService) {
            $this->cloudinaryService = app(CloudinaryMediaUploadService::class);
        }

        return $this->cloudinaryService;
    }
}
