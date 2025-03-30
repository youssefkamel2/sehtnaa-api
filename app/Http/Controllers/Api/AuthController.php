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
            'gender' => 'required|in:male,female',
            'provider_type' => 'required_if:user_type,provider|in:individual,organizational',
            'nid' => 'required_if:provider_type,individual|string|unique:providers,nid',
        ], [
            'nid.required_if' => 'The national ID is required for individual providers',
            'nid.unique' => 'This national ID is already registered',
        ]);

        if ($validator->fails()) {
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
                'gender' => $request->gender,
            ]);

            if ($request->user_type === 'customer') {
                $user->customer()->create();
                $token = JWTAuth::fromUser($user);
                DB::commit();

                activity()
                    ->performedOn($user)
                    ->causedBy($user)
                    ->withProperties(['user_id' => $user->id, 'email' => $user->email])
                    ->log('Customer registered successfully.');

                return $this->success([
                    'user' => $user,
                    'token' => $token,
                ], 'Customer registered successfully', 201);
            } elseif ($request->user_type === 'provider') {
                $providerData = [
                    'provider_type' => $request->provider_type,
                ];

                // Add NID only for individual providers
                if ($request->provider_type === 'individual') {
                    $providerData['nid'] = $request->nid;
                }

                $user->provider()->create($providerData);
                DB::commit();

                activity()
                    ->performedOn($user)
                    ->causedBy($user)
                    ->withProperties([
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'provider_type' => $request->provider_type,
                        'nid' => $request->provider_type === 'individual' ? 'provided' : 'not applicable'
                    ])
                    ->log('Provider registered successfully.');

                return $this->success([
                    'user' => $user,
                ], 'Provider registered successfully. Please upload required documents.', 201);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            activity()
                ->withProperties(['error' => $e->getMessage()])
                ->log('Registration failed.');
            return $this->error('Registration failed. Please try again.', 500);
        }
    }

    // Login remains the same as before
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'user_type' => 'required|in:customer,provider,admin',
        ]);

        if ($validator->fails()) {
            activity()
                ->withProperties(['errors' => $validator->errors()])
                ->log('Validation failed during login.');
            return $this->error($validator->errors()->first(), 422);
        }

        $credentials = $request->only('email', 'password');
        if (!$token = JWTAuth::attempt($credentials)) {
            activity()
                ->withProperties(['email' => $request->email])
                ->log('Invalid login attempt.');
            return $this->error('The provided email or password is incorrect.', 401);
        }

        $user = auth()->user();

        if ($user->user_type !== $request->user_type) {
            activity()
                ->withProperties(['email' => $request->email, 'user_type' => $request->user_type])
                ->log('User type mismatch during login.');
            return $this->error('You are not authorized to access this account type.', 422);
        }

        if ($user->user_type === 'provider') {
            $provider = $user->provider;
            $requiredDocuments = RequiredDocument::where('provider_type', $provider->provider_type)->get();
            $uploadedDocuments = $provider->documents;

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

            if (!empty($missingDocuments) || !empty($pendingDocuments) || !empty($rejectedDocuments)) {
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

            if ($user->status === 'pending') {
                activity()
                    ->causedBy($user)
                    ->withProperties(['user_id' => $user->id])
                    ->log('Provider account pending.');

                return $this->error('Your account is under review. Please check your documents.', 403);
            }
        }

        if ($user->status === 'de-active') {
            activity()
                ->causedBy($user)
                ->withProperties(['user_id' => $user->id])
                ->log('Deactivated account login attempt.');

            return $this->error('Your account is deactivated', 403);
        }

        activity()
            ->causedBy($user)
            ->withProperties(['user_id' => $user->id, 'user_type' => $user->user_type])
            ->log('User logged in successfully.');

        return $this->success([
            'user' => $user,
            'token' => $token,
        ], 'Login successful');
    }

    // Logout remains the same
    public function logout()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $token = JWTAuth::getToken();
            if (!$token) {
                activity()
                    ->log('No token found during logout.');
                return $this->error('No token found.', 401);
            }

            JWTAuth::invalidate($token);

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['user_id' => auth()->id()])
                ->log('User logged out successfully.');

            return $this->success(null, 'Successfully logged out.');
        } catch (\Exception $e) {
            activity()
                ->withProperties(['error' => $e->getMessage()])
                ->log('Logout failed.');

            return $this->error('An error occurred during logout.', 500);
        }
    }

    // Get authenticated user - updated to include provider details
    public function me()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                activity()
                    ->log('Unauthorized access to /me endpoint.');
                return $this->error('Unauthorized access. Please log in.', 401);
            }

            // Load provider with documents and customer data
            $user->load([
                'provider' => function($query) {
                    $query->with('documents');
                },
                'customer'
            ]);

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->withProperties(['user_id' => $user->id])
                ->log('User details retrieved.');

            return $this->success($user, 'User retrieved successfully');
        } catch (\Exception $e) {
            activity()
                ->withProperties(['error' => $e->getMessage()])
                ->log('Error retrieving user details.');

            return $this->error('An error occurred.', 500);
        }
    }
}