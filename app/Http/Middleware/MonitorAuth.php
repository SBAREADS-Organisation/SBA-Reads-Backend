<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MonitorAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $monitorApiKey = config('app.monitor_api_key');

        if (empty($monitorApiKey)) {
            Log::warning('MonitorAuth: MONITOR_API_KEY is not set in .env. Monitoring endpoints are potentially unsecured.');
             return response()->json(['message' => 'Monitoring API key not configured.'], 401);
        } elseif ($request->header('X-Monitor-Key') !== $monitorApiKey) {
            Log::warning('MonitorAuth: Unauthorized attempt to access monitoring endpoint.', [
                'ip' => $request->ip(),
                'provided_key_prefix' => substr($request->header('X-Monitor-Key'), 0, 8) . '...',
            ]);
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
