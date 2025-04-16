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
use Illuminate\Support\Facades\Storage;

class ProviderController extends Controller
{
    use ResponseTrait;

    /**
     * Get all providers separated by type
     */
    public function getAllProviders(Request $request)
    {
        try {
            $providers = Provider::with(['user:id,first_name,last_name,email,phone,status'])
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

    /**
     * Upload or re-upload a document
     */
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

    /**
     * List all uploaded documents for a provider
     */
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
                ->with(['requiredDocument:id,name,description'])
                ->get();

            return $this->success($documents, 'Documents retrieved successfully');

        } catch (\Exception $e) {
            Log::error('ProviderController::listDocuments - ' . $e->getMessage());
            return $this->error('Failed to retrieve documents. Please try again.', 500);
        }
    }

    /**
     * Get document status and account status
     */
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

    /**
     * Get remaining required documents
     */
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