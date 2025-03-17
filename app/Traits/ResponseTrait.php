<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ResponseTrait
{
    protected function success($data = null, $message = 'Operation successful', $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error($message = 'An error occurred', $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $status, // Include the status code in the error response
        ], $status);
    }
}
