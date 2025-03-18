<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Provider;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\RequiredDocument;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;

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
            'password' => 'required|string|min:6|confirmed',
            'user_type' => 'required|in:customer,provider',
            'address' => 'required_if:user_type,customer,provider|string',
            'provider_type' => 'required_if:user_type,provider|in:individual,organizational',
        ]);

        if ($validator->fails()) {
            // Log validation failure
            activity()
                ->withProperties(['errors' => $validator->errors()])
                ->log('Validation failed during registration.');
            return $this->error($validator->errors()->first(), 400);
        }

        DB::beginTransaction();

        try {
            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'user_type' => $request->user_type,
                'status' => $request->user_type === 'provider' ? 'pending' : 'active',
                'address' => $request->address,
            ]);

            if ($request->user_type === 'customer') {
                $user->customer()->create();
                $token = JWTAuth::fromUser($user);
                DB::commit();

                // Log successful customer registration
                activity()
                    ->performedOn($user) // Set the subject (the user being registered)
                    ->causedBy($user) // Set the causer (the user performing the action)
                    ->withProperties(['user_id' => $user->id, 'email' => $user->email])
                    ->log('Customer registered successfully.');

                return $this->success([
                    'user' => $user,
                    'token' => $token,
                ], 'Customer registered successfully', 201);
            } elseif ($request->user_type === 'provider') {
                $user->provider()->create([
                    'provider_type' => $request->provider_type,
                ]);
                DB::commit();

                // Log successful provider registration
                activity()
                    ->performedOn($user) // Set the subject (the user being registered)
                    ->causedBy($user) // Set the causer (the user performing the action)
                    ->withProperties(['user_id' => $user->id, 'email' => $user->email])
                    ->log('Provider registered successfully.');

                return $this->success([
                    'user' => $user,
                ], 'Provider registered successfully. Please upload required documents.', 201);
            }
        } catch (\Exception $e) {
            DB::rollBack();

            // Log registration failure
            activity()
                ->withProperties(['error' => $e->getMessage()])
                ->log('Registration failed.');

            return $this->error('Registration failed. Please try again.', 500);
        }
    }

    // Login
    public function login(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'user_type' => 'required|in:customer,provider,admin',
        ]);

        if ($validator->fails()) {
            // Log validation failure
            activity()
                ->withProperties(['errors' => $validator->errors()])
                ->log('Validation failed during login.');
            return $this->error($validator->errors()->first(), 422);
        }

        // Attempt to authenticate the user
        $credentials = $request->only('email', 'password');
        if (!$token = JWTAuth::attempt($credentials)) {
            activity()
                ->withProperties(['email' => $request->email])
                ->log('Invalid login attempt.');
            return $this->error('The provided email or password is incorrect.', 401);
        }

        // Get the authenticated user
        $user = auth()->user();

        // Check if the user_type matches the requested user_type
        if ($user->user_type !== $request->user_type) {
            activity()
                ->withProperties(['email' => $request->email, 'user_type' => $request->user_type])
                ->log('User type mismatch during login.');
            return $this->error('You are not authorized to access this account type.', 403);
        }

        // Handle provider-specific checks
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
                // Log provider account under review
                activity()
                    ->causedBy($user)
                    ->withProperties([
                        'user_id' => $user->id,
                        'missing_documents' => $missingDocuments,
                        'pending_documents' => $pendingDocuments,
                        'rejected_documents' => $rejectedDocuments,
                    ])
                    ->log('Provider account under review.');

                return $this->error([
                    'missing_documents' => $missingDocuments,
                    'pending_documents' => $pendingDocuments,
                    'rejected_documents' => $rejectedDocuments,
                    'message' => 'Your account is under review. Please check your documents.',
                ], 403);
            }

            // Check if the user is pending
            if ($user->status === 'pending') {
                // Log provider account pending
                activity()
                    ->causedBy($user)
                    ->withProperties(['user_id' => $user->id])
                    ->log('Provider account pending.');

                return $this->error('Your account is under review. Please check your documents.', 403);
            }
        }

        // Check if the user is de-active
        if ($user->status === 'de-active') {
            // Log deactivated account login attempt
            activity()
                ->causedBy($user)
                ->withProperties(['user_id' => $user->id])
                ->log('Deactivated account login attempt.');

            return $this->error('Your account is deactivated', 403);
        }

        // Log successful login
        activity()
            ->causedBy($user)
            ->withProperties(['user_id' => $user->id, 'user_type' => $user->user_type])
            ->log('User logged in successfully.');

        return $this->success([
            'user' => $user,
            'token' => $token,
        ], 'Login successful');
    }
    // Logout
    public function logout()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $token = JWTAuth::getToken();
            if (!$token) {
                // Log no token found during logout
                activity()
                    ->log('No token found during logout.');
                return $this->error('No token found.', 401);
            }

            JWTAuth::invalidate($token);

            // Log successful logout
            activity()
                // ->performedOn($user)
                ->causedBy(auth()->user())
                ->withProperties(['user_id' => auth()->id()])
                ->log('User logged out successfully.');

            return $this->success(null, 'Successfully logged out.');
        } catch (\Exception $e) {
            // Log logout failure
            activity()
                ->withProperties(['error' => $e->getMessage()])
                ->log('Logout failed.');

            return $this->error('An error occurred during logout.', 500);
        }
    }

    // Get authenticated user
    public function me()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                // Log unauthorized access to /me endpoint
                activity()
                    ->log('Unauthorized access to /me endpoint.');
                return $this->error('Unauthorized access. Please log in.', 401);
            }

            // Eager load provider and customer data
            $user->load('provider', 'customer');

            // Log user details retrieved
            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->withProperties(['user_id' => $user->id])
                ->log('User details retrieved.');

            return $this->success($user, 'User retrieved successfully');
        } catch (\Exception $e) {
            // Log error retrieving user details
            activity()
                ->withProperties(['error' => $e->getMessage()])
                ->log('Error retrieving user details.');

            return $this->error('An error occurred.', 500);
        }
    }
}
