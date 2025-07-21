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

            return $this->success($analytics, $scope.' '.'Analytics retrieved successfully.');
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
}
