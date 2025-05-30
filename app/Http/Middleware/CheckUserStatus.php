<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    use ResponseTrait;

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return $this->error('Unauthorized access. Please log in.', 401);
        }

        if ($user->status === 'de-active') {
            return $this->error('Unauthorized access. Please log in.', 401);
        }

        return $next($request);
    }
}