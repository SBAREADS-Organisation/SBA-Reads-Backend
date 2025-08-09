<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
            return response()->json(['message' => 'Monitoring API key not configured.'], 401);
        } elseif ($request->header('X-Monitor-Key') !== $monitorApiKey) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
