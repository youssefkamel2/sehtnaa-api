<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
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
                    'count' => Customer::count(),
                    'recent' => Customer::with('user:id,first_name,last_name,gender,phone_number,email')
                        ->select('id', 'user_id', 'gender', 'phone_number', 'email')
                        ->latest()
                        ->take(5)
                        ->get()
                ],
                'providers' => [
                    'count' => Provider::count(),
                    'recent' => Provider::with('user:id,first_name,last_name')
                        ->select('id', 'user_id', 'provider_type')
                        ->latest()
                        ->take(5)
                        ->get()
                        ->map(function ($provider) {
                            return [
                                'first_name' => $provider->user->first_name,
                                'last_name' => $provider->user->last_name,
                                'provider_type' => $provider->provider_type
                            ];
                        })
                ],
                'services' => [
                    'count' => Service::count(),
                    'popular' => Service::with('category:id,name,icon')
                        ->select('id', 'name', 'category_id', 'is_active')
                        ->latest()
                        ->take(5)
                        ->get()
                        ->map(function ($service) {
                            return [
                                'name' => $service->name,
                                'status' => $service->is_active ? 'Active' : 'Inactive',
                                'category' => [
                                    'name' => $service->category->name,
                                    'icon' => $service->category->icon
                                ]
                            ];
                        })
                ]
            ];

            return $this->success($data, 'Dashboard data retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->error('Failed to load dashboard data: ' . $e->getMessage(), 500);
        }
    }
}