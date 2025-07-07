<?php

namespace App\Traits;
use App\Services\Slack\SlackWebhookService;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a successful JSON response.
     *
     * @param  mixed   $data
     * @param  string  $message
     * @param  int     $code
     * @return \Illuminate\Http\JsonResponse
     */
    protected function success($data, string $message = '', int $code = 200): JsonResponse
    {
        return response()->json([
            'code'    => $code,
            'data'    => $data,
            'message' => $message,
            'error'   => null,
        ], $code);
    }

    /**
     * Return an error JSON response.
     *
     * @param  string     $message
     * @param  int        $code
     * @param  mixed|null $data
     * @return \Illuminate\Http\JsonResponse
     */
    protected function error(string $message, int $code = 400, $data = null, $exception = null): JsonResponse
    {
        // try {
        //     $exception = app()->bound('last_exception') ? app('last_exception') : null;
        // } catch (\Throwable $th) {
        //     logger()->warning('Failed to access last_exception from container: ' . $th->getMessage());
        // }

        // dd($exception);

        try {
            if ($exception) {
                \App\Services\Slack\SlackWebhookService::send('🚨 API Error Notification', [
                    'Message'    => $message,
                    'Code'       => $code,
                    'Exception'  => get_class($exception) ?? null,
                    'Error'      => $exception->getMessage() ?? null,
                    'File'       => $exception->getFile() ?? null,
                    'Line'       => $exception->getLine() ?? null,
                    'URL'        => request()->fullUrl(),
                    'Method'     => request()->method(),
                    'IP'         => request()->ip(),
                    'User'       => optional(request()->user())->email,
                    'stack_trace' => $exception->getTraceAsString() ?? null,
                ]);
            }
        } catch (\Throwable $th) {
            logger()->error('Failed to notify Slack in ApiResponse trait: ' . $th->getMessage());
        }

        return response()->json([
            'code'    => $code,
            'data'    => $data,
            'message' => null,
            'error'   => $message,
        ], $code);
    }

    protected function getThrowableFromBacktrace(): ?\Throwable
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $trace) {
            if (isset($trace['args']) && is_array($trace['args'])) {
                foreach ($trace['args'] as $arg) {
                    if ($arg instanceof \Throwable) {
                        return $arg;
                    }
                }
            }
        }
        return null;
    }
}
