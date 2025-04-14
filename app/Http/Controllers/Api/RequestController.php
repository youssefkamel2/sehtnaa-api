<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Request as ServiceRequest;
use App\Models\RequestCancellationLog;
use App\Models\RequestFeedback;
use App\Models\Complaint;
use App\Services\FirebaseService;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RequestController extends Controller
{
    use ResponseTrait;

    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function getUserRequests() {
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
                'complaints' => function($query) {
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
        if ($token) {
            $this->firebaseService->sendToDevice($token, $title, $body, $data);
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
