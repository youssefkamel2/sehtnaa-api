<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FirebaseService
{
    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = [])
    {
        try {
            if (empty($deviceToken)) {
                Log::warning('Attempted to send notification to empty device token');
                return false;
            }

            Log::info('Sending notification to device', ['token' => $deviceToken]);

            $credentialsPath = Storage::path(env('FIREBASE_CREDENTIALS'));
            
            if (!file_exists($credentialsPath)) {
                throw new \Exception("Firebase credentials file not found at: {$credentialsPath}");
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

            Log::info('Notification sent successfully', ['response' => $response]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send notification', [
                'error' => $e->getMessage(),
                'token' => $deviceToken,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}