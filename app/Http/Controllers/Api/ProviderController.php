<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Provider;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\ProviderDocument;
use App\Models\RequiredDocument;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class ProviderController extends Controller
{
    use ResponseTrait;

    // Upload or re-upload a document
    public function uploadDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email', // Identify the provider by email
            'required_document_id' => 'required|exists:required_documents,id',
            'document' => 'required|file|mimes:pdf,jpg,png|max:2048', // Adjust file types and size as needed
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        // Find the provider by email
        $user = User::where('email', $request->email)->first();
        $provider = $user->provider;

        if (!$provider) {
            return $this->error('Provider not found.', 404);
        }

        // Check if the document is required for the provider's type
        $requiredDocument = RequiredDocument::find($request->required_document_id);

        if ($requiredDocument->provider_type !== $provider->provider_type) {
            return $this->error('This document is not required for your provider type.', 400);
        }

        // Save the uploaded file
        $documentPath = $request->file('document')->store('provider_documents', 'public');

        // Create or update the provider document
        ProviderDocument::updateOrCreate(
            ['provider_id' => $provider->id, 'required_document_id' => $request->required_document_id],
            ['document_path' => $documentPath, 'status' => 'pending'] // Set status to 'pending' when re-uploading
        );

        return $this->success(null, 'Document uploaded successfully. Waiting for admin approval.');
    }

    // List all uploaded documents for the provider
    public function listDocuments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email', // Identify the provider by email
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        // Find the provider by email
        $user = User::where('email', $request->email)->first();
        $provider = $user->provider;

        if (!$provider) {
            return $this->error('Provider not found.', 404);
        }

        $documents = $provider->documents()->with('requiredDocument')->get();

        return $this->success($documents, 'Documents retrieved successfully');
    }

    // Get the status of uploaded documents
    public function documentStatus(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);
        
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }
        
        try {
            // Find the user and provider
            $user = User::where('email', $request->email)->first();
            
            if (!$user || !$user->provider) {
                return $this->error('Provider not found.', 404);
            }
            
            $provider = $user->provider;
            
            // Get required documents for the provider's type
            $requiredDocuments = RequiredDocument::where('provider_type', $provider->provider_type)->get();
            
            if ($requiredDocuments->isEmpty()) {
                return $this->success([
                    'documents' => [],
                    'account_status' => 'pending'
                ], 'No required documents found for this provider type');
            }
            
            // Get uploaded documents
            $uploadedDocuments = $provider->documents;
            
            // Track document statuses
            $hasRejected = false;
            $hasPending = false;
            $allDocumentsUploaded = true;
            $requiredCount = $requiredDocuments->count();
            $approvedCount = 0;
            
            // Prepare documents array
            $documents = [];
            
            foreach ($requiredDocuments as $requiredDocument) {
                $uploadedDocument = $uploadedDocuments->where('required_document_id', $requiredDocument->id)->first();
                
                if ($uploadedDocument) {
                    $documents[] = [
                        'document_id' => $uploadedDocument->id,
                        'required_document_id' => $requiredDocument->id,
                        'document_name' => $requiredDocument->name,
                        'status' => $uploadedDocument->status,
                    ];
                    
                    // Track status counts
                    switch ($uploadedDocument->status) {
                        case 'rejected':
                            $hasRejected = true;
                            break;
                        case 'pending':
                            $hasPending = true;
                            break;
                        case 'approved':
                            $approvedCount++;
                            break;
                    }
                } else {
                    $allDocumentsUploaded = false;
                }
            }
            
            // Determine account status
            $accountStatus = 'pending'; // Default
            
            if ($hasRejected) {
                $accountStatus = 'rejected';
            } elseif ($hasPending || !$allDocumentsUploaded) {
                $accountStatus = 'pending';
            } elseif ($approvedCount === $requiredCount) {
                $accountStatus = 'approved';
            }
            
            // Prepare response
            $responseData = [
                'documents' => $documents,
                'account_status' => $accountStatus,
                'upload_progress' => [
                    'total_required' => $requiredCount,
                    'uploaded' => count($documents),
                    'pending' => $hasPending ? 'yes' : 'no',
                    'rejected' => $hasRejected ? 'yes' : 'no'
                ]
            ];
            
            return $this->success($responseData, 'Document status retrieved successfully');
        } catch (\Exception $e) {
            // Log the error
            Log::error('Document status error: ' . $e->getMessage());
            return $this->error('An error occurred while retrieving document status', 500);
        }
    }
    
    public function getRequiredDocuments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email', // Validate email
        ]);
    
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }
    
        // Fetch the user (provider) by email
        $user = User::where('email', $request->email)->first();
    
        // Ensure the user is a provider
        if ($user->user_type !== 'provider') {
            return $this->error('The provided email does not belong to a provider.', 400);
        }
    
        // Get the provider's type
        $providerType = $user->provider->provider_type;
    
        // Get the required documents for the provider's type
        $requiredDocuments = RequiredDocument::where('provider_type', $providerType)->get();
    
        // Get the documents uploaded by the provider
        $uploadedDocuments = $user->provider->documents;
    
        // Filter out documents that are approved or pending
        $remainingDocuments = $requiredDocuments->reject(function ($requiredDocument) use ($uploadedDocuments) {
            $uploadedDocument = $uploadedDocuments->where('required_document_id', $requiredDocument->id)->first();
            return $uploadedDocument && in_array($uploadedDocument->status, ['approved', 'pending']);
        });
        
        // Convert to array to ensure sequential numeric indices
        $remainingDocumentsArray = $remainingDocuments->values()->all();
    
        return $this->success($remainingDocumentsArray, 'Remaining required documents retrieved successfully');
    }
}