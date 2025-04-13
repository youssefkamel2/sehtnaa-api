<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Provider;
use App\Models\Service;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ResponseTrait;

    public function dashboard(Request $request)
    {
        try {
            $data = [
                'users' => [
                    'count' => User::count(),
                    'recent' => User::latest()->take(5)->get(),
                ],
                'providers' => [
                    'count' => Provider::count(),
                    'recent' => Provider::latest()->take(5)->get(),
                ],
                'services' => [
                    'count' => Service::count(),
                    'popular' => Service::latest()->take(5)->get(),
                ]
            ];

            return $this->success($data, 'Dashboard data retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->error('Failed to load dashboard data: ' . $e->getMessage(), 500);
        }
    }
}