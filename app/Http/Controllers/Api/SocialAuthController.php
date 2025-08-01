<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class SocialAuthController extends Controller
{
    use ResponseTrait;

    private $supportedProviders = ['google', 'facebook'];

    public function getAuthUrl($provider)
    {
        if (!in_array($provider, $this->supportedProviders)) {
            Log::warning('Unsupported social provider requested', ['provider' => $provider]);
            return $this->error('Provider not supported', 422);
        }

        try {
            $url = Socialite::driver($provider)
                ->stateless()
                ->redirect()
                ->getTargetUrl();

            Log::info('Social auth URL generated', ['provider' => $provider]);
            return $this->success(['url' => $url], 'Auth URL generated successfully');
        } catch (Exception $e) {
            Log::error('Failed to generate social auth URL', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to generate auth URL', 500);
        }
    }

    public function handleCallback(Request $request, $provider)
    {
        if (!in_array($provider, $this->supportedProviders)) {
            Log::warning('Unsupported social provider callback', ['provider' => $provider]);
            return $this->error('Provider not supported', 422);
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            // Find or create user
            $user = $this->findOrCreateUser($provider, $socialUser);

            // Generate JWT token
            $token = JWTAuth::fromUser($user);

            Log::info('Social login successful', [
                'provider' => $provider,
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            // Log activity
            activity()
                ->causedBy($user)
                ->withProperties([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'email' => $user->email
                ])
                ->log('User logged in via ' . ucfirst($provider));

            return $this->success([
                'token' => $token,
                'token_type' => 'bearer',
                'user' => $user
            ], 'Login successful');

        } catch (Exception $e) {
            Log::error('Social auth callback failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->error('Authentication failed: ' . $e->getMessage(), 401);
        }
    }

    private function findOrCreateUser($provider, $socialUser)
    {
        // Find by provider ID first
        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->where('user_type', 'customer')
            ->first();

        if (!$user) {
            // Check if email exists (but not with social login)
            $existing = User::where('email', $socialUser->getEmail())
                ->where('user_type', 'customer')
                ->whereNull('provider')
                ->first();

            if ($existing) {
                Log::warning('Social login attempt with existing email', [
                    'provider' => $provider,
                    'email' => $socialUser->getEmail()
                ]);
                throw new Exception('A customer with this email already exists. Please login with email and password.');
            }

            // Create new user, save as first name and last name
            $fullName = $socialUser->getName() ?? 'Customer';
            $nameParts = explode(' ', $fullName, 2); // Split by first space only

            $firstName = $nameParts[0] ?? 'Customer';
            $lastName = $nameParts[1] ?? '';

            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $socialUser->getEmail(),
                'phone' => 'temp_' . Str::random(10),
                'password' => Hash::make(Str::random(32)),
                'user_type' => 'customer',
                'status' => 'active',
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'profile_image' => $socialUser->getAvatar(),
            ]);

            $user->customer()->create();

            Log::info('New social user created', [
                'provider' => $provider,
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            // Log activity for new user creation
            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->withProperties([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'provider' => $provider
                ])
                ->log('Customer registered via ' . ucfirst($provider));
        } else {
            Log::info('Existing social user logged in', [
                'provider' => $provider,
                'user_id' => $user->id,
                'email' => $user->email
            ]);
        }

        return $user;
    }
}