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
                // Facebook-specific flow
                $accessToken = $driver->getAccessToken($code);
                $socialUser = $driver->userFromToken($accessToken);
            }
    
            // Validate token with provider
            // $this->validateProviderToken($provider, $accessToken);
    
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
            return response()->json([
                'error' => 'Authentication failed',
                'details' => $e->getMessage()
            ], 401);
        }
    }
    
    private function validateProviderToken($provider, $token)
    {
        if ($provider === 'google') {
            $client = new \Google_Client(['client_id' => env('GOOGLE_CLIENT_ID')]);
            $payload = $client->verifyIdToken($token);
            
            if (!$payload || $payload['aud'] !== env('GOOGLE_CLIENT_ID')) {
                throw new Exception('Invalid Google token');
            }
        }
        elseif ($provider === 'facebook') {
            $fb = new \Facebook\Facebook([
                'app_id' => env('FACEBOOK_CLIENT_ID'),
                'app_secret' => env('FACEBOOK_CLIENT_SECRET'),
                'default_graph_version' => 'v12.0',
            ]);
            
            try {
                $response = $fb->get('/me?fields=id,name,email', $token);
            } catch (Exception $e) {
                throw new Exception('Invalid Facebook token');
            }
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
                throw new Exception('A customer with this email already exists. Please login with email and password.');
            }

            // Create new user
            $user = User::create([
                'first_name' => $socialUser->getName() ?? 'Customer',
                'last_name' => '',
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
        }

        return $user;
    }
}