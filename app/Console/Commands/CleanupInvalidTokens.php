<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\NotificationLog;
use Illuminate\Console\Command;
use App\Services\LogService;

class CleanupInvalidTokens extends Command
{
    protected $signature = 'notifications:cleanup-tokens {--campaign= : Specific campaign ID to fix} {--force : Force cleanup without confirmation}';
    protected $description = 'Clean up invalid FCM tokens and fix notification campaigns';

    public function handle()
    {
        $campaignId = $this->option('campaign');
        $force = $this->option('force');

        if ($campaignId) {
            $this->fixSpecificCampaign($campaignId);
        } else {
            $this->cleanupAllInvalidTokens($force);
        }

        return 0;
    }

    protected function fixSpecificCampaign($campaignId)
    {
        $this->info("Fixing campaign: {$campaignId}");

        $notifications = NotificationLog::where('campaign_id', $campaignId)->get();

        if ($notifications->isEmpty()) {
            $this->error("Campaign {$campaignId} not found");
            return;
        }

        $fixedCount = 0;
        $invalidTokens = [];

        foreach ($notifications as $notification) {
            if ($this->isInvalidToken($notification->device_token)) {
                $invalidTokens[] = $notification->device_token;

                // Mark notification as failed immediately
                $notification->update([
                    'is_sent' => false,
                    'error_message' => 'Invalid FCM token format',
                    'attempts_count' => 3, // Max attempts
                    'attempt_logs' => array_merge($notification->attempt_logs ?? [], [
                        [
                            'status' => 'token_invalid',
                            'timestamp' => now()->toDateTimeString(),
                            'attempt' => 3,
                            'error' => 'Invalid FCM token format',
                            'message' => 'Token marked as invalid by cleanup command'
                        ]
                    ])
                ]);

                // Clear user's FCM token
                $user = User::find($notification->user_id);
                if ($user && $user->fcm_token === $notification->device_token) {
                    $user->update(['fcm_token' => null]);
                    LogService::notifications('warning', 'Invalid FCM token cleared during campaign fix', [
                        'user_id' => $user->id,
                        'campaign_id' => $campaignId,
                        'old_token' => $this->maskToken($notification->device_token)
                    ]);
                }

                $fixedCount++;
            }
        }

        $this->info("Fixed {$fixedCount} notifications in campaign {$campaignId}");

        if (!empty($invalidTokens)) {
            $this->warn("Found " . count(array_unique($invalidTokens)) . " unique invalid tokens");
        }

        LogService::scheduler('info', 'Campaign tokens cleanup completed', [
            'campaign_id' => $campaignId,
            'fixed_count' => $fixedCount,
            'invalid_tokens_count' => count(array_unique($invalidTokens))
        ]);
    }

    protected function cleanupAllInvalidTokens($force)
    {
        $this->info("Scanning for invalid FCM tokens...");

        $usersWithInvalidTokens = User::whereNotNull('fcm_token')
            ->get()
            ->filter(function ($user) {
                return $this->isInvalidToken($user->fcm_token);
            });

        if ($usersWithInvalidTokens->isEmpty()) {
            $this->info("No invalid FCM tokens found");
            return;
        }

        $this->warn("Found " . $usersWithInvalidTokens->count() . " users with invalid FCM tokens");

        if (!$force && !$this->confirm('Do you want to clear these invalid tokens?')) {
            $this->info("Operation cancelled");
            return;
        }

        $clearedCount = 0;
        foreach ($usersWithInvalidTokens as $user) {
            $oldToken = $user->fcm_token;
            $user->update(['fcm_token' => null]);

            LogService::notifications('warning', 'Invalid FCM token cleared', [
                'user_id' => $user->id,
                'old_token' => $this->maskToken($oldToken)
            ]);

            $clearedCount++;
        }

        $this->info("Cleared {$clearedCount} invalid FCM tokens");

        // Also fix any pending notifications with invalid tokens
        $this->fixPendingNotificationsWithInvalidTokens();

        LogService::scheduler('info', 'Invalid FCM tokens cleanup completed', [
            'cleared_count' => $clearedCount
        ]);
    }

    protected function fixPendingNotificationsWithInvalidTokens()
    {
        $pendingNotifications = NotificationLog::where('is_sent', false)
            ->where('attempts_count', '<', 3)
            ->get();

        $fixedCount = 0;
        foreach ($pendingNotifications as $notification) {
            if ($this->isInvalidToken($notification->device_token)) {
                $notification->update([
                    'is_sent' => false,
                    'error_message' => 'Invalid FCM token format',
                    'attempts_count' => 3,
                    'attempt_logs' => array_merge($notification->attempt_logs ?? [], [
                        [
                            'status' => 'token_invalid',
                            'timestamp' => now()->toDateTimeString(),
                            'attempt' => 3,
                            'error' => 'Invalid FCM token format',
                            'message' => 'Token marked as invalid by cleanup command'
                        ]
                    ])
                ]);
                $fixedCount++;
            }
        }

        if ($fixedCount > 0) {
            $this->info("Fixed {$fixedCount} pending notifications with invalid tokens");
        }
    }

    protected function isInvalidToken($token)
    {
        if (empty($token) || strlen($token) < 100) {
            return true;
        }

        // Check for common invalid token patterns
        $invalidPatterns = [
            '/^[a-z]+$/', // Only lowercase letters
            '/^[0-9]+$/', // Only numbers
            '/^temp_/',   // Temporary tokens
            '/^test_/',   // Test tokens
        ];

        foreach ($invalidPatterns as $pattern) {
            if (preg_match($pattern, $token)) {
                return true;
            }
        }

        // Check for valid FCM token format
        return !preg_match('/^[a-zA-Z0-9:_-]+$/', $token);
    }

    protected function maskToken($token)
    {
        if (strlen($token) <= 10) {
            return '[INVALID_TOKEN]';
        }

        return substr($token, 0, 8) . '...' . substr($token, -8);
    }
}