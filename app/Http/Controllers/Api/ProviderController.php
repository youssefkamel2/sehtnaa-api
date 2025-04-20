<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Provider;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\ProviderDocument;
use App\Models\RequiredDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\Request as ServiceRequest;
use Illuminate\Support\Facades\Auth;
use App\Services\FirestoreService;
use App\Services\FirebaseService;

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

    // accept request
    /**
     * Accept a service request
     */
    public function acceptRequest(Request $request, $requestId)
    {
        try {
            $provider = Auth::user()->provider;

            if (!$provider) {
                return $this->error('Provider account not found', 404);
            }

            DB::beginTransaction();

            // Get and validate the request
            $serviceRequest = ServiceRequest::with(['customer.user', 'service'])
                ->where('status', 'pending')
                ->find($requestId);

            if (!$serviceRequest) {
                return $this->error('Request not found or already accepted', 404);
            }

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

            // update provider is_avilable to be false
            $provider->update([
                'is_available' => false
            ]);


            DB::commit();

            return $this->success([
                'request_id' => $serviceRequest->id,
                'status' => 'accepted',
                'provider_id' => $provider->id
            ], 'Request accepted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ProviderController::acceptRequest - ' . $e->getMessage());
            return $this->error('Failed to accept request: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Send notification to customer about accepted request
     */
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
                'service_name' => $serviceRequest->service->name['en'] ?? $serviceRequest->service->name,
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

    /**
     * Send real-time update to customer via Firestore
     */
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
                'service_name' => $serviceRequest->service->name['en'] ?? $serviceRequest->service->name,
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

    /**
     * Calculate expected completion time (example implementation)
     */
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

    /**
     * Reuse the distance calculation from RequestController
     */
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
}
