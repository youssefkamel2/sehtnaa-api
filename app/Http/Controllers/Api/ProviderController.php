<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Provider;
use App\Models\Complaint;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\RequestFeedback;
use App\Models\RequestProvider;
use Illuminate\Validation\Rule;
use App\Models\ProviderDocument;
use App\Models\RequiredDocument;
use App\Services\FirebaseService;
use App\Services\FirestoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Request as ServiceRequest;
use Illuminate\Support\Facades\Validator;

class ProviderController extends Controller
{
    use ResponseTrait;

    protected $firebaseService;
    protected $firestoreService;

    public function __construct(FirebaseService $firebaseService, FirestoreService $firestoreService)
    {
        $this->firebaseService = $firebaseService;
        $this->firestoreService = $firestoreService;
    }


public function acceptRequest(Request $request, $requestId)
{
    try {
        $provider = Auth::user()->provider;

        if (!$provider) {
            return $this->error('Provider account not found', 404);
        }

        
        // Get and validate the request with services
        $serviceRequest = ServiceRequest::with(['customer.user', 'services'])
            ->where('status', 'pending')
            ->find($requestId);

        if (!$serviceRequest) {
            return $this->error('Request not found or already accepted', 404);
        }

        // Validate input for organizational providers
        $validator = Validator::make($request->all(), [
            'price' => [
                Rule::requiredIf(function () use ($provider) {
                    return $provider->provider_type === 'organizational';
                }),
                'numeric',
                'min:0'
            ]
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        // Check if provider type matches service requirements
        $primaryService = $serviceRequest->services->first();
        if ($primaryService && $primaryService->provider_type !== $provider->provider_type) {
            return $this->error('Provider type does not match service requirements', 400);
        }

        // For organizational providers, update the total price
        if ($provider->provider_type === 'organizational') {
            $serviceRequest->update([
                'total_price' => $request->price
            ]);
        }

        // Get all providers who were notified about this request
        $notifiedProviders = RequestProvider::where('request_id', $serviceRequest->id)
            ->join('providers', 'request_providers.provider_id', '=', 'providers.id')
            ->pluck('providers.user_id');

        // Update request status and assign provider
        $serviceRequest->update([
            'status' => 'accepted',
            'assigned_provider_id' => $provider->id,
            'started_at' => now()
        ]);

        // Send notification to customer
        $this->sendRequestAcceptedNotification($serviceRequest, $provider);

        // Send real-time update to customer
        $this->sendRequestAcceptedRealTimeUpdate($serviceRequest, $provider);

        // update provider is_available to be false
        $provider->update([
            'is_available' => false
        ]);

        // Delete the request from all providers' Firestore collections
        $this->deleteRequestFromProviders($serviceRequest, $notifiedProviders);

        DB::commit();

        return $this->success([
            'request_id' => $serviceRequest->id,
            'status' => 'accepted',
            'provider_id' => $provider->id,
            'total_price' => $serviceRequest->total_price
        ], 'Request accepted successfully');
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('ProviderController::acceptRequest - ' . $e->getMessage());
        return $this->error('Failed to accept request: ' . $e->getMessage(), 500);
    }
}


    protected function deleteRequestFromProviders(ServiceRequest $serviceRequest, $providerIds)
    {
        try {
            foreach ($providerIds as $providerId) {
                $this->firestoreService->deleteDocument(
                    'provider_requests/' . $providerId . '/notifications',
                    (string) $serviceRequest->id
                );
            }

            Log::channel('firestore')->info('Request deleted from providers Firestore', [
                'request_id' => $serviceRequest->id,
                'providers_count' => count($providerIds)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::channel('firestore_errors')->error('Failed to delete request from providers Firestore', [
                'request_id' => $serviceRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }


    protected function sendRequestAcceptedNotification(ServiceRequest $serviceRequest, Provider $provider)
    {
        try {
            $customer = $serviceRequest->customer->user;

            if (empty($customer->fcm_token)) {
                Log::channel('fcm_errors')->warning('No FCM token for customer', [
                    'customer_id' => $customer->id,
                    'request_id' => $serviceRequest->id
                ]);
                return false;
            }

            $notificationData = [
                'type' => 'request_accepted',
                'request_id' => $serviceRequest->id,
                'provider_id' => $provider->id,
                'provider_name' => $provider->user->first_name ?? 'Provider',
                'timestamp' => now()->toDateTimeString()
            ];

            $response = $this->firebaseService->sendToDevice(
                $customer->fcm_token,
                'Request Accepted',
                'Your request has been accepted by a provider',
                $notificationData
            );

            if (!isset($response['success']) || $response['success'] === 0) {
                throw new \Exception($response['results'][0]['error'] ?? 'Unknown FCM error');
            }

            Log::channel('fcm_errors')->info('Request acceptance notification sent', [
                'request_id' => $serviceRequest->id,
                'customer_id' => $customer->id,
                'provider_id' => $provider->id,
                'response' => $response
            ]);

            return true;
        } catch (\Exception $e) {
            Log::channel('fcm_errors')->error('Failed to send request acceptance notification', [
                'request_id' => $serviceRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }


    protected function sendRequestAcceptedRealTimeUpdate(ServiceRequest $serviceRequest, Provider $provider)
    {
        try {

            $customerId = $serviceRequest->customer->user_id;
            $documentId = 'accepted_' . $serviceRequest->id;

            $providerData = [
                'provider_id' => $provider->id,
                'name' => $provider->user->first_name ?? 'Provider',
                'phone' => $provider->user->phone ?? '',
                'profile_image' => $provider->user->profile_image ?? '',
                'provider_type' => $provider->provider_type,
                'rating' => $provider->average_rating ?? 0,
                'distance' => $this->calculateDistance(
                    $serviceRequest->latitude,
                    $serviceRequest->longitude,
                    $provider->user->latitude,
                    $provider->user->longitude
                ) . ' km'
            ];

            $firestoreData = [
                'request_id' => (string) $serviceRequest->id,
                'status' => 'accepted',
                'provider' => $providerData,
                'accepted_at' => now()->toDateTimeString(),
                'total_price' => $serviceRequest->total_price,
                'expected_time' => $this->calculateExpectedTime($serviceRequest, $provider)
            ];

            $result = $this->firestoreService->createDocument(
                'customer_requests/' . $customerId . '/updates',
                $documentId,
                $firestoreData
            );

            Log::channel('firestore')->info('Request acceptance real-time update sent', [
                'request_id' => $serviceRequest->id,
                'customer_id' => $customerId,
                'data' => $firestoreData,
                'response' => $result
            ]);

            return true;
        } catch (\Exception $e) {
            Log::channel('firestore_errors')->error('Failed to send real-time acceptance update', [
                'request_id' => $serviceRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }


    protected function calculateExpectedTime(ServiceRequest $serviceRequest, Provider $provider)
    {
        // This is a simplified example - adjust based on your business logic
        $baseTime = 30; // minutes
        $distance = $this->calculateDistance(
            $serviceRequest->latitude,
            $serviceRequest->longitude,
            $provider->user->latitude,
            $provider->user->longitude
        );

        $travelTime = $distance * 2; // 2 minutes per km
        return now()->addMinutes($baseTime + $travelTime)->toDateTimeString();
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
        return round($earthRadius * $c, 2);
    }


    public function completeRequest(Request $request, $requestId)
    {
        try {
            $provider = Auth::user()->provider;

            if (!$provider) {
                return $this->error('Provider account not found', 404);
            }

            DB::beginTransaction();

            // Get and validate the request
            $serviceRequest = ServiceRequest::with(['customer.user', 'services'])
                ->where('status', 'accepted')
                ->where('assigned_provider_id', $provider->id)
                ->find($requestId);

            if (!$serviceRequest) {
                return $this->error('Request not found or not assigned to you', 404);
            }

            // Update request status and completion time
            $serviceRequest->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);

            // Make provider available again
            $provider->update([
                'is_available' => true
            ]);

            // Send notification to customer
            $this->sendRequestCompletedNotification($serviceRequest, $provider);

            DB::commit();

            return $this->success([
                'request_id' => $serviceRequest->id,
                'status' => 'completed',
                'completed_at' => $serviceRequest->completed_at,
                'provider_id' => $provider->id
            ], 'Request marked as completed successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ProviderController::completeRequest - ' . $e->getMessage());
            return $this->error('Failed to complete request: ' . $e->getMessage(), 500);
        }
    }
    protected function sendRequestCompletedNotification(ServiceRequest $serviceRequest, Provider $provider)
    {
        try {
            $customer = $serviceRequest->customer->user;

            if (empty($customer->fcm_token)) {
                Log::channel('fcm_errors')->error('No FCM token for customer', [
                    'customer_id' => $customer->id,
                    'request_id' => $serviceRequest->id
                ]);
                return false;
            }

            $notificationData = [
                'type' => 'request_completed',
                'request_id' => $serviceRequest->id,
                'provider_id' => $provider->id,
                'provider_name' => $provider->user->first_name ?? 'Provider',
                'timestamp' => now()->toDateTimeString()
            ];

            $response = $this->firebaseService->sendToDevice(
                $customer->fcm_token,
                'Request Completed',
                'Your service request has been completed',
                $notificationData
            );

            if (!isset($response['success']) || $response['success'] === 0) {
                throw new \Exception($response['results'][0]['error'] ?? 'Unknown FCM error');
            }

            Log::channel('fcm_debug')->debug('Request completion notification sent', [
                'request_id' => $serviceRequest->id,
                'customer_id' => $customer->id,
                'provider_id' => $provider->id,
                'response' => $response
            ]);

            return true;

        } catch (\Exception $e) {
            Log::channel('fcm_errors')->error('Failed to send request completion notification', [
                'request_id' => $serviceRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // function to set provider availability
    public function setAvailability(Request $request)
    {
        try {

            $user = $request->user();

            // Verify user is a provider
            if ($user->user_type !== 'provider') {
                return $this->error('Only providers can set availability', 403);
            }

            // Verify provider exists
            if (!$user->provider) {
                return $this->error('Provider profile not found', 404);
            }

            $validator = Validator::make($request->all(), [
                'is_available' => 'required|boolean',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180'
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            // Update provider location
            $user->update([
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ]);


            // Update provider availability
            $user->provider->update([
                'is_available' => $request->is_available
            ]);

            DB::commit();

            return $this->success([
                'is_available' => (bool)$user->provider->is_available,
                'latitude' => $user->latitude,
                'longitude' => $user->longitude
            ], 'Provider availability updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ProviderController::setAvailability - ' . $e->getMessage());
            return $this->error('Failed to update provider availability: ' . $e->getMessage(), 500);
        }
    }

    public function getAllProviders(Request $request)
    {
        try {
            $providers = Provider::with([
                'user:id,first_name,last_name,email,phone,status',
                'documents.requiredDocument:id,name'
            ])
                ->get()
                ->groupBy('provider_type');

            return $this->success([
                'individual' => $providers->get('individual', []),
                'organizational' => $providers->get('organizational', [])
            ], 'Providers retrieved successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::getAllProviders - ' . $e->getMessage());
            return $this->error('Failed to retrieve providers. Please try again later.', 500);
        }
    }

    // change provider status
    public function changeStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'provider_id' => 'required|exists:providers,id',
                'status' => 'required|in:pending,active,de-active'
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            $provider = Provider::with('user')->find($request->provider_id);

            if (!$provider) {
                return $this->error('Provider not found', 404);
            }

            $provider->user()->update([
                'status' => $request->status
            ]);

            return $this->success([
                'provider_id' => $provider->id,
                'status' => $request->status
            ], 'Provider status updated successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::changeStatus - ' . $e->getMessage());
            return $this->error('Failed to update provider status', 500);
        }
    }

    public function uploadDocument(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'required_document_id' => 'required|exists:required_documents,id',
                'document' => 'required|file|mimes:pdf,jpg,png|max:2048',
            ]);

            if ($validator->fails()) {
                Log::warning('Document upload validation failed', ['errors' => $validator->errors()]);
                return $this->error($validator->errors()->first(), 400);
            }

            $user = User::where('email', $request->email)->firstOrFail();

            if (!$user->provider) {
                Log::warning('Provider not found for user', ['user_id' => $user->id]);
                return $this->error('Provider account not found.', 404);
            }

            $provider = $user->provider;
            $requiredDocument = RequiredDocument::findOrFail($request->required_document_id);

            if ($requiredDocument->provider_type !== $provider->provider_type) {
                Log::warning('Document type mismatch', [
                    'required_type' => $requiredDocument->provider_type,
                    'provider_type' => $provider->provider_type
                ]);
                return $this->error('This document is not required for your provider type.', 400);
            }

            // Delete old file if exists
            $existingDocument = ProviderDocument::where([
                'provider_id' => $provider->id,
                'required_document_id' => $requiredDocument->id
            ])->first();

            if ($existingDocument) {
                Storage::disk('public')->delete($existingDocument->document_path);
            }

            // Store new file
            $documentPath = $request->file('document')->store('provider_documents', 'public');

            // Update or create record
            ProviderDocument::updateOrCreate(
                [
                    'provider_id' => $provider->id,
                    'required_document_id' => $requiredDocument->id
                ],
                [
                    'document_path' => $documentPath,
                    'status' => 'pending'
                ]
            );

            Log::info('Document uploaded successfully', [
                'provider_id' => $provider->id,
                'document_id' => $requiredDocument->id
            ]);

            return $this->success(null, 'Document uploaded successfully. Waiting for admin approval.');
        } catch (\Exception $e) {
            Log::error('ProviderController::uploadDocument - ' . $e->getMessage());
            return $this->error('Failed to upload document. Please try again.', 500);
        }
    }

    public function listDocuments(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            $user = User::where('email', $request->email)->firstOrFail();

            if (!$user->provider) {
                return $this->error('Provider account not found.', 404);
            }

            $documents = $user->provider->documents()
                ->with(['requiredDocument:id,name'])
                ->get();

            return $this->success($documents, 'Documents retrieved successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::listDocuments - ' . $e->getMessage());
            return $this->error('Failed to retrieve documents. Please try again.', 500);
        }
    }

    public function documentStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            $user = User::where('email', $request->email)->firstOrFail();

            if (!$user->provider) {
                return $this->error('Provider account not found.', 404);
            }

            $provider = $user->provider;
            $requiredDocuments = RequiredDocument::where('provider_type', $provider->provider_type)->get();
            $uploadedDocuments = $provider->documents;

            $response = $this->calculateDocumentStatus($requiredDocuments, $uploadedDocuments);
            return $this->success($response, 'Document status retrieved successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::documentStatus - ' . $e->getMessage());
            return $this->error('Failed to retrieve document status. Please try again.', 500);
        }
    }

    public function getRequiredDocuments(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            $user = User::where('email', $request->email)->firstOrFail();

            if ($user->user_type !== 'provider') {
                return $this->error('The provided email does not belong to a provider.', 400);
            }

            $provider = $user->provider;
            $requiredDocuments = RequiredDocument::where('provider_type', $provider->provider_type)->get();
            $uploadedDocuments = $provider->documents;

            $remainingDocuments = $requiredDocuments->reject(function ($doc) use ($uploadedDocuments) {
                $uploaded = $uploadedDocuments->where('required_document_id', $doc->id)->first();
                return $uploaded && in_array($uploaded->status, ['approved', 'pending']);
            });

            return $this->success(
                $remainingDocuments->values()->all(),
                'Remaining required documents retrieved successfully'
            );
        } catch (\Exception $e) {
            Log::error('ProviderController::getRequiredDocuments - ' . $e->getMessage());
            return $this->error('Failed to retrieve required documents. Please try again.', 500);
        }
    }

    public function approveDocument(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'document_id' => 'required|exists:provider_documents,id',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            DB::beginTransaction();

            // Approve the document
            $document = ProviderDocument::findOrFail($request->document_id);
            $document->update([
                'status' => 'approved',
                'rejection_reason' => null // Clear any previous rejection reason
            ]);

            $provider = $document->provider;
            $user = $provider->user;

            // Check if all required documents are approved
            $this->checkAndUpdateProviderStatus($provider, $user);

            DB::commit();

            return $this->success(null, 'Document approved successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ProviderController::approveDocument - ' . $e->getMessage());
            return $this->error('Failed to approve document', 500);
        }
    }

    public function rejectDocument(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'document_id' => 'required|exists:provider_documents,id',
                'rejection_reason' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            // Reject the document with reason
            $document = ProviderDocument::findOrFail($request->document_id);
            $document->update([
                'status' => 'rejected',
                'rejection_reason' => $request->rejection_reason
            ]);

            // Update provider status to rejected if not already
            $provider = $document->provider;
            if ($provider->user->status !== 'de-active') {
                $provider->user()->update(['status' => 'de-active']);
            }

            return $this->success(null, 'Document rejected successfully.');
        } catch (\Exception $e) {
            Log::error('ProviderController::rejectDocument - ' . $e->getMessage());
            return $this->error('Failed to reject document', 500);
        }
    }

    /**
     * Helper method to check and update provider status
     */
    protected function checkAndUpdateProviderStatus($provider, $user)
    {
        $requiredDocuments = RequiredDocument::where('provider_type', $provider->provider_type)->get();
        $uploadedDocuments = $provider->documents;

        $allApproved = true;
        $hasMissing = false;

        foreach ($requiredDocuments as $requiredDoc) {
            $uploadedDoc = $uploadedDocuments->where('required_document_id', $requiredDoc->id)->first();

            if (!$uploadedDoc || $uploadedDoc->status !== 'approved') {
                $allApproved = false;
            }

            if (!$uploadedDoc) {
                $hasMissing = true;
            }
        }

        if ($allApproved && !$hasMissing) {
            $user->update(['status' => 'active']);

            // send notification
            if (empty($provider->user->fcm_token)) {
                Log::channel('fcm_errors')->warning('No FCM token for provider', [
                    'provider_id' => $provider->id
                ]);
                return false;
            }

            $response = $this->firebaseService->sendToDevice(
                $provider->user->fcm_token,
                'Account Approved',
                'Your account has been approved.'
            );

            if (!isset($response['success']) || $response['success'] === 0) {
                throw new \Exception($response['results'][0]['error'] ?? 'Unknown FCM error');
            }

            Log::channel('fcm_errors')->info('Request acceptance notification sent', [
                'provider_id' => $provider->id,
                'response' => $response
            ]);


            Log::info('Provider activated', ['provider_id' => $provider->id]);
        }
    }

    public function getAllRequiredDocuments(Request $request)
    {
        try {
            $documents = RequiredDocument::all();
            return $this->success($documents, 'Required documents retrieved successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::getAllRequiredDocuments - ' . $e->getMessage());
            return $this->error('Failed to retrieve required documents', 500);
        }
    }

    // add required documents for specific provider type [name, prover_type]
    public function addRequiredDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'provider_type' => 'required|in:individual,organizational',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        // Create the required document
        RequiredDocument::create([
            'name' => $request->name,
            'provider_type' => $request->provider_type,
        ]);

        return $this->success(null, 'Required document added successfully.');
    }
    public function updateRequiredDocument(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'provider_type' => 'sometimes|in:individual,organizational'
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            $document = RequiredDocument::findOrFail($id);
            $document->update($request->only(['name', 'provider_type']));

            return $this->success($document, 'Required document updated successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::updateRequiredDocument - ' . $e->getMessage());
            return $this->error('Failed to update required document', 500);
        }
    }
    public function deleteRequiredDocument($id)
    {
        try {
            $document = RequiredDocument::find($id);

            if (!$document) {
                return $this->error('Document not found', 404);
            }

            // Update related provider documents to store the document name
            ProviderDocument::where('required_document_id', $id)
                ->update([
                    'deleted_document_name' => $document->name,
                    'required_document_id' => null
                ]);

            $document->delete();

            return $this->success(null, 'Required document deleted successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::deleteRequiredDocument - ' . $e->getMessage());
            return $this->error('Failed to delete required document', 500);
        }
    }

    public function updateFcmToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'fcm_token' => 'required|string',
            'device_type' => 'sometimes|in:ios,android'
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $user = User::where('email', $request->email);
        if (!$user) {
            return $this->error('User not found', 404);
        }
        $user = $user->first();
        if ($user->user_type !== 'provider') {
            return $this->error('The provided email does not belong to a provider.', 400);
        }
        // Update the FCM token and device type
        $user->update([
            'fcm_token' => $request->fcm_token,
            'device_type' => $request->device_type ?? null
        ]);
        

        return $this->success(null, 'FCM token updated');
    }


    /**
     * Helper method to calculate document status
     */
    protected function calculateDocumentStatus($requiredDocuments, $uploadedDocuments)
    {
        $documents = [];
        $hasRejected = false;
        $hasPending = false;
        $allUploaded = true;
        $approvedCount = 0;

        foreach ($requiredDocuments as $requiredDoc) {
            $uploadedDoc = $uploadedDocuments->where('required_document_id', $requiredDoc->id)->first();

            $documents[] = [
                'document_id' => $uploadedDoc->id ?? null,
                'required_document_id' => $requiredDoc->id,
                'document_name' => $requiredDoc->name,
                'status' => $uploadedDoc->status ?? 'missing',
                'rejection_reason' => $uploadedDoc->rejection_reason ?? null,
            ];

            if ($uploadedDoc) {
                if ($uploadedDoc->status === 'rejected') $hasRejected = true;
                if ($uploadedDoc->status === 'pending') $hasPending = true;
                if ($uploadedDoc->status === 'approved') $approvedCount++;
            } else {
                $allUploaded = false;
            }
        }

        $accountStatus = $this->determineAccountStatus(
            $hasRejected,
            $hasPending,
            $allUploaded,
            $approvedCount,
            $requiredDocuments->count()
        );

        return [
            'documents' => $documents,
            'account_status' => $accountStatus,
            'upload_progress' => [
                'total_required' => $requiredDocuments->count(),
                'uploaded' => count(array_filter($documents, fn($doc) => $doc['status'] !== 'missing')),
                'approved' => $approvedCount,
                'pending' => $hasPending,
                'rejected' => $hasRejected
            ]
        ];
    }

    /**
     * Helper method to determine account status
     */
    protected function determineAccountStatus($hasRejected, $hasPending, $allUploaded, $approvedCount, $totalRequired)
    {
        if ($hasRejected) return 'rejected';
        if ($hasPending || !$allUploaded) return 'pending';
        if ($approvedCount === $totalRequired) return 'approved';
        return 'pending';
    }

    // get provider feedbacks

    public function getProviderFeedbacks(Request $request)
    {
        try {
            $user = Auth::user();

            // Verify user is a provider
            if ($user->user_type !== 'provider') {
                return $this->error('Only providers can view feedbacks', 403);
            }

            // Verify provider exists
            if (!$user->provider) {
                return $this->error('Provider profile not found', 404);
            }

            $provider = $user->provider;

            // Get all completed requests assigned to this provider
            $completedRequests = ServiceRequest::where('assigned_provider_id', $provider->id)
                ->where('status', 'completed')
                ->pluck('id');

            // Get all feedbacks for these requests with request and customer details
            $feedbacks = RequestFeedback::with([
                'request.services:id,name',
                'user:id,first_name,last_name,profile_image'
            ])
                ->whereIn('request_id', $completedRequests)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($feedback) {
                    return [
                        'id' => $feedback->id,
                        'rating' => $feedback->rating,
                        'comment' => $feedback->comment,
                        'created_at' => $feedback->created_at,
                        'customer' => [
                            'name' => $feedback->user->first_name . ' ' . $feedback->user->last_name,
                            'profile_image' => $feedback->user->profile_image
                        ],
                        'request' => [
                            'id' => $feedback->request->id,
                            'services' => $feedback->request->services->map(function ($service) {
                                return [
                                    'id' => $service->id,
                                    'name' => $service->name
                                ];
                            }),
                            'total_price' => $feedback->request->total_price,
                            'completed_at' => $feedback->request->completed_at
                        ]
                    ];
                });

            // Calculate average rating
            $averageRating = $feedbacks->avg('rating');
            $totalFeedbacks = $feedbacks->count();

            return $this->success([
                'average_rating' => round($averageRating, 1),
                'total_feedbacks' => $totalFeedbacks,
                'feedbacks' => $feedbacks
            ], 'Provider feedbacks retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve provider feedbacks: ' . $e->getMessage(), 500);
        }
    }

    // get provider complaints
    public function getProviderComplaints()
    {
        try {
            $user = Auth::user();

            // Verify user is a provider
            if ($user->user_type !== 'provider') {
                return $this->error('Only providers can view complaints', 403);
            }

            // Verify provider exists
            if (!$user->provider) {
                return $this->error('Provider profile not found', 404);
            }

            $provider = $user->provider;

            // Get all complaints submitted by this provider with request details
            $complaints = Complaint::with([
                'request.services:id,name',
                'request.customer.user:id,first_name,last_name,profile_image'
            ])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($complaint) {
                    // Add null checks for dates
                    $createdAt = $complaint->created_at ? $complaint->created_at->format('Y-m-d H:i:s') : null;
                    $updatedAt = $complaint->updated_at ? $complaint->updated_at->format('Y-m-d H:i:s') : null;
                    $requestCreatedAt = $complaint->request->created_at ? $complaint->request->created_at->format('Y-m-d H:i:s') : null;

                    return [
                        'id' => $complaint->id,
                        'subject' => $complaint->subject,
                        'description' => $complaint->description,
                        'status' => $complaint->status,
                        'response' => $complaint->response,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                        'request' => [
                            'id' => $complaint->request->id,
                            'services' => $complaint->request->services->map(function ($service) {
                                return [
                                    'id' => $service->id,
                                    'name' => $service->name
                                ];
                            }),
                            'total_price' => $complaint->request->total_price,
                            'status' => $complaint->request->status,
                            'created_at' => $requestCreatedAt
                        ],
                        'customer' => $complaint->request->customer ? [
                            'id' => $complaint->request->customer->id,
                            'name' => $complaint->request->customer->user->first_name . ' ' .
                                $complaint->request->customer->user->last_name,
                            'profile_image' => $complaint->request->customer->user->profile_image
                        ] : null
                    ];
                });

            // Filter out any null complaints (where request might be null)
            $complaints = $complaints->filter();

            // Add complaint statistics
            $stats = [
                'total_complaints' => $complaints->count(),
                'open_complaints' => $complaints->where('status', 'open')->count(),
                'resolved_complaints' => $complaints->where('status', 'resolved')->count(),
                'rejected_complaints' => $complaints->where('status', 'rejected')->count(),
            ];

            return $this->success([
                'statistics' => $stats,
                'complaints' => $complaints->values() // Reset keys after filter
            ], 'Provider complaints retrieved successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::getProviderComplaints - ' . $e->getMessage());
            return $this->error('Failed to retrieve provider complaints: ' . $e->getMessage(), 500);
        }
    }


    /**
     * Get provider analytics and performance metrics
     */
    public function getProviderAnalytics()
    {
        try {
            $user = Auth::user();

            // Verify user is a provider
            if ($user->user_type !== 'provider') {
                return $this->error('Only providers can view analytics', 403);
            }

            // Verify provider exists
            if (!$user->provider) {
                return $this->error('Provider profile not found', 404);
            }

            $provider = $user->provider;
            $providerId = $provider->id;

            // Helper function to ensure double format
            $toDouble = function ($value) {
                return is_numeric($value) ? (float)number_format((float)$value, 1, '.', '') : $value;
            };

            // Helper function to convert to integer
            $toInt = function ($value) {
                return is_numeric($value) ? (int)$value : $value;
            };

            // 1. Request Statistics
            $requestStats = [
                'total_requests' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)->count()),
                'completed_requests' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)
                    ->where('status', 'completed')
                    ->count()),
                'cancelled_requests' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)
                    ->where('status', 'cancelled')
                    ->count()),
                'current_month_requests' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)
                    ->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month)
                    ->count()),
                'previous_month_requests' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)
                    ->whereYear('created_at', now()->subMonth()->year)
                    ->whereMonth('created_at', now()->subMonth()->month)
                    ->count()),
            ];

            // 2. Earnings Analysis
            $earningsStats = [
                'total_earnings' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)
                    ->where('status', 'completed')
                    ->sum('total_price')),
                'current_month_earnings' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)
                    ->where('status', 'completed')
                    ->whereYear('completed_at', now()->year)
                    ->whereMonth('completed_at', now()->month)
                    ->sum('total_price')),
                'previous_month_earnings' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)
                    ->where('status', 'completed')
                    ->whereYear('completed_at', now()->subMonth()->year)
                    ->whereMonth('completed_at', now()->subMonth()->month)
                    ->sum('total_price')),
                'yearly_earnings' => $toDouble(ServiceRequest::where('assigned_provider_id', $providerId)
                    ->where('status', 'completed')
                    ->whereYear('completed_at', now()->year)
                    ->sum('total_price')),
            ];

            // 3. Rating and Feedback Analysis
            $completedRequests = ServiceRequest::where('assigned_provider_id', $providerId)
                ->where('status', 'completed')
                ->pluck('id');

            $feedbackStats = [
                'average_rating' => $toDouble(RequestFeedback::whereIn('request_id', $completedRequests)
                    ->avg('rating')),
                'total_feedbacks' => $toDouble(RequestFeedback::whereIn('request_id', $completedRequests)
                    ->count()),
                'rating_distribution' => RequestFeedback::whereIn('request_id', $completedRequests)
                    ->select('rating', DB::raw('count(*) as count'))
                    ->groupBy('rating')
                    ->orderBy('rating', 'desc')
                    ->get()
                    ->mapWithKeys(function ($item) use ($toDouble) {
                        return [(string)$item->rating => $toDouble($item->count)];
                    }),
            ];

            // 4. Monthly Trends (last 6 months)
            $monthlyTrends = ServiceRequest::where('assigned_provider_id', $providerId)
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->subMonths(6))
                ->select(
                    DB::raw("DATE_FORMAT(completed_at, '%Y-%m') as month"),
                    DB::raw('count(*) as request_count'),
                    DB::raw('sum(total_price) as total_earnings')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(function ($item) use ($toDouble) {
                    return [
                        'month' => $item->month,
                        'request_count' => $toDouble($item->request_count),
                        'total_earnings' => $toDouble($item->total_earnings)
                    ];
                });

            // 5. Performance Metrics - Convert specifically these three values to integer
            $performanceMetrics = [
                'average_completion_time' => $toInt($this->calculateAverageCompletionTime($providerId)),
                'acceptance_rate' => $toInt($this->calculateAcceptanceRate($providerId)),
                'cancellation_rate' => $toInt($this->calculateCancellationRate($providerId)),
            ];

            return $this->success([
                'request_statistics' => $requestStats,
                'earnings_analysis' => $earningsStats,
                'feedback_analysis' => $feedbackStats,
                'monthly_trends' => $monthlyTrends,
                'performance_metrics' => $performanceMetrics,
            ], 'Provider analytics retrieved successfully');
        } catch (\Exception $e) {
            Log::error('ProviderController::getProviderAnalytics - ' . $e->getMessage());
            return $this->error('Failed to retrieve provider analytics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper method to calculate average completion time in minutes
     */
    protected function calculateAverageCompletionTime($providerId)
    {
        $completedRequests = ServiceRequest::where('assigned_provider_id', $providerId)
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get();

        if ($completedRequests->isEmpty()) {
            return 0;
        }

        $totalMinutes = $completedRequests->sum(function ($request) {
            return $request->started_at->diffInMinutes($request->completed_at);
        });

        return (int)($totalMinutes / $completedRequests->count());
    }

    /**
     * Helper method to calculate acceptance rate (percentage)
     */
    protected function calculateAcceptanceRate($providerId)
    {
        $totalAssigned = ServiceRequest::where('assigned_provider_id', $providerId)->count();
        $completed = ServiceRequest::where('assigned_provider_id', $providerId)
            ->where('status', 'completed')
            ->count();

        if ($totalAssigned === 0) {
            return 0;
        }

        return (int)(($completed / $totalAssigned) * 100);
    }

    /**
     * Helper method to calculate cancellation rate (percentage)
     */
    protected function calculateCancellationRate($providerId)
    {
        $totalAssigned = ServiceRequest::where('assigned_provider_id', $providerId)->count();
        $cancelled = ServiceRequest::where('assigned_provider_id', $providerId)
            ->where('status', 'cancelled')
            ->count();

        if ($totalAssigned === 0) {
            return 0;
        }

        return (int)(($cancelled / $totalAssigned) * 100);
    }
}
