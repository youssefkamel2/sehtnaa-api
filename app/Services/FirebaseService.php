<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Exception\InvalidArgumentException;
use App\Services\LogService;
use Illuminate\Support\Facades\Storage;

class FirebaseService
{
    protected $messaging;
    protected $isInitialized = false;

    public function __construct()
    {
        $this->initializeFirebase();
    }

    protected function initializeFirebase()
    {
        try {
            $credentialsPath = Storage::path(env('FIREBASE_CREDENTIALS'));

            if (!file_exists($credentialsPath)) {
                throw new \RuntimeException("Firebase credentials file not found at: {$credentialsPath}");
            }

            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $this->messaging = $factory->createMessaging();
            $this->isInitialized = true;

            LogService::firestore('debug', 'Firebase initialized successfully');
        } catch (\Exception $e) {
            LogService::exception($e, [
                'action' => 'firebase_initialization'
            ]);
            throw $e;
        }
    }

    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = [])
    {
        try {
            // Validate device token format
            if (!$this->isValidFcmToken($deviceToken)) {
                return $this->formatValidationError('Invalid FCM token format', $deviceToken);
            }

            if (!$this->isInitialized) {
                $this->initializeFirebase();
            }

            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification(Notification::create($title, $body))
                ->withData(array_merge([
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'sound' => 'default',
                    'priority' => 'high'
                ], $data));

            $response = $this->messaging->send($message);

            LogService::notifications('info', 'FCM notification sent successfully', [
                'device_token' => $this->maskToken($deviceToken),
                'message_id' => $response,
                'title' => $title
            ]);

            return [
                'success' => true,
                'message_id' => $response,
                'device_token' => $this->maskToken($deviceToken),
                'timestamp' => now()->toIso8601String(),
                'platform' => 'FCM'
            ];

        } catch (MessagingException $e) {
            return $this->handleMessagingException($e, $deviceToken);
        } catch (InvalidArgumentException $e) {
            return $this->handleInvalidArgumentException($e, $deviceToken);
        } catch (\Exception $e) {
            return $this->handleGenericException($e, $deviceToken);
        }
    }

    protected function isValidFcmToken(string $token): bool
    {
        // FCM tokens are typically 140+ characters and contain alphanumeric characters and colons
        if (empty($token) || strlen($token) < 100) {
            return false;
        }

        // Basic format validation for FCM tokens
        return preg_match('/^[a-zA-Z0-9:_-]+$/', $token) === 1;
    }

    protected function handleMessagingException(MessagingException $e, string $deviceToken): array
    {
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();

        // Handle specific FCM error codes
        $isTokenInvalid = str_contains($errorMessage, 'InvalidRegistration') ||
            str_contains($errorMessage, 'NotRegistered') ||
            str_contains($errorMessage, 'invalid FCM registration token');

        if ($isTokenInvalid) {
            LogService::fcmErrors('Invalid FCM token detected', [
                'device_token' => $this->maskToken($deviceToken),
                'error' => $errorMessage,
                'error_code' => $errorCode
            ]);
        }

        return [
            'success' => false,
            'error' => $errorMessage,
            'error_code' => $errorCode,
            'error_type' => 'messaging',
            'device_token' => $this->maskToken($deviceToken),
            'timestamp' => now()->toIso8601String(),
            'is_token_invalid' => $isTokenInvalid,
            'details' => method_exists($e, 'errors') ? $e->errors() : null,
            'platform' => 'FCM'
        ];
    }

    protected function handleInvalidArgumentException(InvalidArgumentException $e, string $deviceToken): array
    {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'error_type' => 'invalid_argument',
            'device_token' => $this->maskToken($deviceToken),
            'timestamp' => now()->toIso8601String(),
            'platform' => 'FCM'
        ];
    }

    protected function handleGenericException(\Exception $e, string $deviceToken): array
    {
        LogService::exception($e, [
            'action' => 'fcm_send',
            'device_token' => $this->maskToken($deviceToken)
        ]);

        return [
            'success' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'error_type' => 'generic',
            'device_token' => $this->maskToken($deviceToken),
            'timestamp' => now()->toIso8601String(),
            'platform' => 'FCM'
        ];
    }

    protected function formatValidationError(string $message, string $deviceToken): array
    {
        return [
            'success' => false,
            'error' => $message,
            'error_code' => 400,
            'error_type' => 'validation',
            'device_token' => $this->maskToken($deviceToken),
            'timestamp' => now()->toIso8601String(),
            'is_token_invalid' => true,
            'platform' => 'FCM'
        ];
    }

    protected function maskToken(string $token): string
    {
        if (strlen($token) <= 10) {
            return '[INVALID_TOKEN]';
        }

        return substr($token, 0, 8) . '...' . substr($token, -8);
    }

    public function validateToken(string $deviceToken): array
    {
        try {
            if (!$this->isValidFcmToken($deviceToken)) {
                return [
                    'valid' => false,
                    'reason' => 'Invalid token format'
                ];
            }

            // Try to send a test message (this will fail for invalid tokens)
            $testMessage = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification(Notification::create('Test', 'Test message'));

            $this->messaging->send($testMessage);

            return [
                'valid' => true,
                'reason' => 'Token is valid'
            ];

        } catch (MessagingException $e) {
            $isInvalid = str_contains($e->getMessage(), 'InvalidRegistration') ||
                str_contains($e->getMessage(), 'NotRegistered');

            return [
                'valid' => false,
                'reason' => $isInvalid ? 'Token is invalid or expired' : 'Token validation failed',
                'error' => $e->getMessage()
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'reason' => 'Validation error',
                'error' => $e->getMessage()
            ];
        }
    }
}