<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;

trait ResponseTrait
{
    protected function success($data = null, $message = 'Operation successful', $status = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $this->translateMessage($message),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $status);
    }

    protected function error($message = 'An error occurred', $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->translateMessage($message),
            'code' => $status,
        ], $status);
    }

    protected function localizedResponse($user, $data = null, $messageKey, $status = 200): JsonResponse
    {
        App::setLocale($user->language);
        return $this->success($data, $messageKey, $status);
    }

    protected function localizedError($user, $message, $status = 400, $validator = null): JsonResponse
    {
        App::setLocale($user->language);
        
        if ($validator && $validator->fails()) {
            $message = $validator->errors()->first();
        }
        
        return $this->error($message, $status);
    }

    protected function translateMessage($message)
    {
        if (is_string($message)) {
            // Check if it's a translation key (messages.*)
            if (strpos($message, 'messages.') === 0) {
                return __($message);
            }
            
            // Try to translate the message directly
            $translation = __($message);
            if ($translation !== $message) {
                return $translation;
            }
        }
        
        return $message;
    }
}