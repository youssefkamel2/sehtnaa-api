<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\FirebaseService;
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
                throw new \Exception('User has no FCM token');
            }

            $sent = $firebaseService->sendToDevice(
                $user->fcm_token,
                $this->notificationLog->title,
                $this->notificationLog->body,
                $this->notificationLog->data ?? []
            );

            if (!$sent) {
                throw new \Exception('Failed to send notification');
            }

            $this->notificationLog->update([
                'is_sent' => true
            ]);

        } catch (\Exception $e) {
            $this->notificationLog->update([
                'is_sent' => false,
                'error_message' => $e->getMessage()
            ]);
        }
    }
}