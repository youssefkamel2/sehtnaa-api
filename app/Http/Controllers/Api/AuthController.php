<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Provider;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\RequiredDocument;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ResponseTrait;

    // Register a new user
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|unique:users',
            'password' => 'required|string|min:6',
            'confirm_password' => 'required|same:password',
            'user_type' => 'required|in:customer,provider',
            'address' => 'required_if:user_type,customer,provider|string',
            'provider_type' => 'required_if:user_type,provider|in:individual,organizational',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'user_type' => $request->user_type,
            'status' => $request->user_type === 'provider' ? 'pending' : 'active', // Set status to 'pending' for providers
            'address' => $request->address,
        ]);

        // Create customer or provider record
        if ($request->user_type === 'customer') {
            $user->customer()->create();

            // Generate token for customers
            $token = JWTAuth::fromUser($user);

            return $this->success([
                'user' => $user,
                'token' => $token,
            ], 'Customer registered successfully', 201);
        } elseif ($request->user_type === 'provider') {
            $user->provider()->create([
                'provider_type' => $request->provider_type,
            ]);

            // Do not generate token for providers (status is 'pending')
            return $this->success([
                'user' => $user,
            ], 'Provider registered successfully. Please upload required documents.', 201);
        }
    }

    // Login
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
    
        if (!$token = JWTAuth::attempt($credentials)) {
            return $this->error('Invalid credentials', 401);
        }
    
        $user = auth()->user();
    
        // Check if the user is a provider
        if ($user->user_type === 'provider') {
            $provider = $user->provider;
    
            // Get the required documents for the provider's type
            $requiredDocuments = RequiredDocument::where('provider_type', $provider->provider_type)->get();
    
            // Get the provider's uploaded documents
            $uploadedDocuments = $provider->documents;
    
            // Check if all required documents are uploaded and approved
            $missingDocuments = [];
            $pendingDocuments = [];
            $rejectedDocuments = [];
    
            foreach ($requiredDocuments as $requiredDocument) {
                $uploadedDocument = $uploadedDocuments->where('required_document_id', $requiredDocument->id)->first();
    
                if (!$uploadedDocument) {
                    $missingDocuments[] = $requiredDocument->name;
                } elseif ($uploadedDocument->status === 'pending') {
                    $pendingDocuments[] = $requiredDocument->name;
                } elseif ($uploadedDocument->status === 'rejected') {
                    $rejectedDocuments[] = $requiredDocument->name;
                }
            }
    
            // If any documents are missing, pending, or rejected, return an error
            if (!empty($missingDocuments) || !empty($pendingDocuments) || !empty($rejectedDocuments)) {
                return $this->error([
                    'missing_documents' => $missingDocuments,
                    'pending_documents' => $pendingDocuments,
                    'rejected_documents' => $rejectedDocuments,
                    'message' => 'Your account is under review. Please check your documents.',
                ], 403);
            }

            // check if the user is pending
            if ($user->status === 'pending') {
                return $this->error('Your account is under review. Please check your documents.', 403);
            }
        }
    
        // Check if the user is de-active
        if ($user->status === 'de-active') {
            return $this->error('Your account is deactivated', 403);
        }
    
        return $this->success([
            'user' => $user,
            'token' => $token,
        ], 'Login successful');
    }

    // Logout
    public function logout()
    {
        try {
            $token = JWTAuth::getToken();
            if (!$token) {
                return $this->error('No token found.', 401);
            }
    
            JWTAuth::invalidate($token);
            return $this->success(null, 'Successfully logged out.');
        } catch (\Exception $e) {
            return $this->error('An error occurred during logout.', 500);
        }
    }

    // Get authenticated user
    public function me()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return $this->error('Unauthorized access. Please log in.', 401);
            }
    
            // Include user role and related data
            $user->load('provider', 'customer');
            return $this->success($user, 'User retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('An error occurred.', 500);
        }
    }
}
