<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\NotificationLog;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Jobs\SendNotificationCampaign;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    use ResponseTrait;

    // Update profile without image
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            // 'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'phone' => 'sometimes|string|unique:users,phone,'.$user->id,
            'address' => 'sometimes|string',
            'gender' => 'sometimes|in:male,female'
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $data = $request->only(['first_name', 'last_name', 'email', 'phone', 'address', 'gender']);

        $user->update($data);

        return $this->success($user, 'Profile updated');
    }

    // update profile image
    public function updateProfileImage(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        // Delete old image if exists
        if ($user->image) {
            Storage::disk('public')->delete($user->image);
        }

        // Store new image
        $path = $request->file('image')->store('profile_images', 'public');

        // Update user profile image
        $user->update(['profile_image' => $path]);

        return $this->success($user, 'Profile image updated');
    }

    // Update location
    public function updateLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $request->user()->update([
            'latitude' => $request->latitude,
            'longitude' => $request->longitude
        ]);

        return $this->success(null, 'Location updated');
    }

    // Update FCM token
    public function updateFcmToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => 'required|string',
            'device_type' => 'sometimes|in:ios,android'
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $request->user()->update([
            'fcm_token' => $request->fcm_token,
            'device_type' => $request->device_type ?? null
        ]);

        return $this->success(null, 'FCM token updated');
    }

    // change password
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $user = $request->user();

        if (!Hash::check($request->old_password, $user->password)) {
            return $this->error('Old password is incorrect', 400);
        }

        // Check if the new password is the same as the old password
        if (Hash::check($request->new_password, $user->password)) {
            return $this->error('New password cannot be the same as the old password', 400);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return $this->success(null, 'Password changed successfully');
    }
    
    public function sendNotificationCampaign(Request $request)
    {
        try {
            // Validate admin access
            if ($request->user()->user_type !== 'admin') {
                return $this->error('Unauthorized access', 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'body' => 'required|string',
                'data' => 'nullable|array',
                'user_type' => 'required|in:customer,provider,admin'
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }

            // Generate unique campaign ID
            $campaignId = 'camp_' . uniqid();

            // Get users query
            $usersQuery = User::whereNotNull('fcm_token');
            
            // Filter by user type if specified
            if ($request->has('user_type')) {
                $usersQuery->where('user_type', $request->user_type);
            }

            // Create notification logs and dispatch jobs
            $usersQuery->chunk(100, function ($users) use ($request, $campaignId) {
                foreach ($users as $user) {
                    $log = NotificationLog::create([
                        'campaign_id' => $campaignId,
                        'user_id' => $user->id,
                        'title' => $request->title,
                        'body' => $request->body,
                        'data' => $request->data
                    ]);

                    SendNotificationCampaign::dispatch($log);
                }
            });

            return $this->success([
                'campaign_id' => $campaignId
            ], 'Notification campaign started');

        } catch (\Exception $e) {
            return $this->error('Failed to start notification campaign: ' . $e->getMessage(), 500);
        }
    }
    public function getCampaigns(Request $request)
    {
        try {
            if (auth()->user()->user_type !== 'admin') {
                return $this->error('Unauthorized access', 403);
            }

            // Get unique campaigns with their latest information
            $campaigns = NotificationLog::select(
                'campaign_id',
                'title',
                'body',
                'data',
                'created_at'
            )
            ->selectRaw('
                COUNT(*) as total_notifications,
                SUM(CASE WHEN is_sent = 1 THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN is_sent = 0 THEN 1 ELSE 0 END) as failed_count
            ')
            ->groupBy('campaign_id', 'title', 'body', 'data', 'created_at')
            ->orderBy('created_at', 'desc');

            return $this->success($campaigns, 'Campaigns retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to get campaigns: ' . $e->getMessage(), 500);
        }
    }
    public function getCampaignStatus($campaignId)
    {
        try {
            if (auth()->user()->user_type !== 'admin') {
                return $this->error('Unauthorized access', 403);
            }

            $stats = NotificationLog::where('campaign_id', $campaignId)
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN is_sent = 1 THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN is_sent = 0 THEN 1 ELSE 0 END) as failed
                ')
                ->first();

            $failedLogs = NotificationLog::where('campaign_id', $campaignId)
                ->where('is_sent', false)
                ->whereNotNull('error_message')
                ->with('user:id,first_name,last_name,email')
                ->get(['user_id', 'error_message']);

            return $this->success([
                'campaign_id' => $campaignId,
                'statistics' => $stats,
                'failed_deliveries' => $failedLogs
            ], 'Campaign status retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to get campaign status: ' . $e->getMessage(), 500);
        }
    }
}