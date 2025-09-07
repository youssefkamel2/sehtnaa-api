<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Request as ServiceRequest;
use App\Models\RequestCancellationLog;
use App\Models\Customer;
use App\Models\Provider;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\NotificationLog;
use App\Models\WalletTransaction;
use App\Http\Requests\WalletRequest;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Jobs\SendNotificationCampaign;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\LogService;
use Illuminate\Support\Facades\Auth;

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
            'phone' => 'sometimes|string|unique:users,phone,' . $user->id,
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
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'body' => 'required|string',
                'data' => 'nullable|array',
                'user_type' => 'required|in:customer,provider,admin',
                'schedule_at' => 'nullable|date|after:now'
            ]);

            if ($validator->fails()) {

                return $this->error($validator->errors()->first(), 422);
            }

            $campaignId = 'camp_' . uniqid();
            $userType = $request->user_type;
            $usersCount = 0;

            LogService::notifications('info', 'Starting notification campaign', [
                'campaign_id' => $campaignId,
                'title' => $request->title,
                'user_type' => $userType,
                'scheduled_at' => $request->schedule_at ?? 'immediately',
            ]);

            User::where('user_type', $userType)
                ->whereNotNull('fcm_token')
                ->select(['id', 'user_type', 'fcm_token'])
                ->chunk(200, function ($users) use ($request, $campaignId, $userType, &$usersCount) {
                    $logs = [];
                    $now = now();

                    foreach ($users as $user) {
                        $logs[] = [
                            'campaign_id' => $campaignId,
                            'user_id' => $user->id,
                            'user_type' => $userType,
                            'title' => $request->title,
                            'body' => $request->body,
                            'data' => $request->data,
                            'device_token' => $user->fcm_token,
                            'attempt_logs' => json_encode([
                                [
                                    'status' => 'queued',
                                    'timestamp' => $now->toDateTimeString(),
                                    'attempt' => 0,
                                    'queue' => 'notifications',
                                ]
                            ]),
                            'created_at' => $now,
                            'updated_at' => $now
                        ];
                        $usersCount++;
                    }

                    try {
                        NotificationLog::insert($logs);

                        foreach ($logs as $log) {
                            $job = SendNotificationCampaign::dispatch($log['campaign_id'], $log['user_id'])
                                ->onQueue('notifications')
                                ->delay($request->schedule_at ?? null);

                            LogService::jobs('debug', 'Job dispatched', [
                                'campaign_id' => $log['campaign_id'],
                                'user_id' => $log['user_id'],
                                'queue' => 'notifications',
                                'scheduled_at' => $request->schedule_at ?? 'immediately',
                            ]);
                        }
                    } catch (\Exception $e) {
                        LogService::fcmErrors('Failed to insert notification logs', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'campaign_id' => $campaignId,
                            'batch_size' => count($logs),
                        ]);
                        throw $e;
                    }
                });

            if ($usersCount === 0) {
                LogService::fcmErrors('No users with FCM tokens found', [
                    'user_type' => $userType,
                    'campaign_id' => $campaignId,
                ]);

                DB::commit();
                return $this->error('No users available with FCM tokens for the selected user type', 400);
            }

            DB::commit();

            LogService::notifications('info', 'Notification campaign queued successfully', [
                'campaign_id' => $campaignId,
                'total_recipients' => $usersCount,
            ]);

            return $this->success([
                'campaign_id' => $campaignId,
                'title' => $request->title,
                'body' => $request->body,
                'user_type' => $userType,
                'total_recipients' => $usersCount,
                'status' => 'queued',
                'schedule_at' => $request->schedule_at
            ], 'Notification campaign started successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            LogService::fcmErrors('Failed to start notification campaign', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['fcm_token']),
            ]);

            return $this->error('Failed to start notification campaign: ' . $e->getMessage(), 500);
        }
    }

    public function getCampaigns(Request $request)
    {
        try {

            $query = NotificationLog::query()
                ->select([
                    'campaign_id',
                    'title',
                    'body',
                    'user_type',
                    DB::raw('MIN(created_at) as created_at'),
                    DB::raw('COUNT(*) as total_notifications'),
                    DB::raw('SUM(CASE WHEN is_sent = 1 THEN 1 ELSE 0 END) as sent_count'),
                    DB::raw('SUM(CASE WHEN is_sent = 0 AND attempts_count >= ' . config('notification.max_attempts', 3) . ' THEN 1 ELSE 0 END) as failed_count'),
                    DB::raw('SUM(CASE WHEN is_sent = 0 AND attempts_count < ' . config('notification.max_attempts', 3) . ' THEN 1 ELSE 0 END) as pending_count'),
                    DB::raw('MAX(updated_at) as last_updated_at')
                ])
                ->groupBy('campaign_id', 'title', 'body', 'user_type')
                ->orderBy('created_at', 'desc');

            // // Add pagination
            // $perPage = $request->get('per_page', 15);
            // $campaigns = $query->paginate($perPage);

            // // Transform each campaign
            // $campaigns->getCollection()->transform(function ($campaign) {
            //     $campaign->status = $this->determineCampaignStatus($campaign);
            //     return $campaign;
            // });
            $campaigns = $query->get();
            $campaigns->transform(function ($campaign) {
                $campaign->status = $this->determineCampaignStatus($campaign);
                return $campaign;
            });

            return $this->success($campaigns, 'Campaigns retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to get campaigns: ' . $e->getMessage(), 500);
        }
    }

    private function determineCampaignStatus($campaign)
    {
        // If all notifications failed (including invalid tokens)
        if ($campaign->failed_count == $campaign->total_notifications) {
            return 'failed';
        }

        // If all notifications were sent successfully
        if ($campaign->sent_count == $campaign->total_notifications) {
            return 'success';
        }

        // If there are still pending notifications (not reached max attempts)
        if ($campaign->pending_count > 0) {
            return 'processing';
        }

        // If some were sent but some failed (partial success)
        if ($campaign->sent_count > 0 && $campaign->failed_count > 0) {
            return 'partial_success';
        }

        // If no notifications have been processed yet
        if ($campaign->sent_count == 0 && $campaign->failed_count == 0) {
            return 'queued';
        }

        // Default fallback
        return 'processing';
    }

    // public function getCampaignStatus($campaignId)
    // {
    //     try {
    //         if (auth()->user()->user_type !== 'admin') {
    //             return $this->error('Unauthorized access', 403);
    //         }

    //         $stats = NotificationLog::where('campaign_id', $campaignId)
    //             ->selectRaw('
    //                 COUNT(*) as total,
    //                 SUM(CASE WHEN is_sent = 1 THEN 1 ELSE 0 END) as sent,
    //                 SUM(CASE WHEN is_sent = 0 THEN 1 ELSE 0 END) as failed
    //             ')
    //             ->first();

    //         $failedLogs = NotificationLog::where('campaign_id', $campaignId)
    //             ->where('is_sent', false)
    //             ->whereNotNull('error_message')
    //             ->with('user:id,first_name,last_name,email')
    //             ->get(['user_id', 'error_message']);

    //         return $this->success([
    //             'campaign_id' => $campaignId,
    //             'statistics' => $stats,
    //             'failed_deliveries' => $failedLogs
    //         ], 'Campaign status retrieved successfully');

    //     } catch (\Exception $e) {
    //         return $this->error('Failed to get campaign status: ' . $e->getMessage(), 500);
    //     }
    // }

    /**
     * Delete (deactivate) the authenticated user's account.
     * Blocks deletion if there are ongoing/assigned requests.
     * Frees the email (by appending +deleted suffix) so the user can re-register.
     */
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        DB::beginTransaction();
        try {
            // Block if customer has ongoing requests
            if ($user->user_type === 'customer') {
                $customer = $user->customer;
                if ($customer && $customer->requests()->whereIn('status', ['pending', 'accepted', 'started'])->exists()) {
                    DB::rollBack();
                    return $this->error('You have ongoing requests. Please complete or cancel them before deleting your account.', 409);
                }
            }

            // Block if provider is assigned to requests
            if ($user->user_type === 'provider') {
                $provider = $user->provider;
                if ($provider && ServiceRequest::where('assigned_provider_id', $provider->id)->whereIn('status', ['accepted', 'started'])->exists()) {
                    DB::rollBack();
                    return $this->error('You are assigned to ongoing requests. Please complete them first.', 409);
                }
                if ($provider) {
                    $provider->is_available = false;
                    $provider->save();
                }
            }

            // Modify email to free it up for re-registration
            $originalEmail = $user->email;
            if (!empty($originalEmail) && str_contains($originalEmail, '@')) {
                [$local, $domain] = explode('@', $originalEmail, 2);
                $user->email = $local . '+deleted-' . $user->id . '-' . now()->format('YmdHis') . '@' . $domain;
            }
            
            $user->last_name .= ' Deleted-account';
            $user->status = 'de-active';
            $user->save();

            // Soft delete the user
            $user->delete();

            // Invalidate JWT token
            try {
                if ($token = JWTAuth::getToken()) {
                    JWTAuth::invalidate($token);
                }
            } catch (\Throwable $e) {
                // Ignore token errors
            }

            DB::commit();
            return $this->success(null, 'Account deleted successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            LogService::exception($e, ['action' => 'delete_account', 'user_id' => $user->id]);
            return $this->error('Failed to delete account. Please try again later.', 500);
        }
    }
}
