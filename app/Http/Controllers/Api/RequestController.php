<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Models\Provider;
use App\Models\Complaint;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\RequestFeedback;
use App\Models\RequestProvider;
use Illuminate\Validation\Rule;
use App\Services\FirebaseService;
use App\Models\RequestRequirement;
use App\Models\ServiceRequirement;
use App\Services\FirestoreService;
use App\Services\ProviderNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\RequestCancellationLog;
use App\Jobs\ExpandRequestSearchRadius;
use App\Models\Request as ServiceRequest;
use Illuminate\Support\Facades\Validator;
use App\Services\LogService;

class RequestController extends Controller
{
    use ResponseTrait;

    protected $firebaseService;
    protected $firestoreService;
    protected $providerNotifier;

    public function __construct(FirebaseService $firebaseService, FirestoreService $firestoreService, ProviderNotifier $providerNotifier)
    {
        $this->firebaseService = $firebaseService;
        $this->firestoreService = $firestoreService;
        $this->providerNotifier = $providerNotifier;
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

            // Handle JSON string inputs
            $serviceIds = is_array($request->service_ids) ? $request->service_ids : (json_decode($request->service_ids, true) ?? []);
            $requirements = is_array($request->requirements) ? $request->requirements : (json_decode($request->requirements, true) ?? []);

            // Replace the original values with decoded ones
            $request->merge([
                'service_ids' => $serviceIds,
                'requirements' => $requirements
            ]);

            // Validate input
            $validator = Validator::make($request->all(), [
                'service_ids' => 'required|array|min:1',
                'service_ids.*' => 'exists:services,id',
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'phone' => 'required|string',
                'gender' => 'required|in:male,female',
                'additional_info' => 'nullable|string',
                'age' => 'required|integer|min:1',
                'name' => 'required|string',
                'requirements' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }

            $services = Service::with('category')->whereIn('id', $serviceIds)->get();

            // Check if all services exist
            if ($services->count() !== count($serviceIds)) {
                return $this->error('One or more services not found', 404);
            }

            // Check if all services belong to the same category
            $categoryIds = $services->pluck('category_id')->unique();
            if ($categoryIds->count() > 1) {
                return $this->error('All services must belong to the same category', 422);
            }

            $category = $services->first()->category;

            // Check if multiple services are allowed for this category
            if (!$category->is_multiple && count($serviceIds) > 1) {
                return $this->error('This category does not support multiple services', 422);
            }

            // Check maximum ongoing requests (3)
            $ongoingRequestsCount = ServiceRequest::where('customer_id', $user->customer->id)
                ->whereIn('status', ['pending', 'accepted'])
                ->count();

            if ($ongoingRequestsCount >= 3) {
                return $this->error('You cannot have more than 3 ongoing requests', 400);
            }

            // Check if customer already has a request in this category
            $existingCategoryRequest = ServiceRequest::where('customer_id', $user->customer->id)
                ->whereIn('status', ['pending', 'accepted'])
                ->whereHas('services', function ($query) use ($category) {
                    $query->where('category_id', $category->id);
                })
                ->exists();

            if ($existingCategoryRequest) {
                return $this->error('You already have an ongoing request in this category', 400);
            }

            // Get requirements for all services
            $serviceRequirements = ServiceRequirement::whereIn('service_id', $serviceIds)->get();

            // if the category is multiple, we can skip requirement validation
            if (!$category->is_multiple) {

                // Prepare validation rules for requirements
                $requirementRules = [];
                if ($serviceRequirements->count() > 0) {
                    $requirementRules['requirements'] = 'required|array';
                }

                // Custom validation for each requirement
                $validator = Validator::make($request->all(), $requirementRules, [
                    'requirements.required' => 'All service requirements must be provided'
                ]);

                // Validate individual requirements
                if ($serviceRequirements->count() > 0) {
                    foreach ($request->requirements as $index => $requirement) {
                        if (!is_array($requirement)) {
                            $validator->errors()->add("requirements.$index", 'Invalid requirement format');
                            continue;
                        }

                        $serviceRequirement = ServiceRequirement::find($requirement['requirement_id'] ?? null);

                        if (!$serviceRequirement) {
                            $validator->errors()->add("requirements.$index", 'Invalid requirement ID');
                            continue;
                        }

                        if ($serviceRequirement->type === 'file') {
                            $fileKey = 'file_' . $requirement['requirement_id'];
                            if (!$request->hasFile($fileKey)) {
                                $validator->errors()->add("requirements.$index", 'File upload is required for this requirement');
                            }
                        } else {
                            if (!isset($requirement['value']) || empty(trim($requirement['value'] ?? ''))) {
                                $validator->errors()->add("requirements.$index", 'Value is required for this requirement');
                            }
                        }
                    }
                }

                if ($validator->fails()) {
                    return $this->error($validator->errors()->first(), 422);
                }
            }

            DB::beginTransaction();

            try {
                // Calculate total price
                $totalPrice = $services->sum('price');

                // Create the main request record
                $serviceRequest = ServiceRequest::create([
                    'customer_id' => $user->customer->id,
                    'phone' => $request->phone,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'additional_info' => $request->additional_info,
                    'gender' => $request->gender,
                    'age' => $request->age,
                    'status' => 'pending',
                    'current_search_radius' => 1,
                    'expansion_attempts' => 0,
                    'total_price' => $totalPrice,
                    'name' => $request->name
                ]);

                // Attach all services to the request with their individual prices
                $serviceRequest->services()->attach(
                    $services->mapWithKeys(function ($service) {
                        return [$service->id => ['price' => $service->price]];
                    })
                );

                // Handle requirements
                if ($serviceRequirements->count() > 0) {
                    foreach ($request->requirements as $requirementData) {
                        $serviceRequirement = ServiceRequirement::find($requirementData['requirement_id']);

                        $filePath = null;
                        $value = null;

                        if ($serviceRequirement->type === 'file') {
                            $fileKey = 'file_' . $requirementData['requirement_id'];
                            if ($request->hasFile($fileKey)) {
                                $filePath = $request->file($fileKey)->store('request_requirements', 'public');
                            }
                        } else {
                            $value = $requirementData['value'];
                        }

                        RequestRequirement::create([
                            'request_id' => $serviceRequest->id,
                            'service_requirement_id' => $requirementData['requirement_id'],
                            'value' => $value,
                            'file_path' => $filePath
                        ]);
                    }
                }

                // Provider notification logic
                $notifiedCount = $this->providerNotifier->findAndNotifyProviders($serviceRequest, 1);

                if ($notifiedCount === 0) {
                    $notifiedCount = $this->providerNotifier->findAndNotifyProviders($serviceRequest, 3);
                    $serviceRequest->update(['current_search_radius' => 3]);

                    if ($notifiedCount === 0) {
                        $notifiedCount = $this->providerNotifier->findAndNotifyProviders($serviceRequest, 5);
                        $serviceRequest->update(['current_search_radius' => 5]);

                        if ($notifiedCount === 0) {
                            DB::rollBack();
                            return $this->error('No available providers found for this service in your area', 400);
                        }
                    }
                } else {
                    LogService::requests('info', 'Scheduling request expansion job', [
                        'request_id' => $serviceRequest->id,
                        'providers_notified' => $notifiedCount,
                    ]);
                    ExpandRequestSearchRadius::dispatch($serviceRequest, 1)
                        ->delay(now()->addSeconds(10))
                        ->onQueue('request_expansion');
                }

                DB::commit();

                return $this->success([
                    'request' => $serviceRequest->load('services')
                ], 'Request created successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                LogService::exception($e, [
                    'action' => 'request_creation',
                    'user_id' => $user->id
                ]);
                return $this->error('Failed to create request: ' . $e->getMessage(), 500);
            }
        } catch (\Exception $e) {
            LogService::exception($e, [
                'action' => 'request_processing',
                'request_id' => $request->id
            ]);
            return $this->error('Failed to process request: ' . $e->getMessage(), 500);
        }
    }

    // function to add service to the current request 
    public function addServiceToRequest(Request $request, $id)
    {
        try {
            $user = Auth::user();

            if ($user->user_type !== 'customer') {
                return $this->error('Only customers can modify requests', 403);
            }

            if (!$user->customer) {
                return $this->error('Customer profile not found', 404);
            }

            // Handle JSON string input
            $serviceIds = json_decode($request->service_ids, true) ?? [];
            $request->merge(['service_ids' => $serviceIds]);

            $validator = Validator::make($request->all(), [
                'service_ids' => 'required|array|min:1',
                'service_ids.*' => 'exists:services,id',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }

            $serviceRequest = ServiceRequest::with(['services.category'])
                ->where('customer_id', $user->customer->id)
                ->whereIn('status', ['pending', 'accepted'])
                ->find($id);

            if (!$serviceRequest) {
                return $this->error('Request not found or not modifiable', 404);
            }

            $newServices = Service::with('category')->whereIn('id', $serviceIds)->get();

            // Check if all services exist
            if ($newServices->count() !== count($serviceIds)) {
                return $this->error('One or more services not found', 404);
            }

            $existingCategory = $serviceRequest->services->first()->category;

            // Check if new services belong to same category
            foreach ($newServices as $newService) {
                if ($newService->category_id !== $existingCategory->id) {
                    return $this->error('All services must belong to the same category as existing services', 400);
                }
            }

            if (!$existingCategory->is_multiple && ($serviceRequest->services->count() + count($serviceIds)) > 1) {
                return $this->error('This category does not support multiple services', 400);
            }

            // Check for duplicate services
            $existingServiceIds = $serviceRequest->services->pluck('id')->toArray();
            $duplicates = array_intersect($existingServiceIds, $serviceIds);
            if (!empty($duplicates)) {
                return $this->error('One or more services already exist in this request', 400);
            }

            DB::beginTransaction();

            try {
                // Attach new services with their prices
                $serviceRequest->services()->attach(
                    $newServices->mapWithKeys(function ($service) {
                        return [$service->id => ['price' => $service->price]];
                    })
                );

                // Calculate new total price
                $newTotalPrice = $serviceRequest->services->sum('pivot.price') + $newServices->sum('price');
                $serviceRequest->total_price = $newTotalPrice;
                $serviceRequest->save();

                if ($serviceRequest->status === 'accepted' && $serviceRequest->assigned_provider_id) {
                    foreach ($newServices as $newService) {
                        $this->notifyProviderAboutServiceAddition($serviceRequest, $newService);
                    }
                }

                DB::commit();

                return $this->success([
                    'request' => $serviceRequest->fresh()->load('services')
                ], 'Services added successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                LogService::exception($e, [
                    'action' => 'add_services_to_request',
                    'request_id' => $request->id
                ]);
                return $this->error('Failed to add services to request: ' . $e->getMessage(), 500);
            }
        } catch (\Exception $e) {
            LogService::exception($e, [
                'action' => 'request_processing',
                'request_id' => $request->id
            ]);
            return $this->error('Failed to process request: ' . $e->getMessage(), 500);
        }
    }

    protected function notifyProviderAboutServiceAddition($serviceRequest, $newService)
    {
        $provider = $serviceRequest->assignedProvider;
        if (!$provider || !$provider->user->fcm_token) {
            LogService::fcmErrors('Provider or FCM token not found', [
                'request_id' => $serviceRequest->id,
                'provider_id' => $provider->id ?? null
            ]);
            return;
        }

        try {
            $this->firebaseService->sendToDevice(
                $provider->user->fcm_token,
                'Service Added to Request',
                'A new service has been added to your assigned request: ' . $newService->name,
                [
                    'type' => 'service_added',
                    'request_id' => $serviceRequest->id,
                    'service_id' => $newService->id,
                    'service_name' => $newService->name,
                    'new_total_price' => $serviceRequest->total_price
                ]
            );
        } catch (\Exception $e) {
            LogService::exception($e, [
                'action' => 'notify_provider_service_addition',
                'request_id' => $serviceRequest->id,
                'provider_id' => $provider->id
            ]);
        }
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
                'services:id,name,price',
                'customer.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider:id,provider_type,user_id',
                'feedbacks',
                'complaints'
            ])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($request) {
                    $formatted = [
                        'id' => $request->id,
                        'customer_id' => $request->customer_id,
                        'services' => $request->services->map(function ($service) {
                            return [
                                'id' => $service->id,
                                'name' => $service->name,
                                'price' => $service->pivot->price
                            ];
                        }),
                        'total_price' => $request->total_price,
                        'phone' => $request->phone,
                        'status' => $request->status,
                        'created_at' => $request->created_at,
                        'customer' => [
                            'first_name' => $request->customer->user->first_name,
                            'last_name' => $request->customer->user->last_name,
                            'phone' => $request->customer->user->phone,
                            'profile_image' => $request->customer->user->profile_image
                        ],
                        'assigned_provider' => $request->assignedProvider ? [
                            'id' => $request->assignedProvider->id,
                            'first_name' => $request->assignedProvider->user->first_name,
                            'last_name' => $request->assignedProvider->user->last_name,
                            'provider_type' => $request->assignedProvider->provider_type,
                            'phone' => $request->assignedProvider->user->phone,
                            'profile_image' => $request->assignedProvider->user->profile_image
                        ] : null,
                        'feedbacks' => $request->feedbacks,
                        'complaints_count' => $request->complaints->count(),
                        'additional_info' => $request->additional_info,
                        'location' => [
                            'latitude' => $request->latitude,
                            'longitude' => $request->longitude
                        ]
                    ];
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

            if (!in_array($user->user_type, ['customer', 'provider'])) {
                return $this->error('Unauthorized access', 403);
            }

            if ($user->user_type === 'customer' && !$user->customer) {
                return $this->error('Customer profile not found', 404);
            }

            if ($user->user_type === 'provider' && !$user->provider) {
                return $this->error('Provider profile not found', 404);
            }

            $requests = ServiceRequest::with([
                'services:id,name,price,category_id',
                'services.category:id,is_multiple,name',
                'assignedProvider.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider:id,provider_type,user_id',
                'customer.user:id,first_name,last_name,phone,profile_image', // Added customer relationship
                'feedbacks',
                'cancellations'
            ])
                ->when($user->user_type === 'customer', function ($query) use ($user) {
                    return $query->where('customer_id', $user->customer->id);
                })
                ->when($user->user_type === 'provider', function ($query) use ($user) {
                    return $query->where('assigned_provider_id', $user->provider->id);
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($request) use ($user) {
                    $baseData = [
                        'id' => $request->id,
                        'services' => $request->services->map(function ($service) {
                            return [
                                'id' => $service->id,
                                'name' => $service->name,
                                'price' => $service->price,
                                'category' => [
                                    'id' => $service->category->id,
                                    'name' => $service->category->name,
                                    'is_multiple' => $service->category->is_multiple
                                ]
                            ];
                        }),
                        'total_price' => $request->total_price,
                        'status' => $request->status,
                        'created_at' => $request->created_at,
                        'additional_info' => $request->additional_info,
                        'location' => [
                            'latitude' => $request->latitude,
                            'longitude' => $request->longitude
                        ],
                        'feedbacks' => $request->feedbacks,
                        'cancellations' => $request->cancellations
                    ];

                    // Add provider data for customers
                    if ($user->user_type === 'customer' && $request->assignedProvider) {
                        $baseData['provider'] = [
                            'id' => $request->assignedProvider->id,
                            'name' => $request->assignedProvider->user->first_name . ' ' . $request->assignedProvider->user->last_name,
                            'phone' => $request->assignedProvider->user->phone,
                            'profile_image' => $request->assignedProvider->user->profile_image,
                            'provider_type' => $request->assignedProvider->provider_type,
                            'rating' => $request->assignedProvider->average_rating ?? 0
                        ];
                    }

                    // Add customer data for providers
                    if ($user->user_type === 'provider' && $request->customer) {
                        $baseData['customer'] = [
                            'id' => $request->customer->id,
                            'name' => $request->customer->user->first_name . ' ' . $request->customer->user->last_name,
                            'phone' => $request->customer->user->phone,
                            'profile_image' => $request->customer->user->profile_image,
                            'gender' => $request->gender,
                            'age' => $request->age
                        ];
                    }

                    return $baseData;
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
                'services:id,name,price,category_id',
                'services.category:id,is_multiple,name',
                'services.requirements:id,service_id,name,type', // Add service requirements
                'assignedProvider.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider:id,provider_type,user_id',
                'customer.user:id,first_name,last_name,phone,profile_image',
                'feedbacks.user:id,first_name,last_name,profile_image',
                'cancellations',
                'complaints.user:id,first_name,last_name,profile_image',
                'requirements.serviceRequirement:id,service_id,name,type' // Add request requirements
            ])->find($id);

            if (!$request) {
                return $this->error('Request not found', 404);
            }

            $user = Auth::user();

            // Authorization checks
            if ($user->user_type === 'customer' && (!$user->customer || $request->customer_id !== $user->customer->id)) {
                return $this->error('Unauthorized', 403);
            }

            if (
                $user->user_type === 'provider' && (!$user->provider ||
                    ($request->assigned_provider_id !== $user->provider->id &&
                        !RequestProvider::where('request_id', $id)
                            ->where('provider_id', $user->provider->id)
                            ->exists()))
            ) {
                return $this->error('Unauthorized', 403);
            }

            // Format the response based on who's viewing
            $formattedData = $this->formatRequestDetails($request, $user->user_type);

            // Add Google Maps link to the location data
            if (isset($formattedData['location']) && $request->latitude && $request->longitude) {
                $formattedData['location']['google_maps_link'] = sprintf(
                    'https://www.google.com/maps/search/?api=1&query=%s,%s',
                    $request->latitude,
                    $request->longitude
                );
            }

            return $this->success($formattedData, 'Request details fetched successfully');
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
                'feedback' => 'nullable|string|max:1000',
            ]);

            // return first error only
            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }

            $feedback = new RequestFeedback([
                'user_id' => $user->id,
                'rating' => $request->rating,
                'comment' => $request->feedback,
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

            // Verify user is either a customer or provider
            if (!in_array($user->user_type, ['customer', 'provider'])) {
                return $this->error('Only customers or providers can submit complaints', 403);
            }

            // Verify user profile exists
            if (
                ($user->user_type === 'customer' && !$user->customer) ||
                ($user->user_type === 'provider' && !$user->provider)
            ) {
                return $this->error('User profile not found', 404);
            }

            $serviceRequest = ServiceRequest::with(['customer', 'assignedProvider'])->find($id);
            if (!$serviceRequest) {
                return $this->error('Request not found', 404);
            }

            // Verify user is associated with the request
            if ($user->user_type === 'customer' && $serviceRequest->customer_id !== $user->customer->id) {
                return $this->error('You can only submit complaints for your own requests', 403);
            }

            if ($user->user_type === 'provider' && $serviceRequest->assigned_provider_id !== $user->provider->id) {
                return $this->error('You can only submit complaints for requests assigned to you', 403);
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
            $serviceRequest = ServiceRequest::with(['customer.user', 'assignedProvider.user'])
                ->find($id);
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
            DB::beginTransaction();
            // Create cancellation log
            $cancellation = new RequestCancellationLog([
                'cancelled_by' => $isCustomer ? 'customer' : 'provider',
                'reason' => $request->reason,
                'is_after_acceptance' => $serviceRequest->getRawOriginal('status') === 'accepted' ? 1 : 0,
                'cancelled_at' => now(),
            ]);
            $serviceRequest->cancellations()->save($cancellation);
            // Update request status
            $serviceRequest->status = 'cancelled';
            $serviceRequest->save();
            // Get all providers who were notified about this request and map to user IDs
            $notifiedProviderUserIds = RequestProvider::where('request_id', $serviceRequest->id)
                ->join('providers', 'request_providers.provider_id', '=', 'providers.id')
                ->pluck('providers.user_id');
            // Delete the request from all providers' Firestore collections using user IDs
            $this->deleteRequestFromProviders($serviceRequest, $notifiedProviderUserIds);
            // Send notifications
            $this->handleCancellationNotifications($serviceRequest, $user);
            DB::commit();
            return $this->success(null, 'Request cancelled successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            LogService::exception($e, [
                'action' => 'cancel_request',
                'request_id' => $request->id,
                'user_id' => $user->id
            ]);
            return $this->error('Failed to cancel request: ' . $e->getMessage(), 500);
        }
    }

    protected function deleteRequestFromProviders(ServiceRequest $serviceRequest, $providerUserIds)
    {
        try {
            foreach ($providerUserIds as $userId) {
                $this->firestoreService->deleteDocument(
                    'provider_requests/' . $userId . '/notifications',
                    (string) $serviceRequest->id
                );
            }
            LogService::firestore('info', 'Request deleted from providers Firestore', [
                'request_id' => $serviceRequest->id,
                'providers_count' => count($providerUserIds)
            ]);
            return true;
        } catch (\Exception $e) {
            LogService::firestore('debug', 'Failed to delete request from providers Firestore', [
                'request_id' => $serviceRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // function to get the ongoing requests of a customer [status is accepted or pending]
    public function getOngoingRequests()
    {
        try {
            $user = Auth::user();

            if (!in_array($user->user_type, ['customer', 'provider'])) {
                return $this->error('Only customers or providers can view requests', 403);
            }

            if ($user->user_type === 'customer' && !$user->customer) {
                return $this->error('Customer profile not found', 404);
            }

            if ($user->user_type === 'provider' && !$user->provider) {
                return $this->error('Provider profile not found', 404);
            }

            $requests = ServiceRequest::with([
                'services:id,name',
                'requirements.serviceRequirement:id,name,type',
                'assignedProvider.user:id,first_name,last_name,profile_image',
                'customer.user:id,first_name,last_name,profile_image'
            ])
                ->when($user->user_type === 'customer', function ($query) use ($user) {
                    return $query->where('customer_id', $user->customer->id);
                })
                ->when($user->user_type === 'provider', function ($query) use ($user) {
                    return $query->where('assigned_provider_id', $user->provider->id);
                })
                ->whereIn('status', ['pending', 'accepted'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($request) use ($user) {
                    $baseData = [
                        'id' => $request->id,
                        'services' => $request->services->map(function ($service) {
                            return [
                                'id' => $service->id,
                                'name' => $service->name,
                                'price' => $service->pivot->price
                            ];
                        }),
                        // return the category name for first service only
                        'category' => $request->services->first()->category->name ?? null,
                        'total_price' => $request->total_price,
                        'status' => $request->status,
                        'created_at' => $request->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $request->updated_at->format('Y-m-d H:i:s'),
                        'location' => [
                            'latitude' => $request->latitude,
                            'longitude' => $request->longitude
                        ],
                        'requirements' => $request->requirements->map(function ($requirement) {
                            return [
                                'id' => $requirement->id,
                                'name' => $requirement->serviceRequirement->name ?? 'Unknown',
                                'type' => $requirement->serviceRequirement->type ?? 'input',
                                'value' => $requirement->value,
                                'file_url' => $requirement->file_path ? asset('storage/' . $requirement->file_path) : null
                            ];
                        })
                    ];

                    // Add provider data for customer requests
                    if ($user->user_type === 'customer') {
                        $baseData['provider'] = $request->assignedProvider ? [
                            'id' => $request->assignedProvider->id,
                            'name' => $request->assignedProvider->user->first_name . ' ' . $request->assignedProvider->user->last_name,
                            'image' => $request->assignedProvider->user->profile_image ? $request->assignedProvider->user->profile_image : null
                        ] : null;
                    }

                    // Add customer data for provider requests
                    if ($user->user_type === 'provider') {
                        $baseData['customer'] = $request->customer ? [
                            'id' => $request->customer->id,
                            'name' => $request->customer->user->first_name . ' ' . $request->customer->user->last_name,
                            'image' => $request->customer->user->profile_image ? $request->customer->user->profile_image : null,
                            'phone' => $request->phone,
                            'additional_info' => $request->additional_info
                        ] : null;
                    }

                    return $baseData;
                });

            return $this->success($requests, 'Ongoing requests retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve ongoing requests: ' . $e->getMessage(), 500);
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
            LogService::fcmErrors('Attempted to send notification with empty token', [
                'title' => $title,
                'body' => $body
            ]);
            return ['error' => 'Empty FCM token'];
        }

        try {
            $response = $this->firebaseService->sendToDevice($token, $title, $body, $data);

            if (!isset($response['success']) || $response['success'] === 0) {
                $error = $response['results'][0]['error'] ?? 'Unknown FCM error';
                LogService::fcmErrors('FCM delivery failed', [
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
            LogService::fcmErrors('FCM service error', [
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
            'services' => $request->services->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'price' => $service->pivot->price
                ];
            }),
            'category' => [
                'id' => $request->services->first()->category_id,
                'is_multiple' => $request->services->first()->category->is_multiple,
                'name' => $request->services->first()->category->name,
            ],
            'total_price' => $request->total_price,
            'phone' => $request->phone,
            'address' => $request->address,
            'status' => $request->status,
            'assigned_provider' => $request->assignedProvider ? [
                'first_name' => $request->assignedProvider->user->first_name,
                'last_name' => $request->assignedProvider->user->last_name,
                'provider_type' => $request->assignedProvider->provider_type,
                'phone' => $request->assignedProvider->user->phone,
                'profile_image' => $request->assignedProvider->user->profile_image
            ] : null,
        ];
    }

    protected function formatRequestDetails(ServiceRequest $request, $userType)
    {
        $baseData = [
            'id' => $request->id,
            'services' => $request->services->map(function ($service) use ($request) {
                $serviceData = [
                    'id' => $service->id,
                    'name' => $service->name,
                    'price' => $service->pivot->price,
                    'category' => [
                        'id' => $service->category->id,
                        'name' => $service->category->name,
                        'is_multiple' => $service->category->is_multiple
                    ],
                    'requirements' => $service->requirements->map(function ($requirement) {
                        return [
                            'id' => $requirement->id,
                            'name' => $requirement->name,
                            'type' => $requirement->type
                        ];
                    })
                ];
                return $serviceData;
            }),
            'total_price' => $request->total_price,
            'status' => $request->status,
            'created_at' => $request->created_at,
            'additional_info' => $request->additional_info,
            'location' => [
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ],
            'requirements' => $request->requirements->map(function ($requirement) {
                return [
                    'id' => $requirement->id,
                    'requirement_id' => $requirement->service_requirement_id,
                    'name' => $requirement->serviceRequirement->name,
                    'type' => $requirement->serviceRequirement->type,
                    'value' => $requirement->value,
                    'file_url' => $requirement->file_path ? asset('storage/' . $requirement->file_path) : null
                ];
            }),
            'feedbacks' => $request->feedbacks->map(function ($feedback) {
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
            }),
            'cancellations' => $request->cancellations,
            'complaints' => $request->complaints->map(function ($complaint) {
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
            })
        ];

        // Add provider data for customers
        if ($userType === 'customer' && $request->assignedProvider) {
            $baseData['provider'] = [
                'id' => $request->assignedProvider->id,
                'name' => $request->assignedProvider->user->first_name . ' ' . $request->assignedProvider->user->last_name,
                'phone' => $request->assignedProvider->user->phone,
                'profile_image' => $request->assignedProvider->user->profile_image,
                'provider_type' => $request->assignedProvider->provider_type,
                'rating' => $request->assignedProvider->average_rating ?? 0
            ];
        }

        // Add customer data for providers
        if ($userType === 'provider' && $request->customer) {
            $baseData['customer'] = [
                'id' => $request->customer->id,
                'name' => $request->customer->user->first_name . ' ' . $request->customer->user->last_name,
                'phone' => $request->customer->user->phone,
                'profile_image' => $request->customer->user->profile_image,
                'gender' => $request->gender,
                'age' => $request->age
            ];
        }

        return $baseData;
    }
}
