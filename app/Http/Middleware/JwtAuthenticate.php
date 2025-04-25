<?php

namespace App\Http\Middleware;

use Closure;
use App\Traits\ResponseTrait;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
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
        Log::info('JwtAuthenticate middleware executing');
        
        try {
            $this->authenticate($request);
            $user = auth()->user();
            
            Log::info('User status check', ['user_id' => $user->id, 'status' => $user->status]);
    
            if ($user && $user->status === 'de-active') {
                $token = JWTAuth::getToken();
                if (!$token) {
                    Log::warning('No token found during deactivated user check');
                    return $this->error('No token found.', 401);
                }
    
                Log::info('Invalidating token for deactivated user', ['user_id' => $user->id]);
                JWTAuth::invalidate($token);
                
                return $this->error('User is deactivated.', 401);
            }
            
        } catch (TokenExpiredException $e) {
            Log::error('Token expired', ['error' => $e->getMessage()]);
            return $this->error('Token has expired.', 401);
        } catch (JWTException $e) {
            Log::error('JWT Exception', ['error' => $e->getMessage()]);
            return $this->error('Token is invalid.', 401);
        }
    
        return $next($request);
    }
}