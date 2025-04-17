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
                Log::info('Notification skipped - No FCM token', [
                    'campaign_id' => $this->notificationLog->campaign_id,
                    'user_id' => $user->id,
                    'error' => 'User has no FCM token'
                ]);
                throw new \Exception('User has no FCM token');
            }

            Log::info('Attempting to send notification', [
                'campaign_id' => $this->notificationLog->campaign_id,
                'user_id' => $user->id,
                'fcm_token' => $user->fcm_token,
                'title' => $this->notificationLog->title
            ]);

            $sent = $firebaseService->sendToDevice(
                $user->fcm_token,
                $this->notificationLog->title,
                $this->notificationLog->body,
                $this->notificationLog->data ?? []
            );

            if (!$sent) {
                Log::error('Firebase notification failed', [
                    'campaign_id' => $this->notificationLog->campaign_id,
                    'user_id' => $user->id,
                    'fcm_token' => $user->fcm_token
                ]);
                throw new \Exception('Failed to send notification');
            }

            Log::info('Firebase notification sent successfully', [
                'campaign_id' => $this->notificationLog->campaign_id,
                'user_id' => $user->id,
                'fcm_token' => $user->fcm_token
            ]);

            $this->notificationLog->update([
                'is_sent' => true
            ]);

        } catch (\Exception $e) {
            Log::error('Notification error', [
                'campaign_id' => $this->notificationLog->campaign_id,
                'user_id' => $user->id ?? null,
                'fcm_token' => $user->fcm_token ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->notificationLog->update([
                'is_sent' => false,
                'error_message' => $e->getMessage()
            ]);
        }
    }
}