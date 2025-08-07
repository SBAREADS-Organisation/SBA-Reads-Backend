<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;

class DashboardCacheService
{
    public static function clearAdminDashboard()
    {
        Cache::forget('admin_dashboard_data');
        Cache::forget('admin_dashboard_analytics');
    }

    public static function clearAuthorDashboard(int $authorId)
    {
        Cache::forget("author_dashboard_data_{$authorId}");
        Cache::forget("author_analytics_{$authorId}");
    }

    public static function clearAllDashboards()
    {
        self::clearAdminDashboard();
        Cache::flush(); // Use with caution in production
    }

    public static function clearMultipleAuthorDashboards(array $authorIds)
    {
        foreach ($authorIds as $authorId) {
            self::clearAuthorDashboard($authorId);
        }
    }
}
