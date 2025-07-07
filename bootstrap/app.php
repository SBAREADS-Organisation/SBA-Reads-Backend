<?php

use App\Http\Middleware\CheckAppVersion;
use Illuminate\Foundation\Application;
use App\Services\Slack\SlackWebhookService;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
// use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
        $middleware->append(CheckAppVersion::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (\Throwable $e) {
            dd($e);
            app()->instance('last_exception', $e);
        });
        $exceptions->render(function (Throwable $e) {
            // if (!app()->environment(['production', 'staging'])) return;

            // try {
            //     SlackWebhookService::send('🚨 Exception Thrown', [
            //         'Exception' => get_class($e),
            //         'Message' => $e->getMessage(),
            //         'File' => $e->getFile(),
            //         'Line' => $e->getLine(),
            //         'URL' => request()->fullUrl(),
            //         'Method' => request()->method(),
            //         'IP' => request()->ip(),
            //         'User' => optional(request()->user())->email ?? 'Guest',
            //     ], 'error');
            // } catch (\Throwable $notificationError) {
            //     logger()->error('Slack notification failed: ' . $notificationError->getMessage());
            // }

            // return response()->json([
            //     'code' => 500,
            //     'data' => null,
            //     'message' => $e->getMessage() ?: 'An unexpected error occurred.',
            //     'error' => 'A server error occurred.'
            // ], 500);
        });
    })->create();
