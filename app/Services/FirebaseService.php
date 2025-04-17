<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\MessagingException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FirebaseService
{
    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = [])
    {
        try {
            if (empty($deviceToken)) {
                throw new \InvalidArgumentException("Empty device token provided");
            }

            $credentialsPath = Storage::path(env('FIREBASE_CREDENTIALS'));
            
            if (!file_exists($credentialsPath)) {
                throw new \RuntimeException("Firebase credentials file not found at: {$credentialsPath}");
            }

            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $messaging = $factory->createMessaging();

            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification(Notification::create($title, $body))
                ->withData(array_merge([
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'sound' => 'default'
                ], $data));

            $response = $messaging->send($message);

            return [
                'success' => true,
                'message_id' => $response,
                'device_token' => $deviceToken,
                'timestamp' => now()->toIso8601String(),
                'platform' => 'FCM'
            ];

        } catch (MessagingException $e) {
            return $this->formatFirebaseError($e, $deviceToken);
        } catch (\Exception $e) {
            return $this->formatGenericError($e, $deviceToken);
        }
    }

    protected function formatFirebaseError(MessagingException $e, string $deviceToken): array
    {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'error_type' => 'messaging',
            'device_token' => $deviceToken,
            'timestamp' => now()->toIso8601String(),
            'details' => method_exists($e, 'errors') ? $e->errors() : null,
            'platform' => 'FCM'
        ];
    }

    protected function formatGenericError(\Exception $e, string $deviceToken): array
    {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'error_type' => 'generic',
            'device_token' => $deviceToken,
            'timestamp' => now()->toIso8601String(),
            'platform' => 'FCM'
        ];
    }
}