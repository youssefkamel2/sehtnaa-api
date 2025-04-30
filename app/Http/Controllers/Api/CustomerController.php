<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Traits\ResponseTrait;
use Illuminate\Support\Facades\Auth;
use App\Models\Request as ServiceRequest;

class CustomerController extends Controller
{
    use ResponseTrait;

    public function getAllCustomers()
    {
        try {
            $customers = Customer::with(['user' => function ($query) {
                $query->select('id', 'first_name', 'last_name', 'email', 'phone', 'gender', 'status', 'profile_image');
            }])
                ->select('id', 'user_id', 'created_at')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($customer) {
                    return [
                        'id' => $customer->id,
                        'first_name' => $customer->user->first_name,
                        'last_name' => $customer->user->last_name,
                        'email' => $customer->user->email,
                        'phone' => $customer->user->phone,
                        'gender' => $customer->user->gender,
                        'status' => $customer->user->status,
                        'profile_image' => $customer->user->profile_image,
                        'created_at' => $customer->created_at
                    ];
                });

            return $this->success($customers, 'Customers retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve customers: ' . $e->getMessage(), 500);
        }
    }

    // function to toggle customer status
    public function toggleCustomerStatus($id)
    {
        try {
            $customer = Customer::with('user')->findOrFail($id);

            if (!$customer->user) {
                return $this->error('Customer user not found', 404);
            }

            // Toggle the status
            $customer->user->status = $customer->user->status === 'active' ? 'de-active' : 'active';
            $customer->user->save();

            return $this->success([
                'status' => $customer->user->status
            ], 'Customer status updated successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to update customer status: ' . $e->getMessage(), 500);
        }
    }

    // function to get the ongoing requests of a customer [status is accepted or pending]
    public function getOngoingRequests()
    {
        try {
            $user = Auth::user();

            if ($user->user_type !== 'customer') {
                return $this->error('Only customers can view requests', 403);
            }

            if (!$user->customer) {
                return $this->error('Customer profile not found', 404);
            }

            $requests = ServiceRequest::with([
                'services:id,name',
                'requirements.serviceRequirement:id,name,type',
                'assignedProvider.user:id,first_name,last_name,profile_image'
            ])
                ->where('customer_id', $user->customer->id)
                ->whereIn('status', ['pending', 'accepted'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'services' => $request->services->map(function ($service) {
                            return [
                                'id' => $service->id,
                                'name' => $service->name,
                                'price' => $service->pivot->price
                            ];
                        }),
                        'total_price' => $request->total_price,
                        'status' => $request->status,
                        'created_at' => $request->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $request->updated_at->format('Y-m-d H:i:s'),
                        'location' => [
                            'latitude' => $request->latitude,
                            'longitude' => $request->longitude
                        ],
                        'requirements' => $request->requirements->map(function ($requirement) {
                            return [
                                'id' => $requirement->id,
                                'name' => $requirement->serviceRequirement->name ?? 'Unknown',
                                'type' => $requirement->serviceRequirement->type ?? 'input',
                                'value' => $requirement->value,
                                'file_url' => $requirement->file_path ? asset('storage/' . $requirement->file_path) : null
                            ];
                        }),
                        'provider' => $request->assignedProvider ? [
                            'id' => $request->assignedProvider->id,
                            'name' => $request->assignedProvider->user->first_name . ' ' . $request->assignedProvider->user->last_name,
                            'image' => $request->assignedProvider->user->profile_image ? $request->assignedProvider->user->profile_image : null
                        ] : null,
                        'customer_info' => [
                            'name' => $request->name,
                            'age' => $request->age,
                            'gender' => $request->gender,
                            'phone' => $request->phone,
                            'additional_info' => $request->additional_info
                        ]
                    ];
                });

            return $this->success($requests, 'Ongoing requests retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve ongoing requests: ' . $e->getMessage(), 500);
        }
    }
}
