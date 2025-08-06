<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analyticsService)
    {
        try {
            $user = $request->user();

            $scope = $user->hasRole(['admin', 'superadmin']) ? 'user' : 'admin';

            $analytics = $analyticsService->getAnalytics($user, $scope, $request->all());

            return $this->success($analytics, $scope . ' ' . 'Analytics retrieved successfully.');
            // return response()->json([
            //     'status' => 'success',
            //     'data' => $analytics,
            // ]);
        } catch (\Throwable $th) {
            // throw $th;
            dd($th);

            return $this->error('Failed to retrieve analytics.', 500, null, $th);
        }
    }

    /**
     * Get monthly revenue chart data
     * Returns revenue per month in format: { "January": 120, "February": 300, ... }
     */
    public function monthlyRevenue(Request $request, AnalyticsService $analyticsService)
    {
        try {
            $user = $request->user();

            // For admin/superadmin, show all revenue. For regular users, show their revenue only
            $filterUser = $user->hasRole(['admin', 'superadmin']) ? null : $user;

            $monthlyRevenue = $analyticsService->getMonthlyRevenue($filterUser);

            return $this->success($monthlyRevenue, 'Monthly revenue data retrieved successfully.');
        } catch (\Throwable $th) {
            return $this->error('Failed to retrieve monthly revenue data.', 500, null, $th);
        }
    }
}
