<?php

namespace App\Exceptions;

use App\Services\Slack\SlackWebhookService;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            $this->notifySlack($e);
        });
    }

    protected function notifySlack(Throwable $e): void
    {
        try {
            // Avoid sending notifications in local/dev environment
            if (app()->environment('production', 'staging')) {
                SlackWebhookService::send('🚨 Exception Captured', [
                    'Error' => $e->getMessage(),
                    'Type' => get_class($e),
                    'File' => $e->getFile(),
                    'Line' => $e->getLine(),
                    'URL' => request()->fullUrl(),
                    'Method' => request()->method(),
                    'IP' => request()->ip(),
                    'User' => optional(request()->user())->email,
                ], 'error');
            }
        } catch (\Throwable $th) {
            logger()->error('Slack Notification Failed: '.$th->getMessage());
        }
    }
}
