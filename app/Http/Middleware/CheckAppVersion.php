<?php

namespace App\Http\Middleware;

use App\Models\AppVersion;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckAppVersion
{
    use ApiResponse;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // dd($request->header());
            $appVersion = $request->header('x-app-version');
            $deviceId = $request->header('x-device-id');
            $platform = $request->header('x-platform'); // e.g., ios, android
            $appId = $request->header('x-app-id');
            // Allow guest access by adding a header allow guest access with a default value of true
            /**
             * Checks if guest access is allowed by comparing the 'x-allow-guest-access' request header
             * to a UUID stored in the environment variable 'GUEST_ACCESS_UUID'.
             *
             * This approach allows toggling guest access by updating the UUID in the environment file (.env).
             * The UUID acts as a secret token that can be rotated at any time for security purposes.
             *
             * Example .env entry:
             * GUEST_ACCESS_UUID=123e4567-e89b-12d3-a456-426614174000 // sample uuid
             *
             * Implementation:
             * - Retrieves the 'x-allow-guest-access' header from the request.
             * - Compares its value to the UUID stored in 'GUEST_ACCESS_UUID'.
             * - If they match, guest access is permitted.
             */
            $allowGuestAccess = $request->header('x-allow-guest-access', env('GUEST_ACCESS_UUID')) === env('GUEST_ACCESS_UUID');
            // if (!$request->user() && !$appVersion && !$deviceId && !$platform && !$appId) {
            //     return $next($request);
            // }
            // $is_webhook = $request->route() === '/webhooks/stripe';
            $is_webhook = str_contains($request->path(), 'webhooks');

            // allow webhook requests to pass without any headers check
            if ($is_webhook) {
                // Log::info('Webhook request detected. Skipping app version check.');
                // return response()->json([
                //     'data' => null,
                //     'message' => 'Webhook request detected. Skipping app version check.',
                //     'code' => 200
                // ], 200);
                return $next($request);
            }

            if (! $appVersion || ! $deviceId || ! $platform || ! $appId) {
                throw new \InvalidArgumentException('Invalid request.');
            }

            $appVersion = AppVersion::where('app_id', $appId)
                ->where('platform', $platform)
                ->where('version', $appVersion)
                ->first();

            // dd('HEADERS FROM DB', $appVersion);

            if (! $appVersion && ! $allowGuestAccess) {
                throw new \Exception('Unauthorised access.', 401);
            }

            if (! $appVersion && $allowGuestAccess) {
                return $next($request);
            }

            if ($appVersion->force_update) {
                throw new \Exception('A new version is required. Please update your app to continue.', 426);
                // return response()->json([
                //     'data' => null,
                //     'message' => 'A new version is required. Please update your app to continue.',
                //     'code' => 426,
                // ], 426);
            }

            if ($appVersion->deprecated) {
                throw new \Exception('This app version is no longer supported. Upgrade to continue.', 426);
                // return response()->json([
                //     'data' => null,
                //     'message' => 'This app version is no longer supported. Upgrade to continue.',
                //     'code' => 426,
                // ], 426);
            }

            if ($appVersion->support_expires_at && now()->greaterThanOrEqualTo($appVersion->support_expires_at)) {
                throw new \Exception('Support for this version has ended. Upgrade required.', 426);
                // return response()->json([
                //     'data' => null,
                //     'code' => 426,
                //     'message' => 'Support for this version has ended. Upgrade required.'
                // ], 426);
            }

            // Default
            // // Define minimum supported versions per platform
            // $minSupportedVersions = [
            //     'ios' => ['0.0.1', '0.0.0'],
            //     'android' => ['0.0.1', '0.0.0'],
            // ];

            // // Hardcoded x-app-id
            // $validAppIds = ['com.sbareads', 'com.sbareads.author', 'com.sbareads.admin', 'com.sbareads.reader'];

            // // Check if the provided x-app-id is in the allowed list
            // if (!in_array($appId, $validAppIds)) {
            //     return response()->json([
            //         'data' => null,
            //         'code' => 403,
            //         'message' => 'Unauthorized app access.'
            //     ], 403);
            // }

            // // Check if the version is below the required one
            // if (!in_array($appVersion, $minSupportedVersions[$platform])) {
            //     return response()->json([
            //         'message' => 'Your app version is no longer supported. Please update.',
            //         'code' => 426 // HTTP 426 Upgrade Required
            //     ], 426);
            // }

            return $next($request);
        } catch (\Throwable $th) {
            return response()->json([
                'data' => null,
                'message' => $th->getMessage() ?? 'An error occurred while checking app version.',
                'code' => 500,
            ], 500);
        }
    }
}
