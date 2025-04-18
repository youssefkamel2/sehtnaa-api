<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Models\Provider;
use App\Models\Complaint;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\RequestFeedback;
use Illuminate\Validation\Rule;
use App\Services\FirebaseService;
use App\Models\RequestRequirement;
use App\Models\ServiceRequirement;
use App\Services\FirestoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\RequestCancellationLog;
use App\Models\Request as ServiceRequest;
use Illuminate\Support\Facades\Validator;

class RequestController extends Controller
{
    use ResponseTrait;

    protected $firebaseService;
    protected $firestoreService;

    public function __construct(FirebaseService $firebaseService, FirestoreService $firestoreService)
    {
        $this->firebaseService = $firebaseService;
        $this->firestoreService = $firestoreService;
    }

    public function createRequest(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->user_type !== 'customer') {
                return $this->error('Only customers can create requests', 403);
            }

            if (!$user->customer) {
                return $this->error('Customer profile not found', 404);
            }

            $validator = Validator::make($request->all(), [
                'service_id' => 'required|exists:services,id',
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'phone' => 'required|string',
                'gender' => 'required|in:male,female',
                'additional_info' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            try {
                $serviceRequest = ServiceRequest::create([
                    'customer_id' => $user->customer->id,
                    'service_id' => $request->service_id,
                    'phone' => $request->phone,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'additional_info' => $request->additional_info,
                    'gender' => $request->gender,
                    'status' => 'pending'
                ]);

                if (isset($request->requirements)) {
                    foreach ($request->requirements as $requirementData) {
                        $serviceRequirement = ServiceRequirement::find($requirementData['requirement_id']);

                        $filePath = null;
                        if ($serviceRequirement->type === 'file' && isset($requirementData['file'])) {
                            $filePath = $requirementData['file']->store('requirements');
                        }

                        RequestRequirement::create([
                            'request_id' => $serviceRequest->id,
                            'service_requirement_id' => $requirementData['requirement_id'],
                            'value' => $serviceRequirement->type === 'input' ? $requirementData['value'] : null,
                            'file_path' => $filePath
                        ]);
                    }
                }

                $notifiedCount = $this->findAndNotifyProviders($serviceRequest);

                if ($notifiedCount === 0) {
                    DB::rollBack();
                    return $this->error('No available providers found for this service in your area', 400);
                }

                DB::commit();

                return $this->success([
                    'request' => $serviceRequest,
                    'requirements' => $serviceRequest->requirements,
                    'message' => 'Request created successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                return $this->error('Failed to create request: ' . $e->getMessage(), 500);
            }
        } catch (\Exception $e) {
            return $this->error('Failed to process request: ' . $e->getMessage(), 500);
        }
    }

    protected function findAndNotifyProviders(ServiceRequest $serviceRequest)
    {

        try {
            $logData = [
                'request_id' => $serviceRequest->id,
                'service_id' => $serviceRequest->service_id,
                'customer_id' => $serviceRequest->customer_id,
                'timestamp' => now()->toDateTimeString(),
                'providers' => [],
                'search_radius' => [],
                'notification_analysis' => [],
                'firestore_analysis' => []
            ];

            $service = Service::find($serviceRequest->service_id);
            if (!$service) {
                $logData['error'] = 'Service not found';
                Log::channel('provider_matching')->error('Service not found', $logData);
                return 0;
            }

            $providers = Provider::with(['user'])
                ->where('is_available', true)
                ->where('provider_type', $service->provider_type)
                ->get();

            $logData['total_available_providers'] = $providers->count();
            $logData['service_type'] = $service->name ?? 'Unknown';
            $logData['required_provider_type'] = $service->provider_type;

            $searchRadii = [1, 3, 5];
            $nearbyProviders = [];

            foreach ($searchRadii as $radius) {
                $maxDistance = $radius;
                $logData['search_radius'][] = $maxDistance . 'km';

                $currentRadiusProviders = [];

                foreach ($providers as $provider) {
                    $providerLog = [
                        'provider_id' => $provider->id,
                        'provider_name' => $provider->user->name ?? $provider->user->username ?? 'Provider #' . $provider->id,
                        'provider_type' => $provider->provider_type,
                        'has_location' => false,
                        'distance' => null,
                        'within_range' => false,
                        'notification_details' => [
                            'fcm_token_exists' => !empty($provider->user->fcm_token),
                            'token_status' => !empty($provider->user->fcm_token) ? 'Valid' : 'Missing',
                            'sent' => false,
                            'error' => null,
                            'response' => null
                        ],
                        'firestore_details' => [
                            'attempted' => false,
                            'success' => false,
                            'error' => null,
                            'document_id' => null,
                            'data_sent' => null
                        ],
                        'location_status' => null
                    ];

                    if (!$provider->user->latitude || !$provider->user->longitude) {
                        $providerLog['location_status'] = 'Missing coordinates';
                        $logData['providers'][] = $providerLog;
                        continue;
                    }

                    $providerLog['has_location'] = true;
                    $providerLog['location_status'] = 'Coordinates available';

                    try {
                        $distance = $this->calculateDistance(
                            $serviceRequest->latitude,
                            $serviceRequest->longitude,
                            $provider->user->latitude,
                            $provider->user->longitude
                        );

                        $providerLog['distance'] = round($distance, 2);

                        if ($distance <= $maxDistance) {
                            $providerLog['within_range'] = true;
                            $currentRadiusProviders[] = [
                                'provider' => $provider,
                                'distance' => $distance,
                                'log_entry' => &$providerLog
                            ];
                        }
                    } catch (\Exception $e) {
                        $providerLog['location_status'] = 'Distance calculation failed: ' . $e->getMessage();
                    }

                    $logData['providers'][] = $providerLog;
                }

                if (count($currentRadiusProviders) > 0) {
                    $nearbyProviders = $currentRadiusProviders;
                    $logData['final_search_radius'] = $maxDistance . 'km';
                    break;
                }
            }

            if (count($nearbyProviders) === 0) {
                $logData['outcome'] = 'No providers found in any search radius';
                Log::channel('provider_matching')->info('No providers found', $logData);
                return 0;
            }

            $notificationStats = [
                'total_eligible' => count($nearbyProviders),
                'sent_successfully' => 0,
                'failed' => 0,
                'reasons_failed' => []
            ];

            $firestoreStats = [
                'total_attempts' => 0,
                'successful' => 0,
                'failed' => 0,
                'reasons_failed' => []
            ];

            foreach ($nearbyProviders as $index => $nearby) {
                $provider = $nearby['provider'];
                $distance = $nearby['distance'];
                $providerLog = &$nearby['log_entry'];

                // Enhanced FCM Notification Handling
                if (!empty($provider->user->fcm_token)) {
                    try {
                        $notificationResponse = $this->sendNotification(
                            $provider->user->fcm_token,
                            'New Service Request',
                            'You have a new service request nearby',
                            [
                                'type' => 'new_request',
                                'request_id' => $serviceRequest->id,
                                'distance' => $distance
                            ]
                        );

                        if (isset($notificationResponse['error'])) {
                            throw new \Exception($notificationResponse['error']);
                        }

                        $providerLog['notification_details']['sent'] = true;
                        $providerLog['notification_details']['response'] = $notificationResponse;
                        $notificationStats['sent_successfully']++;
                    } catch (\Exception $e) {
                        $errorMsg = 'FCM Error: ' . $e->getMessage();
                        $providerLog['notification_details']['error'] = $errorMsg;
                        $providerLog['notification_details']['sent'] = false;
                        $notificationStats['failed']++;
                        $notificationStats['reasons_failed'][$errorMsg] = ($notificationStats['reasons_failed'][$errorMsg] ?? 0) + 1;
                    }
                } else {
                    $errorMsg = 'No FCM token available';
                    $providerLog['notification_details']['error'] = $errorMsg;
                    $notificationStats['failed']++;
                    $notificationStats['reasons_failed'][$errorMsg] = ($notificationStats['reasons_failed'][$errorMsg] ?? 0) + 1;
                }

                // Enhanced Firestore Update
                $providerLog['firestore_details']['attempted'] = true;
                $firestoreStats['total_attempts']++;

                try {
                    $documentId = uniqid();
                    $firestoreData = [
                        'request_id' => (string) $serviceRequest->id,
                        'customer_name' => $serviceRequest->customer->user->first_name . ' ' . $serviceRequest->customer->user->last_name,
                        'service_name' => is_array($serviceRequest->service->name) 
                            ? ($serviceRequest->service->name['en'] ?? 'Unknown Service') 
                            : (string) $serviceRequest->service->name,
                        'gender' => (string) ($serviceRequest->gender ?? 'unknown'), // Fallback for empty gender
                        'distance' => (string) round($distance, 2) . ' km',
                        'timestamp' => now()->toDateTimeString(),
                        'status' => 'pending'
                    ];

                    $providerLog['firestore_details']['data_sent'] = $firestoreData;

                    $result = $this->firestoreService->createDocument(
                        'provider_requests/' . $provider->id . '/notifications',
                        $documentId,
                        $firestoreData
                    );

                    $providerLog['firestore_details']['success'] = true;
                    $providerLog['firestore_details']['document_id'] = $documentId;
                    $providerLog['firestore_details']['result'] = $result;
                    $firestoreStats['successful']++;
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    $providerLog['firestore_details']['success'] = false;
                    $providerLog['firestore_details']['error'] = $errorMsg;
                    $firestoreStats['failed']++;
                    $firestoreStats['reasons_failed'][$errorMsg] = ($firestoreStats['reasons_failed'][$errorMsg] ?? 0) + 1;

                    Log::channel('firestore_errors')->error('Firestore update failed for provider', [
                        'provider_id' => $provider->id,
                        'request_id' => $serviceRequest->id,
                        'error' => $errorMsg,
                        'data' => $firestoreData
                    ]);
                }
            }

            $logData['notification_analysis'] = $notificationStats;
            $logData['firestore_analysis'] = $firestoreStats;
            $logData['outcome'] = 'Found ' . count($nearbyProviders) . ' providers in ' . $logData['final_search_radius'];

            Log::channel('provider_matching')->info('Provider matching results', $logData);

            return count($nearbyProviders);
        } catch (\Exception $e) {
            Log::channel('provider_matching')->error('Error in findAndNotifyProviders', [
                'request_id' => $serviceRequest->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'time' => now()->toDateTimeString()
            ]);
            return 0;
        }
    }

    protected function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }




    // get all requests (for admin)
    public function getAllRequests()
    {
        try {
            $user = Auth::user();

            if ($user->user_type !== 'admin') {
                return $this->error('Unauthorized access', 403);
            }

            $requests = ServiceRequest::with([
                'service:id,name,price',
                'customer.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider:id,provider_type,user_id',
                'feedbacks',
                'complaints'
            ])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($request) {
                    $formatted = $this->formatRequest($request);
                    $formatted['customer'] = [
                        'first_name' => $request->customer->user->first_name,
                        'last_name' => $request->customer->user->last_name,
                        'phone' => $request->customer->user->phone,
                        'profile_image' => $request->customer->user->profile_image
                    ];
                    $formatted['feedbacks'] = $request->feedbacks;
                    $formatted['complaints_count'] = $request->complaints->count();
                    $formatted['created_at'] = $request->created_at;
                    return $formatted;
                });

            return $this->success($requests, 'Requests fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch requests: ' . $e->getMessage(), 500);
        }
    }
    public function getUserRequests()
    {
        try {
            $user = Auth::user();

            $customerId = $user->user_type === 'customer' ? $user->customer->id : null;
            $providerId = $user->user_type === 'provider' ? $user->provider->id : null;

            $requests = ServiceRequest::with([
                'service:id,name,price',
                'assignedProvider.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider:id,provider_type,user_id',
                'feedbacks',
                'cancellations'
            ])
                ->when($user->user_type === 'customer', function ($query) use ($customerId) {
                    return $query->where('customer_id', $customerId);
                })
                ->when($user->user_type === 'provider', function ($query) use ($providerId) {
                    return $query->where('assigned_provider_id', $providerId);
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($request) {
                    return $this->formatRequest($request);
                });

            return $this->success($requests, 'Requests fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch requests: ' . $e->getMessage(), 500);
        }
    }
    public function getRequestDetails($id)
    {
        try {
            $request = ServiceRequest::with([
                'service:id,name,price',
                'assignedProvider.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider:id,provider_type,user_id',
                'feedbacks.user:id,first_name,last_name,profile_image',
                'cancellations',
                'complaints.user:id,first_name,last_name,profile_image'
            ])->find($id);

            if (!$request) {
                return $this->error('Request not found', 404);
            }

            $user = Auth::user();

            if ($user->user_type === 'customer' && (!$user->customer || $request->customer_id !== $user->customer->id)) {
                return $this->error('Unauthorized', 403);
            }

            if ($user->user_type === 'provider' && (!$user->provider || $request->assigned_provider_id !== $user->provider->id)) {
                return $this->error('Unauthorized', 403);
            }

            return $this->success($this->formatRequestDetails($request), 'Request details fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch request details: ' . $e->getMessage(), 500);
        }
    }

    // get request complaints
    public function getRequestComplaints($id)
    {
        try {
            $request = ServiceRequest::with([
                'complaints' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                },
                'assignedProvider.user:id,first_name,last_name,profile_image'
            ])->find($id);

            if (!$request) {
                return $this->error('Request not found', 404);
            }

            $user = Auth::user();

            // Authorization check
            if ($user->user_type === 'customer' && (!$user->customer || $request->customer_id !== $user->customer->id)) {
                return $this->error('Unauthorized', 403);
            }

            if ($user->user_type === 'provider' && (!$user->provider || $request->assigned_provider_id !== $user->provider->id)) {
                return $this->error('Unauthorized', 403);
            }

            $complaints = $request->complaints->map(function ($complaint) use ($request) {
                return [
                    'subject' => $complaint->subject,
                    'description' => $complaint->description,
                    'status' => $complaint->status,
                    'response' => $complaint->response,
                    'created_at' => $complaint->created_at,
                    'provider' => $request->assignedProvider ? [
                        'name' => $request->assignedProvider->user->first_name . ' ' . $request->assignedProvider->user->last_name,
                        'profile_image' => $request->assignedProvider->user->profile_image
                    ] : null
                ];
            });

            return $this->success($complaints, 'Complaints fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch complaints: ' . $e->getMessage(), 500);
        }
    }
    public function submitFeedback(Request $request, $id)
    {
        try {
            $user = Auth::user();

            // Verify user is a customer
            if ($user->user_type !== 'customer') {
                return $this->error('Only customers can submit feedback', 403);
            }

            // Verify customer exists
            if (!$user->customer) {
                return $this->error('Customer profile not found', 404);
            }

            $serviceRequest = ServiceRequest::with(['customer'])->find($id);
            if (!$serviceRequest) {
                return $this->error('Request not found', 404);
            }

            // Verify requesting customer owns the request
            if ($serviceRequest->customer_id !== $user->customer->id) {
                return $this->error('You can only submit feedback for your own requests', 403);
            }

            // Check if request is completed
            if ($serviceRequest->status !== 'completed') {
                return $this->error('Feedback can only be submitted for completed requests', 400);
            }

            // Check for existing feedback
            if ($serviceRequest->feedbacks()->where('user_id', $user->id)->exists()) {
                return $this->error('You have already submitted feedback for this request', 400);
            }

            $validator = Validator::make($request->all(), [
                'rating' => 'required|string|between:1,5',
                'comment' => 'nullable|string|max:1000',
            ]);

            // return first error only
            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }

            $feedback = new RequestFeedback([
                'user_id' => $user->id,
                'rating' => $request->rating,
                'comment' => $request->comment,
            ]);

            $serviceRequest->feedbacks()->save($feedback);

            // Send notification to provider if assigned
            if ($serviceRequest->assigned_provider_id && $serviceRequest->assignedProvider->user->fcm_token) {
                $this->sendNotification(
                    $serviceRequest->assignedProvider->user->fcm_token,
                    'New Feedback Received',
                    'You received new feedback for your service',
                    [
                        'type' => 'feedback_received',
                        'request_id' => $serviceRequest->id,
                        'rating' => $request->rating
                    ]
                );
            }

            return $this->success(null, 'Feedback submitted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to submit feedback: ' . $e->getMessage(), 500);
        }
    }

    public function createComplaint(Request $request, $id)
    {
        try {
            $user = Auth::user();

            // Verify user is a customer
            if ($user->user_type !== 'customer') {
                return $this->error('Only customers can submit complaints', 403);
            }

            // Verify customer exists
            if (!$user->customer) {
                return $this->error('Customer profile not found', 404);
            }

            $serviceRequest = ServiceRequest::with(['customer'])->find($id);
            if (!$serviceRequest) {
                return $this->error('Request not found', 404);
            }

            // Verify requesting customer owns the request
            if ($serviceRequest->customer_id !== $user->customer->id) {
                return $this->error('You can only submit complaints for your own requests', 403);
            }

            $validator = Validator::make($request->all(), [
                'subject' => 'required|string|max:255',
                'description' => 'required|string|max:2000',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }

            $complaint = new Complaint([
                'user_id' => $user->id,
                'subject' => $request->subject,
                'description' => $request->description,
                'status' => 'open',
            ]);

            $serviceRequest->complaints()->save($complaint);

            return $this->success(null, 'Complaint submitted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to submit complaint: ' . $e->getMessage(), 500);
        }
    }

    public function cancelRequest(Request $request, $id)
    {
        try {
            $serviceRequest = ServiceRequest::with(['customer.user', 'assignedProvider.user'])->find($id);
            if (!$serviceRequest) {
                return $this->error('Request not found', 404);
            }

            $user = Auth::user();
            $isCustomer = $user->user_type === 'customer';
            $isProvider = $user->user_type === 'provider';

            // Authorization check
            if ($isCustomer && $serviceRequest->customer_id !== $user->customer->id) {
                return $this->error('Unauthorized', 403);
            }

            if ($isProvider && $serviceRequest->assigned_provider_id !== $user->provider->id) {
                return $this->error('Unauthorized', 403);
            }

            if (!$serviceRequest->isCancellable()) {
                return $this->error('Request cannot be cancelled at this stage', 400);
            }

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }


            // Create cancellation log - using getRawOriginal() to bypass accessor
            $cancellation = new RequestCancellationLog([
                'cancelled_by' => $isCustomer ? 'customer' : 'provider',
                'reason' => $request->reason,
                // if request is cancelled after acceptance, set is_after_acceptance to 1
                'is_after_acceptance' => $serviceRequest->getRawOriginal('status') === 'accepted' ? 1 : 0,
                'cancelled_at' => now(),
            ]);

            $serviceRequest->cancellations()->save($cancellation);

            // Update request status
            $serviceRequest->status = 'cancelled';
            $serviceRequest->save();

            // Send notifications
            $this->handleCancellationNotifications($serviceRequest, $user);

            return $this->success(null, 'Request cancelled successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to cancel request: ' . $e->getMessage(), 500);
        }
    }

    protected function handleCancellationNotifications(ServiceRequest $serviceRequest, $user)
    {
        $isCustomer = $user->user_type === 'customer';

        // Notification to the canceller
        $this->sendNotification(
            $user->fcm_token,
            'Request Cancelled',
            'You have cancelled the request',
            ['type' => 'request_cancelled', 'request_id' => $serviceRequest->id]
        );

        // Notification to the other party if assigned
        if ($serviceRequest->assigned_provider_id) {
            if ($isCustomer && $serviceRequest->assignedProvider->user->fcm_token) {
                $this->sendNotification(
                    $serviceRequest->assignedProvider->user->fcm_token,
                    'Request Cancelled',
                    'The customer has cancelled the request',
                    ['type' => 'request_cancelled', 'request_id' => $serviceRequest->id]
                );
            } elseif (!$isCustomer && $serviceRequest->customer->user->fcm_token) {
                $this->sendNotification(
                    $serviceRequest->customer->user->fcm_token,
                    'Request Cancelled',
                    'The provider has cancelled the request',
                    ['type' => 'request_cancelled', 'request_id' => $serviceRequest->id]
                );
            }
        }
    }

    protected function sendNotification($token, $title, $body, $data = [])
    {
        if (empty($token)) {
            Log::channel('fcm_errors')->warning('Attempted to send notification with empty token', [
                'title' => $title,
                'body' => $body
            ]);
            return ['error' => 'Empty FCM token'];
        }

        try {
            $response = $this->firebaseService->sendToDevice($token, $title, $body, $data);

            if (!isset($response['success']) || $response['success'] === 0) {
                $error = $response['results'][0]['error'] ?? 'Unknown FCM error';
                Log::channel('fcm_errors')->error('FCM delivery failed', [
                    'token' => $token,
                    'error' => $error,
                    'title' => $title,
                    'body' => $body,
                    'full_response' => $response
                ]);
                return ['error' => $error];
            }

            return $response;
        } catch (\Exception $e) {
            Log::channel('fcm_errors')->error('FCM service error', [
                'token' => $token,
                'error' => $e->getMessage(),
                'title' => $title,
                'body' => $body,
                'trace' => $e->getTraceAsString()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    private function formatRequest(ServiceRequest $request): array
    {
        return [
            'id' => $request->id,
            'customer_id' => $request->customer_id,
            'service_id' => $request->service_id,
            'phone' => $request->phone,
            'address' => $request->address,
            'status' => $request->status,
            'service' => [
                'name' => $request->service->name,
                'price' => $request->service->price
            ],
            'assigned_provider' => $request->assignedProvider ? [
                'first_name' => $request->assignedProvider->user->first_name,
                'last_name' => $request->assignedProvider->user->last_name,
                'provider_type' => $request->assignedProvider->provider_type,
                'phone' => $request->assignedProvider->user->phone,
                'profile_image' => $request->assignedProvider->user->profile_image
            ] : null,
        ];
    }

    private function formatRequestDetails(ServiceRequest $request): array
    {
        $formatted = $this->formatRequest($request);

        $formatted['additional_info'] = $request->additional_info;
        $formatted['latitude'] = $request->latitude;
        $formatted['longitude'] = $request->longitude;
        $formatted['scheduled_at'] = $request->scheduled_at;
        $formatted['started_at'] = $request->started_at;
        $formatted['completed_at'] = $request->completed_at;
        $formatted['created_at'] = $request->created_at;

        $formatted['feedbacks'] = $request->feedbacks->map(function ($feedback) {
            return [
                'id' => $feedback->id,
                'rating' => $feedback->rating,
                'comment' => $feedback->comment,
                'created_at' => $feedback->created_at,
                'user' => [
                    'name' => $feedback->user->first_name . ' ' . $feedback->user->last_name,
                    'profile_image' => $feedback->user->profile_image,
                ]
            ];
        });

        $formatted['cancellations'] = $request->cancellations->map(function ($cancellation) {
            return [
                'id' => $cancellation->id,
                'cancelled_by' => $cancellation->cancelled_by,
                'reason' => $cancellation->reason,
                'is_after_acceptance' => $cancellation->is_after_acceptance,
                'cancelled_at' => $cancellation->cancelled_at,
            ];
        });

        $formatted['complaints'] = $request->complaints->map(function ($complaint) {
            return [
                'id' => $complaint->id,
                'subject' => $complaint->subject,
                'description' => $complaint->description,
                'status' => $complaint->status,
                'created_at' => $complaint->created_at,
                'user' => [
                    'name' => $complaint->user->first_name . ' ' . $complaint->user->last_name,
                    'profile_image' => $complaint->user->profile_image,
                ]
            ];
        });

        return $formatted;
    }
}
