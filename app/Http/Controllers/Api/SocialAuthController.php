<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class SocialAuthController extends Controller
{
    private $supportedProviders = ['google', 'facebook'];

    public function getAuthUrl($provider)
    {
        if (!in_array($provider, $this->supportedProviders)) {
            return response()->json(['error' => 'Provider not supported'], 422);
        }

        return response()->json([
            'url' => Socialite::driver($provider)
                ->stateless()
                ->redirect()
                ->getTargetUrl()
        ]);
    }

    public function handleCallback(Request $request, $provider)
    {
        if (!in_array($provider, $this->supportedProviders)) {
            return response()->json(['error' => 'Provider not supported'], 422);
        }

        // Get code from either GET parameters or POST body
        $code = $request->input('code') ?? $request->code;

        if (!$code) {
            return response()->json([
                'error' => 'Authorization code not found',
                'details' => 'The code parameter is missing from the callback'
            ], 400);
        }

        try {
            $driver = Socialite::driver($provider)->stateless();

            if ($provider === 'google') {
                // Google-specific flow
                $response = $driver->getAccessTokenResponse($code);
                $accessToken = $response['access_token'];
                $socialUser = $driver->userFromToken($accessToken);
            } elseif ($provider === 'facebook') {
                // Facebook-specific flow - use standard Socialite approach
                $socialUser = $driver->userFromCode($code);
            }

            // Find or create user
            $user = $this->findOrCreateUser($provider, $socialUser);

            // Generate JWT token
            $token = JWTAuth::fromUser($user);

            return response()->json([
                'token' => $token,
                'token_type' => 'bearer',
                'user' => $user
            ]);

        } catch (Exception $e) {
            \Log::error('Social auth error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'code' => $code
            ]);

            return response()->json([
                'error' => 'Authentication failed',
                'details' => $e->getMessage(),
                'provider' => $provider
            ], 401);
        }
    }

    public function debugFacebook(Request $request)
    {
        try {
            $code = $request->input('code');
            if (!$code) {
                return response()->json(['error' => 'No code provided'], 400);
            }

            $driver = Socialite::driver('facebook')->stateless();

            // Try to get access token response
            $response = $driver->getAccessTokenResponse($code);

            return response()->json([
                'success' => true,
                'response' => $response,
                'has_access_token' => isset($response['access_token'])
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    private function findOrCreateUser($provider, $socialUser)
    {
        // Get user ID and email from social user object
        $providerId = $socialUser->id ?? $socialUser->getId();
        $email = $socialUser->email ?? $socialUser->getEmail();
        $name = $socialUser->name ?? $socialUser->getName();
        $avatar = $socialUser->avatar ?? $socialUser->getAvatar();

        // Find by provider ID first
        $user = User::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->where('user_type', 'customer')
            ->first();

        if (!$user) {
            // Check if email exists (but not with social login)
            $existing = User::where('email', $email)
                ->where('user_type', 'customer')
                ->whereNull('provider')
                ->first();

            if ($existing) {
                throw new Exception('A customer with this email already exists. Please login with email and password.');
            }

            // Create new user
            $user = User::create([
                'first_name' => $name ?? 'Customer',
                'last_name' => '',
                'email' => $email,
                'phone' => 'temp_' . Str::random(10),
                'password' => Hash::make(Str::random(32)),
                'user_type' => 'customer',
                'status' => 'active',
                'provider' => $provider,
                'provider_id' => $providerId,
                'profile_image' => $avatar,
            ]);

            $user->customer()->create();
        }

        return $user;
    }
}