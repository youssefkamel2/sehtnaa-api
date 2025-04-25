<?php

namespace App\Http\Middleware;

use App\Traits\ResponseTrait;
use Closure;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Http\Middleware\Authenticate;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;

class JwtAuthenticate extends Authenticate
{

    use ResponseTrait;
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            // First, perform the default JWT authentication
            $this->authenticate($request);
            
            // Then get the authenticated user
            $user = auth()->user();
            
            // Check if user is deactivated
            if ($user && $user->status === 'de-active') {
                // Invalidate their token
                $token = JWTAuth::getToken();
                if (!$token) {
                    activity()
                        ->log('No token found during logout.');
                    return $this->error('No token found.', 401);
                }
    
                JWTAuth::invalidate($token);
                
                // Return unauthorized response
                return $this->error('User is deactivated.', 401);
            }
            
        } catch (TokenExpiredException $e) {
            return $this->error('Token has expired.', 401);
        } catch (JWTException $e) {
            return $this->error('Token is invalid.', 401);
        }

        return $next($request);
    }
}