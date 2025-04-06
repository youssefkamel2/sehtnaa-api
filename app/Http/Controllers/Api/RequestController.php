<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Request as ServiceRequest;
use App\Traits\ResponseTrait;
use Illuminate\Support\Facades\Auth;

class RequestController extends Controller
{
    use ResponseTrait;

    /**
     * Get authenticated user's requests
     */
    public function getUserRequests()
    {
        try {
            $user = Auth::user();
            print_r($user->customer);die;
            $requests = ServiceRequest::with([
                'service:id,name,price',
                'assignedProvider.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider:id,provider_type,user_id'
            ])
            ->where('customer_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($request) {
                return [
                    'id' => $request->id,
                    'customer_id' => $request->customer_id,
                    'service_id' => $request->service_id,
                    'phone' => $request->phone,
                    'address' => $request->address,
                    'status' => $request->status,
                    'service' => [
                        'name' => $request->service->name,
                        'price' => $request->service->price
                    ],
                    'assigned_provider' => $request->assignedProvider ? [
                        'first_name' => $request->assignedProvider->user->first_name,
                        'last_name' => $request->assignedProvider->user->last_name,
                        'provider_type' => $request->assignedProvider->provider_type,
                        'phone' => $request->assignedProvider->user->phone,
                        'profile_image' => $request->assignedProvider->user->profile_image
                    ] : null
                ];
            });

            return $this->success($requests, 'Requests fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch requests: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get request details
     */
    public function getRequestDetails($id)
    {
        try {
            $request = ServiceRequest::with([
                'service:id,name,price',
                'assignedProvider.user:id,first_name,last_name,phone,profile_image',
                'assignedProvider:id,provider_type,user_id'
            ])->find($id);

            if (!$request) {
                return $this->error('Request not found', 404);
            }

            // Authorization check
            $user = Auth::user();
            if ($user->user_type === 'customer' && $request->customer_id !== $user->id) {
                return $this->error('Unauthorized', 403);
            }
            if ($user->user_type === 'provider' && $request->assigned_provider_id !== $user->provider->id) {
                return $this->error('Unauthorized', 403);
            }

            $formattedRequest = [
                'id' => $request->id,
                'customer_id' => $request->customer_id,
                'service_id' => $request->service_id,
                'phone' => $request->phone,
                'address' => $request->address,
                'status' => $request->status,
                'service' => [
                    'name' => $request->service->name,
                    'price' => $request->service->price
                ],
                'assigned_provider' => $request->assignedProvider ? [
                    'first_name' => $request->assignedProvider->user->first_name,
                    'last_name' => $request->assignedProvider->user->last_name,
                    'provider_type' => $request->assignedProvider->provider_type,
                    'phone' => $request->assignedProvider->user->phone,
                    'profile_image' => $request->assignedProvider->user->profile_image
                ] : null
            ];

            return $this->success($formattedRequest, 'Request details fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch request details: ' . $e->getMessage(), 500);
        }
    }
}