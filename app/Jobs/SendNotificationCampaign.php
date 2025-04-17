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
                    'response' => null
                ]);
                throw new \Exception('User has no FCM token');
            }

            $this->logAttempt([
                'status' => 'start',
                'response' => null
            ]);

            $response = $firebaseService->sendToDevice(
                $user->fcm_token,
                $this->notificationLog->title,
                $this->notificationLog->body,
                $this->notificationLog->data ?? []
            );

            // Handle both boolean and array responses
            if (is_bool($response)) {
                if (!$response) {
                    $this->logAttempt([
                        'status' => 'failed',
                        'reason' => 'firebase_error',
                        'response' => ['success' => false]
                    ]);
                    throw new \Exception('Failed to send notification');
                }
                $responseData = ['success' => true];
            } else {
                $responseData = $response;
                if (!($response['success'] ?? false)) {
                    $this->logAttempt([
                        'status' => 'failed',
                        'reason' => $response['error'] ?? 'firebase_error',
                        'response' => $response
                    ]);
                    throw new \Exception($response['error'] ?? 'Failed to send notification');
                }
            }

            $this->logAttempt([
                'status' => 'success',
                'response' => $responseData
            ]);

            $this->notificationLog->update([
                'is_sent' => true
            ]);

        } catch (\Exception $e) {
            $this->logAttempt([
                'status' => 'error',
                'reason' => $e->getMessage(),
                'error_trace' => $this->formatTrace($e->getTraceAsString()),
                'response' => $responseData ?? null
            ]);

            $this->notificationLog->update([
                'is_sent' => false,
                'error_message' => $e->getMessage()
            ]);
        }
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
                'fcm_token' => $this->notificationLog->user->fcm_token ?? null
            ],
            'attempt' => $context
        ];

        Log::channel('notifications')->info(
            sprintf(
                '[Campaign: %s] [User: %s] [Status: %s] %s',
                $this->notificationLog->campaign_id,
                $this->notificationLog->user->id ?? 'unknown',
                $context['status'],
                $context['reason'] ?? ''
            ),
            $logData
        );
    }

    protected function formatTrace($trace)
    {
        $lines = explode("\n", $trace);
        return array_slice($lines, 0, 5);
    }
}