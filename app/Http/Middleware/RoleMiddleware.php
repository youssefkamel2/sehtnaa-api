<?php

namespace App\Http\Middleware;

use App\Traits\ResponseTrait;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{

    use ResponseTrait;
    public function handle(Request $request, Closure $next, $role)
    {
        $user = $request->user();

        if (!$user || $user->user_type !== $role) {
            return $this->error('Unauthorized.', Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}