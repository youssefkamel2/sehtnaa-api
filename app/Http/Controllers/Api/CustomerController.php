<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ResponseTrait;

    public function getAllCustomers()
    {
        try {
            $customers = Customer::with(['user' => function($query) {
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
    public function toggleCustomerStatus(Request $request, $id)
    {
        try {
            $customer = Customer::with('user')->findOrFail($id);
            
            if (!$customer->user) {
                return $this->error('Customer user not found', 404);
            }

            // Toggle the status
            $customer->user->status = $customer->user->status === 'active' ? 'inactive' : 'active';
            $customer->user->save();

            return $this->success([
                'status' => $customer->user->status
            ], 'Customer status updated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to update customer status: ' . $e->getMessage(), 500);
        }
    }
}