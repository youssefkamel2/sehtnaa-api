<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ResponseTrait;
use App\Services\LogService;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;

class SocialAuthController extends Controller
{
    use ResponseTrait;

    private $supportedProviders = ['google', 'facebook'];
    private $appScheme = 'sehetna://auth';

    /**
     * Generate social authentication URL for mobile app
     */
    public function getAuthUrl($provider)
    {
        if (!in_array($provider, $this->supportedProviders)) {
            LogService::auth('warning', 'Unsupported social provider requested', ['provider' => $provider]);
            return $this->error('Provider not supported', 422);
        }

        try {
            // Generate a state parameter to track the request and prevent CSRF
            $state = Str::random(40);
            
            // Store state in cache for validation (expires in 10 minutes)
            Cache::put("social_auth_state_{$state}", [
                'provider' => $provider,
                'created_at' => now(),
                'user_agent' => request()->userAgent(),
                'ip' => request()->ip()
            ], 600);

            $url = Socialite::driver($provider)
                ->stateless()
                ->with(['state' => $state])
                ->redirect()
                ->getTargetUrl();

            LogService::auth('info', 'Social auth URL generated', [
                'provider' => $provider,
                'state' => $state,
                'ip' => request()->ip()
            ]);

            return $this->success([
                'url' => $url,
                'state' => $state
            ], 'Auth URL generated successfully');

        } catch (Exception $e) {
            LogService::exception($e, [
                'provider' => $provider,
                'action' => 'generate_auth_url'
            ]);
            return $this->error('Failed to generate auth URL', 500);
        }
    }

