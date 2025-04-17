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
                $this->logNotification('warning', 'Notification skipped - No FCM token', [
                    'status' => 'skipped',
                    'reason' => 'missing_token'
                ]);
                throw new \Exception('User has no FCM token');
            }

            $this->logNotification('info', 'Notification attempt started', [
                'status' => 'attempting'
            ]);

            $sent = $firebaseService->sendToDevice(
                $user->fcm_token,
                $this->notificationLog->title,
                $this->notificationLog->body,
                $this->notificationLog->data ?? []
            );

            if (!$sent) {
                $this->logNotification('error', 'Firebase notification failed', [
                    'status' => 'failed',
                    'reason' => 'firebase_error'
                ]);
                throw new \Exception('Failed to send notification');
            }

            $this->logNotification('info', 'Firebase notification sent successfully', [
                'status' => 'success'
            ]);

            $this->notificationLog->update([
                'is_sent' => true
            ]);

        } catch (\Exception $e) {
            $this->logNotification('error', $e->getMessage(), [
                'status' => 'error',
                'error_trace' => $this->formatTrace($e->getTraceAsString())
            ]);

            $this->notificationLog->update([
                'is_sent' => false,
                'error_message' => $e->getMessage()
            ]);
        }
    }

    protected function logNotification($level, $message, array $context = [])
    {
        try {
            $baseContext = [
                'campaign_id' => $this->notificationLog->campaign_id,
                'user_id' => $this->notificationLog->user->id ?? null,
                'fcm_token' => $this->notificationLog->user->fcm_token ?? null,
                'title' => $this->notificationLog->title,
                'timestamp' => now()->toIso8601String()
            ];

            // Try notifications channel first, fallback to default if it fails
            try {
                Log::channel('notifications')->{$level}($message, array_merge($baseContext, $context));
            } catch (\Exception $e) {
                Log::{$level}($message, array_merge($baseContext, $context, [
                    'logging_error' => $e->getMessage()
                ]));
            }
        } catch (\Exception $e) {
            // Last resort fallback
            Log::error('Failed to log notification', [
                'error' => $e->getMessage(),
                'original_message' => $message
            ]);
        }
    }

    protected function formatTrace($trace)
    {
        $lines = explode("\n", $trace);
        return array_slice($lines, 0, 5); // Return only first 5 lines of trace
    }
}