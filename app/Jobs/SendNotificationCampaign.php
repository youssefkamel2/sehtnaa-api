<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\LogService;
use Exception;

class SendNotificationCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $maxExceptions = 1;
    public $timeout = 60;

    protected $campaignId;
    protected $userId;

    public function __construct(string $campaignId, int $userId)
    {
        $this->campaignId = $campaignId;
        $this->userId = $userId;
        LogService::jobs('debug', 'Job created', [
            'campaign_id' => $campaignId,
            'user_id' => $userId
        ]);
    }

    public function handle(FirebaseService $firebaseService)
    {
        LogService::jobs('debug', 'Job processing started', [
            'campaign_id' => $this->campaignId,
            'user_id' => $this->userId,
            'attempt' => $this->attempts(),
        ]);

        $notificationLog = NotificationLog::where('campaign_id', $this->campaignId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$notificationLog) {
            $errorMessage = 'Notification log not found for campaign';
            LogService::notifications('error', $errorMessage, [
                'campaign_id' => $this->campaignId,
                'user_id' => $this->userId
            ]);
            throw new Exception($errorMessage);
        }

        try {
            // Update attempt count
            $notificationLog->increment('attempts_count');
            $currentAttempt = $notificationLog->attempts_count;

            $this->logAttempt($notificationLog, 'attempt_start', [
                'attempt_number' => $currentAttempt
            ]);

            // Check if device token still exists
            if (empty($notificationLog->device_token)) {
                $errorMessage = 'Device token no longer available';
                $this->handleFailure($notificationLog, $errorMessage, null, true);
                LogService::fcmErrors('Device token no longer available', [
                    'campaign_id' => $this->campaignId,
                    'user_id' => $this->userId,
                    'notification_id' => $notificationLog->id,
                    'error' => $errorMessage
                ]);
                return;
            }

            // Validate FCM token format before sending
            if (!$this->isValidFcmTokenFormat($notificationLog->device_token)) {
                $errorMessage = 'Invalid FCM token format';
                $this->handleInvalidToken($notificationLog, $errorMessage);
                return;
            }

            LogService::notifications('debug', 'Sending FCM notification', [
                'campaign_id' => $this->campaignId,
                'user_id' => $this->userId,
                'fcm_token' => $this->maskToken($notificationLog->device_token)
            ]);

            // Send notification
            $response = $firebaseService->sendToDevice(
                $notificationLog->device_token,
                $notificationLog->title,
                $notificationLog->body,
                $notificationLog->data ?? []
            );

            LogService::notifications('debug', 'FCM response received', [
                'campaign_id' => $this->campaignId,
                'user_id' => $this->userId,
                'response' => $response
            ]);

            if ($response['success']) {
                $this->handleSuccess($notificationLog, $response);
            } else {
                $isTokenInvalid = $response['is_token_invalid'] ?? false;
                $this->handleFailure($notificationLog, $response['error'], $response, $isTokenInvalid);

                // If token is invalid, mark user's FCM token as invalid
                if ($isTokenInvalid) {
                    $this->handleInvalidToken($notificationLog, $response['error']);
                }
            }

        } catch (Exception $e) {
            $errorMessage = 'Failed to send FCM notification';
            LogService::fcmErrors($errorMessage, [
                'campaign_id' => $this->campaignId,
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            throw new Exception($errorMessage);
        }
    }

    protected function handleSuccess(NotificationLog $log, array $response)
    {
        $log->update([
            'is_sent' => true,
            'response' => $response,
            'sent_at' => now(),
            'error_message' => null,
            'attempt_logs' => $this->addAttemptLog($log, 'success', [
                'response' => $response,
                'message' => 'Notification sent successfully'
            ])
        ]);

        $this->logAttempt($log, 'success', [
            'response' => $response
        ]);

        LogService::notifications('info', 'Notification sent successfully', [
            'campaign_id' => $log->campaign_id,
            'user_id' => $log->user_id,
            'message_id' => $response['message_id'] ?? null
        ]);
    }

    protected function handleFailure(NotificationLog $log, string $error, array $response = null, bool $isTokenInvalid = false)
    {
        $updateData = [
            'error_message' => $error,
            'response' => $response,
            'attempt_logs' => $this->addAttemptLog($log, 'failed', [
                'error' => $error,
                'response' => $response,
                'is_token_invalid' => $isTokenInvalid
            ])
        ];

        // Only mark as failed if this was the last attempt
        if ($log->attempts_count >= $this->tries) {
            $updateData['is_sent'] = false;
        }

        $log->update($updateData);

        $this->logAttempt($log, 'failed', [
            'error' => $error,
            'response' => $response,
            'is_token_invalid' => $isTokenInvalid
        ]);

        LogService::fcmErrors('Notification failed', [
            'campaign_id' => $log->campaign_id,
            'user_id' => $log->user_id,
            'attempt' => $log->attempts_count,
            'max_attempts' => $this->tries,
            'error' => $error,
            'is_token_invalid' => $isTokenInvalid
        ]);
    }

    protected function handleInvalidToken(NotificationLog $log, string $error)
    {
        // Mark the notification as failed immediately for invalid tokens
        $log->update([
            'is_sent' => false,
            'error_message' => $error,
            'attempts_count' => $this->tries, // Mark as max attempts reached
            'attempt_logs' => $this->addAttemptLog($log, 'token_invalid', [
                'error' => $error,
                'message' => 'Token marked as invalid, no more attempts'
            ])
        ]);

        // Clear the user's FCM token since it's invalid
        $user = User::find($log->user_id);
        if ($user) {
            $user->update(['fcm_token' => null]);
            LogService::notifications('warning', 'Invalid FCM token cleared for user', [
                'user_id' => $user->id,
                'campaign_id' => $log->campaign_id
            ]);
        }

        $this->logAttempt($log, 'token_invalid', [
            'error' => $error,
            'message' => 'Token invalidated and cleared'
        ]);

        LogService::fcmErrors('Invalid FCM token handled', [
            'campaign_id' => $log->campaign_id,
            'user_id' => $log->user_id,
            'error' => $error,
            'action' => 'token_cleared'
        ]);
    }

    protected function isValidFcmTokenFormat(string $token): bool
    {
        // FCM tokens are typically 140+ characters and contain alphanumeric characters and colons
        if (empty($token) || strlen($token) < 100) {
            return false;
        }

        // Basic format validation for FCM tokens
        return preg_match('/^[a-zA-Z0-9:_-]+$/', $token) === 1;
    }

    protected function maskToken(string $token): string
    {
        if (strlen($token) <= 10) {
            return '[INVALID_TOKEN]';
        }

        return substr($token, 0, 8) . '...' . substr($token, -8);
    }

    protected function addAttemptLog(NotificationLog $log, string $status, array $data = [])
    {
        $logs = $log->attempt_logs ?? [];
        $logs[] = array_merge([
            'status' => $status,
            'timestamp' => now()->toDateTimeString(),
            'attempt' => $log->attempts_count
        ], $data);

        return $logs;
    }

    protected function logAttempt(NotificationLog $log, string $status, array $context = [])
    {
        LogService::notifications(
            $this->getLogLevel($status),
            $this->formatLogMessage($log, $status),
            [
                'campaign_id' => $log->campaign_id,
                'notification_id' => $log->id,
                'user_id' => $log->user_id,
                'attempt' => $log->attempts_count,
                'status' => $status,
                'context' => $context
            ]
        );
    }

    protected function formatLogMessage(NotificationLog $log, string $status)
    {
        return sprintf(
            "Campaign %s - Notification %s - Attempt %d/%d - %s",
            $log->campaign_id,
            $log->id,
            $log->attempts_count,
            $this->tries,
            $status
        );
    }

    protected function getLogLevel(string $status)
    {
        return match ($status) {
            'success' => 'info',
            'attempt_start' => 'debug',
            'failed', 'token_invalid' => 'error',
            default => 'notice'
        };
    }

    public function failed(Exception $exception)
    {
        $notificationLog = NotificationLog::where('campaign_id', $this->campaignId)
            ->where('user_id', $this->userId)
            ->first();

        if ($notificationLog) {
            $notificationLog->update([
                'is_sent' => false,
                'error_message' => $exception->getMessage(),
                'attempts_count' => $this->tries, // Mark as max attempts reached
                'attempt_logs' => $this->addAttemptLog($notificationLog, 'job_failed', [
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString()
                ])
            ]);
        }

        LogService::notifications(
            'error',
            "Job failed for campaign {$this->campaignId} user {$this->userId}",
            [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]
        );
    }
}