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
                    'recent' => Customer::with(['user' => function($query) {
                            $query->select('id', 'first_name', 'last_name', 'gender', 'phone', 'email', 'status');
                        }])
                        ->select('id', 'user_id')
                        ->latest()
                        ->take(5)
                        ->get()
                        ->map(function ($customer) {
                            return [
                                'first_name' => $customer->user->first_name,
                                'last_name' => $customer->user->last_name,
                                'gender' => $customer->user->gender,
                                'phone' => $customer->user->phone,
                                'email' => $customer->user->email,
                                'status' => $customer->user->status
                            ];
                        })
                ],
                'providers' => [
                    'count' => Provider::count(),
                    'recent' => Provider::with(['user' => function($query) {
                            $query->select('id', 'first_name', 'last_name');
                        }])
                        ->select('id', 'user_id', 'provider_type')
                        ->latest()
                        ->take(5)
                        ->get()
                        ->map(function ($provider) {
                            return [
                                'first_name' => $provider->user->first_name,
                                'last_name' => $provider->user->last_name,
                                'type' => $provider->provider_type
                            ];
                        })
                ],
                'services' => [
                    'count' => Service::count(),
                    'recent' => Service::with(['category' => function($query) {
                            $query->select('id', 'name', 'icon');
                        }])
                        ->select('id', 'name', 'category_id', 'is_active', 'cover_photo')
                        ->latest()
                        ->take(5)
                        ->get()
                        ->map(function ($service) {
                            return [
                                'name' => $service->name,
                                'status' => $service->is_active ? 'Active' : 'Inactive',
                                'cover_photo' => $service->cover_photo,
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