<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    use ResponseTrait;

    // Update profile
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|unique:users,phone,'.$user->id,
            'address' => 'sometimes|string',
            'profile_image' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->localizedError(
                $user,
                'Validation failed', // Fallback message
                422,
                $validator
            );
        }

        $data = $request->only(['first_name', 'last_name', 'phone', 'address']);

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                Storage::delete($user->profile_image);
            }
            $path = $request->file('profile_image')->store('profile_images', 'public');
            $data['profile_image'] = $path;
        }

        $user->update($data);

        return $this->localizedResponse(
            $user,
            $user,
            'messages.user.profile_updated'
        );
    }

    // Update location
    public function updateLocation(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $user->update([
            'latitude' => $request->latitude,
            'longitude' => $request->longitude
        ]);

        return $this->success(null, 'Location updated');
    }

    // Update FCM token
    public function updateFcmToken(Request $request)
    {
        $user = $request->user();

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

    // Update language preference
    public function updateLanguage(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'language' => 'required|in:en,ar',
        ]);

        if ($validator->fails()) {
            return $this->localizedError(
                $user,
                'Validation failed',
                422,
                $validator
            );
        }

        try {
            $user->language = $request->language;
            $user->save();

            return $this->localizedResponse(
                $user,
                ['language' => $user->language],
                'messages.language.updated'
            );
        } catch (\Exception $e) {
            return $this->localizedError(
                $user,
                'messages.language.update_failed',
                500
            );
        }
    }
}