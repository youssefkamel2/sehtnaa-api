<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TestNotificationController extends Controller
{
    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function sendTestNotification(Request $request)
    {
        // Get authenticated user
        $user = Auth::user();

        // Validate request
        $request->validate([
            'title' => 'required|string',
            'body' => 'required|string',
        ]);

        // Check if user has FCM token
        if (empty($user->fcm_token)) {
            return response()->json([
                'success' => false,
                'message' => 'User does not have an FCM token registered'
            ], 400);
        }

        try {
            // Send notification
            $response = $this->firebaseService->sendToDevice(
                $user->fcm_token,
                $request->title,
                $request->body
            );

            return response()->json([
                'success' => true,
                'message' => 'Notification sent successfully',
                'data' => $response
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}