    /**
     * Handle OAuth callback and redirect to mobile app with token
     */
    public function handleCallback(Request $request, $provider)
    {
        if (!in_array($provider, $this->supportedProviders)) {
            LogService::auth('warning', 'Unsupported social provider callback', ['provider' => $provider]);
            return $this->redirectToApp('error=provider_not_supported&error_description=Provider%20not%20supported');
        }

        // Validate state parameter to prevent CSRF attacks
        $state = $request->get('state');
        if (!$state || !Cache::has("social_auth_state_{$state}")) {
            LogService::auth('warning', 'Invalid or missing state parameter', [
                'provider' => $provider,
                'state' => $state,
                'ip' => request()->ip()
            ]);
            return $this->redirectToApp('error=invalid_state&error_description=Invalid%20or%20expired%20state%20parameter');
        }

        // Remove the state from cache to prevent replay attacks
        Cache::forget("social_auth_state_{$state}");

        // Check for OAuth provider errors (user denied, etc.)
        if ($request->has('error')) {
            $error = $request->get('error');
            $errorDescription = $request->get('error_description', '');
            
            LogService::auth('warning', 'OAuth provider returned error', [
                'provider' => $provider,
                'error' => $error,
                'error_description' => $errorDescription
            ]);

            return $this->redirectToApp("error={$error}&error_description=" . urlencode($errorDescription ?: 'Authentication was cancelled or failed'));
        }

        try {
            // Get user information from OAuth provider
            $socialUser = Socialite::driver($provider)->stateless()->user();

            if (!$socialUser || !$socialUser->getEmail()) {
                LogService::auth('error', 'Invalid social user data', [
                    'provider' => $provider,
                    'social_user_id' => $socialUser ? $socialUser->getId() : null
                ]);
                return $this->redirectToApp('error=invalid_user_data&error_description=Unable%20to%20retrieve%20user%20information%20from%20' . ucfirst($provider));
            }

            // Find or create user (customers only)
            $user = $this->findOrCreateUser($provider, $socialUser);

            // Load customer relationships (social auth is for customers only)
            $user->load(['customer']);

            // Generate JWT token
            $token = JWTAuth::fromUser($user);

            LogService::auth('info', 'Social login successful', [
                'provider' => $provider,
                'user_id' => $user->id,
                'email' => $user->email,
                'is_new_user' => $user->wasRecentlyCreated ?? false
            ]);

            // Log user activity
            activity()
                ->causedBy($user)
                ->withProperties([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'email' => $user->email,
                    'login_method' => 'social_oauth'
                ])
                ->log('User logged in via ' . ucfirst($provider));

            // Prepare full response data (same structure as normal login)
            $responseData = [
                'user' => $user,
                'token' => $token,
                'message' => 'Login successful'
            ];

            // Redirect to mobile app with full response data
            return $this->redirectToAppWithFullResponse($responseData, $provider);

        } catch (Exception $e) {
            LogService::exception($e, [
                'provider' => $provider,
                'action' => 'social_callback'
            ]);

            $errorMessage = 'Authentication failed';
            
            // Check for specific error types to provide better user feedback
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $errorMessage = 'Email already registered with different method';
            }

            return $this->redirectToApp('error=authentication_failed&error_description=' . urlencode($errorMessage));
        }
    }

    /**
     * Redirect to mobile app using deep link
     */
    private function redirectToApp($params)
    {
        $redirectUrl = $this->appScheme . '?' . $params;
        
        LogService::auth('info', 'Redirecting to mobile app', [
            'redirect_url' => $redirectUrl,
            'params' => $params
        ]);

        // Return redirect response
        return redirect($redirectUrl);
    }

    /**
     * Redirect to mobile app with full response data (same structure as normal login)
     */
    private function redirectToAppWithFullResponse($responseData, $provider)
    {
        // Encode the full response data as JSON and then base64 for URL safety
        $encodedData = base64_encode(json_encode($responseData));
        
        // Create parameters for the deep link
        $params = http_build_query([
            'success' => 'true',
            'provider' => $provider,
            'data' => $encodedData
        ]);
        
        $redirectUrl = $this->appScheme . '?' . $params;
        
        LogService::auth('info', 'Redirecting to mobile app with full response data', [
            'redirect_url' => $redirectUrl,
            'provider' => $provider,
            'user_id' => $responseData['user']->id ?? null,
            'has_token' => isset($responseData['token']),
            'has_user' => isset($responseData['user'])
        ]);

        // Return redirect response
        return redirect($redirectUrl);
    }

    /**
     * Find existing user or create new user from social provider data
     */
    private function findOrCreateUser($provider, $socialUser)
    {
        // First, try to find user by social provider ID and email
        $user = User::where('auth_source', $provider)
            ->where('auth_source_id', $socialUser->getId())
            ->where('user_type', 'customer')
            ->first();

        if (!$user) {
            // Check if email exists with regular registration (no social auth)
            $existingRegularUser = User::where('email', $socialUser->getEmail())
                ->where('user_type', 'customer')
                ->whereNull('auth_source')
                ->first();

            if ($existingRegularUser) {
                LogService::auth('warning', 'Social login attempt with existing email (regular registration)', [
                    'provider' => $provider,
                    'email' => $socialUser->getEmail()
                ]);
                throw new Exception('A customer with this email already exists. Please login with email and password, or reset your password if needed.');
            }

            // Check if email exists with different social provider
            $existingWithDifferentProvider = User::where('email', $socialUser->getEmail())
                ->where('user_type', 'customer')
                ->whereNotNull('auth_source')
                ->where('auth_source', '!=', $provider)
                ->first();

            if ($existingWithDifferentProvider) {
                LogService::auth('warning', 'Social login attempt with different provider', [
                    'provider' => $provider,
                    'existing_provider' => $existingWithDifferentProvider->auth_source,
                    'email' => $socialUser->getEmail()
                ]);
                throw new Exception('This email is already registered with ' . ucfirst($existingWithDifferentProvider->auth_source) . '. Please use ' . ucfirst($existingWithDifferentProvider->auth_source) . ' to login.');
            }

            // Create new user
            $user = $this->createNewSocialUser($provider, $socialUser);

        } else {
            // Update existing user's information from social provider
            $this->updateExistingUserFromSocial($user, $socialUser);
            
            LogService::auth('info', 'Existing social user logged in', [
                'provider' => $provider,
                'user_id' => $user->id,
                'email' => $user->email
            ]);
        }

        return $user;
    }

    /**
     * Create new user from social provider data
     */
    private function createNewSocialUser($provider, $socialUser)
    {
        // Parse full name into first and last name
        $fullName = trim($socialUser->getName() ?: 'Customer');
        $nameParts = explode(' ', $fullName, 2);

        $firstName = trim($nameParts[0] ?: 'Customer');
        $lastName = trim($nameParts[1] ?? '');

        // Create user
        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $socialUser->getEmail(),
            'phone' => null,
            'password' => Hash::make(Str::random(32)), // Random password since they'll use social login
            'user_type' => 'customer',
            'status' => 'active',
            'auth_source' => $provider,
            'auth_source_id' => $socialUser->getId(),
            'email_verified_at' => now(), // Social providers verify emails
        ]);

        // Create customer record
        $user->customer()->create([
            // Add any customer-specific fields here if needed
        ]);

        LogService::auth('info', 'New social user created', [
            'provider' => $provider,
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $firstName . ' ' . $lastName
        ]);

        // Log activity for new user registration
        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties([
                'user_id' => $user->id,
                'email' => $user->email,
                'provider' => $provider,
                'registration_method' => 'social_oauth'
            ])
            ->log('Customer registered via ' . ucfirst($provider));

        return $user;
    }

    /**
     * Update existing user's info from social provider
     */
    private function updateExistingUserFromSocial($user, $socialUser)
    {
        $fullName = trim($socialUser->getName() ?: $user->first_name . ' ' . $user->last_name);
        $nameParts = explode(' ', $fullName, 2);

        $firstName = trim($nameParts[0] ?: $user->first_name);
        $lastName = trim($nameParts[1] ?? $user->last_name);

        // Update user info if it has changed
        $updates = [];
        
        if ($user->first_name !== $firstName) {
            $updates['first_name'] = $firstName;
        }
        
        if ($user->last_name !== $lastName) {
            $updates['last_name'] = $lastName;
        }

        if (!empty($updates)) {
            $user->update($updates);
            
            LogService::auth('info', 'User info updated from social provider', [
                'user_id' => $user->id,
                'updates' => $updates,
                'provider' => $user->auth_source
            ]);
        }

        return $user;
    }
}