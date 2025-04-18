<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FirestoreTestController extends Controller
{
    protected $firestoreService;

    public function __construct()
    {
        $this->firestoreService = new FirestoreService();
    }

    public function sendNotificationToUser(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required',
                'title' => 'required|string',
                'body' => 'required|string',
            ]);

            // Send real-time update via Firestore REST API
            try {
                Log::info('Sending real-time update to Firestore for user: ' . $request->user_id);

                $this->firestoreService->createDocument('user_updates', $request->user_id, [
                    'title' => $request->title, // Send the title as the real-time update
                    'message' => $request->body, // Send the body text as the real-time update
                    'timestamp' => time(), // Add a timestamp
                ]);

                Log::info('Real-time update sent to Firestore for user: ' . $request->user_id);
            } catch (\Exception $e) {
                Log::error('Error sending real-time update to Firestore: ' . $e->getMessage());
                throw $e;
            }



            return response()->json(['message' => 'Real-time update and notification sent to user']);
        } catch (\Exception $e) {
            Log::error('Error in sendNotificationToUser: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}