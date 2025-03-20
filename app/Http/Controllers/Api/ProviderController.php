<?php

namespace App\Http\Controllers\Api;

use App\Models\Provider;
use App\Models\RequiredDocument;
use App\Models\ProviderDocument;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Traits\ResponseTrait;

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

        // Get the required documents for the provider's type
        $requiredDocuments = RequiredDocument::where('provider_type', $provider->provider_type)->get();

        // Get the provider's uploaded documents
        $uploadedDocuments = $provider->documents;

        // Prepare the response
        $status = [];

        foreach ($requiredDocuments as $requiredDocument) {
            $uploadedDocument = $uploadedDocuments->where('required_document_id', $requiredDocument->id)->first();

            $status[] = [
                'document_name' => $requiredDocument->name,
                'status' => $uploadedDocument ? $uploadedDocument->status : 'missing',
                'document_path' => $uploadedDocument ? $uploadedDocument->document_path : null,
            ];
        }

        return $this->success($status, 'Document status retrieved successfully');
    }

    // get all needed documents based on provider_type
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
    
        return $this->success($remainingDocuments, 'Remaining required documents retrieved successfully');
    }
}