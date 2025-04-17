<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\FirebaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $notificationLog;
    protected $maxAttempts = 3;

    public function __construct(NotificationLog $notificationLog)
    {
        $this->notificationLog = $notificationLog;
    }

    public function handle(FirebaseService $firebaseService)
    {
        try {
            $user = $this->notificationLog->user;
            
            if (!$user->fcm_token) {
                $this->logAttempt([
                    'status' => 'skipped',
                    'reason' => 'missing_token',
                    'response' => [
                        'success' => false,
                        'error' => 'User has no FCM token'
                    ]
                ]);
                throw new \Exception('User has no FCM token');
            }

            $this->logAttempt([
                'status' => 'start',
                'details' => 'Attempting to send notification'
            ]);

            $response = $firebaseService->sendToDevice(
                $user->fcm_token,
                $this->notificationLog->title,
                $this->notificationLog->body,
                $this->notificationLog->data ?? []
            );

            // Enhanced response handling
            $this->handleFirebaseResponse($response);

        } catch (\Exception $e) {
            $this->handleFailure($e, $response ?? null);
        }
    }

    protected function handleFirebaseResponse($response)
    {
        $logData = [
            'status' => is_bool($response) ? ($response ? 'success' : 'failed') : ($response['success'] ? 'success' : 'failed'),
            'response' => is_array($response) ? $response : ['success' => $response]
        ];

        if ($logData['status'] === 'success') {
            $this->logAttempt($logData);
            $this->notificationLog->update([
                'is_sent' => true,
                'response_data' => $logData['response']
            ]);
        } else {
            $error = is_array($response) 
                ? ($response['error'] ?? $response['error_details'] ?? 'Unknown Firebase error')
                : 'Firebase returned false';
                
            $logData['reason'] = $error;
            $this->logAttempt($logData);
            throw new \Exception($error);
        }
    }

    protected function handleFailure(\Exception $e, $response = null)
    {
        $this->logAttempt([
            'status' => 'error',
            'reason' => $e->getMessage(),
            'error_trace' => $this->formatTrace($e->getTraceAsString()),
            'response' => $response ?? null
        ]);

        $this->notificationLog->update([
            'is_sent' => false,
            'error_message' => $e->getMessage(),
            'response_data' => $response
        ]);
    }

    protected function logAttempt(array $context = [])
    {
        $logData = [
            'timestamp' => now()->toIso8601String(),
            'campaign' => [
                'id' => $this->notificationLog->campaign_id,
                'title' => $this->notificationLog->title,
                'body' => $this->notificationLog->body
            ],
            'user' => [
                'id' => $this->notificationLog->user->id ?? null,
                'fcm_token' => $this->notificationLog->user->fcm_token ?? null,
                'device_type' => $this->notificationLog->user->device_type ?? null
            ],
            'attempt' => array_merge([
                'attempt_number' => $this->attempts(),
                'job_id' => $this->job->getJobId()
            ], $context)
        ];

        Log::channel('notifications')->log(
            $this->getLogLevel($context['status']),
            $this->formatLogMessage($context),
            $logData
        );
    }

    protected function formatLogMessage(array $context): string
    {
        $parts = [
            'Campaign:' . $this->notificationLog->campaign_id,
            'User:' . ($this->notificationLog->user->id ?? 'unknown'),
            'Status:' . $context['status'],
            'Attempt:' . $this->attempts()
        ];

        if (!empty($context['reason'])) {
            $parts[] = 'Reason:' . $context['reason'];
        }

        return implode(' | ', $parts);
    }

    protected function getLogLevel(string $status): string
    {
        return match($status) {
            'error', 'failed' => 'error',
            'skipped' => 'warning',
            default => 'info'
        };
    }

    protected function formatTrace($trace)
    {
        return array_slice(explode("\n", $trace), 0, 5);
    }
}