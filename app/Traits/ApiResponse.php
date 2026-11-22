<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a successful JSON response.
     *
     * @param  mixed  $data
     */
    protected function success($data, string $message = '', int $code = 200): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'data' => $data,
            'message' => $message,
            'error' => null,
        ], $code);
    }

    /**
     * Return an error JSON response.
     *
     * @param  mixed|null  $data
     */
    protected function error(string $message, int $code = 400, $data = null): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'data' => $data,
            'message' => null,
            'error' => $message,
        ], $code);
    }

}
