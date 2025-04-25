<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        Log::channel('job_processing')->debug('Job created', [
            'campaign_id' => $campaignId,
            'user_id' => $userId
        ]);
    }

    public function handle(FirebaseService $firebaseService)
    {

        Log::channel('job_processing')->debug('Job processing started', [
            'campaign_id' => $this->campaignId,
            'user_id' => $this->userId,
            'attempt' => $this->attempts(),
        ]);

        $notificationLog = NotificationLog::where('campaign_id', $this->campaignId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$notificationLog) {
            $errorMessage = "Notification log not found";
            Log::channel('notifications')->error($errorMessage, [
                'campaign_id' => $this->campaignId,
                'user_id' => $this->userId
                
            ]);
            
            // Also log to fcm_errors for easier tracking
            Log::channel('fcm_errors')->error($errorMessage, [
                'type' => 'missing_notification_log',
                'campaign_id' => $this->campaignId,
                'user_id' => $this->userId,
            ]);
            

            return;
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
                $this->handleFailure($notificationLog, $errorMessage);
                Log::channel('fcm_errors')->error($errorMessage, [
                    'type' => 'missing_device_token',
                    'notification_id' => $notificationLog->id,
                    'user_id' => $this->userId,
                ]);
                
                return;
            }

            Log::channel('fcm_debug')->debug('Sending FCM notification', [
                'notification_id' => $notificationLog->id,
                'device_token' => ($notificationLog->device_token), 
                'title' => $notificationLog->title,
                'body' => $notificationLog->body,
                'data' => $notificationLog->data,
            ]);

            // Send notification
            $response = $firebaseService->sendToDevice(
                $notificationLog->device_token,
                $notificationLog->title,
                $notificationLog->body,
                $notificationLog->data ?? []
            );

            Log::channel('fcm_debug')->debug('FCM response received', [
                'notification_id' => $notificationLog->id,
                'response' => $response,
                'success' => $response['success'] ?? false,
            ]);

            if ($response['success']) {
                $this->handleSuccess($notificationLog, $response);
            } else {
                $this->handleFailure($notificationLog, $response['error'], $response);
            }

        } catch (\Exception $e) {
            $this->handleFailure($notificationLog, $e->getMessage());
            throw $e; // Allow job to retry if attempts remain
        }
    }

    protected function handleSuccess(NotificationLog $log, array $response)
    {
        $log->update([
            'is_sent' => true,
            'response' => $response,
            'sent_at' => now(),
            'attempt_logs' => $this->addAttemptLog($log, 'success', [
                'response' => $response,
                'message' => 'Notification sent successfully'
            ])
        ]);

        $this->logAttempt($log, 'success', [
            'response' => $response
        ]);
    }

    protected function handleFailure(NotificationLog $log, string $error, array $response = null)
    {
        $updateData = [
            'error_message' => $error,
            'attempt_logs' => $this->addAttemptLog($log, 'failed', [
                'error' => $error,
                'response' => $response
            ])
        ];

        // Only mark as failed if this was the last attempt
        if ($log->attempts_count >= $this->tries) {
            $updateData['is_sent'] = false;
        }

        $log->update($updateData);

        $this->logAttempt($log, 'failed', [
            'error' => $error,
            'response' => $response
        ]);
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
        Log::channel('notifications')->log(
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
        return match($status) {
            'success' => 'info',
            'attempt_start' => 'debug',
            'failed' => 'error',
            default => 'notice'
        };
    }

    public function failed(\Exception $exception)
    {
        $notificationLog = NotificationLog::where('campaign_id', $this->campaignId)
            ->where('user_id', $this->userId)
            ->first();

        if ($notificationLog) {
            $notificationLog->update([
                'is_sent' => false,
                'error_message' => $exception->getMessage(),
                'attempt_logs' => $this->addAttemptLog($notificationLog, 'job_failed', [
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString()
                ])
            ]);
        }

        Log::channel('notifications')->error(
            "Job failed for campaign {$this->campaignId} user {$this->userId}",
            [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]
        );
    }
}